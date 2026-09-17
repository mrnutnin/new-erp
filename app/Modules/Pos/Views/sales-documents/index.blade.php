@extends('Pos::layout')

@section('title', 'เอกสารขาย | POS / Sales')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div><p class="eyebrow mb-2">POS / SALES</p><h1 class="h3 mb-2">ใบแจ้งหนี้และใบลดหนี้</h1><p class="text-secondary mb-0">เอกสารบริการแบบไม่คิด VAT</p></div>
            @if (auth()->user()->hasPermission('pos.sales-documents.create'))
                <a class="btn btn-app-primary" href="{{ route('pos.sales-documents.create', ['documentType' => 'INVOICE']) }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มใบแจ้งหนี้</a>
            @endif
        </div>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body p-3 p-lg-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรองเอกสารขาย</h2><p class="text-secondary small mb-0">กรองตามสถานะรับชำระก่อนค้นหาจากตาราง</p></div><button id="sales-document-reset" class="btn btn-sm btn-app-soft" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
            <div class="row align-items-end g-3">
                <div class="col-12 col-md-4 col-lg-3"><label class="form-label" for="sales-document-payment-status">สถานะรับชำระ</label><select id="sales-document-payment-status" class="form-select"><option value="">ทุกสถานะ</option><option value="UNPAID">ยังไม่ชำระ</option><option value="PARTIAL">ชำระบางส่วน</option><option value="PAID">ชำระครบ</option></select></div>
                <div class="col-12 col-md-auto"><button id="sales-document-filter" class="btn btn-app-primary" type="button"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>ใช้ตัวกรอง</button></div>
            </div>
        </div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="mb-3"><h2 class="h5 mb-1">รายการเอกสารขาย</h2><p class="text-secondary small mb-0">เปิดรายละเอียดเพื่ออนุมัติ ลงบัญชี หรือยกเลิกเอกสารตามสิทธิ์และสถานะ</p></div><div class="table-responsive">
            <table id="sales-documents-table" class="table table-hover align-middle w-100" data-url="{{ route('pos.sales-documents.data') }}">
                <thead><tr><th>ลำดับ</th><th>เลขที่</th><th>ประเภท</th><th>วันที่</th><th>ครบกำหนด</th><th>ลูกค้า</th><th class="text-end">ยอดสุทธิ</th><th class="text-end">คงเหลือ</th><th>สถานะรับชำระ</th><th>สมุดรายวัน</th><th>สถานะเอกสาร</th><th class="text-end">จัดการ</th></tr></thead>
            </table>
        </div></div></div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    var $table = $('#sales-documents-table'), text = $.fn.dataTable.render.text();
    var status = {DRAFT:'app-badge-soft', APPROVED:'app-badge-info', POSTED:'app-badge-success', VOID:'text-bg-danger'};
    var paymentStatus = {UNPAID:'app-badge-warning', PARTIAL:'app-badge-info', PAID:'app-badge-success', CHECK:'text-bg-danger'};
    var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: {url:$table.data('url'), data:function (data) { data.payment_status=$('#sales-document-payment-status').val(); }}, order: [[3, 'desc']], buttons: [window.erpExcelButton($table)],
        columns: [window.erpRowNumberColumn(),
            {data:'document_number', render:function (value, type, row) { return type === 'display' && row.show_url ? '<a href="'+text.display(row.show_url)+'">'+text.display(value)+'</a>' : value; }}, {data:'type_label', render:text.display}, {data:'document_date_label', name:'sales_documents.document_date'},
            {data:'due_date_label', name:'sales_documents.due_date'}, {data:'party_label', name:'sales_documents.party_code', render:text.display},
            {data:'total_amount', className:'text-end', render:$.fn.dataTable.render.number(',', '.', 2)},
            {data:'payment_remaining', className:'text-end', defaultContent:'—', render:function (value, type) { if (value === null || value === '') return type === 'display' ? '—' : ''; return type === 'display' ? $.fn.dataTable.render.number(',', '.', 2).display(value) : value; }},
            {data:'payment_status_label', orderable:true, searchable:false, render:function (value, type, row) { if (type !== 'display' || !row.payment_status) return value; return '<span class="badge '+(paymentStatus[row.payment_status]||'app-badge-soft')+'">'+text.display(value)+'</span>'; }},
            {data:'journal_entry_number', defaultContent:'—', render:text.display},
            {data:'status_label', name:'sales_documents.status', render:function (value, type, row) { return type === 'display' ? '<span class="badge '+(status[row.status]||'app-badge-soft')+'">'+text.display(value)+'</span>' : value; }},
            {data:null, orderable:false, searchable:false, className:'text-end text-nowrap', render:function (_, type, row) { if(type!=='display') return ''; var a=[]; if(row.show_url)a.push('<a class="btn btn-sm btn-app-soft" href="'+text.display(row.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-file-find" aria-hidden="true"></i></a>'); if(row.edit_url)a.push('<a class="btn btn-sm btn-app-soft" href="'+text.display(row.edit_url)+'" title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit-alt" aria-hidden="true"></i></a>'); if(row.pdf_url)a.push('<a class="btn btn-sm btn-app-soft" href="'+text.display(row.pdf_url)+'" target="_blank" rel="noopener" title="พิมพ์ PDF" aria-label="พิมพ์ PDF"><i class="bx bx-printer" aria-hidden="true"></i></a>'); if(row.delete_url)a.push('<button class="btn btn-sm btn-app-danger js-delete-sales-document" type="button" data-url="'+text.display(row.delete_url)+'" title="ลบร่าง" aria-label="ลบร่าง"><i class="bx bx-trash"></i></button>'); return a.join(' '); }}
        ]
    }));
    $('#sales-document-filter').on('click', function () { table.ajax.reload(); });
    $('#sales-document-reset').on('click', function () { $('#sales-document-payment-status').val(''); table.ajax.reload(); });
    window.erpAjaxDelete({button: '.js-delete-sales-document', reload: '#sales-documents-table', confirm: 'ยืนยันการลบร่างเอกสารขายนี้หรือไม่?', confirmButtonText: 'ลบร่าง', cancelButtonText: 'กลับ'});
});
</script>
@endpush
