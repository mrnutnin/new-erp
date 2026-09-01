<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetHistory;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Requests\SaveAssetRequest;
use App\Modules\Asset\Services\DepreciationPreviewCalculator;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mpdf\Barcode;
use Yajra\DataTables\Facades\DataTables;

class AssetController extends Controller
{
    public function index(): View
    {
        return view('Asset::assets.index');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Asset::query()->with(['branch:id,code,name', 'category:id,code,name', 'location:id,code,name', 'custodian:id,name'])
            ->where('branch_id', $request->attributes->get('selectedBranch')->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->integer('asset_category_id'), fn ($query) => $query->where('asset_category_id', $request->integer('asset_category_id')))
            ->when($request->integer('location_id'), fn ($query) => $query->where('location_id', $request->integer('location_id')))
            ->when($request->integer('custodian_user_id'), fn ($query) => $query->where('custodian_user_id', $request->integer('custodian_user_id')));

        return DataTables::eloquent($query)
            ->addColumn('branch_label', fn (Asset $asset) => $asset->branch?->code.' · '.$asset->branch?->name)
            ->addColumn('category_label', fn (Asset $asset) => $asset->category?->code.' · '.$asset->category?->name)
            ->addColumn('location_label', fn (Asset $asset) => $asset->location ? $asset->location->code.' · '.$asset->location->name : '-')
            ->addColumn('custodian_label', fn (Asset $asset) => $asset->custodian?->name ?? '-')
            ->addColumn('edit_url', fn (Asset $asset) => $request->user()->hasPermission('asset.register.update') && $asset->status === 'DRAFT' ? route('asset.assets.edit', $asset) : null)
            ->toJson();
    }

    public function options(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $search = trim($request->string('q')->toString());
        $page = max(1, $request->integer('page', 1));

        $query = match ($type) {
            'asset' => Asset::query()->where('branch_id', $branchId)->whereNotIn('status', ['HELD_FOR_DISPOSAL', 'DISPOSED', 'WRITTEN_OFF'])->select(['id', 'asset_number', 'name']),
            'warehouse' => Warehouse::query()->where('branch_id', $branchId)->where('is_active', true)->select(['id', 'code', 'name']),
            'custodian' => User::query()->where('is_active', true)->whereHas('warehouses', fn ($query) => $query->where('warehouses.branch_id', $branchId)->where('warehouses.is_active', true))->select(['users.id', 'users.name', 'users.employee_code']),
            'supplier' => Party::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('role', 'SUPPLIER')->where('is_active', true))->select(['id', 'code', 'name']),
            'category' => AssetCategory::query()->where('is_active', true)->select(['id', 'code', 'name']),
            'location' => AssetLocation::query()->where('branch_id', $branchId)->where('is_active', true)->select(['id', 'code', 'name']),
            default => abort(404),
        };
        if ($search !== '') {
            $query->where(function ($query) use ($type, $search): void {
                $query->where('name', 'like', "%{$search}%");
                $query->orWhere($type === 'asset' ? 'asset_number' : ($type === 'custodian' ? 'employee_code' : 'code'), 'like', "%{$search}%");
            });
        }
        $rows = $query->orderBy('name')->forPage($page, 31)->get();

