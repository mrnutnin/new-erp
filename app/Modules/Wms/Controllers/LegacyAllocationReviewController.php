<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Wms\Models\CostAllocationReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class LegacyAllocationReviewController extends Controller
{
    public function index(): View
    {
        return view('Wms::legacy-allocation-reviews.index');
    }

    public function data(Request $request): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')?->id;
        $query = CostAllocationReview::query()
            ->with(['allocation.movement.item', 'allocation.movement.uom'])
            ->where('status', 'OPEN')
            ->whereHas('allocation', fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->select('wms_cost_allocation_reviews.*');

        return DataTables::eloquent($query)
            ->addColumn('allocation_label', fn ($row) => '#'.$row->allocation_id.' / Rev '.$row->revision)
            ->addColumn('warehouse_label', fn ($row) => $row->allocation?->warehouse?->code ?? '-')
            ->addColumn('item_label', fn ($row) => $row->allocation?->movement?->item
                ? trim($row->allocation->movement->item->code.' · '.$row->allocation->movement->item->name)
                : '-')
            ->addColumn('movement_label', fn ($row) => $row->allocation?->movement
                ? trim($row->allocation->movement->movement_type.' / '.$row->allocation->movement->direction)
                : '-')
            ->addColumn('status_label', fn () => 'เปิดรอตรวจสอบ')
            ->addColumn('action', fn ($row) => '<a class="btn btn-sm btn-outline-primary" title="ดูหลักฐาน" aria-label="ดูหลักฐาน" href="'.e(route('wms.legacy-allocation-reviews.show', $row)).'"><i class="bx bx-search-alt-2" aria-hidden="true"></i></a>')
            ->rawColumns(['action'])
            ->toJson();
    }

    public function show(Request $request, CostAllocationReview $review): View
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')?->id;
        $review->load([
            'allocation.movement.item', 'allocation.movement.uom', 'allocation.layer',
            'allocation.journalLineLinks.journalEntryLine.entry', 'allocation.parent', 'actor',
        ]);

        abort_unless($review->allocation?->warehouse_id === $warehouseId, 404);

        $audit = AuditLog::query()
            ->with('user')
            ->where('subject_type', $review->getMorphClass())
            ->where('subject_id', $review->id)
            ->latest('created_at')
            ->get();

        return view('Wms::legacy-allocation-reviews.show', compact('review', 'audit'));
    }
}
