<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\IssueType;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockPolicy;
use App\Modules\Wms\Requests\SaveIssueTypeRequest;
use App\Modules\Wms\Requests\SaveStockPolicyRequest;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class StockPolicyController extends Controller
{
    public function policies(): View
    {
        return view('Wms::stock-policies.index');
    }

    public function policyData(Request $request): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');

        return DataTables::eloquent(StockPolicy::query()->with(['warehouse', 'item'])->where('warehouse_id', $warehouse->id)->latest('id'))
            ->addColumn('warehouse_label', fn (StockPolicy $row) => $row->warehouse?->code.' · '.$row->warehouse?->name)
            ->addColumn('item_label', fn (StockPolicy $row) => $row->item ? $row->item->code.' · '.$row->item->name : 'ค่าเริ่มต้นทั้งคลัง')
            ->editColumn('min_quantity', fn (StockPolicy $row) => WmsDecimal::format($row->min_quantity))
            ->editColumn('max_quantity', fn (StockPolicy $row) => WmsDecimal::format($row->max_quantity))
            ->editColumn('reorder_quantity', fn (StockPolicy $row) => WmsDecimal::format($row->reorder_quantity))
            ->addColumn('status_label', fn (StockPolicy $row) => $row->is_active ? 'ใช้งาน' : 'ปิดใช้งาน')
            ->addColumn('edit_url', fn (StockPolicy $row) => auth()->user()->hasPermission('wms.stock-policies.update') ? route('wms.stock-policies.edit', $row) : null)
            ->addColumn('delete_url', fn (StockPolicy $row) => auth()->user()->hasPermission('wms.stock-policies.delete') ? route('wms.stock-policies.destroy', $row) : null)->toJson();
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $q = trim((string) ($values['q'] ?? ''));
        $page = max(1, (int) ($values['page'] ?? 1));
        $query = Item::query()->where('is_active', true)->where('is_stock_item', true)->when($q, fn ($builder) => $builder->where(fn ($nested) => $nested->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))->orderBy('code');
        $rows = $query->forPage($page, 31)->get(['id', 'code', 'name']);

        return response()->json(['results' => $rows->map(fn ($item) => ['id' => $item->id, 'text' => $item->code.' · '.$item->name]), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function policyCreate(Request $request): View
    {
        return view('Wms::stock-policies.form', ['policy' => new StockPolicy(['is_active' => true]), 'warehouse' => $request->attributes->get('selectedWarehouse')]);
    }

    public function policyStore(SaveStockPolicyRequest $request, AuditLogger $audit): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        DB::transaction(function () use ($request, $warehouse, $audit): void {
            $data = $request->validated();
            // Serialize default/item-specific policy creation per warehouse so
            // nullable legacy policies cannot be duplicated under concurrency.
            DB::table('warehouses')->where('id', $warehouse->id)->lockForUpdate()->first();
            $itemId = $data['item_id'] ?? null;
            $policy = StockPolicy::withTrashed()->where('warehouse_id', $warehouse->id)
                ->when($itemId === null, fn ($query) => $query->whereNull('item_id'), fn ($query) => $query->where('item_id', $itemId))
                ->lockForUpdate()->first();
            if (! $policy) {
                $policy = new StockPolicy(['warehouse_id' => $warehouse->id, 'item_id' => $itemId, 'created_by' => $request->user()->id]);
            } elseif ($policy->trashed()) {
                $policy->restore();
            }
            $policy->fill($data);
            $policy->warehouse_id = $warehouse->id;
            $policy->item_id = $itemId;
            $policy->created_by ??= $request->user()->id;
            $policy->save();
            $audit->record('wms.stock_policy.saved', $policy, [], $policy->fresh()->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'บันทึกนโยบาย Min/Max ของคลังแล้ว', 'redirect' => route('wms.stock-policies.index')]);
    }

    public function policyEdit(Request $request, StockPolicy $policy): View
    {
        $this->assertWarehouse($request, $policy->warehouse_id);

        return view('Wms::stock-policies.form', ['policy' => $policy, 'warehouse' => $request->attributes->get('selectedWarehouse')]);
    }

    public function policyUpdate(SaveStockPolicyRequest $request, StockPolicy $policy, AuditLogger $audit): JsonResponse
    {
        $this->assertWarehouse($request, $policy->warehouse_id);
        DB::transaction(function () use ($request, $policy, $audit): void {
            $data = $request->validated();
            DB::table('warehouses')->where('id', $policy->warehouse_id)->lockForUpdate()->first();
            $locked = StockPolicy::query()->lockForUpdate()->findOrFail($policy->id);
            $itemId = $data['item_id'] ?? null;
            $duplicate = StockPolicy::withTrashed()->where('warehouse_id', $locked->warehouse_id)->where('id', '<>', $locked->id)
                ->when($itemId === null, fn ($query) => $query->whereNull('item_id'), fn ($query) => $query->where('item_id', $itemId))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['item_id' => 'มีนโยบาย Min/Max สำหรับคลังและสินค้านี้แล้ว หรืออยู่ในถังลบ กรุณาแก้ไขรายการเดิมก่อน']);
            }
            $before = $locked->toArray();
            $locked->fill($data);
            $locked->item_id = $itemId;
            $locked->save();
            $audit->record('wms.stock_policy.updated', $locked, $before, $locked->fresh()->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขนโยบาย Min/Max แล้ว']);
    }

    public function policyDestroy(Request $request, StockPolicy $policy, AuditLogger $audit): JsonResponse
    {
        $this->assertWarehouse($request, $policy->warehouse_id);
        $before = $policy->toArray();
        $policy->delete();
        $audit->record('wms.stock_policy.deleted', $policy, $before, ['deleted_at' => $policy->deleted_at], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบนโยบาย Min/Max แล้ว']);
    }

    public function issueTypes(): View
    {
        return view('Wms::issue-types.index');
    }

    public function issueTypeData(Request $request): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');

        return DataTables::eloquent(IssueType::query()->with('warehouse')->where('warehouse_id', $warehouse->id)->latest('id'))->addColumn('warehouse_label', fn (IssueType $row) => $row->warehouse?->code.' · '.$row->warehouse?->name)->addColumn('status_label', fn (IssueType $row) => $row->is_active ? 'ใช้งาน' : 'ปิดใช้งาน')->addColumn('edit_url', fn (IssueType $row) => auth()->user()->hasPermission('wms.issue-types.update') ? route('wms.issue-types.edit', $row) : null)->addColumn('delete_url', fn (IssueType $row) => auth()->user()->hasPermission('wms.issue-types.delete') ? route('wms.issue-types.destroy', $row) : null)->toJson();
    }

    public function issueTypeCreate(Request $request): View
    {
        return view('Wms::issue-types.form', ['issueType' => new IssueType(['is_active' => true]), 'warehouse' => $request->attributes->get('selectedWarehouse')]);
    }

    public function issueTypeStore(SaveIssueTypeRequest $request, AuditLogger $audit): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $type = IssueType::create([...$request->validated(), 'warehouse_id' => $warehouse->id, 'created_by' => $request->user()->id]);
        $audit->record('wms.issue_type.created', $type, [], $type->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'เพิ่มประเภทการเบิกแล้ว', 'redirect' => route('wms.issue-types.index')]);
    }

    public function issueTypeEdit(Request $request, IssueType $issueType): View
    {
        $this->assertWarehouse($request, $issueType->warehouse_id);

        return view('Wms::issue-types.form', ['issueType' => $issueType, 'warehouse' => $request->attributes->get('selectedWarehouse')]);
    }

    public function issueTypeUpdate(SaveIssueTypeRequest $request, IssueType $issueType, AuditLogger $audit): JsonResponse
    {
        $this->assertWarehouse($request, $issueType->warehouse_id);
        $before = $issueType->toArray();
        $issueType->update($request->validated());
        $audit->record('wms.issue_type.updated', $issueType, $before, $issueType->fresh()->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'แก้ไขประเภทการเบิกแล้ว']);
    }

    public function issueTypeDestroy(Request $request, IssueType $issueType, AuditLogger $audit): JsonResponse
    {
        $this->assertWarehouse($request, $issueType->warehouse_id);
        $before = $issueType->toArray();
        $issueType->delete();
        $audit->record('wms.issue_type.deleted', $issueType, $before, ['deleted_at' => $issueType->deleted_at], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบประเภทการเบิกแล้ว']);
    }

    private function assertWarehouse(Request $request, int $warehouseId): void
    {
        abort_unless((int) $request->attributes->get('selectedWarehouse')?->id === $warehouseId, 404);
    }
}
