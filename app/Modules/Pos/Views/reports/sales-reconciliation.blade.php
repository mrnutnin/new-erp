@extends('Pos::layout')

@section('title', 'กระทบยอดขาย–รับชำระ–ลูกหนี้ | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    @include('Pos::partials.sales-list-header', ['eyebrow' => 'POS / REPORTS', 'title' => 'กระทบยอดขาย–รับชำระ–ลูกหนี้', 'description' => 'ตรวจยอด HS/IV เทียบเงินรับ ใบลดหนี้ เงินรับล่วงหน้า และยอดลูกหนี้จากรายการที่ Post แล้ว'])
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-3 p-lg-4"><div class="row g-3 align-items-end">
        <div class="col-md-2"><label class="form-label" for="reconcile-from">วันที่ Post ตั้งแต่</label><input class="form-control" id="reconcile-from" type="date" value="{{ now()->startOfMonth()->format('Y-m-d') }}"></div>
        <div class="col-md-2"><label class="form-label" for="reconcile-to">ถึงวันที่</label><input class="form-control" id="reconcile-to" type="date" value="{{ now()->endOfMonth()->format('Y-m-d') }}"></div>
        <div class="col-md-2"><label class="form-label" for="reconcile-as-of">คำนวณยอด ณ วันที่</label><input class="form-control" id="reconcile-as-of" type="date" value="{{ today()->toDateString() }}"></div>
        <div class="col-md-2"><label class="form-label" for="reconcile-type">ประเภท</label><select class="form-select" id="reconcile-type"><option value="">ทั้งหมด</option><option value="HS">HS · ขายสด</option><option value="IV">IV · ขายเชื่อ</option></select></div>
        <div class="col-md-2"><label class="form-label" for="reconcile-status">ผลตรวจ</label><select class="form-select" id="reconcile-status"><option value="">ทั้งหมด</option><option value="CLEAR">ตรงกัน</option><option value="OUTSTANDING">มีลูกหนี้คงเหลือ</option><option value="CHECK">ต้องตรวจสอบ</option></select></div>
        <div class="col-md-2"><button class="btn btn-outline-secondary w-100" id="reconcile-filter" type="button"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรอง</button></div>
        <div class="col-md-4"><label class="form-label" for="reconcile-party">ลูกค้า</label><select class="form-select" id="reconcile-party"><option value="">ทั้งหมด</option></select></div>
    </div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-start gap-3 mb-3"><div><h2 class="h6 mb-1">รายการกระทบยอด</h2><p class="text-secondary small mb-0">HS ต้องเท่ากับเงินรับจริงและเงินรับล่วงหน้าที่ใช้; IV ติดตามยอดคงเหลือจาก AR Open Item โดยตรง</p></div></div><div class="table-responsive"><table id="reconciliation-table" class="table table-hover align-middle w-100 mb-0" data-url="{{ route('pos.sales-reports.reconciliation.data') }}"><thead class="table-light"><tr><th>เลขที่</th><th>วันที่ Post</th><th>ลูกค้า</th><th>ประเภท</th><th class="text-end">ยอดขาย</th><th class="text-end">รับชำระ</th><th class="text-end">เงินรับล่วงหน้า</th><th class="text-end">ใบลดหนี้</th><th class="text-end">คืนเงิน</th><th class="text-end">ลูกหนี้คงเหลือ</th><th>ผลตรวจ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#reconciliation-table'), esc = $.fn.dataTable.render.text(), money = $.fn.dataTable.render.number(',', '.', 2);
    const badge = status => ({CLEAR: ['app-badge-success', 'ตรงกัน'], OUTSTANDING: ['app-badge-warning', 'มีลูกหนี้คงเหลือ'], CHECK: ['text-bg-danger', 'ต้องตรวจสอบ']}[status] || ['app-badge-soft', '—']);
    const dt = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {ajax: {url: table.data('url'), data: d => { d.date_from = $('#reconcile-from').val(); d.date_to = $('#reconcile-to').val(); d.as_of = $('#reconcile-as-of').val(); d.party_id = $('#reconcile-party').val(); d.document_type = $('#reconcile-type').val(); d.status = $('#reconcile-status').val(); }}, order: [[1, 'desc']], buttons: [window.erpExcelButton(table)], columns: [
        {data: 'document_number', name: 'sales.document_number', render: (v, _, r) => '<a href="' + esc.display(r.detail_url) + '">' + esc.display(v) + '</a>'}, {data: 'posting_date', name: 'sales.posting_date', render: esc.display}, {data: 'party_label', name: 'sales.party_code', render: esc.display}, {data: 'document_type', name: 'sales.document_type', render: v => v === 'HS' ? 'HS · ขายสด' : 'IV · ขายเชื่อ'}, {data: 'total_amount', className: 'text-end', render: money}, {data: 'received_amount', className: 'text-end', render: money}, {data: 'advance_amount', className: 'text-end', render: money}, {data: 'credit_note_amount', className: 'text-end', render: money}, {data: 'refund_amount', className: 'text-end', render: money}, {data: 'outstanding_amount', className: 'text-end fw-semibold', render: money}, {data: 'status', orderable: false, searchable: false, render: v => { const s = badge(v); return '<span class="badge ' + s[0] + '">' + s[1] + '</span>'; }}, {data: null, orderable: false, searchable: false, className: 'text-end', render: (_, __, r) => { let html = '<a class="btn btn-sm btn-app-soft" href="' + esc.display(r.detail_url) + '"><i class="bx bx-show" aria-hidden="true"></i><span class="visually-hidden">ดูเอกสาร</span></a>'; if (r.journal_url) html += ' <button class="btn btn-sm btn-app-soft" type="button" data-journal-preview-url="' + esc.display(r.journal_url) + '"><i class="bx bx-book-open" aria-hidden="true"></i><span class="visually-hidden">ดู GL</span></button>'; return html; }}
    ]}));
    $('#reconcile-filter').on('click', () => dt.ajax.reload());
    $('#reconcile-party').select2({theme: 'bootstrap-5', width: '100%', allowClear: true, ajax: {url: '{{ route('pos.sales-reports.customer-options') }}', dataType: 'json', delay: 250, data: p => ({q: p.term || '', page: p.page || 1}), processResults: d => d}});
});
</script>
@endpush
