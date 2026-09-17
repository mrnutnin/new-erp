@extends('Asset::layout')

@section('title', 'ทะเบียนสินทรัพย์ | MintERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ASSET / REGISTER</p>
                <h1 class="h3 mb-2">ทะเบียนสินทรัพย์</h1>
                <p class="text-secondary mb-0">สร้างและแก้ไขทะเบียนสินทรัพย์ร่างในสาขาที่เลือก</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if (auth()->user()->hasPermission('asset.register.create'))
                    <a class="btn btn-app-primary" href="{{ route('asset.assets.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มสินทรัพย์</a>
                @endif
                @if (auth()->user()->hasPermission('asset.register.import'))
                    <a class="btn btn-app-soft" href="{{ route('asset.assets.import.create') }}"><i class="bx bx-upload me-1" aria-hidden="true"></i>นำเข้า Excel</a>
                @endif
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรอง</h2><p class="small text-secondary mb-0">กรองข้อมูลก่อนค้นหาจากตาราง</p></div><button class="btn btn-sm btn-app-soft" id="asset-filter-reset" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div><div class="row g-3"><div class="col-12 col-md-3"><label class="form-label" for="asset-filter-status">สถานะ</label><select class="form-select" id="asset-filter-status"><option value="">ทุกสถานะ</option><option value="DRAFT">ร่าง</option><option value="REGISTERED">ขึ้นทะเบียนแล้ว</option><option value="ACTIVE">ใช้งาน</option><option value="SUSPENDED">ระงับ</option><option value="UNDER_REPAIR">ซ่อมบำรุง</option><option value="HELD_FOR_DISPOSAL">รอจำหน่าย</option><option value="DISPOSED">จำหน่ายแล้ว</option><option value="WRITTEN_OFF">ตัดจำหน่าย</option></select></div><div class="col-12 col-md-3"><label class="form-label" for="asset-filter-category">หมวดสินทรัพย์</label><select class="form-select" id="asset-filter-category"></select></div><div class="col-12 col-md-3"><label class="form-label" for="asset-filter-location">สถานที่</label><select class="form-select" id="asset-filter-location"></select></div><div class="col-12 col-md-3"><label class="form-label" for="asset-filter-custodian">ผู้ดูแล</label><select class="form-select" id="asset-filter-custodian"></select></div></div></div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="table-responsive"><table class="table table-hover align-middle w-100" id="assets-table" data-url="{{ route('asset.assets.data') }}" data-options-url="{{ route('asset.assets.options') }}" data-can-update="{{ auth()->user()->hasPermission('asset.register.update') ? '1' : '0' }}"><thead><tr><th>เลขทะเบียน</th><th>ชื่อสินทรัพย์</th><th>สาขา</th><th>หมวด</th><th>สถานที่</th><th>ผู้ดูแล</th><th>มูลค่า</th><th>สถานะ</th>@if(auth()->user()->hasPermission('asset.register.update'))<th class="text-end">จัดการ</th>@endif</tr></thead></table></div></div></div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    var $table=$('#assets-table'),text=$.fn.dataTable.render.text(),statuses={DRAFT:'ร่าง',REGISTERED:'ขึ้นทะเบียนแล้ว',ACTIVE:'ใช้งาน',SUSPENDED:'ระงับ',UNDER_REPAIR:'ซ่อมบำรุง',HELD_FOR_DISPOSAL:'รอจำหน่าย',DISPOSED:'จำหน่ายแล้ว',WRITTEN_OFF:'ตัดจำหน่าย'},optionsUrl=$table.data('options-url');
    function filterSelect(selector,type,placeholder){window.erpInitSelect2(selector,{placeholder:placeholder,allowClear:true,ajax:{url:optionsUrl,delay:250,data:function(p){return{type:type,q:p.term||'',page:p.page||1};},processResults:function(r){return r;}}});}
    filterSelect('#asset-filter-category','category','ทุกหมวด');filterSelect('#asset-filter-location','location','ทุกสถานที่');filterSelect('#asset-filter-custodian','custodian','ผู้ดูแลทุกคน');
    var showUrl=@json(route('asset.assets.show', ['asset' => '__asset__']));
    var badge={DRAFT:'app-status-neutral',REGISTERED:'app-status-info',ACTIVE:'app-status-success',SUSPENDED:'app-status-warning',UNDER_REPAIR:'app-status-warning',HELD_FOR_DISPOSAL:'app-status-warning',DISPOSED:'app-status-danger',WRITTEN_OFF:'app-status-danger'},columns=[{data:'asset_number',name:'asset_number',render:function(v,t,row){return t==='display'?'<a class="text-decoration-none fw-semibold" href="'+showUrl.replace('__asset__',row.id)+'">'+text.display(v)+'</a>':v;}},{data:'name',name:'name',render:text.display},{data:'branch_label',name:'branch_label',render:text.display},{data:'category_label',name:'category_label',render:text.display},{data:'location_label',name:'location_label',render:text.display},{data:'custodian_label',name:'custodian_label',render:text.display},{data:'original_cost',name:'original_cost',render:function(v,t){return t==='display'?Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}):v;}},{data:'status',name:'status',render:function(v,t){return t==='display'?'<span class="badge '+(badge[v]||'app-status-neutral')+'">'+text.display(statuses[v]||v)+'</span>':v;}}];
    if($table.data('can-update'))columns.push({data:null,orderable:false,searchable:false,className:'text-end',render:function(v,t,row){if(t!=='display')return '';var actions=['<a class="btn btn-sm btn-app-soft" href="'+text.display(row.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-file-find" aria-hidden="true"></i></a>'];if(row.edit_url)actions.push('<a class="btn btn-sm btn-app-soft" href="'+text.display(row.edit_url)+'" title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit" aria-hidden="true"></i></a>');if(row.delete_url)actions.push('<button class="btn btn-sm btn-app-danger js-delete-asset" data-url="'+text.display(row.delete_url)+'" type="button" title="ลบร่าง" aria-label="ลบร่าง"><i class="bx bx-trash" aria-hidden="true"></i></button>');return actions.join(' ');}});
    var table=$table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:$table.data('url'),data:function(data){data.status=$('#asset-filter-status').val();data.asset_category_id=$('#asset-filter-category').val();data.location_id=$('#asset-filter-location').val();data.custodian_user_id=$('#asset-filter-custodian').val();}},order:[[0,'desc']],buttons:[window.erpExcelButton($table)],columns:columns}));
    $('#asset-filter-status,#asset-filter-category,#asset-filter-location,#asset-filter-custodian').on('change',function(){table.ajax.reload();});$('#asset-filter-reset').on('click',function(){$('#asset-filter-status').val('');$('#asset-filter-category,#asset-filter-location,#asset-filter-custodian').val(null).trigger('change');table.ajax.reload();});
    window.erpAjaxDelete({button:'.js-delete-asset',reload:'#assets-table',confirm:'ยืนยันการลบทะเบียนสินทรัพย์ร่างนี้หรือไม่?'});
});
</script>
@endpush
