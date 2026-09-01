<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Models\AssetTransfer;
use App\Modules\Asset\Requests\StoreAssetTransferRequest;
use App\Modules\Asset\Services\AssetTransferService;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class AssetTransferController extends Controller
{
    public function index(): View
    {
        return view('Asset::transfers.index');
    }

    public function data(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'destination_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        return DataTables::eloquent(AssetTransfer::query()->with(['sourceBranch:id,code,name', 'destinationBranch:id,code,name'])->withCount('lines')->where(fn (Builder $query) => $query->where('source_branch_id', $branchId)->orWhere('destination_branch_id', $branchId))->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('document_date', '>=', $date))->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('document_date', '<=', $date))->when($filters['destination_branch_id'] ?? null, fn ($query, $id) => $query->where('destination_branch_id', $id))->latest('document_date')->latest('id'))
            ->addColumn('document_date_label', fn (AssetTransfer $transfer) => $transfer->document_date?->format('d/m/Y') ?? '-')
            ->addColumn('source_branch_label', fn (AssetTransfer $transfer) => $transfer->sourceBranch?->code.' · '.$transfer->sourceBranch?->name)
            ->addColumn('destination_branch_label', fn (AssetTransfer $transfer) => $transfer->destinationBranch?->code.' · '.$transfer->destinationBranch?->name)
            ->addColumn('show_url', fn (AssetTransfer $transfer) => route('asset.transfers.show', $transfer))->toJson();
    }

    public function create(): View
    {
        return view('Asset::transfers.form');
    }

    public function store(StoreAssetTransferRequest $request, AssetTransferService $service, DocumentSequenceService $sequences): JsonResponse
    {
        $transfer = DB::transaction(function () use ($request, $service, $sequences): AssetTransfer {
            $branch = $request->attributes->get('selectedBranch');
            $date = Carbon::parse($request->validated('document_date'));
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'ASSET_TRANSFER')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_date' => 'ยังไม่ได้ตั้งค่าเลขใบโอนสินทรัพย์']);
            }
            $number = $sequences->issueAvailableForBranch($sequence, $branch, $date, fn (string $candidate) => AssetTransfer::query()->where('document_number', $candidate)->exists());
            $transfer = $service->createDraft($branch, [...$request->validated(), 'document_number' => $number], $request->user());
            $sequences->recordIssued($sequence, $number, 'asset_transfers', $transfer->id, $date, $request->user()->id);

            return $transfer;
        });

        return response()->json(['status' => true, 'msg' => 'สร้างใบโอนสินทรัพย์ร่างแล้ว', 'redirect' => route('asset.transfers.show', $transfer)]);
    }

    public function show(Request $request, AssetTransfer $transfer): View
    {
        $transfer = AssetTransfer::query()->where(fn (Builder $query) => $query->where('source_branch_id', $this->branchId($request))->orWhere('destination_branch_id', $this->branchId($request)))->findOrFail($transfer->id);

        return view('Asset::transfers.show', ['transfer' => $transfer->load(['sourceBranch', 'destinationBranch', 'createdBy', 'submittedBy', 'approvedBy', 'postedBy', 'cancelledBy', 'lines.oldLocation', 'lines.newLocation', 'lines.oldCustodian', 'lines.newCustodian'])]);
    }

    public function submit(Request $request, AssetTransfer $transfer, AssetTransferService $service): JsonResponse
    {
        return $this->changed($service->submit($this->sourceScoped($request, $transfer), $request->user()), 'ส่งใบโอนเพื่ออนุมัติแล้ว');
    }

    public function approve(Request $request, AssetTransfer $transfer, AssetTransferService $service): JsonResponse
    {
        return $this->changed($service->approve($this->sourceScoped($request, $transfer), $request->user()), 'อนุมัติใบโอนแล้ว');
    }

    public function post(Request $request, AssetTransfer $transfer, AssetTransferService $service): JsonResponse
    {
        return $this->changed($service->post($this->sourceScoped($request, $transfer), $request->user()), 'ลงรายการโอนสินทรัพย์แล้ว');
    }

    public function cancel(Request $request, AssetTransfer $transfer, AssetTransferService $service): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->changed($service->cancel($this->sourceScoped($request, $transfer), $data['cancellation_reason'], $request->user()), 'ยกเลิกใบโอนแล้ว');
    }

    public function options(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        $branchId = $request->integer('branch_id') ?: $this->branchId($request);
        $q = trim($request->string('q')->toString());
        $page = max(1, $request->integer('page', 1));
        $query = match ($type) {
            'branch' => Branch::query()->where('is_active', true), 'warehouse' => Warehouse::query()->where('branch_id', $branchId)->where('is_active', true), 'location' => AssetLocation::query()->where('branch_id', $branchId)->where('is_active', true),
            'custodian' => User::query()->where('is_active', true)->whereHas('warehouses', fn ($x) => $x->where('branch_id', $branchId)->where('is_active', true)),
            'asset' => Asset::query()->where('branch_id', $this->branchId($request))->where('status', 'ACTIVE'), default => abort(404),
        };
        if ($q !== '') {
            $query->where(fn ($x) => $x->where($type === 'asset' ? 'asset_number' : ($type === 'branch' ? 'code' : 'name'), 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
        }
        $rows = $query->orderBy('name')->forPage($page, 31)->get();

        return response()->json(['results' => $rows->take(30)->map(fn ($row) => ['id' => $row->id, 'text' => (($row->code ?? $row->asset_number ?? '').' · '.$row->name)])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    private function changed(AssetTransfer $transfer, string $msg): JsonResponse
    {
        return response()->json(['status' => true, 'msg' => $msg, 'redirect' => route('asset.transfers.show', $transfer)]);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function sourceScoped(Request $request, AssetTransfer $transfer): AssetTransfer
    {
        return AssetTransfer::query()->where('source_branch_id', $this->branchId($request))->findOrFail($transfer->id);
    }
}
