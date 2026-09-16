@extends('Purchasing::layout')
@section('title', 'Purchase Order '.$order->document_number)
@section('content')
@php($labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'VOID' => 'ยกเลิก'])
@php($classes = ['DRAFT' => 'neutral', 'APPROVED' => 'success', 'VOID' => 'danger'])
@php($hasVat = $order->tax_treatment === 'VAT_IN')
@php($taxLabel = $hasVat ? ($order->prices_include_vat ? 'รวมภาษี' : 'ภาษีนอก') : 'ไม่มีภาษี')
@php($taxCodesUsed = $order->lines->map(fn ($line) => $line->taxCode ? $line->taxCode->code.' · '.$line->taxCode->name.' ('.number_format((float) $line->taxCode->rate, 2).'%)' : null)->filter()->unique()->values())
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">PURCHASING / PO</p>
            <h1 class="h3 mb-2">{{ $order->document_number }}</h1>
            <p class="text-secondary mb-2">{{ $order->supplier_code }} · {{ $order->supplier_name }}</p>
            <span class="badge app-status-{{ $classes[$order->status] ?? 'neutral' }}">{{ $labels[$order->status] ?? $order->status }}</span>
            <span class="badge app-status-neutral">{{ $taxLabel }}</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('purchasing.purchase-orders.index') }}"><i class="bx bx-arrow-back me-1"></i>กลับหน้ารายการ</a>
            @if($order->status === 'DRAFT' && auth()->user()->hasPermission('purchasing.purchase-orders.update'))<a class="btn btn-app-soft" href="{{ route('purchasing.purchase-orders.edit', $order) }}"><i class="bx bx-edit me-1"></i>แก้ไข</a>@endif
            @if(auth()->user()->hasPermission('purchasing.purchase-orders.print'))<a class="btn btn-app-soft" target="_blank" href="{{ route('purchasing.purchase-orders.pdf', $order) }}"><i class="bx bx-printer me-1"></i>พิมพ์</a>@endif
            @if($order->status === 'DRAFT' && auth()->user()->hasPermission('purchasing.purchase-orders.approve'))<button class="btn btn-app-primary js-po-approve" data-url="{{ route('purchasing.purchase-orders.approve', $order) }}"><i class="bx bx-check me-1"></i>อนุมัติ</button>@endif
            @if(in_array($order->status, ['DRAFT', 'APPROVED'], true) && auth()->user()->hasPermission('purchasing.purchase-orders.void'))<button class="btn btn-app-danger js-po-void" data-url="{{ route('purchasing.purchase-orders.void', $order) }}"><i class="bx bx-x-circle me-1"></i>ยกเลิกเอกสาร</button>@endif
            @if($order->status === 'DRAFT' && auth()->user()->hasPermission('purchasing.purchase-orders.delete'))<button class="btn btn-app-danger js-po-delete" data-url="{{ route('purchasing.purchase-orders.destroy', $order) }}"><i class="bx bx-trash me-1"></i>ลบร่าง</button>@endif
        </div>
    </div>
    <div class="alert alert-info border-0 mb-4"><strong>ขั้นตอนถัดไป:</strong> {{ $order->status === 'DRAFT' ? 'ตรวจสอบราคา ภาษี และจำนวน แล้วกด “อนุมัติ”' : ($order->status === 'APPROVED' ? 'พร้อมใช้สร้าง Goods Receipt เมื่อรับสินค้า' : 'เอกสารนี้ไม่มีขั้นตอนที่ต้องดำเนินการต่อ') }}</div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4">
        <h2 class="h5 mb-3">ข้อมูลใบสั่งซื้อ</h2>
        <div class="row g-3">
            <div class="col-md-6 col-xl-3"><small class="text-secondary">Supplier</small><div class="fw-semibold">{{ $order->supplier_code }} · {{ $order->supplier_name }}</div></div>
            <div class="col-md-6 col-xl-3"><small class="text-secondary">คลังรับสินค้า</small><div class="fw-semibold">{{ $order->warehouse ? $order->warehouse->code.' · '.$order->warehouse->name : '-' }}</div></div>
            <div class="col-md-6 col-xl-3"><small class="text-secondary">เงื่อนไขชำระเงิน</small><div class="fw-semibold">{{ $order->paymentTerm ? $order->paymentTerm->code.' · '.$order->paymentTerm->name : 'ไม่กำหนด' }}</div></div>
            <div class="col-md-6 col-xl-3"><small class="text-secondary">อ้างอิง PR</small><div class="fw-semibold">@if($order->purchaseRequisition)<a class="link-primary" href="{{ route('purchasing.purchase-requisitions.show', $order->purchaseRequisition) }}">{{ $order->purchaseRequisition->document_number }}</a>@else สร้างโดยตรง @endif</div></div>
            <div class="col-md-6 col-xl-3"><small class="text-secondary">วันที่เอกสาร</small><div class="fw-semibold">{{ $order->document_date?->format($dateFormat) }}</div></div>
            <div class="col-md-6 col-xl-3"><small class="text-secondary">คาดรับสินค้า</small><div class="fw-semibold">{{ $order->expected_date?->format($dateFormat) ?? '-' }}</div></div>
            <div class="col-md-6 col-xl-3"><small class="text-secondary">การคำนวณภาษี</small><div class="fw-semibold">{{ $taxLabel }}</div></div>
            <div class="col-md-6 col-xl-3"><small class="text-secondary">Tax Code ที่ใช้</small><div class="fw-semibold">{{ $hasVat ? ($taxCodesUsed->implode(', ') ?: '-') : 'ไม่ใช้ Tax Code' }}</div></div>
            <div class="col-12"><small class="text-secondary">หมายเหตุ</small><div class="fw-semibold text-break">{{ $order->description ?: '-' }}</div></div>
        </div>
    </div></div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4">
        <h2 class="h5 mb-3">รายการสั่งซื้อ</h2>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead><tr><th>#</th><th>สินค้า/รายละเอียด</th><th>หน่วย</th><th class="text-end">จำนวน</th><th class="text-end">ราคา/หน่วย</th><th>Tax Code</th><th class="text-end">ฐานภาษี</th><th class="text-end">VAT</th><th class="text-end">รวม</th></tr></thead>
            <tbody>@foreach($order->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ ($line->item?->code ? $line->item->code.' · ' : '').$line->description }}</td><td>{{ $line->uom?->code ?? '-' }}</td><td class="text-end">{{ $line->quantity }}</td><td class="text-end">{{ number_format((float) $line->unit_price, 2) }}</td><td>{{ $line->taxCode?->code ?? '-' }}</td><td class="text-end">{{ number_format((float) ($line->tax_base ?: $line->line_total), 2) }}</td><td class="text-end">{{ $hasVat ? number_format((float) $line->tax_amount, 2) : '-' }}</td><td class="text-end">{{ number_format((float) ($line->gross_amount ?: $line->line_total), 2) }}</td></tr>@endforeach</tbody>
            <tfoot><tr><th colspan="8" class="text-end">มูลค่าก่อน VAT</th><th class="text-end">{{ number_format((float) $order->subtotal, 2) }}</th></tr>@if($hasVat)<tr><th colspan="8" class="text-end">ภาษีมูลค่าเพิ่ม</th><th class="text-end">{{ number_format((float) $order->tax_amount, 2) }}</th></tr>@endif<tr><th colspan="8" class="text-end">รวมทั้งสิ้น</th><th class="text-end">{{ number_format((float) $order->total_amount, 2) }}</th></tr></tfoot>
        </table></div>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">ประวัติเอกสาร</h2>@forelse($history as $event)<div class="border-bottom py-2"><strong>{{ $event->action }}</strong><small class="text-secondary ms-2">{{ $event->created_at?->format('d/m/Y H:i') }} · {{ $event->user?->name ?? '-' }}</small></div>@empty<div class="text-secondary">ยังไม่มีประวัติ</div>@endforelse</div></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    function request(button, config) {
        button.prop('disabled', true);
        $.ajax(config).done(function (response) {
            Swal.fire({icon: 'success', text: response.msg, timer: 1200, showConfirmButton: false}).then(function () { location.reload(); });
        }).fail(function (xhr) {
            button.prop('disabled', false);
            Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ดำเนินการไม่สำเร็จ'});
        });
    }
    $('.js-po-approve').on('click', function () {
        var button = $(this);
        Swal.fire({icon: 'warning', title: 'อนุมัติใบสั่งซื้อ?', showCancelButton: true, confirmButtonText: 'อนุมัติ', cancelButtonText: 'กลับ'}).then(function (result) {
            if (result.isConfirmed) request(button, {url: button.data('url'), method: 'POST', data: {_token: $('meta[name=csrf-token]').attr('content')}});
        });
    });
    $('.js-po-void').on('click', function () {
        var button = $(this);
        Swal.fire({icon: 'warning', title: 'ยกเลิกเอกสารใบสั่งซื้อ?', input: 'textarea', inputLabel: 'เหตุผลอย่างน้อย 10 ตัวอักษร', showCancelButton: true, confirmButtonText: 'ยกเลิกเอกสาร', cancelButtonText: 'กลับ', preConfirm: function (value) { if ($.trim(value || '').length < 10) { Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร'); return false; } return value; }}).then(function (result) {
            if (result.isConfirmed) request(button, {url: button.data('url'), method: 'POST', data: {_token: $('meta[name=csrf-token]').attr('content'), reason: result.value}});
        });
    });
    window.erpAjaxDelete({button: '.js-po-delete', redirect: '{{ route('purchasing.purchase-orders.index') }}', title: 'ลบร่างใบสั่งซื้อ?', text: 'เอกสารร่างจะถูกลบและไม่สามารถกู้คืนได้', confirmButtonText: 'ลบร่าง', cancelButtonText: 'กลับ'});
});
</script>
@endpush
