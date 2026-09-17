@extends('Wms::layout')

@section('title', 'ใบรับคืนจากการเบิก | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / ISSUE RETURN</p>
            <h1 class="h3 mb-2">ใบรับคืนจากการเบิก</h1>
            <p class="text-secondary mb-0">สร้างและติดตามการรับสินค้าคืนจากใบเบิกที่ลง Stock แล้ว</p>
        </div>
        @if(auth()->user()->hasPermission('wms.issue-returns.create'))
            <a class="btn btn-app-primary" href="{{ route('wms.issue-returns.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบรับคืน</a>
        @endif
    </div>

    @include('Wms::partials.document-filters', [
        'filterId' => 'return-filters',
        'statusOptions' => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิกเอกสาร', 'REVERSED' => 'ยกเลิกเอกสารแล้ว'],
    ])

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                <div>
                    <h2 class="h5 mb-1">รายการใบรับคืน</h2>
                    <p class="text-secondary small mb-0">เปิดรายละเอียดเพื่ออนุมัติ ลง Stock หรือยกเลิกเอกสาร</p>
                </div>
            </div>
            <div class="table-responsive">
                <table id="return-table" class="table table-hover align-middle w-100">
                    <thead><tr><th>ลำดับ</th><th>เลขที่เอกสาร</th><th>วันที่</th><th>อ้างอิงใบเบิก</th><th>จำนวน</th><th>เหตุผล</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const tableElement = $('#return-table');
    const filters = $('#return-filters');
    const text = $.fn.dataTable.render.text();
    const statusClass = {
        DRAFT: 'app-status-neutral', APPROVED: 'app-status-info', POSTED: 'app-status-success',
        VOID: 'app-status-danger', REVERSED: 'app-status-warning'
    };

    const table = tableElement.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        processing: true,
        serverSide: true,
        order: [[2, 'desc']],
        ajax: {
            url: '{{ route('wms.issue-returns.data') }}',
            data: function (data) {
                data.status = filters.find('.js-wms-filter-status').val();
                data.date_from = filters.find('.js-wms-filter-from').val();
                data.date_to = filters.find('.js-wms-filter-to').val();
            }
        },
        buttons: [window.erpExcelButton(tableElement)],
        columns: [
            window.erpRowNumberColumn(),
            {data: 'document_number', name: 'document_number', render: text.display},
            {data: 'business_date', name: 'document_date', render: text.display},
            {data: 'issue_number', name: 'issue.document_number', render: text.display},
            {data: 'quantity', className: 'text-end', orderable: false, render: text.display},
            {data: 'reason', name: 'reason', render: text.display},
            {data: 'status_label', name: 'status', render: function (value, type, row) {
                if (type !== 'display') return value;
                return '<span class="badge ' + (statusClass[row.status] || 'app-status-neutral') + '">' + text.display(value) + '</span>';
            }},
            {data: null, orderable: false, searchable: false, className: 'text-end text-nowrap', render: function (value, type, row) {
                if (type !== 'display') return '';
                let html = '<a class="btn btn-sm btn-app-soft" href="' + row.show_url + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด ' + text.display(row.document_number) + '"><i class="bx bx-file-find" aria-hidden="true"></i></a>';
                if (row.can_delete) {
                    html += ' <button type="button" class="btn btn-sm btn-app-danger js-return-delete" data-url="{{ url('/wms/issue-returns') }}/' + row.id + '" data-name="' + text.display(row.document_number) + '" title="ลบร่าง" aria-label="ลบร่าง ' + text.display(row.document_number) + '"><i class="bx bx-trash" aria-hidden="true"></i></button>';
                }
                return html;
            }}
        ]
    }));

    filters.on('click', '.js-wms-apply-filter', function () { table.ajax.reload(); });
    filters.on('click', '.js-wms-reset-filter', function () {
        filters.find('select,input').val('');
        table.ajax.reload();
    });

    window.erpAjaxDelete({
        button: '.js-return-delete',
        reload: '#return-table',
        title: 'ลบร่างใบรับคืน?',
        text: 'เอกสารร่างจะถูกลบและไม่สามารถกู้คืนได้',
        confirmButtonText: 'ลบร่าง',
        cancelButtonText: 'กลับ'
    });
});
</script>
@endpush
