<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Wms\Models\CostAllocationReview;
use App\Modules\Wms\Services\LegacyAllocationReviewCorrectionService;
use App\Modules\Wms\Services\LegacyAllocationReviewDecisionService;
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
            ->addColumn('status_label', fn () => '<span class="badge app-status-warning">เปิดรอตรวจสอบ</span>')
            ->addColumn('action', fn ($row) => '<a class="btn btn-sm btn-app-soft" href="'.e(route('wms.legacy-allocation-reviews.show', $row)).'"><i class="bx bx-search-alt-2 me-1" aria-hidden="true"></i>เปิดตรวจหลักฐาน</a>')
            ->rawColumns(['status_label', 'action'])
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

        $movement = $review->allocation?->movement;
        $sourceDocument = null;
        if ($movement?->source_type === 'ISSUE_RETURN') {
            $sourceDocument = \App\Modules\Wms\Models\IssueReturn::query()->find((int) $movement->source_id);
            if (! $sourceDocument && $movement->source_reference) {
                $sourceDocument = \App\Modules\Wms\Models\IssueReturn::query()
                    ->where('document_number', $movement->source_reference)
                    ->first();
            }
        }

        return view('Wms::legacy-allocation-reviews.show', compact('review', 'audit', 'sourceDocument'));
    }

    public function resolve(Request $request, CostAllocationReview $review, LegacyAllocationReviewCorrectionService $corrections)
    {
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')?->id;
        abort_unless((int) $review->allocation()->value('warehouse_id') === $warehouseId, 404);
        $result = $corrections->resolve($review, $request->user(), $request, (string) $request->string('reason'));

        return redirect()->route('wms.legacy-allocation-reviews.show', $review)->with('success', 'แก้ไข Legacy Review และบังคับวันที่ Movement ให้เท่ากับ Document date แล้ว');
    }

    public function approveNoAction(Request $request, CostAllocationReview $review, LegacyAllocationReviewDecisionService $decisions)
    {
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')?->id;
        abort_unless((int) $review->allocation()->value('warehouse_id') === $warehouseId, 404);
        $decisions->approveNoAction($review, $request->user(), $request, (string) $request->string('reason'));

        return redirect()->route('wms.legacy-allocation-reviews.show', $review)->with('success', 'Accounting ยืนยันแล้ว: Review นี้ไม่ต้องแก้ไข');
    }
}
