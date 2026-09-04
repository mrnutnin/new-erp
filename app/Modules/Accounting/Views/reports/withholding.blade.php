@extends('Accounting::layout')
@section('title', $title.' | New ERP')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="mb-4">
        <p class="eyebrow mb-2">ACCOUNTING / TAX</p>
        <h1 class="h3 mb-2">{{ $title }}</h1>
        <p class="text-secondary mb-0">อ้างอิง WHT realization จากการรับ/จ่ายเงินจริง</p>
    </div>

    <div class="card border-0 shadow-sm mb-4" id="withholding-filters">
        <div class="card-body p-3 p-lg-4">
            <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h6 mb-0">ตัวกรองรายงาน</h2><button type="button" class="btn btn-outline-secondary btn-sm" id="report-filter-reset"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label" for="wht-from">ตั้งแต่วันที่</label>
                    <input class="form-control" type="date" id="wht-from">
                </div>
                @if($direction === 'PAYABLE')<div class="col-12 col-md-3">
                    <label class="form-label" for="wht-form">แบบนำส่ง</label>
                    <select class="form-select" id="wht-form"><option value="PND53">ภ.ง.ด.53 · นิติบุคคล</option><option value="PND3">ภ.ง.ด.3 · บุคคลธรรมดา</option></select>
                </div>@endif
                <div class="col-12 col-md-3">
                    <label class="form-label" for="wht-to">ถึงวันที่</label>
                    <input class="form-control" type="date" id="wht-to">
                </div>
                <div class="col-12 col-md-3">
                    <button type="button" class="btn btn-outline-secondary" id="wht-filter">
                        <i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรอง
                    </button>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="report-branch">สาขา</label>
                    <select class="form-select" id="report-branch">
                        <option value="current" @selected(request('branch_scope', 'current') === 'current')>สาขาปัจจุบัน</option>
                        <option value="all" @selected(request('branch_scope') === 'all')>ทุกสาขาที่มีสิทธิ์</option>
                        @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} · {{ $branch->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="report-warehouse">คลัง</label>
                    <select class="form-select" id="report-warehouse">
                        <option value="">ทุกคลังที่มีสิทธิ์</option>
                        @foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach
                    </select>
                </div>
                @if($direction === 'PAYABLE')
                    <div class="col-12 col-md-3">
                        <a class="btn btn-primary" id="wht-export-csv" href="{{ route('accounting.reports.withholding-expense.export') }}">
                            <i class="bx bx-download me-1" aria-hidden="true"></i>ส่งออก CSV สำหรับนำส่ง
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="withholding-table" data-url="{{ $direction === 'PAYABLE' ? route('accounting.reports.withholding-expense.data') : route('accounting.reports.withholding-received.data') }}" data-export-url="{{ $direction === 'PAYABLE' ? route('accounting.reports.withholding-expense.export') : '' }}"><thead><tr><th>@if($direction === 'PAYABLE')<input type="checkbox" class="form-check-input" id="wht-select-all" checked aria-label="เลือกทุกรายการ"> @endifวันที่รับ/จ่าย</th><th>เอกสารต้นทาง</th><th>เอกสารรับ/จ่าย</th><th>คู่ค้า</th><th>Tax Code</th><th class="text-end">ฐาน WHT</th><th class="text-end">ยอด WHT</th>@if($direction === 'PAYABLE')<th class="text-end">50 ทวิ</th>@endif</tr></thead></table>
            </div>
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script>$(function(){var t=$('#withholding-table'),x=$.fn.dataTable.render.text(),dt=t.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:t.data('url'),data:function(d){d.direction=@json($direction);d.date_from=$('#wht-from').val();d.date_to=$('#wht-to').val();d.form_type=$('#wht-form').val()}},buttons:@json($direction === 'PAYABLE')?[window.erpExcelButton(t,function(){return{direction:'PAYABLE',form_type:$('#wht-form').val(),date_from:$('#wht-from').val(),date_to:$('#wht-to').val(),branch_scope:$('#report-branch').val(),warehouse_id:$('#report-warehouse').val()}})]:[],columns:[{data:'settlement_date',name:'wr.settlement_date',render:x.display},{data:'document_number',name:'oi.document_number',render:x.display},{data:'settlement_label',name:'s.document_number',render:x.display},{data:'party_label',name:'p.code',render:x.display},{data:'tax_label',name:'tc.code',render:x.display},{data:'tax_base',name:'wr.tax_base',className:'text-end',render:x.display},{data:'tax_amount',name:'wr.tax_amount',className:'text-end',render:x.display}@if($direction === 'PAYABLE'),{data:null,orderable:false,searchable:false,className:'text-end',render:function(v,type,row){return type==='display'?'<a class="btn btn-sm btn-outline-dark" target="_blank" rel="noopener" href="{{ route('accounting.reports.withholding-expense.index') }}/'+row.id+'/certificate">PDF</a>':'';}}@endif]}));$('#wht-from,#wht-to,#wht-form').on('change',function(){dt.ajax.reload()});$('#wht-filter').on('click',function(){dt.ajax.reload()})});</script>
<script>$(function(){var t=$('#withholding-table');t.on('preXhr.dt',function(e,s,d){d.branch_scope=$('#report-branch').val();d.warehouse_id=$('#report-warehouse').val()});$('#report-branch,#report-warehouse').on('change',function(){t.DataTable().ajax.reload()});$('#report-filter-reset').on('click',function(){$('#wht-from,#wht-to').val('');$('#wht-form').val('PND53');$('#report-branch').val('current');$('#report-warehouse').val('');t.DataTable().ajax.reload()})});</script>
@if($direction === 'PAYABLE')
<script>$(function(){function updateWhtExport(){var params=$.param({form_type:$('#wht-form').val(),date_from:$('#wht-from').val(),date_to:$('#wht-to').val(),branch_scope:$('#report-branch').val(),warehouse_id:$('#report-warehouse').val()});$('#wht-export-csv').attr('href','{{ route('accounting.reports.withholding-expense.export') }}?'+params)}$('#wht-form,#wht-from,#wht-to,#report-branch,#report-warehouse').on('change',updateWhtExport);updateWhtExport()});</script>
<script>$(function(){var selected={},excluded={},allSelected=true,table=$('#withholding-table').DataTable();function refreshExport(){var params={form_type:$('#wht-form').val(),date_from:$('#wht-from').val(),date_to:$('#wht-to').val(),branch_scope:$('#report-branch').val(),warehouse_id:$('#report-warehouse').val()};if(allSelected){var excludedIds=Object.keys(excluded);if(excludedIds.length){params.exclude_ids=excludedIds}}else{var ids=Object.keys(selected);params.ids=ids.length?ids:[0]}$('#wht-export-csv').attr('href','{{ route('accounting.reports.withholding-expense.export') }}?'+$.param(params))}function syncHeader(){var checked=allSelected&&Object.keys(excluded).length===0;$('#wht-select-all').prop('checked',checked)}table.on('draw',function(){table.rows({page:'current'}).every(function(){var data=this.data(),$cell=$(this.node()).find('td').first();if(!$cell.find('.wht-select').length){$cell.prepend('<input type="checkbox" class="form-check-input wht-select me-2" value="'+data.id+'">')}var checked=allSelected?!excluded[data.id]:!!selected[data.id];$cell.find('.wht-select').prop('checked',checked)});syncHeader()});$(document).on('change','.wht-select',function(){if(allSelected){if(this.checked){delete excluded[this.value]}else{excluded[this.value]=true}}else if(this.checked){selected[this.value]=true}else{delete selected[this.value]}syncHeader();refreshExport()});$('#wht-select-all').on('change',function(){allSelected=this.checked;selected={};excluded={};table.rows({page:'current'}).nodes().to$().find('.wht-select').prop('checked',this.checked);refreshExport()});$('#wht-form,#wht-from,#wht-to,#report-branch,#report-warehouse').on('change',function(){selected={};excluded={};allSelected=true;$('#wht-select-all').prop('checked',true);refreshExport()});refreshExport()});</script>
@endif
@endpush
