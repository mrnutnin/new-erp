@extends('Production::layout')
@section('title', 'BOM | การผลิต')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><p class="eyebrow mb-2">PRODUCTION / BOM</p><h1 class="h3 mb-2">โครงสร้างการผลิต (BOM)</h1><p class="text-secondary mb-0">กำหนดสินค้าสำเร็จรูป วัตถุดิบ และ Revision ที่ใช้สร้างใบสั่งผลิต</p></div>
        @if(auth()->user()->hasPermission('production.boms.create'))<a class="btn btn-app-primary" href="{{ route('production.boms.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้าง BOM</a>@endif
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรอง BOM</h2><p class="text-secondary small mb-0">กรองตามสถานะ Revision</p></div><button id="clear-bom-filter" class="btn btn-sm btn-app-soft" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
        <form id="bom-filter" class="row g-3 align-items-end"><div class="col-12 col-md-4"><label class="form-label" for="bom-status">สถานะ Revision</label><select id="bom-status" class="form-select"><option value="">ทั้งหมด</option><option value="DRAFT">ร่าง</option><option value="ACTIVE">ใช้งาน</option><option value="INACTIVE">เลิกใช้</option></select></div><div class="col-auto"><button class="btn btn-app-primary" type="submit"><i class="bx bx-search me-1" aria-hidden="true"></i>ค้นหา</button></div></form>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive"><table id="boms-table" class="table table-hover align-middle w-100" data-url="{{ route('production.boms.data') }}"><thead><tr><th>ลำดับ</th><th>รหัส</th><th>ชื่อ BOM</th><th>สินค้าสำเร็จรูป</th><th>หน่วย</th><th>Revision</th><th>เริ่มใช้</th><th class="text-end">วัตถุดิบ</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection
@push('scripts')
<script>
$(function(){
    var $table=$('#boms-table'),text=$.fn.dataTable.render.text();
    var statuses={DRAFT:['ร่าง','app-status-neutral'],ACTIVE:['ใช้งาน','app-status-success'],INACTIVE:['เลิกใช้','app-status-danger']};
    var dt=$table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:$table.data('url'),data:function(d){d.status=$('#bom-status').val();}},order:[[1,'asc']],buttons:[window.erpExcelButton($table)],columns:[window.erpRowNumberColumn(),
        {data:'code',name:'production_boms.code',render:text.display},{data:'name',name:'production_boms.name',render:text.display},{data:'finished_item_label',name:'finished_item_label',render:text.display},{data:'base_uom_code',name:'base_uoms.code',render:text.display},{data:'revision_label',name:'revision_label',orderable:false,render:text.display},{data:'effective_from_label',name:'effective_from_label',orderable:false,render:text.display},{data:'component_count',orderable:false,searchable:false,className:'text-end'},{data:'status_label',name:'status_label',render:function(v,t,row){if(t!=='display')return v;var s=statuses[row.status]||[v,'app-status-neutral'];return '<span class="badge '+s[1]+'">'+text.display(s[0])+'</span>'; }},{data:null,orderable:false,searchable:false,className:'text-end',render:function(v,t,row){if(t!=='display')return '';var a=['<a class="btn btn-sm btn-app-soft" href="'+text.display(row.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-file-find" aria-hidden="true"></i></a>'];if(row.edit_url)a.push('<a class="btn btn-sm btn-app-soft" href="'+text.display(row.edit_url)+'" title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit" aria-hidden="true"></i></a>');if(row.delete_url)a.push('<button class="btn btn-sm btn-app-danger js-bom-action" data-url="'+text.display(row.delete_url)+'" data-method="DELETE" data-message="ลบร่าง BOM นี้?" title="ลบร่าง" aria-label="ลบร่าง"><i class="bx bx-trash" aria-hidden="true"></i></button>');return a.join(' ');}}]}));
    $('#bom-filter').on('submit',function(e){e.preventDefault();dt.ajax.reload();});
    $('#clear-bom-filter').on('click',function(){$('#bom-status').val('');dt.ajax.reload();});
    $(document).on('click','.js-bom-action',function(){var button=$(this);Swal.fire({icon:'warning',text:button.data('message'),showCancelButton:true,confirmButtonText:'ลบร่าง',cancelButtonText:'ยกเลิก'}).then(function(result){if(!result.isConfirmed)return;button.prop('disabled',true);$.post(button.data('url'),{_token:$('meta[name="csrf-token"]').attr('content'),_method:button.data('method')||'POST'}).done(function(){dt.ajax.reload(null,false);}).fail(function(xhr){Swal.fire({icon:'error',text:xhr.responseJSON?.message||'ทำรายการไม่สำเร็จ'});button.prop('disabled',false);});});});
});
</script>
@endpush
