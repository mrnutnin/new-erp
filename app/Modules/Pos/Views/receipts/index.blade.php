@extends('Pos::layout')

@section('title', 'รับชำระหนี้ | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    @include('Pos::partials.sales-list-header', [
        'eyebrow' => 'SALES / RECEIPTS',
        'title' => 'รับชำระหนี้',
        'description' => 'รับชำระใบแจ้งหนี้ (IV) ของลูกค้า และบันทึกช่องทางรับเงิน',
        'actionUrl' => auth()->user()?->hasPermission('pos.receipts.create') ? route('pos.receipts.create') : null,
        'actionLabel' => 'รับชำระหนี้',
        'actionClass' => 'btn-dark',
    ])

    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-3 p-lg-4"><div class="row g-3 align-items-end">
        <div class="col-12 col-md-4 col-lg-3"><label class="form-label" for="receipt-from">วันที่เริ่ม</label><input class="form-control" id="receipt-from" type="date"></div>
        <div class="col-12 col-md-4 col-lg-3"><label class="form-label" for="receipt-to">ถึงวันที่</label><input class="form-control" id="receipt-to" type="date"></div>
        <div class="col-12 col-md-4 col-lg-3"><label class="form-label" for="receipt-party">ลูกค้า</label><select class="form-select" id="receipt-party"></select></div>
        <div class="col-12 col-md-4 col-lg-2"><label class="form-label" for="receipt-status">สถานะเอกสาร</label><select class="form-select" id="receipt-status"><option value="">ทั้งหมด</option><option value="DRAFT">ร่าง</option><option value="APPROVED">อนุมัติแล้ว</option><option value="POSTED">ลงบัญชีแล้ว</option><option value="VOID">ยกเลิก</option><option value="REVERSED">ยกเลิกแล้ว</option></select></div>
        <div class="col-12 col-md-4 col-lg-1"><button class="btn btn-outline-secondary w-100" id="receipt-filter" type="button"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรอง</button></div>
    </div></div></div>

    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="mb-3"><h2 class="h6 mb-0">รายการรับชำระหนี้</h2></div><div class="table-responsive"><table id="receipts-table" class="table table-hover align-middle w-100 mb-0" data-url="{{ route('pos.receipts.data') }}"><thead class="table-light"><tr><th>เลขที่เอกสาร</th><th>วันที่รับเงิน</th><th>ลูกค้า</th><th>บัญชีเงินสด/ธนาคาร</th><th class="text-end">ยอดสุทธิ</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#receipts-table'), esc = $.fn.dataTable.render.text(), money = $.fn.dataTable.render.number(',', '.', 2);
    const badge = status => ({ DRAFT: 'app-badge-soft', APPROVED: 'app-badge-success', POSTED: 'app-badge-success', VOID: 'text-bg-danger', REVERSED: 'text-bg-danger' }[status] || 'app-badge-soft');
    const dt = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: { url: table.data('url'), data: d => { d.date_from = $('#receipt-from').val(); d.date_to = $('#receipt-to').val(); d.party_id = $('#receipt-party').val(); d.status = $('#receipt-status').val(); } },
        order: [[1, 'desc']], buttons: [window.erpExcelButton(table)],
        columns: [
            { data: 'document_number', render: (d, _, r) => '<a href="' + esc.display(r.show_url) + '">' + esc.display(d) + '</a>' },
            { data: 'settlement_date_label', name: 'settlement_date', render: esc.display }, { data: 'party_label', render: esc.display }, { data: 'bank_label', render: esc.display },
            { data: 'net_amount', className: 'text-end', render: money },
            { data: 'status_label', render: (d, _, r) => '<span class="badge ' + badge(r.status) + '">' + esc.display(d) + '</span>' },
            { data: null, orderable: false, searchable: false, className: 'text-end', render: (_, __, r) => '<a class="btn btn-sm btn-app-soft" href="' + esc.display(r.show_url) + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show"></i></a>' }
        ]
    }));
    $('#receipt-filter').on('click', () => dt.ajax.reload());
    $('#receipt-party').select2({ theme: 'bootstrap-5', width: '100%', ajax: { url: '{{ route('pos.sales-documents.party-options') }}', dataType: 'json', delay: 250, data: p => ({ q: p.term || '', page: p.page || 1 }), processResults: d => d } });
});
</script>
@endpush
