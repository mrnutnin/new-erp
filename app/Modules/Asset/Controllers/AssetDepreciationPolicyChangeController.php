<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetDepreciationPolicyChange;
use App\Modules\Asset\Requests\ApproveAssetDepreciationPolicyChangeRequest;
use App\Modules\Asset\Requests\CancelAssetDepreciationPolicyChangeRequest;
use App\Modules\Asset\Requests\StoreAssetDepreciationPolicyChangeRequest;
use App\Modules\Asset\Services\AssetDepreciationPolicyChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AssetDepreciationPolicyChangeController extends Controller
{
    public function index(): View
    {
        return view('Asset::depreciation-policies.index');
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'book_type' => ['nullable', Rule::in(['BOOK', 'TAX'])],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return DataTables::eloquent(AssetDepreciationPolicyChange::query()->with(['depreciationBook.asset', 'createdBy'])
            ->whereHas('depreciationBook.asset', fn ($query) => $query->where('branch_id', $this->branchId($request)))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('effective_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('effective_date', '<=', $date))
            ->when($filters['book_type'] ?? null, fn ($query, $bookType) => $query->whereHas('depreciationBook', fn ($book) => $book->where('book_type', $bookType)))
            ->when($filters['created_by'] ?? null, fn ($query, $createdBy) => $query->where('created_by', $createdBy))
            ->latest('effective_date')->latest('id'))
            ->addColumn('asset_number', fn (AssetDepreciationPolicyChange $change) => $change->depreciationBook?->asset?->asset_number)
            ->addColumn('asset_name', fn (AssetDepreciationPolicyChange $change) => $change->depreciationBook?->asset?->name)
            ->addColumn('book_type', fn (AssetDepreciationPolicyChange $change) => $change->depreciationBook?->book_type)
            ->addColumn('created_by_name', fn (AssetDepreciationPolicyChange $change) => $change->createdBy?->name)
            ->addColumn('show_url', fn (AssetDepreciationPolicyChange $change) => route('asset.depreciation-policies.show', $change))
            ->toJson();
    }

    public function requesterOptions(Request $request): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $term = $request->string('q')->trim()->toString();
        $requesters = User::query()
            ->whereIn('id', AssetDepreciationPolicyChange::query()->select('created_by')->whereNotNull('created_by')
                ->whereHas('depreciationBook.asset', fn ($query) => $query->where('branch_id', $this->branchId($request))))
            ->when($term !== '', fn ($query) => $query->where(fn ($users) => $users->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->orderBy('name')->forPage($page, 21)->get(['id', 'name', 'email']);

        return response()->json([
            'results' => $requesters->take(20)->map(fn (User $user) => ['id' => $user->id, 'text' => trim($user->name.($user->email ? ' · '.$user->email : ''))])->values(),
            'pagination' => ['more' => $requesters->count() > 20],
        ]);
    }

    public function create(Request $request): View
    {
        return view('Asset::depreciation-policies.form', [
            'effectivePeriods' => FiscalPeriod::query()->where('status', 'OPEN')->whereDate('start_date', '>=', today())
                ->orderBy('start_date')->get(['name', 'start_date', 'end_date']),
        ]);
    }

    public function assetsData(Request $request): JsonResponse
    {
        $bookType = $request->string('book_type')->toString();
        abort_unless(in_array($bookType, ['BOOK', 'TAX'], true), 422);

        return DataTables::eloquent(Asset::query()->with('category:id,name')
            ->where('branch_id', $this->branchId($request))->where('status', 'ACTIVE')->where('is_depreciation_suspended', false)
            ->whereHas('depreciationBooks', fn ($query) => $query->where('book_type', $bookType)->where('is_active', true))->orderBy('asset_number'))
            ->addColumn('category_label', fn (Asset $asset) => $asset->category?->name)
            ->toJson();
    }

    public function store(StoreAssetDepreciationPolicyChangeRequest $request, AssetDepreciationPolicyChangeService $service): JsonResponse
    {
        $changes = $service->createDrafts($request->attributes->get('selectedBranch'), $request->validated(), $request->user());

        return response()->json(['status' => true, 'msg' => 'สร้างคำขอเปลี่ยนนโยบายค่าเสื่อมแล้ว', 'policy_change_ids' => $changes->pluck('id')->all(), 'redirect' => route('asset.depreciation-policies.index')]);
    }

    public function approve(ApproveAssetDepreciationPolicyChangeRequest $request, AssetDepreciationPolicyChangeService $service): JsonResponse
    {
        $changes = $service->approveMany($request->attributes->get('selectedBranch'), $request->validated('policy_change_ids'), $request->user());

        return response()->json(['status' => true, 'msg' => 'อนุมัติการเปลี่ยนนโยบายค่าเสื่อมแล้ว', 'policy_change_ids' => $changes->pluck('id')->all()]);
    }

    public function cancel(CancelAssetDepreciationPolicyChangeRequest $request, AssetDepreciationPolicyChange $policyChange, AssetDepreciationPolicyChangeService $service): JsonResponse
    {
        $change = $this->scoped($request, $policyChange);
        $change = $service->cancel($change, $request->validated('cancellation_reason'), $request->user());

        return response()->json(['status' => true, 'msg' => 'ยกเลิกคำขอเปลี่ยนนโยบายค่าเสื่อมแล้ว', 'redirect' => route('asset.depreciation-policies.show', $change)]);
    }

    public function show(Request $request, AssetDepreciationPolicyChange $policyChange): View
    {
        return view('Asset::depreciation-policies.show', ['change' => $this->scoped($request, $policyChange)->load(['depreciationBook.asset.category', 'createdBy', 'approvedBy', 'cancelledBy'])]);
    }

    private function scoped(Request $request, AssetDepreciationPolicyChange $change): AssetDepreciationPolicyChange
    {
        return AssetDepreciationPolicyChange::query()->whereHas('depreciationBook.asset', fn ($query) => $query->where('branch_id', $this->branchId($request)))->findOrFail($change->id);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }
}
