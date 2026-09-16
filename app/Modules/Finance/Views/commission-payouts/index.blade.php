@extends('Finance::layout')
@section('title', 'ชุดจ่ายคอมมิชชั่น | Finance')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="mb-4"><p class="eyebrow mb-2">FINANCE / COMMISSION PAYOUTS</p><h1 class="h3 mb-2">ชุดจ่ายคอมมิชชั่น</h1><p class="text-secondary mb-0">แสดงเฉพาะชุดที่ POS ตรวจสอบและส่งให้ฝ่ายการเงินแล้ว จากนั้นจึงแยกดำเนินการจ่ายให้พนักงานในแต่ละชุด</p></div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 class="h5 mb-1">ตัวกรอง</h2><p class="small text-secondary mb-0">ค้นหาชุดจ่ายตามช่วงวันที่คำนวณ</p></div><button id="commission-payouts-reset" class="btn btn-sm btn-app-soft" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div><div class="row g-3"><div class="col-md-4"><label class="form-label" for="commission-period-from">ช่วงคำนวณตั้งแต่</label><input id="commission-period-from" class="form-control" type="date"></div><div class="col-md-4"><label class="form-label" for="commission-period-to">ช่วงคำนวณถึง</label><input id="commission-period-to" class="form-control" type="date"></div></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="mb-3"><h2 class="h5 mb-1">รายการชุดจ่ายคอมมิชชั่น</h2><p class="small text-secondary mb-0">ค้นหา จัดเรียง และส่งออกข้อมูลได้</p></div><div class="table-responsive"><table id="commission-payouts-table" class="table table-hover align-middle w-100 mb-0" data-url="{{ route('finance.commission-payouts.data') }}"><thead><tr><th>เลขที่ชุดจ่าย</th><th>ช่วงคำนวณ</th><th class="text-end">จำนวนรายการ</th><th class="text-end">ผู้รับ</th><th>สถานะใบขอจ่าย</th><th>ผลการจ่าย</th><th class="text-end">ยอดรวม</th><th>สถานะชุด</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    const table = $('#commission-payouts-table'), escaped = $.fn.dataTable.render.text(), money = $.fn.dataTable.render.number(',', '.', 2), filters = () => ({period_from: $('#commission-period-from').val(), period_to: $('#commission-period-to').val()});
    const dataTable = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: {url: table.data('url'), data: data => $.extend(data, filters())}, buttons: [window.erpExcelButton(table, filters)],
        columns: [
            {data: 'document_number', render: escaped.display},
            {data: 'period_label', render: escaped.display},
            {data: 'lines_count', className: 'text-end'},
            {data: 'recipient_count', className: 'text-end'},
            {data: 'request_summary', orderable: false, render: value => '<span class="badge ' + escaped.display(value.class) + '">' + escaped.display(value.label) + '</span>'},
            {data: 'payment_summary', orderable: false, render: value => '<span class="badge ' + escaped.display(value.class) + '">' + escaped.display(value.label) + '</span>'},
            {data: 'total_amount', className: 'text-end fw-semibold', render: money},
            {data: 'status', render: status => '<span class="badge ' + (status === 'VERIFIED' ? 'app-badge-success' : 'app-badge-info') + '">' + (status === 'VERIFIED' ? 'พร้อมจ่าย' : 'รอตรวจสอบ') + '</span>'},
            {data: 'show_url', orderable: false, searchable: false, className: 'text-end', render: url => '<a class="btn btn-sm btn-app-soft" href="' + escaped.display(url) + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-file-find" aria-hidden="true"></i></a>'},
        ],
    }));
    $('#commission-period-from,#commission-period-to').on('change', () => dataTable.ajax.reload());
    $('#commission-payouts-reset').on('click', () => { $('#commission-period-from,#commission-period-to').val(''); dataTable.ajax.reload(); });
});
</script>
@endpush
