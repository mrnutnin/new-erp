@extends('Production::layout')

@section('title', $title.' · '.$statusLabel.' | Production')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">PRODUCTION / DOCUMENT QUEUE</p>
            <h1 class="h3 mb-2">{{ $title }}</h1>
            <p class="text-secondary mb-2">คลังปัจจุบัน · {{ $statusLabel }}</p>
            <span class="badge app-status-{{ $status === 'DRAFT' ? 'neutral' : 'info' }}">{{ $statusLabel }}</span>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('production.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับศูนย์ควบคุม</a>
    </div>

    <div class="alert alert-info border-0 mb-4" role="status">
        @if($status === 'DRAFT')
            ตรวจสอบเอกสารทีละรายการ แล้วอนุมัติจากหน้ารายละเอียด
        @else
            ตรวจสอบรายละเอียดและความพร้อมก่อนลง Stock และ GL จากหน้ารายละเอียด
        @endif
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <div class="mb-3"><h2 class="h5 mb-1">รายการที่ต้องดำเนินการ</h2><p class="small text-secondary mb-0">จำกัดเฉพาะคลังและสาขาปัจจุบัน · ใช้ช่องค้นหาเพื่อค้นเลขที่เอกสาร สินค้า หรือเหตุผล</p></div>
            <div class="table-responsive">
                <table id="production-document-queue-table" class="table table-hover align-middle w-100">
                    <thead><tr><th>ลำดับ</th><th>เลขที่เอกสาร</th><th>วันที่</th><th>สินค้า</th><th>เหตุผล</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const tableElement = $('#production-document-queue-table');
    const text = $.fn.dataTable.render.text();
    const columns = [
        window.erpRowNumberColumn(),
        {data: 'document_number', name: 'document_number', render: text.display},
        {data: 'business_date', name: 'document_date', render: text.display},
        {data: 'item_summary', name: 'item_summary', orderable: false, render: text.display},
    ];
    columns.push({data: 'reason', name: 'reason', orderable: false, render: text.display});
    columns.push({data: 'status_label', name: 'status_label', orderable: false, render: function (value, type) {
        return type === 'display' ? '<span class="badge {{ $status === 'DRAFT' ? 'app-status-neutral' : 'app-status-info' }}">' + text.display(value) + '</span>' : value;
    }});
    columns.push({data: null, orderable: false, searchable: false, className: 'text-end', render: function (value, type, row) {
        if (type !== 'display') return '';
        const url = text.display(row.show_url);
        const number = text.display(row.document_number);
        return '<a class="btn btn-sm btn-app-soft" href="' + url + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด ' + number + '"><i class="bx bx-show" aria-hidden="true"></i></a>';
    }});

    tableElement.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        processing: true,
        serverSide: true,
        order: [[2, 'desc']],
        ajax: {url: @json($dataUrl)},
        buttons: [window.erpExcelButton(tableElement)],
        columns: columns
    }));
});
</script>
@endpush
