@extends('Pos::layout')

@section('title', 'เอกสารขาย | POS / Sales')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div><p class="eyebrow mb-2">POS / SALES</p><h1 class="h3 mb-2">ใบแจ้งหนี้และใบลดหนี้</h1><p class="text-secondary mb-0">เอกสารบริการแบบไม่คิด VAT</p></div>
            @if (auth()->user()->hasPermission('pos.sales-documents.create'))
                <a class="btn btn-dark" href="{{ route('pos.sales-documents.create', ['documentType' => 'INVOICE']) }}"><i class="bx bx-plus me-1"></i>เพิ่มใบแจ้งหนี้</a>
            @endif
        </div>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body p-3 p-lg-4">
            <div class="row align-items-end g-3">
                <div class="col-12 col-md-4 col-lg-3"><label class="form-label" for="sales-document-payment-status">สถานะรับชำระ</label><select id="sales-document-payment-status" class="form-select"><option value="">ทั้งหมด</option><option value="UNPAID">ยังไม่ชำระ</option><option value="PARTIAL">ชำระบางส่วน</option><option value="PAID">ชำระครบ</option></select></div>
                <div class="col-12 col-md-auto"><button id="sales-document-filter" class="btn btn-outline-dark" type="button"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรอง</button></div>
            </div>
        </div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive">
            <table id="sales-documents-table" class="table table-hover align-middle w-100" data-url="{{ route('pos.sales-documents.data') }}">
                <thead><tr><th>เลขที่</th><th>ประเภท</th><th>วันที่</th><th>ครบกำหนด</th><th>ลูกค้า</th><th class="text-end">ยอดสุทธิ</th><th class="text-end">คงเหลือ</th><th>สถานะรับชำระ</th><th>สมุดรายวัน</th><th>สถานะเอกสาร</th><th class="text-end">จัดการ</th></tr></thead>
            </table>
        </div></div></div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    var $table = $('#sales-documents-table'), text = $.fn.dataTable.render.text();
    var status = {DRAFT:['secondary','ร่าง'], APPROVED:['primary','อนุมัติแล้ว'], POSTED:['success','ลงบัญชีแล้ว'], VOID:['danger','ยกเลิก']};
    var paymentStatus = {UNPAID:['warning','ยังไม่ชำระ'], PARTIAL:['info','ชำระบางส่วน'], PAID:['success','ชำระครบ'], CHECK:['danger','ต้องตรวจสอบ AR']};
    $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: {url:$table.data('url'), data:function (data) { data.payment_status=$('#sales-document-payment-status').val(); }}, order: [[2, 'desc']], buttons: [window.erpExcelButton($table)],
        columns: [
            {data:'document_number', render:text.display}, {data:'type_label', render:text.display}, {data:'document_date_label', name:'sales_documents.document_date'},
            {data:'due_date_label', name:'sales_documents.due_date'}, {data:'party_label', name:'sales_documents.party_code', render:text.display},
            {data:'total_amount', className:'text-end', render:$.fn.dataTable.render.number(',', '.', 2)},
            {data:'payment_remaining', className:'text-end', defaultContent:'—', render:function (value, type) { if (value === null || value === '') return type === 'display' ? '—' : ''; return type === 'display' ? $.fn.dataTable.render.number(',', '.', 2).display(value) : value; }},
            {data:'payment_status_label', orderable:true, searchable:false, render:function (value, type, row) { if (type !== 'display' || !row.payment_status) return value; var item=paymentStatus[row.payment_status]||['secondary',value]; return '<span class="badge bg-'+item[0]+'-subtle text-'+item[0]+'-emphasis">'+text.display(value)+'</span>'; }},
            {data:'journal_entry_number', defaultContent:'—', render:text.display},
            {data:'status_label', name:'sales_documents.status', render:function (value, type, row) { if (type !== 'display') return value; var item=status[row.status]||['secondary',value]; return '<span class="badge bg-'+item[0]+'-subtle text-'+item[0]+'-emphasis">'+text.display(value)+'</span>'; }},
            {data:null, orderable:false, searchable:false, className:'text-end', render:function (_, type, row) { if(type!=='display') return ''; var a=[]; if(row.show_url)a.push('<a class="btn btn-sm btn-outline-dark" href="'+text.display(row.show_url)+'">ดู</a>'); if(row.edit_url)a.push('<a class="btn btn-sm btn-outline-secondary" href="'+text.display(row.edit_url)+'">แก้ไข</a>'); if(row.approve_url)a.push('<button class="btn btn-sm btn-outline-primary js-approve" data-url="'+text.display(row.approve_url)+'">อนุมัติ</button>'); if(row.post_url)a.push('<button class="btn btn-sm btn-outline-success js-post" data-url="'+text.display(row.post_url)+'">Post</button>'); if(row.void_url)a.push('<button class="btn btn-sm btn-outline-danger js-void" data-url="'+text.display(row.void_url)+'">ยกเลิก</button>'); return a.join(' '); }}
        ]
    }));
    $('#sales-document-filter').on('click', function () { $table.DataTable().ajax.reload(); });
    function postState(button, reason) { button.prop('disabled',true); $.post(button.data('url'), {reason:reason || ''}).done(function(r){Swal.fire({icon:'success',text:r.msg}).then(function(){$table.DataTable().ajax.reload(null,false);});}).fail(function(xhr){button.prop('disabled',false); Swal.fire({icon:'error',text:xhr.responseJSON?.message||'ไม่สามารถดำเนินการได้'});}); }
    $(document).on('click', '.js-approve', function () { var button=$(this); Swal.fire({icon:'question',title:'อนุมัติเอกสาร',input:'textarea',inputLabel:'เหตุผลส่วนลด (เมื่อเกินเพดาน ต้องอย่างน้อย 10 ตัวอักษร)',showCancelButton:true,confirmButtonText:'อนุมัติ',cancelButtonText:'ยกเลิก'}).then(function(result){if(result.isConfirmed)postState(button,result.value);}); });
    $(document).on('click', '.js-void', function () { var button=$(this); Swal.fire({icon:'warning',title:'ยกเลิกเอกสาร',input:'textarea',inputLabel:'เหตุผล (อย่างน้อย 10 ตัวอักษร)',showCancelButton:true,confirmButtonText:'ยืนยันยกเลิก',cancelButtonText:'ยกเลิก',preConfirm:function(v){if(!v||v.trim().length<10) Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร'); return v;}}).then(function(result){if(result.isConfirmed)postState(button,result.value);}); });
    $(document).on('click', '.js-post', function () { var button=$(this); Swal.fire({title:'Post เอกสาร',input:'date',inputLabel:'วันที่ Post',inputValue:@json(today()->format('Y-m-d')),showCancelButton:true,confirmButtonText:'Post',cancelButtonText:'ยกเลิก',preConfirm:function(v){if(!v)Swal.showValidationMessage('กรุณาระบุวันที่ Post');return v;}}).then(function(result){if(!result.isConfirmed)return;button.prop('disabled',true);$.post(button.data('url'),{posting_date:result.value}).done(function(r){Swal.fire({icon:'success',text:r.msg}).then(function(){$table.DataTable().ajax.reload(null,false);});}).fail(function(xhr){button.prop('disabled',false);Swal.fire({icon:'error',text:xhr.responseJSON?.message||'ไม่สามารถ Post ได้'});});}); });
});
</script>
@endpush
