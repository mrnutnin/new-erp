@extends('Pos::layout')

@section('title', 'ใบรับเงินล่วงหน้า | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    @include('Pos::partials.sales-list-header', [
        'eyebrow' => 'POS / ADVANCE DEPOSIT',
        'title' => 'ใบรับเงินล่วงหน้า',
        'description' => 'รับเงินจากลูกค้าล่วงหน้า ก่อนนำไปใช้กับการขายสด',
        'actionUrl' => auth()->user()?->hasPermission('pos.advance-deposits.create') ? route('pos.advance-deposits.create') : null,
        'actionLabel' => 'สร้างใบรับเงินล่วงหน้า',
        'actionClass' => 'btn-app-primary',
    ])

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3 p-lg-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรองใบรับเงินล่วงหน้า</h2><p class="text-secondary mb-0 small">กรองก่อนค้นหาจากตาราง</p></div><button class="btn btn-sm btn-app-soft" id="ai-reset" type="button"><i class="bx bx-reset me-1"></i>ล้างตัวกรอง</button></div>
            <div class="row g-3 align-items-end">
                <div class="col-md-3"><label class="form-label" for="ai-from">วันที่เริ่ม</label><input class="form-control" id="ai-from" type="date"></div>
                <div class="col-md-3"><label class="form-label" for="ai-to">ถึงวันที่</label><input class="form-control" id="ai-to" type="date"></div>
                <div class="col-md-3"><label class="form-label" for="ai-party">ลูกค้า</label><select class="form-select" id="ai-party"></select></div>
                <div class="col-md-3"><label class="form-label" for="ai-status">สถานะ</label><select class="form-select" id="ai-status"><option value="">ทั้งหมด</option><option value="DRAFT">ร่าง</option><option value="POSTED">ลงบัญชีแล้ว</option><option value="PARTIAL">ใช้บางส่วน</option><option value="APPLIED">ใช้ครบแล้ว</option><option value="VOID">ยกเลิก</option></select></div>
            </div>
            <button class="btn btn-app-primary mt-3" id="ai-filter" type="button"><i class="bx bx-filter-alt me-1"></i>ใช้ตัวกรอง</button>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <div class="mb-3">
                <h2 class="h5 mb-1">รายการใบรับเงินล่วงหน้า</h2><p class="text-secondary mb-0 small">เปิดรายละเอียดเพื่อตรวจสอบยอดคงเหลือ การใช้กับ HS หรือยกเลิกเอกสาร</p>
            </div>
            <div class="table-responsive">
                <table id="advance-deposits-table" class="table table-hover align-middle w-100 mb-0" data-url="{{ route('pos.advance-deposits.data') }}">
                    <thead class="table-light"><tr><th>ลำดับ</th><th>เลขที่เอกสาร</th><th>วันที่</th><th>ลูกค้า</th><th>VAT</th><th class="text-end">ยอดรับ</th><th class="text-end">ใช้แล้ว</th><th class="text-end">คงเหลือ</th><th>ใช้กับ HS</th><th>อ้างอิง GL</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#advance-deposits-table');
    const esc = $.fn.dataTable.render.text();
    const money = $.fn.dataTable.render.number(',', '.', 2);
    const statusBadge = status => ({ DRAFT: 'app-badge-soft', POSTED: 'app-badge-success', PARTIAL: 'app-badge-info', APPLIED: 'app-badge-success', VOID: 'text-bg-danger' }[status] || 'app-badge-soft');
    const dt = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: { url: table.data('url'), data: d => { d.date_from = $('#ai-from').val(); d.date_to = $('#ai-to').val(); d.status = $('#ai-status').val(); d.party_id = $('#ai-party').val(); } },
        order: [[2, 'desc']],
        language: { search: 'ค้นหา:' },
        buttons: [window.erpExcelButton(table)],
        columns: [window.erpRowNumberColumn(),
            { data: 'document_number', render: (d, _, r) => '<a href="' + esc.display(r.show_url) + '">' + esc.display(d) + '</a>' },
            { data: 'document_date_label', render: esc.display }, { data: 'party_label', render: esc.display }, { data: 'tax_treatment_label', render: esc.display },
            { data: 'original_amount', className: 'text-end', render: money }, { data: 'applied_amount', className: 'text-end', render: money }, { data: 'remaining_amount', className: 'text-end fw-semibold', render: money },
            { data: 'used_hs_label', render: d => '<span class="small text-nowrap" style="white-space:pre-line">' + esc.display(d) + '</span>' },
            { data: 'gl_reference_label', render: d => '<span class="small text-nowrap" style="white-space:pre-line">' + esc.display(d) + '</span>' },
            { data: 'status_label', render: (d, _, r) => '<span class="badge ' + statusBadge(r.status) + '">' + esc.display(d) + '</span>' },
            { data: null, orderable: false, searchable: false, className: 'text-end text-nowrap', render: (_, __, r) => '<a class="btn btn-sm btn-app-soft" href="' + esc.display(r.show_url) + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-file-find" aria-hidden="true"></i></a>' + (r.pdf_url ? ' <a class="btn btn-sm btn-app-soft" href="' + esc.display(r.pdf_url) + '" title="พิมพ์ PDF" aria-label="พิมพ์ PDF" target="_blank" rel="noopener"><i class="bx bx-printer" aria-hidden="true"></i></a>' : '') }
        ]
    }));
    $('#ai-filter').on('click', () => dt.ajax.reload());
    $('#ai-reset').on('click', () => { $('#ai-from,#ai-to').val(''); $('#ai-status').val(''); $('#ai-party').val(null).trigger('change'); dt.ajax.reload(); });
    $('#ai-party').select2({ theme: 'bootstrap-5', width: '100%', ajax: { url: '{{ route('pos.sales-documents.party-options') }}', dataType: 'json', delay: 250, data: p => ({ q: p.term || '', page: p.page || 1 }), processResults: d => d } });
});
</script>
@endpush
