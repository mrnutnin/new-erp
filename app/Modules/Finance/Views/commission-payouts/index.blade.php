@extends('Finance::layout')
@section('title', 'ชุดจ่ายคอมมิชชั่น | Finance')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">FINANCE / COMMISSION PAYOUTS</p>
    <h1 class="h3 mb-2">ชุดจ่ายคอมมิชชั่น</h1>
    <p class="text-secondary mb-4">แสดงเฉพาะชุดที่ POS ตรวจสอบและส่งให้ฝ่ายการเงินแล้ว จากนั้นจึงแยกดำเนินการจ่ายให้พนักงานในแต่ละชุด</p>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive"><table id="commission-payouts-table" class="table table-hover align-middle w-100 mb-0" data-url="{{ route('finance.commission-payouts.data') }}"><thead class="table-light"><tr><th>เลขที่ชุดจ่าย</th><th>ช่วงคำนวณ</th><th class="text-end">จำนวนรายการ</th><th class="text-end">ผู้รับ</th><th>สถานะใบขอจ่าย</th><th>ผลการจ่าย</th><th class="text-end">ยอดรวม</th><th>สถานะชุด</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    const table = $('#commission-payouts-table'), escaped = $.fn.dataTable.render.text(), money = $.fn.dataTable.render.number(',', '.', 2);
    table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: table.data('url'), buttons: [window.erpExcelButton(table)],
        columns: [
            {data: 'document_number', render: escaped.display},
            {data: 'period_label', render: escaped.display},
            {data: 'lines_count', className: 'text-end'},
            {data: 'recipient_count', className: 'text-end'},
            {data: 'request_summary', orderable: false, render: value => '<span class="badge ' + escaped.display(value.class) + '">' + escaped.display(value.label) + '</span>'},
            {data: 'payment_summary', orderable: false, render: value => '<span class="badge ' + escaped.display(value.class) + '">' + escaped.display(value.label) + '</span>'},
            {data: 'total_amount', className: 'text-end fw-semibold', render: money},
            {data: 'status', render: status => '<span class="badge ' + (status === 'VERIFIED' ? 'app-badge-success' : 'app-badge-info') + '">' + (status === 'VERIFIED' ? 'พร้อมจ่าย' : 'รอตรวจสอบ') + '</span>'},
            {data: 'show_url', orderable: false, searchable: false, className: 'text-end', render: url => '<a class="btn btn-sm btn-outline-primary" href="' + escaped.display(url) + '">ดูรายละเอียด</a>'},
        ],
    }));
});
</script>
@endpush
