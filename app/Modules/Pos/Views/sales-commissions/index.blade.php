@extends('Pos::layout')

@section('title', 'คอมมิชชั่นขาย | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    @include('Pos::partials.sales-list-header', ['eyebrow' => 'SALES / COMMISSIONS', 'title' => 'คอมมิชชั่นขาย', 'description' => 'ตรวจสอบสิทธิ์ อนุมัติ และติดตามสถานะคอมมิชชั่นจากเอกสารขายที่ Post แล้ว'])

    @if(auth()->user()->hasPermission('pos.sales-commissions.pay'))<div class="d-flex justify-content-end mb-3"><a class="btn btn-dark" href="{{ route('pos.sales-commission-payment-batches.create') }}"><i class="bx bx-plus me-1"></i>สร้างชุดจ่าย</a></div>@endif
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-3 p-lg-4"><div class="row g-3 align-items-end">
        <div class="col-12 col-md-4 col-lg-3"><label class="form-label" for="commission-from">วันที่เริ่ม</label><input class="form-control" id="commission-from" type="date" value="{{ now()->startOfMonth()->format('Y-m-d') }}"></div>
        <div class="col-12 col-md-4 col-lg-3"><label class="form-label" for="commission-to">ถึงวันที่</label><input class="form-control" id="commission-to" type="date" value="{{ now()->endOfMonth()->format('Y-m-d') }}"></div>
        <div class="col-12 col-md-4 col-lg-3"><label class="form-label" for="commission-recipient">ผู้รับคอมมิชชั่น</label><select class="form-select" id="commission-recipient" data-url="{{ route('pos.sales-commissions.recipient-options') }}"><option value="">ทั้งหมด</option></select></div>
        <div class="col-12 col-md-4 col-lg-2"><label class="form-label" for="commission-status">สถานะ</label><select class="form-select" id="commission-status"><option value="">ทั้งหมด</option><option value="PENDING">รออนุมัติ</option><option value="APPROVED">อนุมัติแล้ว</option><option value="REJECTED">ไม่อนุมัติ</option><option value="PAID">จ่ายแล้ว</option><option value="REVERSED">กลับรายการแล้ว</option></select></div>
        <div class="col-12 col-md-4 col-lg-1"><button class="btn btn-outline-secondary w-100" id="commission-filter" type="button"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรอง</button></div>
    </div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="mb-3"><h2 class="h6 mb-0">รายการคอมมิชชั่น</h2></div><div class="table-responsive"><table id="sales-commissions-table" class="table table-hover align-middle w-100 mb-0" data-url="{{ route('pos.sales-commissions.data') }}"><thead class="table-light"><tr><th>วันที่คำนวณ</th><th>ผู้รับคอมมิชชั่น</th><th>แผนคอมมิชชั่น</th><th>วิธีคำนวณ</th><th>เอกสารขาย</th><th class="text-end">ฐานคำนวณ</th><th class="text-end">อัตรา</th><th class="text-end">คอมมิชชั่น</th><th>สถานะรายการ</th><th>สถานะการจ่าย</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
    <div class="card border-0 shadow-sm mt-3"><div class="card-body p-3 p-lg-4"><h2 class="h6 mb-3">ชุดจ่ายคอมมิชชั่น</h2><div class="table-responsive"><table id="commission-payment-batches-table" class="table table-hover align-middle w-100 mb-0" data-url="{{ route('pos.sales-commission-payment-batches.data') }}"><thead class="table-light"><tr><th>เลขที่</th><th>ช่วงวันที่</th><th class="text-end">จำนวนรายการ</th><th>สถานะใบขอจ่าย</th><th>ผลการจ่าย</th><th class="text-end">ยอดรวม</th><th>สถานะชุด</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#sales-commissions-table'), esc = $.fn.dataTable.render.text(), money = $.fn.dataTable.render.number(',', '.', 2), rate = $.fn.dataTable.render.number(',', '.', 4);
    const badge = status => ({ PENDING: 'app-badge-soft', APPROVED: 'app-badge-info', REJECTED: 'text-bg-danger', PAID: 'app-badge-success', REVERSED: 'text-bg-danger' }[status] || 'app-badge-soft');
    const dt = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: { url: table.data('url'), data: d => { d.date_from = $('#commission-from').val(); d.date_to = $('#commission-to').val(); d.recipient_user_id = $('#commission-recipient').val(); d.status = $('#commission-status').val(); } },
        order: [[0, 'desc']], buttons: [window.erpExcelButton(table)],
        columns: [
            { data: 'calculated_at_label', name: 'calculated_at', render: esc.display }, { data: 'recipient_label', render: esc.display }, { data: 'plan_label', render: esc.display }, { data: 'basis_label', render: esc.display },
            { data: 'sale_label', render: (d, _, r) => r.sale_url ? '<a href="' + esc.display(r.sale_url) + '">' + esc.display(d) + '</a>' : esc.display(d) },
            { data: 'base_amount', className: 'text-end', render: money }, { data: 'rate_percent', className: 'text-end', render: d => rate.display(d) + '%' }, { data: 'commission_amount', className: 'text-end fw-semibold', render: money },
            { data: 'status_label', render: (d, _, r) => '<span class="badge ' + badge(r.status) + '">' + esc.display(d) + '</span>' },
            { data: 'payment_progress', orderable: false, render: value => '<span class="badge ' + esc.display(value.class) + '">' + esc.display(value.label) + '</span>' },
            { data: null, orderable: false, searchable: false, className: 'text-end text-nowrap', render: (_, __, r) => '<div class="d-inline-flex gap-1">' + (r.approve_url ? '<button class="btn btn-sm btn-primary js-commission-approve" type="button" data-url="' + esc.display(r.approve_url) + '" title="อนุมัติ"><i class="bx bx-check-circle" aria-hidden="true"></i><span class="visually-hidden">อนุมัติ</span></button><button class="btn btn-sm btn-outline-danger js-commission-reject" type="button" data-url="' + esc.display(r.reject_url) + '" title="ไม่อนุมัติ"><i class="bx bx-x-circle" aria-hidden="true"></i><span class="visually-hidden">ไม่อนุมัติ</span></button>' : '') + (r.edit_status_url ? '<button class="btn btn-sm btn-outline-secondary js-commission-edit-status" type="button" data-url="' + esc.display(r.edit_status_url) + '" title="แก้ไขสถานะ"><i class="bx bx-edit-alt" aria-hidden="true"></i><span class="visually-hidden">แก้ไขสถานะ</span></button>' : '') + '<button class="btn btn-sm btn-app-soft js-commission-history" type="button" data-url="' + esc.display(r.history_url) + '" title="ประวัติ"><i class="bx bx-history" aria-hidden="true"></i><span class="visually-hidden">ประวัติ</span></button></div>' }
        ]
    }));
    const batchTable = $('#commission-payment-batches-table'), batchEsc = $.fn.dataTable.render.text();
    batchTable.DataTable($.extend(true, {}, window.erpDataTableDefaults, { ajax: batchTable.data('url'), order: [[0, 'desc']], columns: [
        { data: 'document_number', render: batchEsc.display }, { data: 'period_label', render: batchEsc.display }, { data: 'lines_count', className: 'text-end' },
        { data: 'request_summary', orderable: false, render: value => '<span class="badge '+batchEsc.display(value.class)+'">'+batchEsc.display(value.label)+'</span>' }, { data: 'payment_summary', orderable: false, render: value => '<span class="badge '+batchEsc.display(value.class)+'">'+batchEsc.display(value.label)+'</span>' }, { data: 'total_amount', className: 'text-end fw-semibold', render: money },
        { data: 'status_label', render: (d, _, r) => '<span class="badge '+(r.status === 'VERIFIED' ? 'app-badge-success' : (r.status === 'SUBMITTED' ? 'app-badge-info' : (r.status === 'CANCELLED' ? 'app-status-danger' : 'app-badge-soft')))+'">'+batchEsc.display(d)+'</span>' }, { data: null, className: 'text-end', orderable: false, searchable: false, render: (_, __, r) => '<div class="d-inline-flex gap-1"><a class="btn btn-sm btn-app-soft" href="'+batchEsc.display(r.show_url)+'">ดูรายละเอียด</a><button class="btn btn-sm btn-app-soft js-payment-batch-history" type="button" data-url="'+batchEsc.display(r.history_url)+'" title="Audit Trail"><i class="bx bx-history" aria-hidden="true"></i><span class="visually-hidden">Audit Trail</span></button></div>' }
    ] }));
    batchTable.on('click', '.js-payment-batch-history', function () {
        const button = $(this); button.prop('disabled', true);
        $.get(button.data('url')).done(response => {
            const rows = response.history.length ? response.history.map(row => '<tr><td>' + batchEsc.display(row.at) + '</td><td>' + batchEsc.display(row.action) + '</td><td>' + batchEsc.display(row.actor) + '</td><td>' + (row.reason ? batchEsc.display(row.reason) : '—') + '</td></tr>').join('') : '<tr><td colspan="4" class="text-center text-secondary py-4">ยังไม่มีประวัติ</td></tr>';
            Swal.fire({ title: 'Audit Trail: ' + batchEsc.display(response.document_number), width: 900, html: '<div class="table-responsive text-start"><table class="table table-sm align-middle mb-0"><thead><tr><th>วันเวลา</th><th>เหตุการณ์</th><th>ผู้ดำเนินการ</th><th>เหตุผล</th></tr></thead><tbody>' + rows + '</tbody></table></div>', confirmButtonText: 'ปิด' });
        }).fail(xhr => Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถโหลดประวัติได้' })).always(() => button.prop('disabled', false));
    });
    $('#commission-recipient').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, ajax: { url: $('#commission-recipient').data('url'), dataType: 'json', delay: 250, data: p => ({ q: p.term || '' }), processResults: d => d } });
    $('#commission-filter').on('click', () => dt.ajax.reload());
    table.on('click', '.js-commission-approve', function () {
        const button = $(this); if (button.data('submitting')) return;
        Swal.fire({ icon: 'question', text: 'ยืนยันอนุมัติคอมมิชชั่นรายการนี้?', showCancelButton: true, confirmButtonText: 'อนุมัติ', cancelButtonText: 'ย้อนกลับ' }).then(result => {
            if (!result.isConfirmed) return;
            button.data('submitting', true).prop('disabled', true);
            $.ajax({ url: button.data('url'), method: 'PUT', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '' } })
                .done(response => Swal.fire({ icon: 'success', text: response.msg }).then(() => dt.ajax.reload(null, false)))
                .fail(xhr => Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถอนุมัติคอมมิชชั่นได้' }))
                .always(() => button.data('submitting', false).prop('disabled', false));
        });
    });
    table.on('click', '.js-commission-reject', function () {
        const button = $(this); if (button.data('submitting')) return;
        Swal.fire({ icon: 'warning', title: 'ไม่อนุมัติคอมมิชชั่น?', input: 'textarea', inputLabel: 'เหตุผล <span class="text-danger">*</span>', inputValidator: value => !value?.trim() ? 'กรุณาระบุเหตุผล' : undefined, showCancelButton: true, confirmButtonText: 'ไม่อนุมัติ', cancelButtonText: 'ย้อนกลับ' }).then(result => {
            if (!result.isConfirmed) return;
            button.data('submitting', true).prop('disabled', true);
            $.ajax({ url: button.data('url'), method: 'PUT', data: { reason: result.value.trim() }, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '' } })
                .done(response => Swal.fire({ icon: 'success', text: response.msg }).then(() => dt.ajax.reload(null, false)))
                .fail(xhr => Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถไม่อนุมัติคอมมิชชั่นได้' }))
                .always(() => button.data('submitting', false).prop('disabled', false));
        });
    });
    table.on('click', '.js-commission-history', function () {
        const button = $(this); button.prop('disabled', true);
        $.get(button.data('url')).done(response => {
            const rows = response.history.length ? response.history.map(row => '<tr><td>' + esc.display(row.at) + '</td><td>' + esc.display(row.action) + '</td><td>' + esc.display(row.actor) + '</td><td>' + (row.reason ? esc.display(row.reason) : '—') + '</td></tr>').join('') : '<tr><td colspan="4" class="text-center text-secondary py-4">ยังไม่มีประวัติ</td></tr>';
            Swal.fire({ title: 'Audit Trail: ' + esc.display(response.record_label), width: 900, html: '<div class="table-responsive text-start"><table class="table table-sm align-middle mb-0"><thead><tr><th>วันเวลา</th><th>เหตุการณ์</th><th>ผู้ดำเนินการ</th><th>เหตุผล</th></tr></thead><tbody>' + rows + '</tbody></table></div>', confirmButtonText: 'ปิด' });
        }).fail(xhr => Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถโหลดประวัติได้' })).always(() => button.prop('disabled', false));
    });
    table.on('click', '.js-commission-edit-status', function () {
        const button = $(this); if (button.data('submitting')) return;
        Swal.fire({ title: 'แก้ไขสถานะคอมมิชชั่น', input: 'select', inputOptions: { PENDING: 'รออนุมัติ', REJECTED: 'ไม่อนุมัติ' }, inputPlaceholder: 'เลือกสถานะ', showCancelButton: true, confirmButtonText: 'ถัดไป', cancelButtonText: 'ย้อนกลับ' }).then(choice => {
            if (!choice.isConfirmed) return;
            const submit = reason => { button.data('submitting', true).prop('disabled', true); $.ajax({ url: button.data('url'), method: 'PUT', data: { status: choice.value, reason: reason || '' }, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '' } }).done(response => Swal.fire({ icon: 'success', text: response.msg }).then(() => dt.ajax.reload(null, false))).fail(xhr => Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถแก้ไขสถานะได้' })).always(() => button.data('submitting', false).prop('disabled', false)); };
            if (choice.value === 'REJECTED') Swal.fire({ title: 'เหตุผลที่ไม่อนุมัติ', input: 'textarea', inputValidator: value => !value?.trim() ? 'กรุณาระบุเหตุผล' : undefined, showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ย้อนกลับ' }).then(reason => { if (reason.isConfirmed) submit(reason.value.trim()); }); else submit();
        });
    });
});
</script>
@endpush
