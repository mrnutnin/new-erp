<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCapitalization;
use App\Modules\Asset\Models\AssetDepreciationPolicyChange;
use App\Modules\Asset\Models\AssetOpeningBalanceLine;
use App\Modules\Asset\Requests\ReverseAssetCapitalizationRequest;
use App\Modules\Asset\Requests\StoreAssetCapitalizationRequest;
use App\Modules\Asset\Requests\VoidAssetCapitalizationRequest;
use App\Modules\Asset\Services\AssetCapitalizationService;
use App\Modules\Asset\Services\PurchaseAssetSourceEligibilityService;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AssetCapitalizationController extends Controller
{
    public function index(Request $request): View
    {
        return view('Asset::capitalizations.index', $this->documentViewData($request));
    }

    public function data(Request $request): JsonResponse
    {
        return DataTables::eloquent(AssetCapitalization::query()->withCount('lines')
            ->where('branch_id', $this->branchId($request))
            ->where('transaction_type', $this->transactionType($request))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('source_type'), fn ($query) => $query->where('source_type', $request->string('source_type')->toString()))
            ->when($request->filled('document_date_from'), fn ($query) => $query->whereDate('document_date', '>=', $request->date('document_date_from')->toDateString()))
            ->when($request->filled('document_date_to'), fn ($query) => $query->whereDate('document_date', '<=', $request->date('document_date_to')->toDateString()))
            ->latest('document_date')->latest('id'))
            ->addColumn('show_url', fn (AssetCapitalization $row) => route($this->route($request, 'show'), $row))
            ->addColumn('delete_url', fn (AssetCapitalization $row) => $row->status === 'DRAFT' && $request->user()->hasPermission('asset.capitalizations.create') ? route($this->route($request, 'destroy'), $row) : null)
            ->toJson();
    }

    public function create(Request $request): View
    {
        $manualAsset = null;
        if ($request->string('source_type')->toString() === 'MANUAL_RECLASS' && $request->integer('asset_id')) {
            $manualAsset = Asset::query()->with('category:id,code,name')->where('branch_id', $this->branchId($request))
                ->where('status', $this->isAddition($request) ? 'ACTIVE' : 'DRAFT')->findOrFail($request->integer('asset_id'));
        }

        return view('Asset::capitalizations.form', [...$this->documentViewData($request), 'manualAsset' => $manualAsset]);
    }

    public function store(
        StoreAssetCapitalizationRequest $request,
        AssetCapitalizationService $service,
        DocumentSequenceService $sequences,
    ): JsonResponse {
        $capitalization = DB::transaction(function () use ($request, $service, $sequences): AssetCapitalization {
            $branch = $request->attributes->get('selectedBranch');
            $date = Carbon::parse($request->validated('document_date'));
            $isAddition = $this->isAddition($request);
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', $isAddition ? 'ASSET_ADDITION' : 'ASSET_CAPITALIZATION')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_date' => $isAddition ? 'ยังไม่ได้ตั้งค่าเลขใบเพิ่มมูลค่าสินทรัพย์' : 'ยังไม่ได้ตั้งค่าเลขใบรับรู้สินทรัพย์']);
            }
            $number = $sequences->issueAvailableForBranch($sequence, $branch, $date, fn (string $candidate): bool => AssetCapitalization::query()->where('document_number', $candidate)->exists());
            $capitalization = $service->createDraft($branch, [...$request->validated(), 'document_number' => $number, 'transaction_type' => $isAddition ? 'ADDITION' : 'CAPITALIZATION'], $request->user());
            $sequences->recordIssued($sequence, $number, 'asset_capitalizations', $capitalization->id, $date, $request->user()->id);

            return $capitalization;
        });

        return response()->json(['status' => true, 'msg' => $this->isAddition($request) ? 'สร้างใบเพิ่มมูลค่าสินทรัพย์ร่างแล้ว' : 'สร้างใบรับรู้สินทรัพย์ร่างแล้ว', 'redirect' => route($this->route($request, 'show'), $capitalization)]);
    }

    public function show(Request $request, AssetCapitalization $capitalization, AssetCapitalizationService $service): View
    {
        $capitalization = $this->scoped($request, $capitalization)->load([
            'branch', 'createdBy', 'submittedBy', 'approvedBy', 'postedBy', 'reversedBy', 'voidedBy', 'lines.asset.category', 'lines.clearingAccount', 'journalEntry', 'reversalJournalEntry',
        ]);
        $additionPolicies = $this->isAddition($request)
            ? AssetDepreciationPolicyChange::query()->with('depreciationBook')
                ->where('profile_snapshot->addition_document_id', $capitalization->id)->orderBy('id')->get()
            : collect();

        return view('Asset::capitalizations.show', [...$this->documentViewData($request), 'capitalization' => $capitalization, 'additionPolicies' => $additionPolicies, 'postReadiness' => $capitalization->status === 'APPROVED' ? $service->postReadiness($capitalization) : null]);
    }

    public function destroy(Request $request, AssetCapitalization $capitalization, AuditLogger $audit): JsonResponse
    {
        $capitalization = $this->scoped($request, $capitalization);

        DB::transaction(function () use ($capitalization, $request, $audit): void {
            $capitalization = AssetCapitalization::query()->lockForUpdate()->findOrFail($capitalization->id);
            if ($capitalization->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะใบรับรู้สถานะร่าง']);
            }
            $before = $capitalization->toArray();
            $capitalization->delete();
            $audit->record('asset.capitalization.deleted', $capitalization, $before, ['deleted_at' => $capitalization->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => $this->isAddition($request) ? 'ลบใบเพิ่มมูลค่าสินทรัพย์ร่างแล้ว' : 'ลบใบรับรู้สินทรัพย์ร่างแล้ว', 'redirect' => route($this->route($request, 'index'))]);
    }

    public function submit(Request $request, AssetCapitalization $capitalization, AssetCapitalizationService $service): JsonResponse
    {
        $capitalization = $service->submit($this->scoped($request, $capitalization), $request->user());

        return $this->changed($request, $this->isAddition($request) ? 'ส่งใบเพิ่มมูลค่าเพื่ออนุมัติแล้ว' : 'ส่งใบรับรู้สินทรัพย์เพื่ออนุมัติแล้ว', $capitalization);
    }

    public function approve(Request $request, AssetCapitalization $capitalization, AssetCapitalizationService $service): JsonResponse
    {
        $capitalization = $service->approve($this->scoped($request, $capitalization), $request->user());

        return $this->changed($request, $this->isAddition($request) ? 'อนุมัติใบเพิ่มมูลค่าสินทรัพย์แล้ว' : 'อนุมัติใบรับรู้สินทรัพย์แล้ว', $capitalization);
    }

    public function post(Request $request, AssetCapitalization $capitalization, AssetCapitalizationService $service): JsonResponse
    {
        $capitalization = $service->post($this->scoped($request, $capitalization), $request->user());

        return $this->changed($request, $this->isAddition($request) ? 'ลงบัญชีใบเพิ่มมูลค่าสินทรัพย์แล้ว' : 'ลงบัญชีใบรับรู้สินทรัพย์แล้ว', $capitalization);
    }

    public function void(VoidAssetCapitalizationRequest $request, AssetCapitalization $capitalization, AssetCapitalizationService $service, AuditLogger $audit): JsonResponse
    {
        $capitalization = $this->scoped($request, $capitalization);
        $before = $capitalization->only(['status', 'void_reason', 'voided_by', 'voided_at']);
        $capitalization = $service->void($capitalization, $request->validated('void_reason'), $request->user());
        $audit->record('asset.capitalization.voided', $capitalization, $before, $capitalization->only(['status', 'void_reason', 'voided_by', 'voided_at']), $request->user(), $request);

        return $this->changed($request, $this->isAddition($request) ? 'ยกเลิกใบเพิ่มมูลค่าสินทรัพย์แล้ว' : 'ยกเลิกใบรับรู้สินทรัพย์แล้ว', $capitalization);
    }

    public function reverse(ReverseAssetCapitalizationRequest $request, AssetCapitalization $capitalization, AssetCapitalizationService $service): JsonResponse
    {
        $capitalization = $service->reverse(
            $this->scoped($request, $capitalization),
            $request->validated('reversal_date'),
            $request->validated('reversal_reason'),
            $request->user(),
        );

        return $this->changed($request, $this->isAddition($request) ? 'กลับรายการใบเพิ่มมูลค่าสินทรัพย์แล้ว' : 'กลับรายการใบรับรู้สินทรัพย์แล้ว', $capitalization);
    }

    public function sourceOptions(Request $request, PurchaseAssetSourceEligibilityService $purchaseSources): JsonResponse
    {
        $branchId = $this->branchId($request);
        $type = $request->string('type')->toString();
        $manualException = $request->boolean('manual_exception');
        abort_if($manualException && ! $request->user()->hasPermission('asset.capitalizations.exception'), 403);
        $search = trim($request->string('q')->toString());
        $page = max(1, $request->integer('page', 1));

        if ($type === 'PURCHASE_DOCUMENT') {
            $rows = $purchaseSources->eligibleLinesForBranch($branchId, $manualException)->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('purchase_documents.document_number', 'like', "%{$search}%")
                ->orWhere('purchase_document_lines.description', 'like', "%{$search}%")))
                ->forPage($page, 31)->get();
            $results = $rows->take(30)->map(fn ($line) => [
                'id' => $line->id,
                'text' => "{$line->source_document_number} · บรรทัด {$line->source_line_number} · {$line->description} · ".number_format((float) $line->source_net_amount, 2),
                'source_id' => $line->source_document_id,
                'source_line_id' => $line->source_line_id,
                'amount' => $line->source_net_amount,
                'account_id' => $line->account_id,
                'default_asset_category_id' => $line->default_asset_category_id,
            ]);
        } elseif ($type === 'OPENING') {
            $rows = AssetOpeningBalanceLine::query()->select('asset_opening_balance_lines.*', 'asset_opening_balance_batches.batch_reference')
                ->join('asset_opening_balance_batches', 'asset_opening_balance_batches.id', '=', 'asset_opening_balance_lines.asset_opening_balance_batch_id')
                ->where('asset_opening_balance_batches.branch_id', $branchId)->where('asset_opening_balance_batches.status', 'VALIDATED')->whereNull('asset_opening_balance_lines.asset_id')
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('asset_opening_balance_batches.batch_reference', 'like', "%{$search}%")->orWhere('asset_opening_balance_lines.row_key', 'like', "%{$search}%")->orWhere('asset_opening_balance_lines.source_reference', 'like', "%{$search}%")))
                ->orderByDesc('asset_opening_balance_lines.id')->forPage($page, 31)->get();
            $results = $rows->take(30)->map(fn (AssetOpeningBalanceLine $line) => [
                'id' => $line->id,
                'text' => "{$line->batch_reference} · {$line->row_key} · ".number_format((float) $line->opening_cost, 2),
                'source_id' => $line->asset_opening_balance_batch_id,
                'source_line_id' => $line->id,
                'amount' => $line->opening_cost,
            ]);
        } else {
            abort(404);
        }

        return response()->json(['results' => $results->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function assetOptions(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());
        $page = max(1, $request->integer('page', 1));
        $categoryId = $request->integer('asset_category_id') ?: null;
        $rows = Asset::query()->with('category:id,code,name')->where('branch_id', $this->branchId($request))->where('status', $this->isAddition($request) ? 'ACTIVE' : 'DRAFT')->when($categoryId, fn (Builder $query) => $query->where('asset_category_id', $categoryId))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('asset_number', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('asset_number')->forPage($page, 31)->get();

        return response()->json(['results' => $rows->take(30)->map(fn (Asset $asset) => [
            'id' => $asset->id, 'text' => "{$asset->asset_number} · {$asset->name}", 'category' => $asset->category?->name,
        ])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function accountOptions(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());
        $page = max(1, $request->integer('page', 1));
        $rows = Account::query()->where('is_active', true)->where('is_postable', true)->whereNull('control_account_type')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name']);

        return response()->json(['results' => $rows->take(30)->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' · '.$account->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    private function changed(Request $request, string $message, AssetCapitalization $capitalization): JsonResponse
    {
        return response()->json(['status' => true, 'msg' => $message, 'redirect' => route($this->route($request, 'show'), $capitalization)]);
    }

    private function scoped(Request $request, AssetCapitalization $capitalization): AssetCapitalization
    {
        return AssetCapitalization::query()->where('branch_id', $this->branchId($request))->where('transaction_type', $this->transactionType($request))->findOrFail($capitalization->id);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function isAddition(Request $request): bool
    {
        return $request->routeIs('asset.additions.*');
    }

    private function transactionType(Request $request): string
    {
        return $this->isAddition($request) ? 'ADDITION' : 'CAPITALIZATION';
    }

    private function route(Request $request, string $action): string
    {
        return 'asset.'.($this->isAddition($request) ? 'additions' : 'capitalizations').'.'.$action;
    }

    /** @return array{isAddition: bool, documentLabel: string, documentPluralLabel: string, routePrefix: string} */
    private function documentViewData(Request $request): array
    {
        $isAddition = $this->isAddition($request);

        return [
            'isAddition' => $isAddition,
            'documentLabel' => $isAddition ? 'ใบเพิ่มมูลค่าสินทรัพย์' : 'ใบรับรู้สินทรัพย์',
            'documentPluralLabel' => $isAddition ? 'เพิ่มมูลค่าสินทรัพย์' : 'รับรู้สินทรัพย์',
            'routePrefix' => $isAddition ? 'asset.additions' : 'asset.capitalizations',
        ];
    }
}
