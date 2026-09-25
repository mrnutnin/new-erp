<?php

namespace App\Modules\Production\Support;

use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderEvent;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Support\Collection;

final class ProductionOrderEventPresenter
{
    private const TITLES = [
        'created' => 'สร้างใบสั่งผลิต',
        'updated' => 'แก้ไขใบสั่งผลิต',
        'deleted' => 'ลบร่างใบสั่งผลิต',
        'released' => 'ปล่อยใบสั่งผลิตเข้าคิว',
        'started' => 'เริ่มผลิต',
        'held' => 'พักการผลิต',
        'resumed' => 'ทำงานผลิตต่อ',
        'cancelled' => 'ยกเลิกใบสั่งผลิต',
        'materials_reserved' => 'จองวัตถุดิบ',
        'material_issue_created' => 'สร้างใบเบิกวัตถุดิบ',
        'material_issue_posted' => 'ลง Stock ใบเบิกวัตถุดิบ',
        'material_issue_cancelled' => 'ยกเลิกใบเบิกวัตถุดิบ',
        'material_issue_deleted' => 'ลบร่างใบเบิกวัตถุดิบ',
        'material_issue_reversed' => 'กลับรายการใบเบิกวัตถุดิบ',
        'material_return_posted' => 'ลง Stock ใบรับคืนวัตถุดิบ',
        'material_return_reversed' => 'กลับรายการใบรับคืนวัตถุดิบ',
        'recoverable_scrap_receipt_created' => 'สร้างใบรับเศษผลิต',
        'scrap_receipt_posted' => 'รับเศษผลิตเข้า Stock',
        'scrap_receipt_reversed' => 'กลับรายการรับเศษผลิต',
        'non_recoverable_scrap_reported' => 'บันทึกของเสีย',
        'finished_receipt_posted' => 'รับสินค้าผลิตเสร็จเข้าคลัง',
        'finished_receipt_reversed' => 'กลับรายการรับสินค้าผลิตเสร็จ',
        'finished_goods_reserved' => 'จองสินค้าสำเร็จรูปให้ใบสั่งขาย',
        'production_issue_reported' => 'รายงานปัญหาการผลิต',
        'production_issue_resolved' => 'แก้ไขปัญหาการผลิตแล้ว',
        'operation_created' => 'เพิ่มขั้นตอนการผลิต',
        'operation_started' => 'เริ่มขั้นตอนการผลิต',
        'operation_completed' => 'จบขั้นตอนการผลิต',
    ];

    public static function present(Collection $events, ProductionOrder $order, int $decimalPlaces = 2): Collection
    {
        return $events->sortByDesc('occurred_at')->map(function (ProductionOrderEvent $event) use ($order, $decimalPlaces): array {
            $payload = (array) ($event->payload ?? []);
            $summary = [];

            if (filled($payload['document_number'] ?? null)) {
                $summary[] = 'เลขที่เอกสาร '.$payload['document_number'];
            }
            if (filled($payload['source'] ?? null)) {
                $summary[] = 'ที่มา: '.match ($payload['source']) {
                    'MAKE_TO_ORDER' => 'ผลิตตามคำสั่งขาย',
                    'MAKE_TO_STOCK' => 'ผลิตเพื่อสต็อก',
                    'SHOP_FLOOR' => 'Shop Floor',
                    default => 'การผลิต',
                };
            }
            if (filled($payload['reason'] ?? null)) {
                $summary[] = 'เหตุผล: '.$payload['reason'];
            }
            if (filled($payload['description'] ?? null)) {
                $summary[] = $payload['description'];
            }
            if (filled($payload['resolution_method'] ?? null)) {
                $summary[] = 'วิธีแก้ไข: '.$payload['resolution_method'];
            }
            if (filled($payload['severity'] ?? null)) {
                $summary[] = 'ความสำคัญ: '.(['LOW' => 'ต่ำ', 'MEDIUM' => 'ปานกลาง', 'HIGH' => 'สูง'][$payload['severity']] ?? 'ปกติ');
            }
            if (filled($payload['name'] ?? null)) {
                $summary[] = 'ขั้นตอน: '.$payload['name'].(isset($payload['sequence']) ? ' (ลำดับ '.$payload['sequence'].')' : '');
            }

            $readiness = $payload['readiness'] ?? (array_key_exists('ready', $payload) ? $payload : null);
            if (is_array($readiness) && array_key_exists('ready', $readiness)) {
                $rows = (array) ($readiness['rows'] ?? []);
                $notReady = count(array_filter($rows, fn ($row): bool => ! is_array($row) || ! in_array($row['status'] ?? '', ['READY', 'ISSUED'], true)));
                $summary[] = 'ตรวจวัตถุดิบ: '.($readiness['ready'] ? 'พร้อม' : 'ยังไม่พร้อม').(count($rows) ? ' · '.count($rows).' รายการ' : '').(!$readiness['ready'] && $notReady ? ' · ขาด '.$notReady.' รายการ' : '');
            }
            if (isset($payload['reservation_ids'])) {
                $count = count((array) $payload['reservation_ids']);
                $summary[] = $count ? 'จองวัตถุดิบ '.$count.' รายการ' : 'ตรวจสอบการจองวัตถุดิบแล้ว';
            }
            if (isset($payload['released_reservation_ids']) && $payload['released_reservation_ids'] !== []) {
                $summary[] = 'คืนการจองวัตถุดิบ '.count((array) $payload['released_reservation_ids']).' รายการ';
            }
            if (isset($payload['completed_quantity'])) {
                $quantity = WmsDecimal::format($payload['completed_quantity'], $decimalPlaces).' '.($order->uom?->code ?: '');
                $summary[] = 'รับผลิตสะสม '.$quantity.(!empty($payload['completed']) ? ' · ครบตามแผน' : ' · ยังไม่ครบตามแผน');
            } elseif (isset($payload['quantity']) && $event->event_type === 'finished_goods_reserved') {
                $summary[] = 'จำนวนที่จอง '.WmsDecimal::format($payload['quantity'], $decimalPlaces).' '.($order->uom?->code ?: '');
            }

            if ($event->event_type === 'non_recoverable_scrap_reported') {
                $scrap = $order->scraps->firstWhere('id', (int) $event->source_id);
                if ($scrap) {
                    $summary[] = 'จำนวนของเสีย '.WmsDecimal::format($scrap->quantity, $decimalPlaces).' '.($scrap->uom?->code ?: '');
                }
            }

            return [
                'title' => self::TITLES[$event->event_type] ?? 'อัปเดตกิจกรรมการผลิต',
                'occurred_at' => $event->occurred_at,
                'actor' => $event->creator?->name ?: 'ระบบ',
                'summary' => array_values(array_unique($summary)),
            ];
        })->values();
    }
}
