@extends('Wms::layout')
@php($productionMode = $productionMode ?? false)
@section('title', $productionMode ? 'เบิกวัตถุดิบผลิต | WMS' : 'ใบเบิกสินค้า | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / {{ $productionMode ? 'PRODUCTION MATERIAL ISSUE' : 'ISSUE' }}</p><h1 class="h3 mb-2">{{ $productionMode ? 'เบิกวัตถุดิบผลิต' : 'ใบเบิกสินค้า' }}</h1><p class="text-secondary mb-0">{{ $productionMode ? 'เบิกวัตถุดิบสำหรับการผลิตแบบ Manual โดยบังคับประเภทการเบิกเป็น Production' : 'สร้างและติดตามใบเบิกสินค้า ก่อนอนุมัติและลง Stock' }}</p></div>
        <a class="btn btn-app-primary" href="{{ $productionMode ? route('wms.production.material-issues.create') : route('wms.issues.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>{{ $productionMode ? 'สร้างใบเบิกวัตถุดิบ' : 'สร้างใบเบิกสินค้า' }}</a>
    </div>
    @include('Wms::partials.document-filters', ['filterId' => 'issue-filters', 'statusOptions' => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิก']] + ($productionMode ? [] : ['issueTypeOptions' => $issueTypeOptions]))
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">รายการใบเบิก</h2><div class="table-responsive"><table id="issue-table" class="table table-hover align-middle w-100"><thead><tr><th>ลำดับ</th><th>เลขที่เอกสาร</th><th>วันที่</th>@unless($productionMode)<th>ประเภทการเบิก</th>@endunless<th>รายการ</th><th class="text-end">จำนวน</th>@if($productionMode)<th>ใบรับผลิตเสร็จ</th>@else<th>ใบรับคืน</th>@endif<th>เหตุผล</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var tableElement = $('#issue-table'), filters = $('#issue-filters'), escape = $.fn.dataTable.render.text();
    var table = tableElement.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: {url: '{{ $productionMode ? route('wms.production.material-issues.data') : route('wms.issues.data') }}', data: function (data) { data.status = filters.find('.js-wms-filter-status').val(); data.issue_type = filters.find('.js-wms-filter-issue-type').val(); data.date_from = filters.find('.js-wms-filter-from').val(); data.date_to = filters.find('.js-wms-filter-to').val(); }},
        order: [[2, 'desc'], [1, 'desc']], buttons: [window.erpExcelButton(tableElement)],
        columns: [
            window.erpRowNumberColumn(),
            {data: 'document_number', name: 'document_number', render: escape.display},
            {data: 'business_date', name: 'document_date', render: escape.display},
            @unless($productionMode){data: 'issue_type_label', name: 'issue_type', render: escape.display},@endunless
            {data: 'line_count', name: 'id', render: function (value, type) { return type === 'display' ? escape.display(value + ' รายการ') : value; }},
            {data: 'quantity', name: 'id', className: 'text-end', render: escape.display},
            @if($productionMode){data: 'finished_receipt_label', name: 'id', render: escape.display},@else{data: 'return_document_label', name: 'id', render: escape.display},@endif
            {data: 'reason', name: 'reason', render: escape.display},
            {data: 'status_label', name: 'status', render: function (value, type, row) { if (type !== 'display') return value; var classes = {DRAFT: 'app-status-neutral', APPROVED: 'app-status-info', POSTED: 'app-status-success', VOID: 'app-status-danger'}; return '<span class="badge ' + (classes[row.status] || 'app-status-neutral') + '">' + escape.display(value) + '</span>'; }},
            {data: null, orderable: false, searchable: false, className: 'text-end text-nowrap', render: function (value, type, row) { if (type !== 'display') return ''; var html = '<a class="btn btn-sm btn-app-soft" href="' + escape.display(row.show_url) + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-file-find" aria-hidden="true"></i></a>'; if (row.can_edit) html += ' <a class="btn btn-sm btn-app-soft" href="' + escape.display(row.edit_url) + '" title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit" aria-hidden="true"></i></a>'; if (row.can_delete) html += ' <button class="btn btn-sm btn-app-danger js-issue-delete" type="button" data-url="{{ $productionMode ? url('/wms/production/material-issues') : url('/wms/issues') }}/' + row.id + '" data-confirm-message="ลบร่างจะลบเฉพาะใบเบิกที่ยังไม่เริ่ม workflow" title="ลบร่าง" aria-label="ลบร่าง"><i class="bx bx-trash" aria-hidden="true"></i></button>'; return html; }}
        ]
    }));
    filters.on('click', '.js-wms-apply-filter', function () { table.ajax.reload(); });
    filters.on('click', '.js-wms-reset-filter', function () { filters.find('select,input').val(''); table.ajax.reload(); });
    window.erpAjaxDelete({button: '.js-issue-delete', reload: '#issue-table', confirm: 'ลบร่างใบเบิกสินค้านี้หรือไม่?', confirmButtonText: 'ลบร่าง', cancelButtonText: 'กลับ'});
});
</script>
@endpush
