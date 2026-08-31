@extends('Pos::layout')

@section('content')
@php
    $labels = ['DRAFT' => 'ร่าง', 'COMPLETED' => 'เสร็จสิ้น', 'CANCELLED' => 'ยกเลิก'];
    $decimals = (int) ($decimalPlaces ?? 2);
@endphp
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="app-eyebrow mb-2">SALES / INTAKE</p>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h2 mb-2">ใบรับข้อมูล {{ $x->document_number }}</h1>
            <div class="d-flex flex-wrap gap-2">
                @include('Pos::partials.document-status-badge', ['status' => $x->status, 'label' => $labels[$x->status] ?? $x->status])
                @if ($x->requires_rfq)<span class="badge rounded-pill px-3 py-2 app-badge-warning">ต้องผ่านการอนุมัติราคา</span>@endif
            </div>
        </div>
        <div class="d-flex flex-wrap justify-content-end gap-2">
            @if ($x->requires_rfq)
                @if (in_array($x->status, ['DRAFT', 'COMPLETED'], true) && ! $x->rfq && auth()->user()->hasPermission('pos.sales-intakes.convert'))
                    <form class="js-convert d-inline-flex" method="post" action="{{ route('pos.sales-intakes.to-rfq', $x) }}">@csrf<button class="btn btn-primary d-inline-flex align-items-center gap-1" type="submit"><i class="bx bx-plus-circle"></i>สร้าง RFQ</button></form>
                @endif
                @if ($x->rfq?->quotation)
                    <a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-quotations.show', $x->rfq->quotation) }}"><i class="bx bx-file"></i>ดูใบเสนอราคา</a>
                @elseif ($x->rfq?->order)
                    <a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-orders.show', $x->rfq->order) }}"><i class="bx bx-cart"></i>ดูใบสั่งขาย</a>
                @elseif ($x->rfq?->status === 'APPROVED')
                    @if (auth()->user()->hasPermission('pos.sales-quotations.create'))
                        <form class="js-convert d-inline-flex" method="post" action="{{ route('pos.sales-quotations.from-rfq', $x->rfq) }}">@csrf<button class="btn btn-primary d-inline-flex align-items-center gap-1" type="submit"><i class="bx bx-plus-circle"></i>สร้างใบเสนอราคา</button></form>
                    @endif
                    @if (auth()->user()->hasPermission('pos.sales-orders.create'))
                        <form class="js-convert d-inline-flex" method="post" action="{{ route('pos.sales-orders.from-rfq', $x->rfq) }}">@csrf<button class="btn btn-outline-primary d-inline-flex align-items-center gap-1" type="submit"><i class="bx bx-cart-add"></i>สร้างใบสั่งขาย</button></form>
                    @endif
                @endif
            @elseif ($x->quotation)
                <a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-quotations.show', $x->quotation) }}"><i class="bx bx-file"></i>ดูใบเสนอราคา</a>
            @elseif ($x->order)
                <a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-orders.show', $x->order) }}"><i class="bx bx-cart"></i>ดูใบสั่งขาย</a>
            @elseif (in_array($x->status, ['DRAFT', 'COMPLETED'], true))
                @if (auth()->user()->hasPermission('pos.sales-quotations.create'))
                    <form class="js-convert d-inline-flex" method="post" action="{{ route('pos.sales-quotations.from-intake', $x) }}">@csrf<button class="btn btn-primary d-inline-flex align-items-center gap-1" type="submit"><i class="bx bx-plus-circle"></i>สร้างใบเสนอราคา</button></form>
                @endif
                @if (auth()->user()->hasPermission('pos.sales-orders.create'))
                    <form class="js-convert d-inline-flex" method="post" action="{{ route('pos.sales-orders.from-intake', $x) }}">@csrf<button class="btn btn-outline-primary d-inline-flex align-items-center gap-1" type="submit"><i class="bx bx-cart-add"></i>สร้างใบสั่งขาย</button></form>
                @endif
            @endif
            @if ($x->quotation)<a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-quotations.show', $x->quotation) }}"><i class="bx bx-link-external"></i>QT {{ $x->quotation->document_number }}</a>@endif
            @if ($x->rfq)<a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-rfqs.show', $x->rfq) }}"><i class="bx bx-link-external"></i>RFQ {{ $x->rfq->document_number }}</a>@endif
            @if(auth()->user()->hasPermission('pos.sales-intakes.print'))<a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-intakes.pdf', $x) }}" target="_blank" rel="noopener"><i class="bx bx-printer"></i>พิมพ์ PDF</a>@endif
            <a class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" href="{{ route('pos.sales-intakes.index') }}"><i class="bx bx-arrow-back"></i>กลับรายการ</a>
        </div>
    </div>
    @include('Pos::partials.document-trail', ['flowDocuments' => $flowDocuments])
    @include('Pos::partials.sales-document-header', ['document' => $x, 'sourceIntake' => $x])
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">รายการสินค้า / บริการ</h2><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>รายการ</th><th class="text-end">จำนวน</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">ส่วนลด</th><th class="text-end">VAT</th><th class="text-end">รวม</th></tr></thead><tbody>@forelse($x->lines as $line)<tr><td>{{ data_get($line->item_snapshot, 'code', '—') }} · {{ data_get($line->item_snapshot, 'name', $line->description) }}</td><td class="text-end">{{ number_format((float) $line->quantity, $decimals) }}</td><td class="text-end">{{ number_format((float) ($line->requested_unit_price ?? $line->standard_unit_price ?? 0), $decimals) }}</td><td class="text-end">{{ number_format((float) $line->discount_amount, $decimals) }}</td><td class="text-end">{{ number_format((float) $line->tax_amount, $decimals) }}</td><td class="text-end fw-semibold">{{ number_format((float) $line->line_total, $decimals) }}</td></tr>@empty<tr><td colspan="6" class="text-center text-secondary py-4">ไม่มีรายการ</td></tr>@endforelse</tbody><tfoot class="table-light"><tr><th colspan="5" class="text-end">รวมทั้งสิ้น</th><th class="text-end">{{ number_format((float) $x->grand_total, $decimals) }}</th></tr></tfoot></table></div><div class="d-flex justify-content-end mt-4"><section class="col-sm-8 col-md-6 col-lg-5 col-xl-4" aria-label="สรุปยอด"><div class="bg-body-tertiary rounded-3 p-3 p-md-4"><dl class="mb-0"><div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-secondary">ยอดก่อนส่วนลด</dt><dd class="fw-semibold">{{ number_format((float) $x->subtotal, $decimals) }}</dd></div><div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-secondary">ส่วนลด</dt><dd class="fw-semibold">{{ number_format((float) $x->discount_amount, $decimals) }}</dd></div><div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-secondary">ฐานภาษี</dt><dd class="fw-semibold">{{ number_format((float) $x->tax_base, $decimals) }}</dd></div><div class="d-flex justify-content-between"><dt class="fw-normal text-secondary">ภาษี</dt><dd class="fw-semibold">{{ number_format((float) $x->tax_amount, $decimals) }}</dd></div></dl><div class="border-top mt-3 pt-3 d-flex justify-content-between align-items-center"><span class="fs-5">Grand Total</span><strong class="fs-3">{{ number_format((float) $x->grand_total, $decimals) }}</strong></div></div></section></div></div></div>
</div>
@endsection

@push('scripts')
<script>$(function(){window.erpAjaxForm({form:'.js-convert',redirect:true});});</script>
@endpush
