@extends('Pos::layout')

@section('title', 'รายการราคา | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div><p class="eyebrow mb-2">POS / MASTER DATA</p><h1 class="h3 mb-2">รายการราคา</h1><p class="text-secondary mb-0">กำหนดราคาขายตามกลุ่มลูกค้า ช่วงวันที่ และจำนวนขั้นต่ำ</p></div>
        @if (auth()->user()->hasPermission('pos.price-lists.create'))
            <a class="btn btn-dark" href="{{ route('pos.price-lists.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มรายการราคา</a>
        @endif
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><form id="price-list-filter" class="row g-3 align-items-end"><div class="col-md-4"><label class="form-label" for="price-list-group">กลุ่มลูกค้า</label><select id="price-list-group" class="form-select js-price-group" data-url="{{ route('pos.price-lists.group-options') }}"><option value="">ทุกกลุ่ม</option></select></div><div class="col-md-3"><label class="form-label" for="price-list-active">สถานะ</label><select id="price-list-active" class="form-select"><option value="">ทั้งหมด</option><option value="1">ใช้งาน</option><option value="0">ปิดใช้งาน</option></select></div><div class="col-auto"><button class="btn btn-dark" type="submit">กรอง</button> <button id="clear-price-list-filter" class="btn btn-outline-secondary" type="button">ล้าง</button></div></form></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4">
        <div class="table-responsive"><table id="price-lists-table" class="table table-hover align-middle w-100" data-url="{{ route('pos.price-lists.data') }}">
            <thead><tr><th>รหัส</th><th>ชื่อรายการราคา</th><th>กลุ่มลูกค้า</th><th>สกุลเงิน</th><th>รายการ</th><th>วันที่เริ่ม</th><th>วันที่สิ้นสุด</th><th>ลำดับ</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
        </table></div>
    </div></div>
</div>
@endsection

@push('scripts')
<script>
$(function(){
    var $table=$('#price-lists-table'), text=$.fn.dataTable.render.text();
    $('.js-price-group').select2({theme:'bootstrap-5',width:'100%',allowClear:true,placeholder:'ทุกกลุ่ม',ajax:{url:$('.js-price-group').data('url'),dataType:'json',delay:250,data:function(p){return {q:p.term||''};},processResults:function(data){return data;}}});
    var dataTable=$table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:$table.data('url'),data:function(data){data.customer_group_code=$('#price-list-group').val();data.is_active=$('#price-list-active').val();}},order:[[0,'asc']],buttons:[window.erpExcelButton($table)],columns:[
        {data:'code',name:'pos_price_lists.code',render:text.display},{data:'name',name:'pos_price_lists.name',render:text.display},{data:'group_label',name:'pos_price_lists.customer_group_code',render:text.display},{data:'currency',name:'pos_price_lists.currency',render:text.display},{data:'line_count',name:'items_count',className:'text-end'},{data:'effective_from',name:'pos_price_lists.effective_from',render:text.display},{data:'effective_to',name:'pos_price_lists.effective_to',render:text.display},{data:'priority',name:'pos_price_lists.priority',className:'text-end'},{data:'status_label',name:'pos_price_lists.is_active',render:function(v,t){if(t!=='display')return v;return v==='ใช้งาน'?'<span class="badge app-badge-success">ใช้งาน</span>':'<span class="badge app-badge-soft">ปิดใช้งาน</span>'; }},{data:null,orderable:false,searchable:false,className:'text-end',render:function(v,t,row){if(t!=='display')return '';var a=[];if(row.edit_url)a.push('<a class="btn btn-sm btn-outline-dark" title="แก้ไข" href="'+text.display(row.edit_url)+'"><i class="bx bx-edit-alt" aria-hidden="true"></i><span class="visually-hidden">แก้ไข</span></a>');if(row.delete_url)a.push('<button class="btn btn-sm btn-outline-danger js-delete-price-list" title="ลบ" type="button" data-url="'+text.display(row.delete_url)+'"><i class="bx bx-trash" aria-hidden="true"></i><span class="visually-hidden">ลบ</span></button>');return a.join(' ');}}]}));
    $('#price-list-filter').on('submit',function(event){event.preventDefault();dataTable.ajax.reload();});
    $('#clear-price-list-filter').on('click',function(){$('#price-list-group').val(null).trigger('change');$('#price-list-active').val('');dataTable.ajax.reload();});
    window.erpAjaxDelete({button:'.js-delete-price-list',reload:'#price-lists-table',confirm:'ยืนยันการลบรายการราคานี้หรือไม่?'});
});
</script>
@endpush
