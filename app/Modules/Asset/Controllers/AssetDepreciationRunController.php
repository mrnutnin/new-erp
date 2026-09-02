<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetDepreciationRun;
use App\Modules\Asset\Requests\CancelAssetDepreciationRunRequest;
use App\Modules\Asset\Requests\ReverseAssetDepreciationRunRequest;
use App\Modules\Asset\Requests\StoreAssetDepreciationRunRequest;
use App\Modules\Asset\Services\AssetDepreciationRunService;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AssetDepreciationRunController extends Controller
{
    public function index(): View
    {
        return view('Asset::depreciations.index');
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'book_type' => ['nullable', Rule::in(['BOOK', 'TAX'])],
        ]);

        return DataTables::eloquent(AssetDepreciationRun::query()->withCount('lines')
            ->where('branch_id', $this->branchId($request))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('run_through_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('run_through_date', '<=', $date))
            ->when($filters['book_type'] ?? null, fn ($query, $bookType) => $query->where('book_type', $bookType))
            ->latest('run_through_date')->latest('id'))
            ->addColumn('show_url', fn (AssetDepreciationRun $run) => route('asset.depreciations.show', $run))
            ->toJson();
    }

    public function create(Request $request): View
    {
        return view('Asset::depreciations.form', ['periods' => FiscalPeriod::query()->where('status', 'OPEN')->orderByDesc('start_date')->get(['id', 'name', 'start_date', 'end_date']),
            'assets' => Asset::query()->with(['category:id,code,name', 'depreciationBooks' => fn ($query) => $query->where('is_active', true)->select(['id', 'asset_id', 'book_type'])])
                ->where('branch_id', $this->branchId($request))->where('status', 'ACTIVE')->where('is_depreciation_suspended', false)->orderBy('asset_number')->get(['id', 'asset_number', 'name', 'asset_category_id'])]);
    }

    public function store(StoreAssetDepreciationRunRequest $request, AssetDepreciationRunService $service, DocumentSequenceService $sequences, GlobalSettings $settings): JsonResponse
    {
        $existing = AssetDepreciationRun::query()->where('branch_id', $this->branchId($request))
            ->where('fiscal_period_id', $request->validated('fiscal_period_id'))->where('book_type', $request->validated('book_type'))
            ->whereNotIn('status', ['REVERSED', 'VOID', 'FAILED'])->first();
        if ($existing) {
            return response()->json(['status' => true, 'msg' => 'พบชุดคำนวณเดิมของงวดนี้แล้ว', 'redirect' => route('asset.depreciations.show', $existing)]);
        }

        $run = DB::transaction(function () use ($request, $service, $sequences, $settings): AssetDepreciationRun {
            $branch = $request->attributes->get('selectedBranch');
            $date = Carbon::parse($request->validated('run_through_date'));
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'ASSET_DEPRECIATION')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['run_through_date' => 'ยังไม่ได้ตั้งค่าเลขที่ชุดคำนวณค่าเสื่อม']);
            }
            $number = $sequences->issueAvailableForBranch($sequence, $branch, $date, fn (string $candidate): bool => AssetDepreciationRun::query()->where('document_number', $candidate)->exists());
            $run = $service->createDraft($branch, [...$request->validated(), 'document_number' => $number, 'proration' => $settings->value('asset_depreciation_proration')], $request->user());
            if ($run->document_number === $number) {
                $sequences->recordIssued($sequence, $number, 'asset_depreciation_runs', $run->id, $date, $request->user()->id);
            }

            return $run;
        });

        return response()->json(['status' => true, 'msg' => 'สร้างชุดคำนวณค่าเสื่อมร่างแล้ว', 'redirect' => route('asset.depreciations.show', $run)]);
    }

    public function show(Request $request, AssetDepreciationRun $depreciation, AssetDepreciationRunService $service): View
    {
        $run = $this->scoped($request, $depreciation)->load([
            'branch', 'fiscalPeriod', 'createdBy', 'submittedBy', 'approvedBy', 'postedBy', 'reversedBy', 'cancelledBy', 'journalEntry', 'reversalJournalEntry', 'lines.asset', 'lines.depreciationBook', 'exceptions',
        ]);

        return view('Asset::depreciations.show', ['run' => $run, 'postReadiness' => $run->status === 'APPROVED' ? $service->postReadiness($run) : null]);
    }

    public function submit(Request $request, AssetDepreciationRun $depreciation, AssetDepreciationRunService $service): JsonResponse
    {
        return $this->changed('ส่งชุดคำนวณค่าเสื่อมเพื่ออนุมัติแล้ว', $service->submit($this->scoped($request, $depreciation), $request->user()));
    }

    public function approve(Request $request, AssetDepreciationRun $depreciation, AssetDepreciationRunService $service): JsonResponse
    {
        return $this->changed('อนุมัติชุดคำนวณค่าเสื่อมแล้ว', $service->approve($this->scoped($request, $depreciation), $request->user()));
    }

    public function cancel(CancelAssetDepreciationRunRequest $request, AssetDepreciationRun $depreciation, AssetDepreciationRunService $service): JsonResponse
    {
        return $this->changed('ยกเลิกชุดค่าเสื่อมแล้ว', $service->cancel($this->scoped($request, $depreciation), $request->validated('cancellation_reason'), $request->user()));
    }

    public function post(Request $request, AssetDepreciationRun $depreciation, AssetDepreciationRunService $service): JsonResponse
    {
        return $this->changed('ลงบัญชีค่าเสื่อมแล้ว', $service->post($this->scoped($request, $depreciation), $request->user()));
    }

    public function reverse(ReverseAssetDepreciationRunRequest $request, AssetDepreciationRun $depreciation, AssetDepreciationRunService $service): JsonResponse
    {
        return $this->changed('ยกเลิกชุดค่าเสื่อมแล้ว', $service->reverse(
            $this->scoped($request, $depreciation),
            $request->validated('reversal_date'),
            $request->validated('reversal_reason'),
            $request->user(),
        ));
    }

    private function changed(string $message, AssetDepreciationRun $run): JsonResponse
    {
        return response()->json(['status' => true, 'msg' => $message, 'redirect' => route('asset.depreciations.show', $run)]);
    }

    private function scoped(Request $request, AssetDepreciationRun $run): AssetDepreciationRun
    {
        return AssetDepreciationRun::query()->where('branch_id', $this->branchId($request))->findOrFail($run->id);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }
}
