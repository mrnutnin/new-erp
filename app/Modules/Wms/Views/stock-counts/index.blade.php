@extends('Wms::layout')
@section('title', 'ตรวจนับสินค้า | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / STOCK COUNT</p>
            <h1 class="h3 mb-2">ตรวจนับสินค้า</h1>
            <p class="text-secondary mb-0">บันทึกยอดตรวจนับเทียบกับยอดในระบบ ดูผลต่าง และเก็บประวัติการตรวจนับ</p>
        </div>
        <div class="d-flex flex-wrap align-items-end gap-2">
            @include('Wms::partials.warehouse-selector')
            <a class="btn btn-dark" href="{{ route('wms.stock-counts.create') }}">
                <i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างเอกสารตรวจนับ
            </a>
        </div>
    </div>

    @include('Wms::partials.document-filters', ['filterId' => 'count-filters', 'statusOptions' => [
        'DRAFT' => 'ร่าง', 'COUNTED' => 'ตรวจนับแล้ว', 'APPROVED' => 'อนุมัติแล้ว',
        'POSTED' => 'ปิดผลตรวจนับ', 'VOID' => 'ยกเลิก', 'REVERSED' => 'กลับรายการแล้ว',
    ]])

    <section class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <div class="table-responsive">
                <table id="counts" class="table table-hover align-middle w-100" data-url="{{ route('wms.stock-counts.data') }}">
                    <thead>
                        <tr>
                            <th>เลขที่เอกสาร</th>
                            <th>วันที่</th>
                            <th class="text-end">รายการ</th>
                            <th class="text-end">ผลต่างรวม</th>
                            <th>สถานะ</th>
                            <th class="text-end">จัดการ</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    const tableElement = $('#counts');
    const filters = $('#count-filters');
    const escape = $.fn.dataTable.render.text();
    const statusClasses = {
        DRAFT: 'app-status-neutral',
        COUNTED: 'app-status-info',
        APPROVED: 'app-status-success',
        POSTED: 'app-status-success',
        VOID: 'app-status-danger',
        REVERSED: 'app-status-warning'
    };
    const table = tableElement.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: {
            url: tableElement.data('url'),
            data: function (data) {
                data.status = filters.find('.js-wms-filter-status').val();
                data.date_from = filters.find('.js-wms-filter-from').val();
                data.date_to = filters.find('.js-wms-filter-to').val();
            }
        },
        order: [[1, 'desc']],
        buttons: [window.erpExcelButton(tableElement)],
        columns: [
            { data: 'document_number', render: escape.display },
            { data: 'date_label', render: escape.display },
            { data: 'line_count', className: 'text-end', render: function (value) { return escape.display(value); } },
            { data: 'variance_label', className: 'text-end', render: function (value) { return escape.display(value); } },
            {
                data: 'status_label',
                render: function (value, type, row) {
                    if (type !== 'display') return value;
                    return '<span class="badge ' + (statusClasses[row.status] || 'app-status-neutral') + '">' + escape.display(value) + '</span>';
                }
            },
            {
                data: null,
                orderable: false,
                searchable: false,
                className: 'text-end text-nowrap',
                render: function (value, type, row) {
                    if (type !== 'display') return '';
                    let html = '<a class="btn btn-sm btn-app-soft me-1" href="' + escape.display(row.show_url) + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>';
                    if (row.can_edit) html += '<a class="btn btn-sm btn-app-soft me-1" href="' + escape.display(row.show_url + '/edit') + '" title="แก้ไขร่าง" aria-label="แก้ไขร่าง"><i class="bx bx-edit" aria-hidden="true"></i></a>';
                    if (row.can_approve) html += '<button class="btn btn-sm btn-app-soft me-1 js-count-approve" data-url="' + escape.display(row.show_url + '/approve') + '" title="อนุมัติ" aria-label="อนุมัติ"><i class="bx bx-check" aria-hidden="true"></i></button>';
                    if (row.can_delete) html += '<button class="btn btn-sm btn-outline-danger js-count-delete" data-url="' + escape.display(row.show_url) + '" title="ลบร่าง" aria-label="ลบร่าง"><i class="bx bx-trash" aria-hidden="true"></i></button>';
                    return html;
                }
            }
        ]
    }));
    filters.on('click', '.js-wms-apply-filter,.js-wms-reset-filter', function () {
        if ($(this).hasClass('js-wms-reset-filter')) filters.find('select,input').val('');
        table.ajax.reload();
    });
    $(document).on('click', '.js-count-approve', function () {
        const button = $(this);
        Swal.fire({ icon: 'warning', text: 'ยืนยันการอนุมัติเอกสารตรวจนับ?', showCancelButton: true, confirmButtonText: 'อนุมัติ', cancelButtonText: 'ยกเลิก' })
            .then(function (result) {
                if (!result.isConfirmed) return;
                $.post(button.data('url'), { _token: $('meta[name=csrf-token]').attr('content') })
                    .done(function (response) { Swal.fire({ icon: 'success', text: response.msg, timer: 1200, showConfirmButton: false }); table.ajax.reload(null, false); })
                    .fail(function (error) { Swal.fire({ icon: 'error', text: error.responseJSON?.message || 'อนุมัติไม่สำเร็จ' }); });
            });
    });
    $(document).on('click', '.js-count-delete', function () {
        const button = $(this);
        Swal.fire({ icon: 'warning', text: 'ยืนยันการลบร่างเอกสาร?', showCancelButton: true, confirmButtonText: 'ลบร่าง', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc3545' })
            .then(function (result) {
                if (!result.isConfirmed) return;
                $.ajax({ url: button.data('url'), method: 'DELETE', data: { _token: $('meta[name=csrf-token]').attr('content') } })
                    .done(function (response) { Swal.fire({ icon: 'success', text: response.msg, timer: 1200, showConfirmButton: false }); table.ajax.reload(null, false); })
                    .fail(function (error) { Swal.fire({ icon: 'error', text: error.responseJSON?.message || 'ลบเอกสารไม่สำเร็จ' }); });
            });
    });
});
</script>
@endpush
