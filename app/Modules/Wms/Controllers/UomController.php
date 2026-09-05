<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Models\UomConversion;
use App\Modules\Wms\Requests\SaveUomConversionRequest;
use App\Modules\Wms\Requests\SaveUomRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class UomController extends Controller
{
    public function index(): View
    {
        return view('Wms::uoms.index');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Uom::query()->when($request->input('is_active') !== null && $request->input('is_active') !== '', fn ($q) => $q->where('is_active', $request->boolean('is_active')));
        return DataTables::eloquent($query)->addColumn('status_label', fn ($r) => $r->is_active ? 'ใช้งาน' : 'ปิดใช้งาน')->addColumn('edit_url', fn ($r) => auth()->user()->hasPermission('wms.uoms.update') ? route('wms.uoms.edit', $r) : null)->addColumn('delete_url', fn ($r) => auth()->user()->hasPermission('wms.uoms.delete') ? route('wms.uoms.destroy', $r) : null)->toJson();
    }

    public function options(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = Uom::query()->where('is_active', true)->when($q, fn ($x) => $x->where(fn ($y) => $y->where('code', 'like', "%$q%")->orWhere('name', 'like', "%$q%")))->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get();

        return response()->json(['results' => $rows->take(30)->map(fn ($r) => ['id' => $r->id, 'text' => $r->code.' · '.$r->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function create(): View
    {
        return view('Wms::uoms.form', ['uom' => new Uom(['is_active' => true, 'decimal_places' => 2])]);
    }

    public function store(SaveUomRequest $request, AuditLogger $audit): JsonResponse
    {
        $uom = Uom::create([...$request->validated(), 'created_by' => $request->user()->id]);
        $audit->record('wms.uom.created', $uom, [], $uom->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'เพิ่มหน่วยนับแล้ว', 'redirect' => route('wms.uoms.index')]);
    }

    public function edit(Uom $uom): View
    {
        return view('Wms::uoms.form', compact('uom'));
    }

    public function update(SaveUomRequest $request, Uom $uom, AuditLogger $audit): JsonResponse
    {
        $before = $uom->toArray();
        $uom->update($request->validated());
        $audit->record('wms.uom.updated', $uom, $before, $uom->fresh()->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'แก้ไขหน่วยนับแล้ว']);
    }

    public function destroy(Request $request, Uom $uom, AuditLogger $audit): JsonResponse
    {
        $references = [
            ['wms_items', 'base_uom_id'], ['wms_stock_movements', 'uom_id'], ['wms_cost_allocations', 'uom_id'],
            ['wms_stock_cost_layers', 'uom_id'], ['wms_stock_balances', 'uom_id'], ['wms_stock_reservations', 'uom_id'],
            ['wms_inventory_adjustments', 'uom_id'], ['wms_transfer_lines', 'uom_id'],
            ['wms_opening_balance_lines', 'uom_id'], ['wms_stock_count_lines', 'uom_id'],
            ['wms_issue_lines', 'uom_id'],
            ['wms_uom_conversions', 'from_uom_id'], ['wms_uom_conversions', 'to_uom_id'],
        ];
        foreach ($references as [$table, $column]) {
            if (Schema::hasTable($table) && DB::table($table)->where($column, $uom->id)->exists()) {
                return response()->json(['status' => false, 'msg' => 'ลบหน่วยนับไม่ได้ เนื่องจากถูกนำไปใช้ใน '.$table], 422);
            }
        }
        $before = $uom->toArray();
        $uom->delete();
        $audit->record('wms.uom.deleted', $uom, $before, [], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบหน่วยนับแล้ว']);
    }

    public function conversions(): View
    {
        return view('Wms::uoms.conversions');
    }

    public function conversionData(): JsonResponse
    {
        return DataTables::eloquent(UomConversion::query()->with(['fromUom', 'toUom']))->addColumn('from_label', fn ($r) => $r->fromUom?->code.' · '.$r->fromUom?->name)->addColumn('to_label', fn ($r) => $r->toUom?->code.' · '.$r->toUom?->name)->addColumn('delete_url', fn ($r) => auth()->user()->hasPermission('wms.uom-conversions.delete') ? route('wms.uom-conversions.destroy', $r) : null)->editColumn('effective_from', fn ($r) => $r->effective_from?->format('d/m/Y') ?: '-')->editColumn('effective_to', fn ($r) => $r->effective_to?->format('d/m/Y') ?: '-')->toJson();
    }

    public function conversionStore(SaveUomConversionRequest $request, AuditLogger $audit): JsonResponse
    {
        $values = $request->validated();
        $from = Uom::query()->whereKey($values['from_uom_id'])->where('is_active', true)->first();
        $to = Uom::query()->whereKey($values['to_uom_id'])->where('is_active', true)->first();
        if (! $from || ! $to) {
            throw ValidationException::withMessages(['from_uom_id' => 'หน่วยต้นทางและปลายทางต้องเปิดใช้งาน']);
        }
        $conversion = DB::transaction(function () use ($values, $request): UomConversion {
            $start = CarbonImmutable::parse($values['effective_from']);
            $overlaps = UomConversion::query()->where('from_uom_id', $values['from_uom_id'])->where('to_uom_id', $values['to_uom_id'])->where('effective_from', '<=', $values['effective_to'] ?? '9999-12-31')->where(function ($query) use ($values): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $values['effective_from']);
            })->lockForUpdate()->get();
            foreach ($overlaps as $existing) {
                // A legacy/open-ended conversion is closed immediately before
                // the new effective date; an explicit historical range is
                // never silently rewritten when it overlaps.
                if ($existing->effective_to === null && $existing->effective_from->lt($start)) {
                    $existing->update(['effective_to' => $start->subDay()->format('Y-m-d')]);

                    continue;
                }
                throw ValidationException::withMessages(['effective_from' => 'ช่วงวันที่ของคู่ UOM นี้ทับซ้อนกับ conversion เดิม']);
            }

            return UomConversion::create([...$values, 'created_by' => $request->user()->id]);
        });
        $audit->record('wms.uom_conversion.created', $conversion, [], $conversion->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'เพิ่มอัตราแปลงหน่วยแล้ว']);
    }

    public function conversionDestroy(Request $request, UomConversion $conversion, AuditLogger $audit): JsonResponse
    {
        $before = $conversion->toArray();
        $conversion->delete();
        $audit->record('wms.uom_conversion.deleted', $conversion, $before, [], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบอัตราแปลงหน่วยแล้ว']);
    }
}
