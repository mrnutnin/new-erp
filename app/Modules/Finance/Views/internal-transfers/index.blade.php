@extends('Finance::layout')

@section('title', 'โอนเงินระหว่างบัญชี | Finance')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">FINANCE / INTERNAL TRANSFERS</p><h1 class="h3 mb-1">โอนเงินระหว่างบัญชี</h1><p class="text-secondary mb-0">โอนเงินระหว่างบัญชีเงินสด/ธนาคารภายในสาขาเดียวกัน</p></div>
        @if(auth()->user()->hasPermission('finance.internal-transfers.create'))<a class="btn btn-dark" href="{{ route('finance.internal-transfers.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างรายการโอน</a>@endif
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><h2 class="h6 mb-3">ตัวกรอง</h2><div class="row g-3"><div class="col-12 col-md-3"><label class="form-label" for="transfer-filter-status">สถานะ</label><select class="form-select" id="transfer-filter-status"><option value="">ทั้งหมด</option><option value="DRAFT">ร่าง</option><option value="SUBMITTED">รออนุมัติ</option><option value="APPROVED">อนุมัติแล้ว</option><option value="POSTED">ลงบัญชีแล้ว</option><option value="REVERSED">กลับรายการแล้ว</option><option value="VOID">ยกเลิก</option></select></div><div class="col-12 col-md-3"><label class="form-label" for="transfer-filter-source">บัญชีต้นทาง</label><select class="form-select" id="transfer-filter-source"><option value="">ทั้งหมด</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></div><div class="col-12 col-md-3"><label class="form-label" for="transfer-filter-destination">บัญชีปลายทาง</label><select class="form-select" id="transfer-filter-destination"><option value="">ทั้งหมด</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></div><div class="col-12 col-md-3"><label class="form-label" for="transfer-filter-from">วันที่โอน ตั้งแต่</label><input class="form-control" id="transfer-filter-from" type="date"></div><div class="col-12 col-md-3"><label class="form-label" for="transfer-filter-to">วันที่โอน ถึง</label><input class="form-control" id="transfer-filter-to" type="date"></div><div class="col-12 col-md-3"><label class="form-label" for="transfer-filter-min">จำนวนเงินขั้นต่ำ</label><input class="form-control" id="transfer-filter-min" type="number" min="0" step="0.01"></div><div class="col-12 col-md-3"><label class="form-label" for="transfer-filter-max">จำนวนเงินสูงสุด</label><input class="form-control" id="transfer-filter-max" type="number" min="0" step="0.01"></div><div class="col-12 d-flex justify-content-end"><button class="btn btn-outline-secondary" id="transfer-filter-reset" type="button">ล้างตัวกรอง</button></div></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><h2 class="h6 mb-3">รายการโอนเงิน</h2><div class="table-responsive"><table id="internal-transfers-table" class="table table-hover align-middle w-100" data-url="{{ route('finance.internal-transfers.data') }}"><thead><tr><th>เลขที่</th><th>วันที่</th><th>บัญชีต้นทาง</th><th>บัญชีปลายทาง</th><th class="text-end">จำนวนเงิน</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#internal-transfers-table'); const text = $.fn.dataTable.render.text();
    var dt = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, { ajax: { url: table.data('url'), data: function (d) { d.status = $('#transfer-filter-status').val(); d.source_bank_account_id = $('#transfer-filter-source').val(); d.destination_bank_account_id = $('#transfer-filter-destination').val(); d.date_from = $('#transfer-filter-from').val(); d.date_to = $('#transfer-filter-to').val(); d.amount_min = $('#transfer-filter-min').val(); d.amount_max = $('#transfer-filter-max').val(); } }, order: [[1, 'desc']], buttons: [window.erpExcelButton(table)], columns: [
        { data: 'document_number', name: 'document_number', render: text.display }, { data: 'date_label', name: 'document_date', render: text.display },
        { data: 'source_label', name: 'source_bank_account_id', render: text.display }, { data: 'destination_label', name: 'destination_bank_account_id', render: text.display },
        { data: 'amount', name: 'amount', className: 'text-end', render: $.fn.dataTable.render.number(',', '.', 2) },
        { data: 'status', name: 'status', render: function (value, type) { if (type !== 'display') return value; const c = { DRAFT:'app-status-neutral', SUBMITTED:'app-status-info', APPROVED:'app-status-success', POSTED:'app-status-success', REVERSED:'app-status-warning', VOID:'app-status-danger' }; const l = { DRAFT:'ร่าง', SUBMITTED:'รออนุมัติ', APPROVED:'อนุมัติแล้ว', POSTED:'ลงบัญชีแล้ว', REVERSED:'กลับรายการแล้ว', VOID:'ยกเลิก' }; return '<span class="badge '+(c[value] || 'app-status-neutral')+'">'+text.display(l[value] || value)+'</span>'; } },
        { data: null, orderable: false, searchable: false, className: 'text-end', render: function (v, t, row) { return t !== 'display' ? '' : '<a class="btn btn-sm btn-app-soft" href="'+text.display(row.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>'; } }
    ] }));
    $('#transfer-filter-status,#transfer-filter-source,#transfer-filter-destination,#transfer-filter-from,#transfer-filter-to').on('change', function () { dt.ajax.reload(); });
    $('#transfer-filter-min,#transfer-filter-max').on('keyup', function (e) { if (e.key === 'Enter') dt.ajax.reload(); });
    $('#transfer-filter-reset').on('click', function () { $('#transfer-filter-status,#transfer-filter-source,#transfer-filter-destination').val(''); $('#transfer-filter-from,#transfer-filter-to,#transfer-filter-min,#transfer-filter-max').val(''); dt.ajax.reload(); });
});
</script>
@endpush
