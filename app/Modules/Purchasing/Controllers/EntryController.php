<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequisition;
use App\Modules\Purchasing\Models\PurchaseReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Purchasing dashboard entry point.
 *
 * This is intentionally module-local. Inventory-only alerts belong to the
 * WMS dashboard and are not loaded while entering Purchasing.
 */
class EntryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('Purchasing::dashboard', [
            'program' => $request->attributes->get('selectedProgram'),
            'warehouse' => $request->attributes->get('selectedWarehouse'),
        ]);
    }

    public function data(Request $request, string $section): JsonResponse
    {
        abort_unless(in_array($section, ['summary', 'work', 'trend', 'recent'], true), 404);
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $data = Cache::remember("purchasing:dashboard:{$section}:warehouse:{$warehouseId}", now()->addSeconds(30), fn () => match ($section) {
            'summary' => $this->summary($warehouseId), 'work' => $this->work($warehouseId), 'trend' => $this->trend($warehouseId), 'recent' => $this->recent($warehouseId),
        });

        return response()->json($data);
    }

    private function summary(int $warehouseId): array
    {
        $month = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
        $po = PurchaseOrder::query()->where('warehouse_id', $warehouseId)->whereBetween('document_date', $month)->selectRaw("COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = 'APPROVED' THEN total_amount ELSE 0 END), 0) AS approved_amount")->first();
        $receipts = GoodsReceipt::query()->where('warehouse_id', $warehouseId)->whereBetween('business_date', $month)->selectRaw("COUNT(*) AS total, SUM(status = 'POSTED') AS posted")->first();
        $documents = PurchaseDocument::query()->where('warehouse_id', $warehouseId)->whereBetween('document_date', $month)->selectRaw("COUNT(*) AS total, SUM(status = 'POSTED') AS posted")->first();

        return ['po_count' => (int) ($po?->total ?? 0), 'approved_po_amount' => (float) ($po?->approved_amount ?? 0), 'receipt_count' => (int) ($receipts?->total ?? 0), 'posted_receipts' => (int) ($receipts?->posted ?? 0), 'document_count' => (int) ($documents?->total ?? 0), 'posted_documents' => (int) ($documents?->posted ?? 0)];
    }

    private function work(int $warehouseId): array
    {
        return ['draft_requisitions' => PurchaseRequisition::query()->where('warehouse_id', $warehouseId)->where('status', 'DRAFT')->count(), 'approved_requisitions' => PurchaseRequisition::query()->where('warehouse_id', $warehouseId)->where('status', 'APPROVED')->doesntHave('purchaseOrder')->count(), 'draft_orders' => PurchaseOrder::query()->where('warehouse_id', $warehouseId)->where('status', 'DRAFT')->count(), 'approved_receipts' => GoodsReceipt::query()->where('warehouse_id', $warehouseId)->where('status', 'APPROVED')->count(), 'draft_documents' => PurchaseDocument::query()->where('warehouse_id', $warehouseId)->where('status', 'DRAFT')->count(), 'pending_returns' => PurchaseReturn::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count(), 'draft_landed_costs' => LandedCost::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count()];
    }

    private function trend(int $warehouseId): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths(5);
        $rows = PurchaseOrder::query()->where('warehouse_id', $warehouseId)->whereDate('document_date', '>=', $start)->selectRaw("DATE_FORMAT(document_date, '%Y-%m') AS period, COALESCE(SUM(total_amount), 0) AS amount")->groupBy('period')->pluck('amount', 'period');
        $labels = $values = [];
        for ($i = 5; $i >= 0; $i--) { $period = Carbon::today()->startOfMonth()->subMonths($i); $labels[] = $period->format('m/Y'); $values[] = (float) ($rows[$period->format('Y-m')] ?? 0); }

        return compact('labels', 'values');
    }

    private function recent(int $warehouseId): array
    {
        return PurchaseOrder::query()->where('warehouse_id', $warehouseId)->with('supplier:id,code,name')->latest('document_date')->latest('id')->limit(8)->get(['id', 'document_number', 'document_date', 'supplier_id', 'total_amount', 'status'])->map(fn (PurchaseOrder $order): array => ['document_number' => $order->document_number, 'document_date' => $order->document_date?->format('d/m/Y'), 'supplier' => $order->supplier?->name ?? $order->supplier?->code ?? '-', 'total_amount' => (float) $order->total_amount, 'status' => $order->status, 'url' => route('purchasing.purchase-orders.show', $order)])->all();
    }
}
