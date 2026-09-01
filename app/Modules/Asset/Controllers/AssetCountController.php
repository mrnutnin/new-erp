<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\AssetCount;
use App\Modules\Asset\Requests\StoreAssetCountRequest;
use App\Modules\Asset\Services\AssetCountService;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class AssetCountController extends Controller
{
    public function index(): View
    {
        return view('Asset::counts.index');
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);

        return DataTables::eloquent(AssetCount::query()->withCount('lines')->where('branch_id', $this->branchId($request))->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('freeze_date', '>=', $date))->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('freeze_date', '<=', $date))->latest('freeze_date')->latest('id'))
            ->addColumn('freeze_date_label', fn (AssetCount $count) => $count->freeze_date?->format('d/m/Y') ?? '-')
            ->addColumn('show_url', fn (AssetCount $count) => route('asset.counts.show', $count))->toJson();
    }

    public function create(): View
    {
        return view('Asset::counts.form');
    }

    public function store(StoreAssetCountRequest $request, AssetCountService $service, DocumentSequenceService $sequences): JsonResponse
    {
        $count = DB::transaction(function () use ($request, $service, $sequences): AssetCount {
            $branch = $request->attributes->get('selectedBranch');
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'ASSET_COUNT')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['freeze_date' => 'ยังไม่ได้ตั้งค่าเลขที่ใบตรวจนับสินทรัพย์']);
            }
            $date = Carbon::parse($request->validated('freeze_date'));
            $number = $sequences->issueAvailableForBranch($sequence, $branch, $date, fn (string $candidate) => AssetCount::query()->where('document_number', $candidate)->exists());
            $count = $service->createDraft($branch, [...$request->validated(), 'document_number' => $number], $request->user());
            $sequences->recordIssued($sequence, $number, 'asset_counts', $count->id, $date, $request->user()->id);

            return $count;
        });

        return response()->json(['status' => true, 'msg' => 'สร้างใบตรวจนับร่างแล้ว', 'redirect' => route('asset.counts.show', $count)]);
    }

    public function show(Request $request, AssetCount $count): View
    {
        return view('Asset::counts.show', ['count' => $this->scoped($request, $count)->loadCount('lines')->load(['branch', 'location', 'category', 'createdBy', 'submittedBy', 'approvedBy', 'cancelledBy'])]);
    }

    public function linesData(Request $request, AssetCount $count): JsonResponse
    {
        $count = $this->scoped($request, $count);

        return DataTables::eloquent($count->lines()->with(['expectedLocation:id,code,name', 'foundLocation:id,code,name', 'expectedCustodian:id,name', 'foundCustodian:id,name']))
            ->addColumn('expected_location_label', fn ($line) => $line->expectedLocation ? $line->expectedLocation->code.' · '.$line->expectedLocation->name : '-')
            ->addColumn('found_location_label', fn ($line) => $line->foundLocation ? $line->foundLocation->code.' · '.$line->foundLocation->name : '')
            ->addColumn('found_custodian_label', fn ($line) => $line->foundCustodian?->name ?? '')
            ->addColumn('is_saved', fn ($line) => $line->counted_at !== null)
            ->addColumn('saved_at_label', fn ($line) => $line->counted_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i') ?? '')
            ->toJson();
    }

    public function saveLine(Request $request, AssetCount $count, int $line, AssetCountService $service): JsonResponse
    {
        $count = $this->scoped($request, $count);
        $data = $request->validate(['result' => ['required', 'in:FOUND,MISSING,WRONG_LOCATION,DAMAGED'], 'scanned_code' => ['nullable', 'string', 'max:100'], 'found_location_id' => ['nullable', 'integer', Rule::exists('asset_locations', 'id')->where('branch_id', $count->branch_id)], 'found_custodian_user_id' => ['nullable', 'integer', 'exists:users,id'], 'note' => ['nullable', 'string', 'max:500']]);
        $service->recordLine($count, $line, $data, $request->user());

        return response()->json(['status' => true, 'msg' => 'บันทึกผลตรวจนับแล้ว']);
    }

    public function storeExtra(Request $request, AssetCount $count, AssetCountService $service): JsonResponse
    {
        $count = $this->scoped($request, $count);
        $data = $request->validate(['scanned_code' => ['required', 'string', 'max:100'], 'asset_name' => ['required', 'string', 'max:255'], 'found_location_id' => ['nullable', 'integer', Rule::exists('asset_locations', 'id')->where('branch_id', $count->branch_id)], 'found_custodian_user_id' => ['nullable', 'integer', 'exists:users,id'], 'note' => ['nullable', 'string', 'max:500']]);
        $service->recordExtra($count, $data, $request->user());

        return response()->json(['status' => true, 'msg' => 'บันทึกสินทรัพย์นอกขอบเขตเพื่อติดตามแล้ว']);
    }

    public function submit(Request $request, AssetCount $count, AssetCountService $service): JsonResponse
    {
        return $this->changed($service->submit($this->scoped($request, $count), $request->user()), 'ส่งใบตรวจนับเพื่ออนุมัติแล้ว');
    }

    public function approve(Request $request, AssetCount $count, AssetCountService $service): JsonResponse
    {
        return $this->changed($service->approve($this->scoped($request, $count), $request->user()), 'อนุมัติผลตรวจนับแล้ว');
    }

    public function cancel(Request $request, AssetCount $count, AssetCountService $service): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->changed($service->cancel($this->scoped($request, $count), $data['cancellation_reason'], $request->user()), 'ยกเลิกใบตรวจนับแล้ว');
    }

    private function changed(AssetCount $count, string $msg): JsonResponse
    {
        return response()->json(['status' => true, 'msg' => $msg, 'redirect' => route('asset.counts.show', $count)]);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function scoped(Request $request, AssetCount $count): AssetCount
    {
        return AssetCount::query()->where('branch_id', $this->branchId($request))->findOrFail($count->id);
    }
}
