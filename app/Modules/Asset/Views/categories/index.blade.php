@extends('Asset::layout')

@section('title', 'หมวดสินทรัพย์ | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div><p class="eyebrow mb-2">ASSET / MASTER DATA</p><h1 class="h3 mb-2">หมวดสินทรัพย์</h1><p class="text-secondary mb-0">กำหนดค่าเสื่อมและบัญชี GL เริ่มต้นสำหรับทะเบียนสินทรัพย์</p></div>
            @if (auth()->user()->hasPermission('asset.categories.manage'))<a class="btn btn-dark" href="{{ route('asset.categories.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มหมวดสินทรัพย์</a>@endif
        </div>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h6 mb-3">ตัวกรอง</h2><div class="row g-3"><div class="col-12 col-md-4"><label class="form-label" for="category-filter-status">สถานะ</label><select class="form-select" id="category-filter-status"><option value="">ทุกสถานะ</option><option value="1">ใช้งาน</option><option value="0">ปิดใช้งาน</option></select></div><div class="col-12 col-md-2"><button class="btn btn-outline-secondary w-100" id="category-filter-reset" type="button">ล้างตัวกรอง</button></div></div></div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="table-responsive"><table class="table table-hover align-middle w-100" id="categories-table" data-url="{{ route('asset.categories.data') }}" data-can-manage="{{ auth()->user()->hasPermission('asset.categories.manage') ? '1' : '0' }}"><thead><tr><th>ID</th><th>รหัส</th><th>ชื่อ</th><th>คิดค่าเสื่อม</th><th>เกณฑ์ทุน</th><th>สถานะ</th>@if(auth()->user()->hasPermission('asset.categories.manage'))<th class="text-end">จัดการ</th>@endif</tr></thead></table></div></div></div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    var $table = $('#categories-table'), text = $.fn.dataTable.render.text(), columns = [
        {data:'id', name:'id', searchable:false, render:text.display}, {data:'code', name:'code', render:text.display}, {data:'name', name:'name', render:text.display},
        {data:'is_depreciable', name:'is_depreciable', searchable:false, render:function(v,t){return t === 'display' ? '<span class="badge '+(v?'text-bg-success':'text-bg-secondary')+'">'+(v?'คิดค่าเสื่อม':'ไม่คิดค่าเสื่อม')+'</span>' : v;}},
        {data:'capitalization_threshold', name:'capitalization_threshold', searchable:false, render:function(v,t){return t === 'display' ? Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}) : v;}},
        {data:'is_active', name:'is_active', searchable:false, render:function(v,t){return t === 'display' ? '<span class="badge '+(v?'text-bg-dark':'text-bg-secondary')+'">'+(v?'ใช้งาน':'ปิดใช้งาน')+'</span>' : v;}}
    ];
    if ($table.data('can-manage')) columns.push({data:null, orderable:false, searchable:false, className:'text-end', render:function(v,t,row){if(t !== 'display') return ''; var actions=[]; if(row.edit_url) actions.push('<a class="btn btn-sm btn-outline-dark" href="'+text.display(row.edit_url)+'"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข</a>'); if(row.delete_url) actions.push('<button class="btn btn-sm btn-outline-danger js-delete-category" data-url="'+text.display(row.delete_url)+'" type="button"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ</button>'); return actions.join(' ');}});
    var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {ajax:{url:$table.data('url'),data:function(data){data.is_active=$('#category-filter-status').val();}}, order:[[1,'asc']], buttons:[window.erpExcelButton($table)], columns:columns}));
    $('#category-filter-status').on('change', function(){table.ajax.reload();});$('#category-filter-reset').on('click', function(){$('#category-filter-status').val('');table.ajax.reload();});
    window.erpAjaxDelete({button:'.js-delete-category', reload:'#categories-table', confirm:'ยืนยันการลบหมวดสินทรัพย์นี้หรือไม่?'});
});
</script>
@endpush
