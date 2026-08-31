@extends('Accounting::layout')
@section('title', $title.' | New ERP')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="mb-4">
        <p class="eyebrow mb-2">ACCOUNTING / TAX</p>
        <h1 class="h3 mb-2">{{ $title }}</h1>
        <p class="text-secondary mb-0">อ้างอิง WHT realization จากการรับ/จ่ายเงินจริง</p>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3 p-lg-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-4 col-lg-3">
                    <label class="form-label" for="wht-from">ตั้งแต่วันที่</label>
                    <input class="form-control" type="date" id="wht-from">
                </div>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label" for="wht-to">ถึงวันที่</label>
                    <input class="form-control" type="date" id="wht-to">
                </div>
                <div class="col-md-4 col-lg-auto">
                    <button type="button" class="btn btn-outline-secondary" id="wht-filter">
                        <i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรอง
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="withholding-table" data-url="{{ $direction === 'PAYABLE' ? route('accounting.reports.withholding-expense.data') : route('accounting.reports.withholding-received.data') }}"><thead><tr><th>วันที่รับ/จ่าย</th><th>เอกสารต้นทาง</th><th>เอกสารรับ/จ่าย</th><th>คู่ค้า</th><th>Tax Code</th><th class="text-end">ฐาน WHT</th><th class="text-end">ยอด WHT</th></tr></thead></table>
            </div>
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script>$(function(){var t=$('#withholding-table'),x=$.fn.dataTable.render.text(),dt=t.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:t.data('url'),data:function(d){d.direction=@json($direction);d.date_from=$('#wht-from').val();d.date_to=$('#wht-to').val()}},buttons:[window.erpExcelButton(t)],columns:[{data:'settlement_date',name:'wr.settlement_date',render:x.display},{data:'document_number',name:'oi.document_number',render:x.display},{data:'settlement_label',name:'s.document_number',render:x.display},{data:'party_label',name:'p.code',render:x.display},{data:'tax_label',name:'tc.code',render:x.display},{data:'tax_base',name:'wr.tax_base',className:'text-end',render:x.display},{data:'tax_amount',name:'wr.tax_amount',className:'text-end',render:x.display}]}));$('#wht-from,#wht-to').on('change',function(){dt.ajax.reload()});$('#wht-filter').on('click',function(){dt.ajax.reload()})});</script>
@endpush
