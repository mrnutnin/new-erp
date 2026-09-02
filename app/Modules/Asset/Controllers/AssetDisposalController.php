<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetDisposal;
use App\Modules\Asset\Services\AssetDisposalService;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class AssetDisposalController extends Controller
{
    public function index(): View
    {
        return view('Asset::disposals.index');
    }

    public function data(Request $request): JsonResponse
    {
        return DataTables::eloquent(AssetDisposal::query()->withCount('lines')->where('branch_id', $this->branchId($request))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('disposal_type'), fn ($q) => $q->where('disposal_type', $request->string('disposal_type')))->when($request->filled('disposal_date_from'), fn ($q) => $q->whereDate('disposal_date', '>=', $request->date('disposal_date_from')->toDateString()))->when($request->filled('disposal_date_to'), fn ($q) => $q->whereDate('disposal_date', '<=', $request->date('disposal_date_to')->toDateString()))->latest('disposal_date')->latest('id'))->addColumn('show_url', fn (AssetDisposal $row) => route('asset.disposals.show', $row))->toJson();
    }

    public function create(): View
    {
        return view('Asset::disposals.form');
    }

    public function assetOptions(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());
        $page = max(1, $request->integer('page', 1));
        $rows = Asset::query()->where('branch_id', $this->branchId($request))->whereIn('status', ['ACTIVE', 'SUSPENDED', 'UNDER_REPAIR', 'HELD_FOR_DISPOSAL'])->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('asset_number', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))->orderBy('asset_number')->forPage($page, 31)->get();

        return response()->json(['results' => $rows->take(30)->map(fn (Asset $a) => ['id' => $a->id, 'text' => $a->asset_number.' · '.$a->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function store(Request $request, AssetDisposalService $service, DocumentSequenceService $sequences): JsonResponse
    {
        $data = $request->validate(['disposal_type' => ['required', 'in:SALE,WRITE_OFF'], 'disposal_date' => ['required', 'date_format:Y-m-d'], 'proceeds' => ['nullable', 'numeric', 'min:0'], 'proceeds_reference' => ['nullable', 'string', 'max:100'], 'count_reference' => ['nullable', 'string', 'max:100'], 'investigation_reference' => ['nullable', 'string', 'max:100'], 'override_reason' => ['nullable', 'string', 'min:10', 'max:500'], 'reason' => ['required', 'string', 'min:10', 'max:500'], 'asset_ids' => ['required', 'array', 'min:1'], 'asset_ids.*' => ['integer', 'distinct']]);
        $row = DB::transaction(function () use ($request, $data, $service, $sequences): AssetDisposal {
            $branch = $request->attributes->get('selectedBranch');
            $date = Carbon::parse($data['disposal_date']);
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'ASSET_DISPOSAL')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['disposal_date' => 'ยังไม่ได้ตั้งค่าเลขที่เอกสารจำหน่ายสินทรัพย์']);
            }
            $number = $sequences->issueAvailableForBranch($sequence, $branch, $date, fn (string $candidate) => AssetDisposal::query()->where('document_number', $candidate)->exists());
            $row = $service->createDraft($branch, [...$data, 'document_number' => $number], $request->user());
            $sequences->recordIssued($sequence, $number, 'asset_disposals', $row->id, $date, $request->user()->id);

            return $row;
        });

        return response()->json(['status' => true, 'msg' => 'สร้างเอกสารจำหน่ายสินทรัพย์แล้ว', 'redirect' => route('asset.disposals.show', $row)]);
    }

    public function show(Request $request, AssetDisposal $disposal, AssetDisposalService $service): View
    {
        $disposal = $this->scoped($request, $disposal)->load(['branch', 'createdBy', 'lines.asset.category', 'journalEntry', 'reversalJournalEntry']);

        return view('Asset::disposals.show', ['disposal' => $disposal, 'postReadiness' => $disposal->status === 'APPROVED' ? $service->postReadiness($disposal) : null]);
    }

    public function submit(Request $request, AssetDisposal $disposal, AssetDisposalService $service): JsonResponse
    {
        return $this->changed('ส่งอนุมัติเอกสารจำหน่ายแล้ว', $service->submit($this->scoped($request, $disposal), $request->user()));
    }

    public function approve(Request $request, AssetDisposal $disposal, AssetDisposalService $service): JsonResponse
    {
        return $this->changed('อนุมัติเอกสารจำหน่ายแล้ว', $service->approve($this->scoped($request, $disposal), $request->user()));
    }

    public function cancel(Request $request, AssetDisposal $disposal, AssetDisposalService $service): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->changed('ยกเลิกเอกสารจำหน่ายแล้ว', $service->cancel($this->scoped($request, $disposal), $data['cancellation_reason'], $request->user()));
    }

    public function post(Request $request, AssetDisposal $disposal, AssetDisposalService $service): JsonResponse
    {
        return $this->changed('ลงบัญชีจำหน่ายสินทรัพย์แล้ว', $service->post($this->scoped($request, $disposal), $request->user()));
    }

    public function reverse(Request $request, AssetDisposal $disposal, AssetDisposalService $service): JsonResponse
    {
        $data = $request->validate(['reversal_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->changed('กลับรายการจำหน่ายสินทรัพย์แล้ว', $service->reverse($this->scoped($request, $disposal), $data['reversal_date'], $data['reason'], $request->user()));
    }

    private function changed(string $message, AssetDisposal $row): JsonResponse
    {
        return response()->json(['status' => true, 'msg' => $message, 'redirect' => route('asset.disposals.show', $row)]);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function scoped(Request $request, AssetDisposal $row): AssetDisposal
    {
        return AssetDisposal::query()->where('branch_id', $this->branchId($request))->findOrFail($row->id);
    }
}
