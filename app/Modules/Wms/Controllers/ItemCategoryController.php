<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\ItemCategory;
use App\Modules\Wms\Requests\SaveItemCategoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class ItemCategoryController extends Controller
{
    public function index(): View
    {
        return view('Wms::item-categories.index');
    }

    public function data(): JsonResponse
    {
        return DataTables::eloquent(ItemCategory::query())->addColumn('status_label', fn ($r) => $r->is_active ? 'ใช้งาน' : 'ปิดใช้งาน')->addColumn('edit_url', fn ($r) => auth()->user()->hasPermission('wms.item-categories.update') ? route('wms.item-categories.edit', $r) : null)->addColumn('delete_url', fn ($r) => auth()->user()->hasPermission('wms.item-categories.delete') ? route('wms.item-categories.destroy', $r) : null)->toJson();
    }

    public function create(): View
    {
        return view('Wms::item-categories.form', ['category' => new ItemCategory(['is_active' => true])]);
    }

    public function store(SaveItemCategoryRequest $request, AuditLogger $audit): JsonResponse
    {
        $category = ItemCategory::create([...$request->validated(), 'created_by' => $request->user()->id]);
        $audit->record('wms.item_category.created', $category, [], $category->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'เพิ่มหมวดสินค้าแล้ว']);
    }

    public function edit(ItemCategory $category): View
    {
        return view('Wms::item-categories.form', ['category' => $category]);
    }

    public function update(SaveItemCategoryRequest $request, ItemCategory $category, AuditLogger $audit): JsonResponse
    {
        $before = $category->toArray();
        $category->update($request->validated());
        $audit->record('wms.item_category.updated', $category, $before, $category->fresh()->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'แก้ไขหมวดสินค้าแล้ว']);
    }

    public function destroy(Request $request, ItemCategory $category, AuditLogger $audit): JsonResponse
    {
        if ($category->items()->exists()) {
            return response()->json(['status' => false, 'msg' => 'ลบหมวดที่มีสินค้าไม่ได้'], 422);
        }$before = $category->toArray();
        $category->delete();
        $audit->record('wms.item_category.deleted', $category, $before, [], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบหมวดสินค้าแล้ว']);
    }
}
