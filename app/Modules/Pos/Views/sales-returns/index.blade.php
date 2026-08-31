@extends('Pos::layout')

@section('title', 'ใบรับคืน / ใบลดหนี้ | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    @include('Pos::partials.sales-list-header', [
        'eyebrow' => 'SALES / RETURN',
        'title' => 'ใบรับคืน / ใบลดหนี้',
        'description' => 'รับคืนสินค้าจาก HS/IV ที่ลงบัญชีแล้ว และกลับ Stock กับ GL ในขั้นตอน Post',
        'actionUrl' => auth()->user()->hasPermission('pos.sales-returns.create') ? route('pos.sales-returns.create') : null,
        'actionLabel' => 'สร้างใบรับคืน',
        'actionClass' => 'btn-dark',
        'actionIcon' => 'bx-plus',
    ])

    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-3 p-lg-4"><div class="row g-3 align-items-end">
        <div class="col-md-3"><label for="return-date-from" class="form-label">วันที่เริ่ม</label><input id="return-date-from" type="date" class="form-control"></div>
        <div class="col-md-3"><label for="return-date-to" class="form-label">ถึงวันที่</label><input id="return-date-to" type="date" class="form-control"></div>
        <div class="col-md-3"><label for="return-status" class="form-label">สถานะ</label><select id="return-status" class="form-select"><option value="ALL">ทั้งหมด</option><option value="DRAFT">ร่าง</option><option value="POSTED">ลงบัญชีแล้ว</option><option value="VOID">ยกเลิก</option></select></div>
    </div><button id="return-filter" class="btn btn-outline-secondary mt-3" type="button"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรอง</button></div></div>

    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="mb-3"><h2 class="h6 mb-0">รายการใบรับคืน / ใบลดหนี้</h2><small class="text-secondary">ค้นหาด้วยเลขที่เอกสาร เลขที่ HS/IV หรือลูกค้า</small></div><div class="table-responsive">
        <table id="sales-returns-table" class="table table-hover align-middle w-100 mb-0" data-url="{{ route('pos.sales-returns.data') }}"><thead class="table-light"><tr><th>เลขที่</th><th>วันที่เอกสาร</th><th>HS/IV อ้างอิง</th><th>ลูกค้า</th><th>เหตุผล</th><th class="text-end">ยอดรวม</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table>
    </div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#sales-returns-table');
    const esc = value => $('<div>').text(value ?? '').html();
    const badge = status => ({ DRAFT: 'app-badge-soft', POSTED: 'app-badge-success', VOID: 'text-bg-danger' }[status] || 'app-badge-soft');
    const actions = row => `<div class="d-inline-flex flex-wrap justify-content-end gap-1"><a class="btn btn-sm btn-app-soft" href="${esc(row.show_url)}" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>${row.pdf_url ? `<a class="btn btn-sm btn-app-soft" href="${esc(row.pdf_url)}" target="_blank" rel="noopener" title="พิมพ์ PDF" aria-label="พิมพ์ PDF"><i class="bx bx-printer" aria-hidden="true"></i></a>` : ''}${row.post_url ? `<button class="btn btn-sm btn-primary js-return-post" type="button" data-url="${esc(row.post_url)}" title="Post เอกสาร" aria-label="Post เอกสาร"><i class="bx bx-check-circle" aria-hidden="true"></i></button>` : ''}${row.cancel_url ? `<button class="btn btn-sm btn-outline-danger js-return-cancel" type="button" data-url="${esc(row.cancel_url)}" title="ยกเลิกเอกสาร" aria-label="ยกเลิกเอกสาร"><i class="bx bx-x-circle" aria-hidden="true"></i></button>` : ''}</div>`;
    const dt = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, { ajax: { url: table.data('url'), data: d => { d.date_from = $('#return-date-from').val(); d.date_to = $('#return-date-to').val(); d.status = $('#return-status').val(); } }, order: [[1, 'desc']], language: { search: 'ค้นหา:' }, buttons: [window.erpExcelButton(table)], columns: [
        { data: 'document_number', render: (value, _, row) => `<a href="${esc(row.show_url)}">${esc(value)}</a>` }, { data: 'document_date_label', name: 'document_date', render: esc }, { data: 'source_label', render: esc }, { data: 'party_name', render: esc }, { data: 'reason', render: esc }, { data: 'total_amount', className: 'text-end fw-semibold', render: $.fn.dataTable.render.number(',', '.', 2) }, { data: 'status_label', render: (value, _, row) => `<span class="badge ${badge(row.status)}">${esc(value)}</span>` }, { data: null, orderable: false, searchable: false, className: 'text-end', render: (_, __, row) => actions(row) },
    ] }));
    $('#return-filter').on('click', () => dt.ajax.reload());
    table.on('click', '.js-return-post', function () { const button = $(this); Swal.fire({ icon: 'warning', title: 'Post ใบรับคืน/ลดหนี้?', input: 'date', inputLabel: 'วันที่ Post', inputValue: @json(today()->format('Y-m-d')), showCancelButton: true, confirmButtonText: 'ยืนยัน Post', cancelButtonText: 'กลับ', preConfirm: value => { if (!value) Swal.showValidationMessage('กรุณาระบุวันที่ Post'); return value; } }).then(result => { if (!result.isConfirmed) return; button.prop('disabled', true); $.post(button.data('url'), { _token: $('meta[name="csrf-token"]').attr('content'), posting_date: result.value }).done(() => dt.ajax.reload()).fail(xhr => Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถ Post ใบรับคืนได้' })).always(() => button.prop('disabled', false)); }); });
    table.on('click', '.js-return-cancel', function () { const button = $(this); Swal.fire({ icon: 'warning', title: 'ยกเลิกใบรับคืน/ลดหนี้?', input: 'textarea', inputLabel: 'เหตุผล (อย่างน้อย 10 ตัวอักษร)', showCancelButton: true, confirmButtonText: 'ยืนยันยกเลิก', cancelButtonText: 'กลับ', preConfirm: value => { if (!value || value.trim().length < 10) Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร'); return value; } }).then(result => { if (!result.isConfirmed) return; button.prop('disabled', true); $.post(button.data('url'), { _token: $('meta[name="csrf-token"]').attr('content'), reason: result.value }).done(() => dt.ajax.reload()).fail(xhr => Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถยกเลิกเอกสารได้' })).always(() => button.prop('disabled', false)); }); });
});
</script>
@endpush
