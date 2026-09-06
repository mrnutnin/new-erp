@extends('Wms::layout')

@section('title', 'ยอดยกมาสินค้า')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / OPENING BALANCE</p>
            <h1 class="h3 mb-2">ยอดยกมาสินค้า</h1>
            <p class="text-secondary mb-0">เตรียมจำนวนและต้นทุนสินค้าเริ่มต้นก่อนรับ–จ่ายจริง</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-app-soft" href="{{ route('wms.opening-balances.template') }}"><i class="bx bx-download me-1" aria-hidden="true"></i>ดาวน์โหลด Template</a>
            <a class="btn btn-dark" href="{{ route('wms.opening-balances.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างยอดยกมา</a>
        </div>
    </div>

    @include('Wms::partials.document-filters', ['filterId' => 'opening-filters', 'statusOptions' => ['DRAFT' => 'ร่าง', 'POSTED' => 'ลงบัญชีแล้ว', 'VOIDED' => 'ยกเลิก']])

    <section class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="opening-balances-table" data-url="{{ route('wms.opening-balances.data') }}">
                    <thead><tr><th>วันที่ยอดยกมา</th><th>วิธีต้นทุน</th><th>จำนวนรายการ</th><th class="text-end">มูลค่ารวม</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const tableElement = $('#opening-balances-table');
    const filters = $('#opening-filters');
    const escape = $.fn.dataTable.render.text();
    const statusClasses = {DRAFT: 'app-status-neutral', POSTED: 'app-status-success', VOIDED: 'app-status-danger'};
    const table = tableElement.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        processing: true,
        serverSide: true,
        ajax: {
            url: tableElement.data('url'),
            data: function (data) {
                data.status = filters.find('.js-wms-filter-status').val();
                data.date_from = filters.find('.js-wms-filter-from').val();
                data.date_to = filters.find('.js-wms-filter-to').val();
            }
        },
        order: [[0, 'desc']],
        buttons: [window.erpExcelButton(tableElement)],
        columns: [
            {data: 'cutover_date', name: 'cutover_date', render: escape.display},
            {data: 'costing_method_label', name: 'costing_method', render: escape.display},
            {data: 'line_count', name: 'line_count', className: 'text-end', render: escape.display},
            {data: 'total_value', name: 'total_value', className: 'text-end', render: escape.display},
            {data: 'status_label', name: 'status', render: function (value, type, row) {
                if (type !== 'display') return value;
                return '<span class="badge ' + (statusClasses[row.status] || 'app-status-neutral') + '">' + escape.display(value || '-') + '</span>';
            }},
            {data: null, orderable: false, searchable: false, className: 'text-end text-nowrap', render: function (value, type, row) {
                if (type !== 'display') return '';
                return '<a class="btn btn-sm btn-app-soft" href="' + escape.display(row.show_url) + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>';
            }}
        ]
    }));
    filters.on('click', '.js-wms-apply-filter', function () { table.ajax.reload(); });
    filters.on('click', '.js-wms-reset-filter', function () { filters.find('select,input').val(''); table.ajax.reload(); });
});
</script>
@endpush
