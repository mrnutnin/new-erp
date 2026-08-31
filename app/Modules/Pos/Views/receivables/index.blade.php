@extends('Pos::layout')

@section('title', 'ลูกหนี้คงค้าง | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    @include('Pos::partials.sales-list-header', ['eyebrow' => 'SALES / RECEIVABLES', 'title' => 'ลูกหนี้คงค้าง', 'description' => 'ติดตามใบขายเชื่อ (IV) และรับชำระหนี้จากรายการที่คงเหลือ'])
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-3 p-lg-4"><div class="row g-3 align-items-end">
        <div class="col-md-3"><label class="form-label" for="receivable-due-from">ครบกำหนดตั้งแต่</label><input class="form-control" id="receivable-due-from" type="date"></div>
        <div class="col-md-3"><label class="form-label" for="receivable-due-to">ถึงวันที่</label><input class="form-control" id="receivable-due-to" type="date"></div>
        <div class="col-md-3"><label class="form-label" for="receivable-party">ลูกค้า</label><select class="form-select" id="receivable-party">@if ($selectedParty)<option value="{{ $selectedParty->id }}" selected>{{ $selectedParty->code }} · {{ $selectedParty->name }}</option>@endif</select></div>
        <div class="col-md-2"><label class="form-label" for="receivable-status">สถานะชำระเงิน</label><select class="form-select" id="receivable-status"><option value="">ทั้งหมด</option><option value="UNPAID">ยังไม่ชำระ</option><option value="PARTIAL">ชำระบางส่วน</option><option value="PAID">ชำระครบ</option></select></div>
        <div class="col-md-1"><button class="btn btn-outline-secondary w-100" id="receivable-filter" type="button"><i class="bx bx-filter-alt" aria-hidden="true"></i><span class="visually-hidden">กรอง</span></button></div>
    </div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><h2 class="h6 mb-3">รายการใบขายเชื่อ</h2><div class="table-responsive"><table id="receivables-table" class="table table-hover align-middle w-100 mb-0" data-url="{{ route('pos.receivables.data') }}"><thead class="table-light"><tr><th>เลขที่ IV</th><th>วันที่เอกสาร</th><th>ครบกำหนด</th><th>ลูกค้า</th><th class="text-end">ยอดตั้งหนี้</th><th class="text-end">คงเหลือ</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#receivables-table'), esc = $.fn.dataTable.render.text(), money = $.fn.dataTable.render.number(',', '.', 2);
    const badge = status => ({UNPAID: 'app-badge-soft', PARTIAL: 'app-badge-info', PAID: 'app-badge-success'}[status] || 'app-badge-soft');
    const dt = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {ajax: {url: table.data('url'), data: d => { d.due_from = $('#receivable-due-from').val(); d.due_to = $('#receivable-due-to').val(); d.party_id = $('#receivable-party').val(); d.status = $('#receivable-status').val(); }}, order: [[2, 'asc']], buttons: [window.erpExcelButton(table)], columns: [
        {data: 'document_number', render: (d, _, r) => r.show_url ? '<a href="' + esc.display(r.show_url) + '">' + esc.display(d) + '</a>' : esc.display(d)}, {data: 'document_date_label', name: 'oi.document_date', render: esc.display}, {data: 'due_date_label', name: 'oi.due_date', render: esc.display}, {data: 'party_label', render: esc.display},
        {data: 'original_amount', className: 'text-end', render: money}, {data: 'remaining_amount', className: 'text-end fw-semibold', render: money}, {data: 'payment_status_label', render: (d, _, r) => '<span class="badge ' + badge(r.payment_status) + '">' + esc.display(d) + '</span>'},
        {data: null, orderable: false, searchable: false, className: 'text-end', render: (_, __, r) => r.receive_receipt_url ? '<a class="btn btn-sm btn-success" href="' + esc.display(r.receive_receipt_url) + '"><i class="bx bx-money me-1"></i>รับชำระหนี้</a>' : '—'}
    ]}));
    $('#receivable-filter').on('click', () => dt.ajax.reload());
    $('#receivable-party').select2({theme: 'bootstrap-5', width: '100%', ajax: {url: '{{ route('pos.receivables.party-options') }}', dataType: 'json', delay: 250, data: p => ({q: p.term || '', page: p.page || 1}), processResults: d => d}});
});
</script>
@endpush
