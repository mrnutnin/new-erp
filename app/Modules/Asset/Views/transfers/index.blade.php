@extends('Asset::layout')

@section('title', 'โอน/ย้ายสินทรัพย์ | New ERP')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div><p class="eyebrow mb-2">ASSET / TRANSFERS</p><h1 class="h3 mb-1">โอน/ย้ายสินทรัพย์</h1><p class="text-secondary mb-0">ย้ายสาขา สถานที่ หรือผู้ดูแล โดยไม่สร้าง Journal Entry</p></div>
        @if(auth()->user()->hasPermission('asset.transfers.create'))<a class="btn btn-dark" href="{{ route('asset.transfers.create') }}"><i class="bx bx-transfer me-1" aria-hidden="true"></i>สร้างใบโอน</a>@endif
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h6 mb-3">ตัวกรอง</h2><div class="row g-3">
        <div class="col-12 col-md-4 col-xl-3"><label class="form-label" for="transfer-filter-status">สถานะ</label><select class="form-select" id="transfer-filter-status"><option value="">ทุกสถานะ</option><option value="DRAFT">ร่าง</option><option value="SUBMITTED">รออนุมัติ</option><option value="APPROVED">พร้อมลงรายการ</option><option value="POSTED">ลงรายการแล้ว</option><option value="CANCELLED">ยกเลิก</option></select></div>
        <div class="col-12 col-md-4 col-xl-3"><label class="form-label" for="transfer-filter-date-from">วันที่ตั้งแต่</label><input class="form-control" id="transfer-filter-date-from" type="date"></div>
        <div class="col-12 col-md-4 col-xl-3"><label class="form-label" for="transfer-filter-date-to">ถึงวันที่</label><input class="form-control" id="transfer-filter-date-to" type="date"></div>
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="transfer-filter-destination">สาขาปลายทาง</label><select class="form-select" id="transfer-filter-destination"><option value="">ทุกสาขา</option></select></div><div class="col-12 col-xl-2 ms-auto"><button class="btn btn-outline-secondary w-100" id="transfer-filter-reset" type="button">ล้างตัวกรอง</button></div>
    </div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="table-responsive"><table class="table table-hover align-middle w-100" id="transfers-table" data-url="{{ route('asset.transfers.data') }}"><thead><tr><th>เลขที่เอกสาร</th><th>วันที่</th><th>จากสาขา</th><th>ไปสาขา</th><th>รายการ</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var $table = $('#transfers-table'), text = $.fn.dataTable.render.text(), labels = {DRAFT: 'ร่าง', SUBMITTED: 'รออนุมัติ', APPROVED: 'พร้อมลงรายการ', POSTED: 'ลงรายการแล้ว', CANCELLED: 'ยกเลิก'}, badges = {DRAFT: 'app-badge-soft', SUBMITTED: 'app-badge-info', APPROVED: 'app-badge-info', POSTED: 'app-badge-success', CANCELLED: 'app-status-danger'};
    $('#transfer-filter-destination').select2({width: '100%', allowClear: true, placeholder: 'ทุกสาขา', ajax: {url: @json(route('asset.transfers.options')), dataType: 'json', delay: 250, data: function (params) { return {type: 'branch', q: params.term || '', page: params.page || 1}; }, processResults: function (data) { return data; }, cache: true}});
    var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {ajax: {url: $table.data('url'), data: function (data) { data.status = $('#transfer-filter-status').val(); data.date_from = $('#transfer-filter-date-from').val(); data.date_to = $('#transfer-filter-date-to').val(); data.destination_branch_id = $('#transfer-filter-destination').val(); }}, order: [[1, 'desc']], buttons: [window.erpExcelButton($table)], columns: [
        {data: 'document_number', render: text.display}, {data: 'document_date_label', name: 'document_date', render: text.display}, {data: 'source_branch_label', render: text.display}, {data: 'destination_branch_label', render: text.display}, {data: 'lines_count'},
        {data: 'status', render: function (value, type) { return type === 'display' ? '<span class="badge ' + badges[value] + '">' + labels[value] + '</span>' : value; }},
        {data: 'show_url', orderable: false, searchable: false, className: 'text-end', render: function (value, type) { return type === 'display' ? '<a class="btn btn-sm btn-outline-dark" href="' + text.display(value) + '">ดู</a>' : value; }}
    ]}));
    $('#transfer-filter-status, #transfer-filter-date-from, #transfer-filter-date-to, #transfer-filter-destination').on('change', function () { table.ajax.reload(); }); $('#transfer-filter-reset').on('click', function () { $('#transfer-filter-status, #transfer-filter-date-from, #transfer-filter-date-to, #transfer-filter-destination').val(''); table.ajax.reload(); });
});
</script>
@endpush
