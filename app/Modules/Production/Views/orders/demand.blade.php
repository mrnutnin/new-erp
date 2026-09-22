@extends('Production::layout')
@section('title', 'คำสั่งขายรอผลิต | Production')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><p class="eyebrow mb-2">PRODUCTION / DEMAND</p><h1 class="h3 mb-1">คำสั่งขายรอผลิต</h1><p class="text-secondary mb-0">แสดงเฉพาะรายการจาก Sales Order ที่ยืนยันแล้ว มี Active BOM และยังไม่มี WO ที่ใช้งานอยู่</p></div>
        <a class="btn btn-app-soft" href="{{ route('production.orders.index') }}"><i class="bx bx-list-ul me-1" aria-hidden="true"></i>ใบสั่งผลิต</a>
    </div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive"><table id="production-demand-table" class="table table-hover align-middle w-100" data-url="{{ route('production.demand.data') }}"><thead><tr><th>ลำดับ</th><th>Sales Order / ลูกค้า</th><th>สินค้า</th><th class="text-end">จำนวน</th><th>กำหนดส่ง</th><th>จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection
@push('scripts')
<script>
$(function(){
    const esc = $.fn.dataTable.render.text().display;
    const table = $('#production-demand-table');
    const dt = table.DataTable({...window.erpDataTableDefaults, buttons:[window.erpExcelButton(table)], ajax: table.data('url'), columns:[
        window.erpRowNumberColumn(),
        {data:'sales_order_label', name:'sales_orders.document_number', render: esc},
        {data:'item_label', name:'wms_items.code', render: esc},
        {data:'quantity_label', orderable:false, searchable:false, className:'text-end', render: esc},
        {data:'required_delivery_date_label', name:'sales_orders.required_delivery_date', render: esc},
        {data:null, orderable:false, searchable:false, className:'text-nowrap', render:function(_, type, row){ if(type !== 'display') return ''; return row.create_url ? '<button class="btn btn-sm btn-app-primary js-create-wo" type="button" data-url="'+esc(row.create_url)+'"><i class="bx bx-plus" aria-hidden="true"></i><span class="visually-hidden">สร้างใบสั่งผลิต</span></button>' : ''; }}
    ]});
    $(document).on('click','.js-create-wo',function(){ const btn=$(this).prop('disabled',true); $.post(btn.data('url'), {_token:$('meta[name="csrf-token"]').attr('content')}).done(function(res){ window.location=res.redirect; }).fail(function(xhr){ Swal.fire({icon:'error',text:((xhr.responseJSON||{}).message)||'สร้างใบสั่งผลิตไม่สำเร็จ'}); btn.prop('disabled',false); }); });
});
</script>
@endpush
