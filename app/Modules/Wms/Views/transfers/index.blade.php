@extends('Wms::layout')
@php($isIncoming = $direction === 'in')
@section('title', ($isIncoming ? 'รับโอนสินค้า' : 'โอนสินค้าออก').' | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><p class="eyebrow mb-2">WMS / TRANSFER</p><h1 class="h3 mb-2">{{ $isIncoming ? 'รับโอนสินค้าเข้า' : 'โอนสินค้าออก' }}</h1><p class="text-secondary mb-0">{{ $isIncoming ? 'ตรวจสอบและรับสินค้าจากคลังต้นทาง โดยรักษาต้นทุนเดิม' : 'สร้างรายการส่งสินค้าออกจากคลังปัจจุบันไปยังคลังปลายทาง' }}</p></div><div class="d-flex flex-wrap align-items-end gap-2">@include('Wms::partials.warehouse-selector') @if(!$isIncoming && auth()->user()->hasPermission('wms.transfers.create'))<a class="btn btn-app-primary" href="{{ route('wms.transfers.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบโอนสินค้าออก</a>@endif</div></div>
    @include('Wms::partials.document-filters', ['filterId' => 'transfer-filters', 'statusOptions' => $transferStatusLabels, 'branchOptions' => $branches])
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">{{ $isIncoming ? 'รายการรอรับโอนสินค้า' : 'รายการใบโอนสินค้าออก' }}</h2><div class="table-responsive"><table id="transfers-table" class="table table-hover align-middle w-100" data-url="{{ route($isIncoming ? 'wms.transfers.incoming.data' : 'wms.transfers.outgoing.data') }}"><thead><tr><th>เลขที่</th><th>วันที่</th>@if($isIncoming)<th>เอกสารต้นทาง</th>@endif<th>ต้นทาง</th><th>ปลายทาง</th><th>สถานะ</th><th>การรับเข้าปลายทาง</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    var table = $('#transfers-table'), filters = $('#transfer-filters'), escape = $.fn.dataTable.render.text(), statusClasses = @json($transferStatusClasses);
    var dataTable = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: {url: table.data('url'), data: function (data) { data.status = filters.find('.js-wms-filter-status').val(); data.date_from = filters.find('.js-wms-filter-from').val(); data.date_to = filters.find('.js-wms-filter-to').val(); data.source_branch_id = filters.find('.js-wms-filter-source-branch').val(); data.destination_branch_id = filters.find('.js-wms-filter-destination-branch').val(); }}, order: [[1, 'desc'], [0, 'desc']], buttons: [window.erpExcelButton(table)], columns: [
            {data:'document_number', name:'document_number', render:escape.display}, {data:'document_date', name:'document_date', render:escape.display}, @if($isIncoming){data:null, render:function (value, type, row) { if (type !== 'display') return row.document_number || '-'; return '<a class="link-primary" href="'+escape.display(row.detail_url)+'">'+escape.display(row.document_number || '-')+'</a><div class="small text-secondary">'+escape.display(row.source_label || '-')+'</div>'; }}, @endif {data:'source_label', name:'source_label', render:escape.display}, {data:'destination_label', name:'destination_label', render:escape.display},
            {data:'status_label', name:'status_label', render:function (value, type, row) { return type === 'display' ? '<span class="badge '+(statusClasses[row.status] || 'app-status-neutral')+'">'+escape.display(value || '-')+'</span>' : value; }},
            {data:'destination_receipt_search', name:'destination_receipt_search', render:function (value, type, row) { return type === 'display' ? '<div class="fw-semibold">'+escape.display(row.destination_receipt_status || '-')+'</div><div class="small text-secondary">'+escape.display(row.destination_receipt_summary || '-')+'</div>' : value; }},
            {data:null, orderable:false, searchable:false, className:'text-end', render:function (value, type, row) {
                if (type !== 'display') return '';
                var html = '<a title="ดูรายละเอียด" aria-label="ดูรายละเอียด" class="btn btn-sm btn-app-soft me-1" href="'+escape.display(row.detail_url)+'"><i class="bx bx-file-find" aria-hidden="true"></i></a>';
                if (row.can_delete) html += '<button title="ลบร่าง" aria-label="ลบร่าง" class="btn btn-sm btn-app-danger js-transfer-delete" data-url="'+escape.display(row.delete_url)+'" data-confirm-message="ลบร่างใบโอนสินค้าออกนี้หรือไม่? เอกสารร่างที่ยังไม่มีการเคลื่อนไหวจะถูกลบออกจากรายการ"><i class="bx bx-trash" aria-hidden="true"></i></button>';
                return html;
            }}
        ]
    }));
    filters.on('click', '.js-wms-apply-filter', function () { dataTable.ajax.reload(); });
    filters.on('click', '.js-wms-reset-filter', function () { filters.find('select,input').val(''); dataTable.ajax.reload(); });
    window.erpAjaxDelete({button: '.js-transfer-delete', reload: '#transfers-table', confirmButtonText: 'ลบร่าง', cancelButtonText: 'กลับ'});
});
</script>
@endpush