        return response()->json(['results' => $rows->take(30)->map(function ($row) {
            $code = $row->asset_number ?? $row->code ?? $row->employee_code;

            return ['id' => $row->id, 'text' => $code ? $code.' · '.$row->name : $row->name];
        })->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function create(Request $request): View
    {
        return $this->form(new Asset(['registration_date' => today(), 'acquisition_date' => today(), 'currency_code' => 'THB', 'exchange_rate' => 1]), $request);
    }

    public function store(SaveAssetRequest $request, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        $asset = DB::transaction(fn (): Asset => $this->save($request, new Asset, $audit, $sequences));

        return response()->json(['status' => true, 'msg' => 'บันทึกทะเบียนสินทรัพย์ร่างแล้ว', 'redirect' => route('asset.assets.edit', $asset)]);
    }

    public function edit(Request $request, Asset $asset): View
    {
        return $this->form($this->scoped($request, $asset), $request);
    }

    public function show(Request $request, Asset $asset, GlobalSettings $settings, DepreciationPreviewCalculator $depreciationPreview): View
    {
        $asset = $this->scoped($request, $asset)->load([
            'branch', 'warehouse', 'location', 'custodian', 'category', 'supplier', 'parent', 'depreciationBooks',
            'histories.actor', 'histories.oldLocation', 'histories.newLocation', 'histories.oldCustodian', 'histories.newCustodian', 'maintenanceRequests',
        ]);

        $previews = $asset->depreciationBooks
            ->filter(fn ($book) => $book->is_active && $book->method === 'STRAIGHT_LINE')
            ->mapWithKeys(fn ($book) => [$book->id => [
                'FULL_MONTH' => $depreciationPreview->calculate($book, 'FULL_MONTH'),
                'DAILY' => $depreciationPreview->calculate($book, 'DAILY'),
            ]]);

        return view('Asset::assets.show', ['asset' => $asset, 'dateFormat' => (string) $settings->value('date_format'), 'depreciationPreviews' => $previews]);
    }

    public function label(Request $request, Asset $asset): View
    {
        $asset = $this->scoped($request, $asset);
        $barcodeValue = $asset->barcode_value ?: $asset->tag_number ?: $asset->asset_number;
        abort_unless(mb_check_encoding($barcodeValue, 'ASCII') && preg_match('/^[ -~]+$/', $barcodeValue), 422, 'ค่าบาร์โค้ดต้องเป็นอักขระ ASCII ที่พิมพ์ได้');

        return view('Asset::assets.label', [
            'asset' => $asset,
            'branch' => $request->attributes->get('selectedBranch'),
            'barcodeValue' => $barcodeValue,
            'barcode' => (new Barcode)->getBarcodeArray($barcodeValue, 'C128B'),
        ]);
    }

    public function update(SaveAssetRequest $request, Asset $asset, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        $asset = $this->scoped($request, $asset);
        if ($asset->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะสินทรัพย์ร่าง']);
        }

        DB::transaction(fn (): Asset => $this->save($request, $asset, $audit, $sequences));

        return response()->json(['status' => true, 'msg' => 'แก้ไขทะเบียนสินทรัพย์แล้ว']);
    }

    public function destroy(Request $request, Asset $asset, AuditLogger $audit): JsonResponse
    {
        $asset = $this->scoped($request, $asset);
        if ($asset->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะสินทรัพย์ร่าง']);
        }

        DB::transaction(function () use ($asset, $audit, $request): void {
            $asset = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($asset->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะสินทรัพย์ร่าง']);
            }
            $before = $asset->toArray();
            AssetHistory::query()->create([
                'asset_id' => $asset->id,
                'event_type' => 'REGISTER_DRAFT_DELETED',
                'occurred_at' => now(),
                'source_type' => 'ASSET_REGISTER',
                'source_document_number' => $asset->asset_number,
                'actor_id' => $request->user()->id,
                'old_branch_id' => $asset->branch_id,
                'old_location_id' => $asset->location_id,
                'old_custodian_user_id' => $asset->custodian_user_id,
                'old_status' => $asset->status,
                'old_values' => $before,
            ]);
            $asset->delete();
            $audit->record('asset.register.deleted', $asset, $before, ['deleted_at' => $asset->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบสินทรัพย์ร่างแล้ว']);
    }

    private function save(SaveAssetRequest $request, Asset $asset, AuditLogger $audit, DocumentSequenceService $sequences): Asset
    {
        $values = $request->safe()->except('branch_id');
        $before = $asset->exists ? $asset->toArray() : [];
        $asset->fill($values);
        $asset->branch_id = $request->integer('branch_id');
        $asset->created_by ??= $request->user()->id;
        $asset->updated_by = $request->user()->id;

        if (! $asset->exists) {
            $branch = $request->attributes->get('selectedBranch');
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'ASSET_REGISTER')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence || ! $branch) {
                throw ValidationException::withMessages(['registration_date' => 'ยังไม่ได้ตั้งค่าเลขทะเบียนสินทรัพย์สำหรับสาขานี้']);
            }
            $documentDate = Carbon::parse($request->validated('registration_date'));
            $asset->asset_number = $sequences->issueAvailableForBranch($sequence, $branch, $documentDate, fn (string $number): bool => Asset::query()->where('asset_number', $number)->exists());
            $asset->status = 'DRAFT';
        } elseif ($asset->isDirty('registration_date')) {
            $branch = $request->attributes->get('selectedBranch');
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'ASSET_REGISTER')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence || ! $branch) {
                throw ValidationException::withMessages(['registration_date' => 'ยังไม่ได้ตั้งค่าเลขทะเบียนสินทรัพย์สำหรับสาขานี้']);
            }
            $asset->asset_number = $sequences->replaceDraftNumberForBranch($sequence, $branch, $asset->asset_number, 'assets', $asset->id, Carbon::parse($request->validated('registration_date')), $request->user()->id);
        }

        $asset->save();
        $current = $asset->fresh();
        if ($before === []) {
            $sequences->recordIssued($sequence, $asset->asset_number, 'assets', $asset->id, $documentDate, $request->user()->id);
        }
        AssetHistory::query()->create([
            'asset_id' => $asset->id,
            'event_type' => $before === [] ? 'REGISTER_DRAFT_CREATED' : 'REGISTER_DRAFT_UPDATED',
            'occurred_at' => now(),
            'source_type' => 'ASSET_REGISTER',
            'source_document_number' => $asset->asset_number,
            'actor_id' => $request->user()->id,
            'old_branch_id' => $before['branch_id'] ?? null,
            'new_branch_id' => $asset->branch_id,
            'old_location_id' => $before['location_id'] ?? null,
            'new_location_id' => $asset->location_id,
            'old_custodian_user_id' => $before['custodian_user_id'] ?? null,
            'new_custodian_user_id' => $asset->custodian_user_id,
            'old_status' => $before['status'] ?? null,
            'new_status' => $asset->status,
            'old_values' => $before ?: null,
            'new_values' => $current->toArray(),
        ]);
        $audit->record($before === [] ? 'asset.register.created' : 'asset.register.updated', $asset, $before, $current->toArray(), $request->user(), $request);

        return $asset;
    }

    private function scoped(Request $request, Asset $asset): Asset
    {
        return Asset::query()->where('branch_id', $request->attributes->get('selectedBranch')->id)->findOrFail($asset->id);
    }

    private function form(Asset $asset, Request $request): View
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $asset->loadMissing(['warehouse', 'custodian', 'supplier']);

        return view('Asset::assets.form', [
            'asset' => $asset,
            'categories' => AssetCategory::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'locations' => AssetLocation::query()->where('branch_id', $branchId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'parents' => Asset::query()->where('branch_id', $branchId)->whereKeyNot($asset->id ?: 0)->orderBy('asset_number')->get(['id', 'asset_number', 'name']),
        ]);
    }
}
