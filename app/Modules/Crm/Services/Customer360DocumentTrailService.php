<?php

namespace App\Modules\Crm\Services;

use App\Modules\Pos\Models\SalesIntake;
use App\Modules\Pos\Support\SalesDocumentTrail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class Customer360DocumentTrailService
{
    private const META = [
        'intake' => ['label' => 'Sales Intake', 'statuses' => ['DRAFT' => 'ร่าง', 'COMPLETED' => 'เสร็จสิ้น', 'CANCELLED' => 'ยกเลิก']],
        'rfq' => ['label' => 'RFQ', 'statuses' => ['WAIT' => 'รอพิจารณา', 'APPROVED' => 'อนุมัติแล้ว', 'REJECTED' => 'ไม่อนุมัติ', 'CANCELLED' => 'ยกเลิก']],
        'quotation' => ['label' => 'Quotation', 'statuses' => ['DRAFT' => 'ร่าง', 'SENT' => 'ส่งแล้ว', 'ACCEPTED' => 'ตอบรับแล้ว', 'REJECTED' => 'ปฏิเสธ', 'CANCELLED' => 'ยกเลิก']],
        'order' => ['label' => 'Sales Order', 'statuses' => ['DRAFT' => 'ร่าง', 'CONFIRMED' => 'ยืนยันแล้ว', 'FULFILLED' => 'ดำเนินการแล้ว', 'CANCELLED' => 'ยกเลิก']],
        'sale' => ['label' => 'Invoice / Sale', 'statuses' => ['DRAFT' => 'ร่าง', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก']],
        'payment' => ['label' => 'Payment', 'statuses' => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'รับเงินแล้ว', 'VOID' => 'กลับรายการแล้ว']],
    ];

    public function forCustomer(int $partyId, int $branchId): Collection
    {
        $intakes = SalesIntake::query()->where(['party_id' => $partyId, 'branch_id' => $branchId])
            ->with([
                'rfq.quotation.order.physicalSales', 'rfq.order.physicalSales',
                'quotation.order.physicalSales', 'order.physicalSales',
            ])->latest('document_date')->latest('id')->limit(10)->get();
        $flows = $intakes->map(fn (SalesIntake $intake) => SalesDocumentTrail::for($intake));
        $invoiceNumbers = $flows->pluck('iv.document_number')->filter()->values();
        $payments = $invoiceNumbers->isEmpty() ? collect() : DB::table('finance_settlements as settlements')
            ->join('finance_settlement_allocation_intents as intents', 'intents.settlement_id', '=', 'settlements.id')
            ->join('finance_open_items as open_items', 'open_items.id', '=', 'intents.open_item_id')
            ->where('settlements.party_type', 'CUSTOMER')->where('settlements.party_id', $partyId)
            ->whereIn('open_items.document_number', $invoiceNumbers)
            ->select('open_items.document_number as invoice_number', 'settlements.document_number', 'settlements.settlement_date', 'settlements.status', 'intents.amount')
            ->orderByDesc('settlements.settlement_date')->orderByDesc('settlements.id')->get()->groupBy('invoice_number');

        return $flows->map(function (array $flow) use ($payments): array {
            $sale = $flow['iv'] ?? $flow['hs'] ?? null;
            $receipts = $sale && $sale->document_type === 'IV' ? $payments->get($sale->document_number, collect()) : collect();
            $steps = [
                $this->step('intake', $flow['intake']), $this->step('rfq', $flow['rfq']),
                $this->step('quotation', $flow['quotation']), $this->step('order', $flow['order']),
                $this->step('sale', $sale, $sale?->document_type), $this->paymentStep($receipts),
            ];

            return ['key' => $flow['intake']->id, 'steps' => $steps, 'payment_count' => $receipts->count()];
        });
    }

    private function step(string $type, $document, ?string $detail = null): array
    {
        $meta = self::META[$type];

        return [
            'type' => $type, 'label' => $meta['label'], 'number' => $document?->document_number,
            'date' => $document?->document_date?->format('d/m/Y'), 'status' => $document?->status,
            'status_label' => $document ? ($meta['statuses'][$document->status] ?? $document->status) : 'ยังไม่มี', 'detail' => $detail,
        ];
    }

    private function paymentStep(Collection $receipts): array
    {
        $receipt = $receipts->first();
        $step = $this->step('payment', null);
        if (! $receipt) {
            return $step;
        }

        return array_merge($step, [
            'number' => $receipt->document_number, 'date' => date('d/m/Y', strtotime($receipt->settlement_date)),
            'status' => $receipt->status, 'status_label' => self::META['payment']['statuses'][$receipt->status] ?? $receipt->status,
            'detail' => 'รับชำระปัจจุบัน '.number_format((float) $receipts->where('status', 'POSTED')->sum('amount'), 2).' บาท',
        ]);
    }
}
