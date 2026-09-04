<?php

namespace App\Modules\Wms\Services;

use App\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockPolicy;
use App\Modules\Wms\Models\UomConversion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Min/Max signal for the WMS dashboard.
 *
 * This service deliberately does not create a PR.  It only tells the user
 * which item is below its policy and how much would bring it to Max.
 */
class StockMinMaxAlertService
{
    public function alerts(?Warehouse $warehouse): Collection
    {
        if (! $warehouse) {
            return collect();
        }

        $policies = StockPolicy::query()
            ->with(['item', 'warehouse'])
            ->where('warehouse_id', $warehouse->id)
            ->where('is_active', true)
            ->whereNotNull('item_id')
            ->latest('id')
            ->get()
            ->unique('item_id')
            ->values();

        if ($policies->isEmpty()) {
            return collect();
        }

        $balances = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('item_id', $policies->pluck('item_id'))
            ->selectRaw('item_id, COALESCE(SUM(on_hand), 0) AS on_hand_quantity, COALESCE(SUM(reserved), 0) AS reserved_quantity, COALESCE(SUM(available), 0) AS available_quantity')
            ->groupBy('item_id')
            ->get()->keyBy('item_id');

        // Only approved, not-yet-received PO quantities reduce the suggested
        // replenishment.  Draft/void POs must not hide a real shortage.
        $received = DB::table('goods_receipt_lines AS grl')
            ->join('goods_receipts AS gr', 'gr.id', '=', 'grl.goods_receipt_id')
            ->where('gr.status', '!=', 'VOID')
            ->groupBy('grl.purchase_order_line_id')
            ->select('grl.purchase_order_line_id')
            ->selectRaw('COALESCE(SUM(grl.purchase_quantity), 0) AS received_quantity');
        $openPoLines = PurchaseOrderLine::query()
            ->join('purchase_orders AS po', 'po.id', '=', 'purchase_order_lines.purchase_order_id')
            ->leftJoinSub($received, 'received', 'received.purchase_order_line_id', '=', 'purchase_order_lines.id')
            ->where('po.warehouse_id', $warehouse->id)
            ->where('po.status', 'APPROVED')
            ->whereIn('purchase_order_lines.item_id', $policies->pluck('item_id'))
            ->with('item:id,base_uom_id')
            ->get([
                'purchase_order_lines.item_id', 'purchase_order_lines.uom_id', 'purchase_order_lines.quantity',
                'po.document_date', DB::raw('COALESCE(received.received_quantity, 0) AS received_quantity'),
            ]);
        $conversionCandidates = UomConversion::query()
            ->whereIn('from_uom_id', $openPoLines->pluck('uom_id')->filter()->unique())
            ->whereIn('to_uom_id', $openPoLines->map(fn (PurchaseOrderLine $line) => $line->item?->base_uom_id)->filter()->unique())
            ->get();
        $openPoByItem = $openPoLines->groupBy('item_id')->map(function (Collection $lines) use ($conversionCandidates): BigDecimal {
            return $lines->reduce(function (BigDecimal $total, PurchaseOrderLine $line) use ($conversionCandidates): BigDecimal {
                $remaining = BigDecimal::of((string) $line->quantity)->minus((string) $line->received_quantity);
                if ($remaining->isNegative() || $remaining->isZero() || ! $line->item?->base_uom_id) {
                    return $total;
                }
                $factor = BigDecimal::one();
                if ((int) $line->uom_id !== (int) $line->item->base_uom_id) {
                    $date = $line->document_date instanceof \DateTimeInterface
                        ? $line->document_date->format('Y-m-d')
                        : substr((string) $line->document_date, 0, 10);
                    $valid = $conversionCandidates->filter(function (UomConversion $conversion) use ($line, $date): bool {
                        $effectiveFrom = $conversion->effective_from instanceof \DateTimeInterface
                            ? $conversion->effective_from->format('Y-m-d')
                            : substr((string) $conversion->effective_from, 0, 10);
                        $effectiveTo = $conversion->effective_to instanceof \DateTimeInterface
                            ? $conversion->effective_to->format('Y-m-d')
                            : ($conversion->effective_to ? substr((string) $conversion->effective_to, 0, 10) : null);

                        return (int) $conversion->from_uom_id === (int) $line->uom_id
                            && (int) $conversion->to_uom_id === (int) $line->item->base_uom_id
                            && $effectiveFrom <= $date
                            && ($effectiveTo === null || $date <= $effectiveTo);
                    });
                    // An absent or overlapping conversion must not inflate the
                    // recommendation; Goods Receipt will block the same case.
                    if ($valid->count() !== 1) {
                        return $total;
                    }
                    $factor = BigDecimal::of((string) $valid->first()->factor);
                }

                return $total->plus($remaining->multipliedBy($factor)->toScale(8, RoundingMode::HALF_UP));
            }, BigDecimal::zero());
        });

        return $policies->map(function (StockPolicy $policy) use ($balances, $openPoByItem): ?array {
            $balance = $balances->get($policy->item_id);
            $onHand = BigDecimal::of((string) ($balance?->on_hand_quantity ?? '0'));
            $reserved = BigDecimal::of((string) ($balance?->reserved_quantity ?? '0'));
            $available = BigDecimal::of((string) ($balance?->available_quantity ?? '0'));
            $openPo = BigDecimal::of((string) ($openPoByItem[$policy->item_id] ?? '0'));
            $covered = $available->plus($openPo);
            $min = BigDecimal::of((string) $policy->min_quantity);
            $max = BigDecimal::of((string) $policy->max_quantity);

            if (! $covered->isLessThan($min)) {
                return null;
            }

            $recommended = $max->minus($covered);
            if ($recommended->isNegative()) {
                $recommended = BigDecimal::zero();
            }

            return [
                'policy_id' => $policy->id,
                'item_id' => $policy->item_id,
                'item_label' => $policy->item?->code.' · '.$policy->item?->name,
                'warehouse_label' => $policy->warehouse?->code.' · '.$policy->warehouse?->name,
                'on_hand' => $onHand->__toString(),
                'reserved' => $reserved->__toString(),
                'available' => $available->__toString(),
                'open_po' => $openPo->__toString(),
                'min' => $min->__toString(),
                'max' => $max->__toString(),
                'recommended' => $recommended->__toString(),
            ];
        })->filter()->values();
    }
}
