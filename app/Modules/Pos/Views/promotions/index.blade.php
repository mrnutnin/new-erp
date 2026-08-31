@extends('Pos::layout')

@section('title', 'โปรโมชั่น | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div><p class="eyebrow mb-2">POS / MASTER DATA</p><h1 class="h3 mb-2">โปรโมชั่น</h1><p class="text-secondary mb-0">กำหนดราคาโปรโมชั่นหรือส่วนลดที่มีผลก่อน Price List</p></div>
        @if (auth()->user()->hasPermission('pos.promotions.create'))<a class="btn btn-dark" href="{{ route('pos.promotions.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มโปรโมชั่น</a>@endif
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><form id="promotion-filter" class="row g-3 align-items-end"><div class="col-md-4"><label class="form-label" for="promotion-group">กลุ่มลูกค้า</label><select id="promotion-group" class="form-select js-promotion-group" data-url="{{ route('pos.promotions.group-options') }}"><option value="">ทุกกลุ่ม</option></select></div><div class="col-md-3"><label class="form-label" for="promotion-active">สถานะ</label><select id="promotion-active" class="form-select"><option value="">ทั้งหมด</option><option value="1">ใช้งาน</option><option value="0">ปิดใช้งาน</option></select></div><div class="col-auto"><button class="btn btn-dark" type="submit">กรอง</button> <button id="clear-promotion-filter" class="btn btn-outline-secondary" type="button">ล้าง</button></div></form></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive"><table id="promotions-table" class="table table-hover align-middle w-100" data-url="{{ route('pos.promotions.data') }}"><thead><tr><th>รหัส</th><th>ชื่อโปรโมชั่น</th><th>ประเภท</th><th>กลุ่มลูกค้า</th><th>รายการ</th><th>ลำดับ</th><th>วันที่เริ่ม</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var table = $('#promotions-table'), text = $.fn.dataTable.render.text();
    $('.js-promotion-group').select2({theme:'bootstrap-5', width:'100%', allowClear:true, placeholder:'ทุกกลุ่ม', ajax:{url:$('.js-promotion-group').data('url'), dataType:'json', delay:250, data:function(p){return {q:p.term||''};}, processResults:function(data){return data;}}});
    var dataTable = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {ajax:{url:table.data('url'),data:function(data){data.customer_group_code=$('#promotion-group').val();data.is_active=$('#promotion-active').val();}},order:[[4,'desc']],buttons:[window.erpExcelButton(table)],columns:[
        {data:'code',name:'pos_promotions.code',render:text.display},{data:'name',name:'pos_promotions.name',render:text.display},{data:'scope_label',name:'pos_promotions.application_scope',render:text.display},{data:'group_label',name:'pos_promotions.customer_group_code',render:text.display},{data:'line_count',name:'items_count',className:'text-end'},{data:'priority',name:'pos_promotions.priority',className:'text-end'},{data:'effective_from',name:'pos_promotions.effective_from',render:text.display},{data:'status',name:'pos_promotions.is_active',render:function(v,t){if(t!=='display')return v;return v==='ACTIVE'?'<span class="badge app-badge-success">ใช้งาน</span>':'<span class="badge app-badge-soft">ปิดใช้งาน</span>'; }},{data:null,orderable:false,searchable:false,className:'text-end',render:function(v,t,row){if(t!=='display')return '';var actions=['<a class="btn btn-sm btn-app-soft" title="ดูรายละเอียด" href="'+text.display(row.show_url)+'"><i class="bx bx-show" aria-hidden="true"></i><span class="visually-hidden">ดูรายละเอียด</span></a>'];if(row.edit_url)actions.push('<a class="btn btn-sm btn-outline-dark" title="แก้ไข" href="'+text.display(row.edit_url)+'"><i class="bx bx-edit-alt" aria-hidden="true"></i><span class="visually-hidden">แก้ไข</span></a>');if(row.delete_url)actions.push('<button class="btn btn-sm btn-outline-danger js-delete-promotion" title="ลบ" type="button" data-url="'+text.display(row.delete_url)+'"><i class="bx bx-trash" aria-hidden="true"></i><span class="visually-hidden">ลบ</span></button>');return actions.join(' ');}}
    ]}));
    $('#promotion-filter').on('submit',function(event){event.preventDefault();dataTable.ajax.reload();});
    $('#clear-promotion-filter').on('click',function(){$('#promotion-group').val(null).trigger('change');$('#promotion-active').val('');dataTable.ajax.reload();});
    window.erpAjaxDelete({button:'.js-delete-promotion',reload:'#promotions-table',confirm:'ยืนยันการลบโปรโมชั่นนี้หรือไม่? เอกสารที่ snapshot แล้วจะไม่เปลี่ยนแปลง'});
});
</script>
@endpush
