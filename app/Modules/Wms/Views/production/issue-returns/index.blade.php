@extends('Wms::layout')

@section('title', 'รับคืนวัตถุดิบผลิต | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / PRODUCTION ISSUE RETURN</p>
            <h1 class="h3 mb-2">รับคืนวัตถุดิบผลิต</h1>
            <p class="text-secondary mb-0">สร้างและติดตามการรับคืนวัตถุดิบจากใบเบิกผลิตที่ลง Stock แล้ว</p>
        </div>
        @if(auth()->user()->hasPermission('wms.issue-returns.create'))
            <a class="btn btn-app-primary" href="{{ route('wms.production.issue-returns.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบรับคืนวัตถุดิบ</a>
        @endif
    </div>

    @include('Wms::partials.document-filters', ['filterId' => 'production-return-filters', 'statusOptions' => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิกเอกสาร', 'REVERSED' => 'ยกเลิกเอกสารแล้ว']])

    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4">
        <div class="mb-3"><h2 class="h5 mb-1">รายการรับคืนวัตถุดิบ</h2><p class="text-secondary small mb-0">เปิดรายละเอียดเพื่ออนุมัติ ลง Stock หรือยกเลิกเอกสาร</p></div>
        <div class="table-responsive"><table id="production-return-table" class="table table-hover align-middle w-100"><thead><tr><th>เลขที่เอกสาร</th><th>วันที่</th><th>ใบเบิกต้นทาง</th><th>จำนวน</th><th>เหตุผล</th><th>สถานะ</th><th>จัดการ</th></tr></thead></table></div>
    </div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const tableElement = $('#production-return-table');
    const filters = $('#production-return-filters');
    const text = $.fn.dataTable.render.text();
    const statuses = {DRAFT:'app-status-neutral', APPROVED:'app-status-info', POSTED:'app-status-success', VOID:'app-status-danger', REVERSED:'app-status-warning'};
    const table = tableElement.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        processing: true, serverSide: true, order: [[1, 'desc']],
        ajax: {url: '{{ route('wms.production.issue-returns.data') }}', data: function (data) {
            data.status = filters.find('.js-wms-filter-status').val();
            data.date_from = filters.find('.js-wms-filter-from').val();
            data.date_to = filters.find('.js-wms-filter-to').val();
        }},
        buttons: [window.erpExcelButton(tableElement)],
        columns: [
            {data:'document_number', name:'document_number', render:text.display},
            {data:'business_date', name:'document_date', render:text.display},
            {data:'issue_number', name:'issue.document_number', render:text.display},
            {data:'quantity', orderable:false, className:'text-end', render:text.display},
            {data:'reason', name:'reason', render:text.display},
            {data:'status_label', name:'status', render:function(value, type, row) { return type === 'display' ? '<span class="badge '+(statuses[row.status] || 'app-status-neutral')+'">'+text.display(value)+'</span>' : value; }},
            {data:null, orderable:false, searchable:false, className:'text-end text-nowrap', render:function(value, type, row) {
                if (type !== 'display') return '';
                let html = '<a class="btn btn-sm btn-app-soft" href="'+row.show_url+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด '+text.display(row.document_number)+'"><i class="bx bx-file-find" aria-hidden="true"></i></a>';
                if (row.can_delete) html += ' <button type="button" class="btn btn-sm btn-app-danger js-production-return-delete" data-url="'+row.delete_url+'" title="ลบร่าง" aria-label="ลบร่าง '+text.display(row.document_number)+'"><i class="bx bx-trash" aria-hidden="true"></i></button>';
                return html;
            }}
        ]
    }));
    filters.on('click', '.js-wms-apply-filter', () => table.ajax.reload());
    filters.on('click', '.js-wms-reset-filter', function () { filters.find('select,input').val(''); table.ajax.reload(); });
    window.erpAjaxDelete({button:'.js-production-return-delete', reload:'#production-return-table', title:'ลบร่างใบรับคืนวัตถุดิบ?', text:'เอกสารร่างจะถูกลบและไม่สามารถกู้คืนได้', confirmButtonText:'ลบร่าง', cancelButtonText:'กลับ'});
});
</script>
@endpush
