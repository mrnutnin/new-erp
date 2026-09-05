<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\ItemCategory;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Requests\SaveItemRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class ItemController extends Controller
{
    public function index(): View
    {
        return view('Wms::items.index');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Item::query()->with(['category', 'baseUom', 'defaultAssetCategory'])
            ->when($request->filled('item_type'), fn ($q) => $q->where('item_type', $request->input('item_type')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('base_uom_id'), fn ($q) => $q->where('base_uom_id', $request->integer('base_uom_id')))
            ->when($request->input('asset_capitalizable') !== null && $request->input('asset_capitalizable') !== '', fn ($q) => $q->where('is_asset_capitalizable', $request->boolean('asset_capitalizable')))
            ->when($request->input('is_active') !== null && $request->input('is_active') !== '', fn ($q) => $q->where('is_active', $request->boolean('is_active')));

        return DataTables::eloquent($query)->addColumn('category_label', fn ($r) => $r->category?->code.' · '.$r->category?->name)->addColumn('base_uom_label', fn ($r) => $r->baseUom?->code.' · '.$r->baseUom?->name ?: $r->base_uom)->addColumn('asset_capitalization_label', fn ($r) => $r->is_asset_capitalizable ? 'ได้ · '.($r->defaultAssetCategory?->name ?? '-') : 'ไม่ได้')->addColumn('status_label', fn ($r) => $r->is_active ? 'ใช้งาน' : 'ปิดใช้งาน')->addColumn('edit_url', fn ($r) => auth()->user()->hasPermission('wms.items.update') ? route('wms.items.edit', $r) : null)->addColumn('delete_url', fn ($r) => auth()->user()->hasPermission('wms.items.delete') ? route('wms.items.destroy', $r) : null)->toJson();
    }

    public function categoryOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = ItemCategory::query()->where('is_active', true)->when($q, fn ($x) => $x->where(fn ($y) => $y->where('code', 'like', "%$q%")->orWhere('name', 'like', "%$q%")))->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get();

        return response()->json(['results' => $rows->take(30)->map(fn ($r) => ['id' => $r->id, 'text' => $r->code.' · '.$r->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function accountOptions(Request $request): JsonResponse
    {
        $type = $request->input('type');
        $codes = $type === 'REVENUE' ? ['REVENUE'] : ($type === 'COGS' ? ['EXPENSE'] : ['ASSET']);
        $q = trim((string) $request->input('q'));
        $rows = Account::query()->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')->whereIn('account_types.code', $codes)->where('accounts.is_active', true)->where('accounts.is_postable', true)->whereNull('accounts.control_account_type')->when($q, fn ($x) => $x->where(fn ($y) => $y->where('accounts.code', 'like', "%$q%")->orWhere('accounts.name', 'like', "%$q%")))->orderBy('accounts.code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['accounts.id', 'accounts.code', 'accounts.name']);

        return response()->json(['results' => $rows->take(30)->map(fn ($r) => ['id' => $r->id, 'text' => $r->code.' · '.$r->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function assetCategoryOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = AssetCategory::query()->where('is_active', true)->when($q, fn ($query) => $query->where(fn ($nested) => $nested->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get();

        return response()->json(['results' => $rows->take(30)->map(fn ($row) => ['id' => $row->id, 'text' => $row->code.' · '.$row->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function uomOptions(Request $request): JsonResponse
    {
        return app(UomController::class)->options($request);
    }

    public function create(): View
    {
        return view('Wms::items.form', $this->formData(new Item(['is_active' => true, 'item_type' => 'GOODS', 'is_stock_item' => true])));
    }

    public function store(SaveItemRequest $request, AuditLogger $audit): JsonResponse
    {
        $item = DB::transaction(fn () => $this->save($request, new Item, $audit, 'created'));

        return response()->json(['status' => true, 'msg' => 'เพิ่มสินค้าแล้ว', 'redirect' => route('wms.items.index')]);
    }

    public function edit(Item $item): View
    {
        return view('Wms::items.form', $this->formData($item));
    }

    public function update(SaveItemRequest $request, Item $item, AuditLogger $audit): JsonResponse
    {
        $this->save($request, $item, $audit, 'updated');

        return response()->json(['status' => true, 'msg' => 'แก้ไขสินค้าแล้ว']);
    }

    public function destroy(Request $request, Item $item, AuditLogger $audit): JsonResponse
    {
        $references = [
            'wms_stock_movements', 'wms_cost_allocations', 'wms_stock_cost_layers', 'wms_stock_balances',
            'wms_stock_reservations', 'wms_inventory_adjustments', 'wms_transfer_lines',
            'wms_opening_balance_lines', 'wms_stock_count_lines', 'wms_stock_policies',
            'wms_issue_lines',
        ];
        foreach ($references as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('item_id', $item->id)->exists()) {
                return response()->json(['status' => false, 'msg' => 'ลบสินค้าไม่ได้ เนื่องจากถูกนำไปใช้ใน '.$table], 422);
            }
        }
        $before = $item->toArray();
        $item->delete();
        $audit->record('wms.item.deleted', $item, $before, ['deleted_at' => now()->toDateTimeString()], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบสินค้าแล้ว']);
    }

    private function save(SaveItemRequest $request, Item $item, AuditLogger $audit, string $event): Item
    {
        $values = $request->validated();
        $this->assertAccounts($values);
        $before = $item->exists ? $item->toArray() : [];
        $item->fill([...$values, 'created_by' => $item->created_by ?? $request->user()->id])->save();
        $audit->record('wms.item.'.$event, $item, $before, $item->fresh()->toArray(), $request->user(), $request);

        return $item;
    }

    private function assertAccounts(array $values): void
    {
        $ids = collect([$values['inventory_account_id'] ?? null, $values['sales_account_id'] ?? null, $values['cogs_account_id'] ?? null])->filter()->map(fn ($id) => (int) $id)->unique();
        $accounts = Account::query()->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')->whereKey($ids)->where('accounts.is_active', true)->where('accounts.is_postable', true)->whereNull('accounts.control_account_type')->get(['accounts.id', 'account_types.code as type_code'])->keyBy('id');
        $expected = [(int) ($values['sales_account_id'] ?? 0) => 'REVENUE'];
        if (! empty($values['inventory_account_id'])) {
            $expected[(int) $values['inventory_account_id']] = 'ASSET';
        }
        if (! empty($values['cogs_account_id'])) {
            $expected[(int) $values['cogs_account_id']] = 'EXPENSE';
        }
        if ($values['item_type'] === 'GOODS' && $values['is_stock_item'] && (empty($values['inventory_account_id']) || empty($values['cogs_account_id']))) {
            throw ValidationException::withMessages(['inventory_account_id' => 'สินค้าที่ติดตามสต็อกต้องมีบัญชีสินค้าคงเหลือและบัญชีต้นทุน']);
        }
        foreach ($expected as $id => $type) {
            if (! $accounts->has($id) || $accounts[$id]->type_code !== $type) {
                throw ValidationException::withMessages(['sales_account_id' => 'บัญชี GL ของสินค้าไม่ตรงประเภทหรือไม่พร้อมใช้งาน']);
            }
        }
    }

    private function formData(Item $item): array
    {
        return [
            'item' => $item,
            'selectedCategory' => $item->category_id ? ItemCategory::query()->find($item->category_id) : null,
            'selectedUom' => $item->base_uom_id ? Uom::query()->find($item->base_uom_id) : null,
            'selectedAssetCategory' => $item->default_asset_category_id ? AssetCategory::query()->find($item->default_asset_category_id) : null,
            'accounts' => Account::query()->whereKey(collect([$item->inventory_account_id, $item->sales_account_id, $item->cogs_account_id])->filter())->get(['id', 'code', 'name'])->keyBy('id'),
        ];
    }
}
