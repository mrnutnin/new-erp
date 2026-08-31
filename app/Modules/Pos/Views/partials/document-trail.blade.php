@php
    $types = [
        'intake' => ['label' => 'ใบรับข้อมูล', 'route' => 'pos.sales-intakes.show', 'status' => ['DRAFT' => 'ร่าง', 'COMPLETED' => 'เสร็จสิ้น', 'CANCELLED' => 'ยกเลิก']],
        'rfq' => ['label' => 'ใบขอราคา', 'route' => 'pos.sales-rfqs.show', 'status' => ['WAIT' => 'รอพิจารณา', 'APPROVED' => 'อนุมัติแล้ว', 'REJECTED' => 'ไม่อนุมัติ', 'CANCELLED' => 'ยกเลิก']],
        'quotation' => ['label' => 'ใบเสนอราคา', 'route' => 'pos.sales-quotations.show', 'status' => ['DRAFT' => 'ร่าง', 'SENT' => 'ส่งแล้ว', 'ACCEPTED' => 'ตอบรับแล้ว', 'REJECTED' => 'ปฏิเสธ', 'CANCELLED' => 'ยกเลิก']],
        'order' => ['label' => 'ใบสั่งขาย', 'route' => 'pos.sales-orders.show', 'status' => ['DRAFT' => 'ร่าง', 'CONFIRMED' => 'ยืนยันแล้ว', 'FULFILLED' => 'ดำเนินการแล้ว', 'CANCELLED' => 'ยกเลิก']],
        'hs' => ['label' => 'ขายสด (HS)', 'route' => 'pos.physical-sales.show', 'status' => ['DRAFT' => 'ร่าง', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก']],
        'iv' => ['label' => 'ขายเชื่อ (IV)', 'route' => 'pos.physical-sales.show', 'status' => ['DRAFT' => 'ร่าง', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก']],
        'receipts' => ['label' => 'รับชำระหนี้', 'route' => 'pos.receipts.show', 'permission' => 'pos.receipts.view', 'status' => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'รับเงินแล้ว', 'VOID' => 'กลับรายการแล้ว']],
        'sales_returns' => ['label' => 'รับคืนสินค้า / ลดหนี้', 'route' => 'pos.sales-returns.show', 'permission' => 'pos.sales-returns.view', 'status' => ['DRAFT' => 'ร่าง', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก']],
    ];
    $documents = collect($flowDocuments)->flatMap(function ($document, string $type) {
        return collect($document instanceof \Illuminate\Support\Collection ? $document : [$document])
            ->filter()
            ->map(fn ($item) => ['type' => $type, 'item' => $item]);
    })->values();
@endphp
<section class="card border-0 shadow-sm mb-4" aria-labelledby="document-trail-title">
    <div class="card-body p-3 p-lg-4">
        <h2 id="document-trail-title" class="h5 mb-3">ลำดับเอกสารที่เกี่ยวข้อง</h2>
        <div class="d-flex flex-wrap align-items-center gap-2">
            @foreach ($documents as ['type' => $type, 'item' => $document])
                @php($meta = $types[$type])
                @php($canOpen = empty($meta['permission']) || auth()->user()->hasPermission($meta['permission']))
                <{{ $canOpen ? 'a' : 'div' }} class="border rounded-3 p-3 text-decoration-none text-body bg-body-tertiary" @if($canOpen) href="{{ route($meta['route'], $document) }}" @endif>
                    <div class="small text-secondary">{{ $meta['label'] }}</div>
                    <div class="fw-semibold">{{ $document->document_number }}</div>
                    <div class="small text-secondary">{{ ($document->settlement_date ?? $document->document_date)?->format('d/m/Y') ?? '—' }}</div>
                    <div class="mt-2">@include('Pos::partials.document-status-badge', ['status' => $document->status, 'label' => $meta['status'][$document->status] ?? $document->status])</div>
                </{{ $canOpen ? 'a' : 'div' }}>
                @if (! $loop->last)
                    <i class="bx bx-chevron-right text-secondary" aria-hidden="true"></i>
                @endif
            @endforeach
        </div>
    </div>
</section>
