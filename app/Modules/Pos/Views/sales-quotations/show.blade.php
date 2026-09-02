@extends('Pos::layout')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="app-eyebrow">SALES / QUOTATION</p>
    @php($statusLabels = ['DRAFT'=>'ร่าง','SENT'=>'ส่งแล้ว','ACCEPTED'=>'ตอบรับแล้ว','REJECTED'=>'ปฏิเสธ','CANCELLED'=>'ยกเลิก'])
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><h1 class="h2 mb-2">ใบเสนอราคา {{ $quotation->document_number }}</h1>@include('Pos::partials.document-status-badge', ['status' => $quotation->status, 'label' => $statusLabels[$quotation->status] ?? $quotation->status])</div>
        <div class="d-flex flex-wrap justify-content-end gap-2">
            @if($quotation->status === 'DRAFT' && auth()->user()->hasPermission('pos.sales-quotations.send'))<button type="button" class="btn btn-primary d-inline-flex align-items-center gap-1 js-quotation-send" data-url="{{ route('pos.sales-quotations.send', $quotation) }}"><i class="bx bx-send"></i>ส่งใบเสนอราคา</button>@endif
            @if($quotation->status === 'SENT' && auth()->user()->hasPermission('pos.sales-quotations.accept'))<button type="button" class="btn btn-primary d-inline-flex align-items-center gap-1 js-quotation-accept" data-url="{{ route('pos.sales-quotations.accept', $quotation) }}"><i class="bx bx-check-circle"></i>ตอบรับ</button>@endif
            @if($quotation->order && auth()->user()->hasPermission('pos.sales-orders.view'))<a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-orders.show', $quotation->order) }}"><i class="bx bx-cart"></i>ดูใบสั่งขาย</a>@elseif($quotation->status === 'ACCEPTED' && auth()->user()->hasPermission('pos.sales-orders.create'))<form method="post" action="{{ route('pos.sales-orders.from-quotation', $quotation) }}" class="d-inline-flex">@csrf<button class="btn btn-primary d-inline-flex align-items-center gap-1" type="submit"><i class="bx bx-cart-add"></i>สร้างใบสั่งขาย</button></form>@endif
            @if($quotation->status === 'SENT' && auth()->user()->hasPermission('pos.sales-quotations.reject'))<button type="button" class="btn btn-outline-danger d-inline-flex align-items-center gap-1 js-quotation-reject" data-url="{{ route('pos.sales-quotations.reject', $quotation) }}" data-title="ปฏิเสธใบเสนอราคา"><i class="bx bx-x-circle"></i>ปฏิเสธ</button>@endif
            @if((in_array($quotation->status, ['DRAFT', 'SENT'], true) || ($quotation->status === 'ACCEPTED' && (! $quotation->order || $quotation->order->status === 'CANCELLED'))) && auth()->user()->hasPermission('pos.sales-quotations.cancel'))<button type="button" class="btn btn-outline-danger d-inline-flex align-items-center gap-1 js-quotation-cancel" data-url="{{ route('pos.sales-quotations.cancel', $quotation) }}" data-title="ยกเลิกใบเสนอราคา"><i class="bx bx-x-circle"></i>ยกเลิก</button>@endif
            @if($quotation->rfq)<a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-rfqs.show', $quotation->rfq) }}"><i class="bx bx-link-external"></i>RFQ {{ $quotation->rfq->document_number }}</a>@endif
            @if(auth()->user()->hasPermission('pos.sales-quotations.print'))<a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-quotations.pdf', $quotation) }}" target="_blank" rel="noopener"><i class="bx bx-printer"></i>พิมพ์ PDF</a>@endif
            <a class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-quotations.index') }}"><i class="bx bx-arrow-back"></i>กลับรายการ</a>
        </div>
    </div>
    @include('Pos::partials.document-trail', ['flowDocuments' => $flowDocuments])
    @include('Pos::partials.sales-document-header', ['document' => $quotation, 'sourceIntake' => $flowDocuments['intake'] ?? null])
    @if($quotation->description)<div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="text-secondary small">รายละเอียด</div><div>{{ $quotation->description }}</div></div></div>@endif
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">รายการสินค้า</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>#</th><th>สินค้า/รายละเอียด</th><th>หน่วย</th><th class="text-end">จำนวน</th><th class="text-end">ราคา</th><th class="text-end">ส่วนลด</th><th class="text-end">รวม</th></tr></thead><tbody>@forelse($quotation->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ data_get($line->item_snapshot, 'code') }} · {{ data_get($line->item_snapshot, 'name', $line->description) }}</td><td>{{ data_get($line->uom_snapshot, 'code') }}</td><td class="text-end">{{ number_format((float)$line->quantity, 4) }}</td><td class="text-end">{{ number_format((float)$line->unit_price, 2) }}</td><td class="text-end">{{ number_format((float)$line->discount_amount, 2) }}</td><td class="text-end">{{ number_format((float)$line->line_total, 2) }}</td></tr>@empty<tr><td colspan="7" class="text-center text-secondary">ไม่มีรายการ</td></tr>@endforelse</tbody><tfoot><tr><th colspan="6" class="text-end">ยอดรวม</th><th class="text-end">{{ number_format((float)$quotation->total_amount, 2) }}</th></tr></tfoot></table></div>@include('Pos::partials.sales-tax-summary', ['document' => $quotation, 'sourceIntake' => $flowDocuments['intake'] ?? null])</div></div>
    @if($quotation->reject_reason || $quotation->cancel_reason)<div class="alert alert-warning">เหตุผล: {{ $quotation->reject_reason ?: $quotation->cancel_reason }}</div>@endif
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5 mb-3">ประวัติเอกสาร</h2>@php($historyLabels = ['pos.sales-quotation.created'=>'สร้างใบเสนอราคา','pos.sales-quotation.updated'=>'แก้ไขใบเสนอราคา','pos.sales-quotation.send'=>'ส่งใบเสนอราคา','pos.sales-quotation.accept'=>'ตอบรับใบเสนอราคา','pos.sales-quotation.reject'=>'ปฏิเสธใบเสนอราคา','pos.sales-quotation.cancel'=>'ยกเลิกใบเสนอราคา']) @forelse($history as $entry)<div class="border-bottom py-2"><span class="text-secondary small">{{ optional($entry->created_at)->format('d/m/Y H:i') }}</span> <strong>{{ $historyLabels[$entry->action] ?? $entry->action }}</strong> <span class="text-secondary">{{ $entry->user?->name }}</span></div>@empty<div class="text-secondary">ยังไม่มีประวัติ</div>@endforelse</div></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    const post = (button, reason = '') => {
        button.prop('disabled', true);
        $.post(button.data('url'), {_token: '{{ csrf_token() }}', reason})
            .done(() => window.location.reload())
            .fail((xhr) => {
                button.prop('disabled', false);
                const errors = xhr.responseJSON?.errors || {};
                const message = xhr.responseJSON?.message || Object.values(errors).flat()[0] || 'ไม่สามารถเปลี่ยนสถานะได้';
                Swal.fire({icon: 'error', title: 'ดำเนินการไม่สำเร็จ', text: message});
            });
    };
    $('.js-quotation-send,.js-quotation-accept').on('click', function () { post($(this)); });
    $('.js-quotation-reject,.js-quotation-cancel').on('click', function () {
        const button = $(this);
        Swal.fire({icon: 'warning', title: button.data('title'), input: 'textarea', inputLabel: 'เหตุผล (อย่างน้อย 10 ตัวอักษร)', inputValidator: value => !value || value.trim().length < 10 ? 'กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร' : undefined, showCancelButton: true, confirmButtonText: 'ยืนยัน', cancelButtonText: 'ยกเลิก'}).then(result => { if (result.isConfirmed) post(button, result.value); });
    });
});
</script>
@endpush
