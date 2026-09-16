@extends('Asset::layout')

@section('title', 'ค่าเสื่อมราคา | New ERP')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">ASSET / DEPRECIATION</p><h1 class="h3 mb-2">ค่าเสื่อมราคา</h1><p class="text-secondary mb-0">คำนวณ ตรวจสอบ อนุมัติ และลงบัญชีค่าเสื่อมแยกตาม Book และ Tax</p></div>
        @if(auth()->user()->hasPermission('asset.depreciation.calculate'))<a class="btn btn-app-primary" href="{{ route('asset.depreciations.create') }}"><i class="bx bx-calculator me-1" aria-hidden="true"></i>สร้างชุดคำนวณ</a>@endif
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรอง</h2><p class="small text-secondary mb-0">กรองข้อมูลก่อนค้นหาจากตาราง</p></div><button class="btn btn-sm btn-app-soft" id="depreciation-filter-reset" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div><div class="row g-3"><div class="col-12 col-md-3"><label class="form-label" for="depreciation-filter-date-from">คิดถึงวันที่ ตั้งแต่</label><input class="form-control" id="depreciation-filter-date-from" type="date"></div><div class="col-12 col-md-3"><label class="form-label" for="depreciation-filter-date-to">คิดถึงวันที่ ถึง</label><input class="form-control" id="depreciation-filter-date-to" type="date"></div><div class="col-12 col-md-2"><label class="form-label" for="depreciation-filter-book">สมุด</label><select class="form-select" id="depreciation-filter-book"><option value="">ทุกสมุด</option><option value="BOOK">บัญชี (Book)</option><option value="TAX">ภาษี (Tax)</option></select></div><div class="col-12 col-md-2"><label class="form-label" for="depreciation-filter-status">สถานะ</label><select class="form-select" id="depreciation-filter-status"><option value="">ทุกสถานะ</option><option value="CALCULATING">กำลังคำนวณ</option><option value="DRAFT">ร่าง</option><option value="SUBMITTED">รออนุมัติ</option><option value="APPROVED">พร้อมลงบัญชี</option><option value="POSTED">ลงบัญชีแล้ว</option><option value="REVERSED">ยกเลิกแล้ว</option><option value="VOID">ยกเลิก</option><option value="FAILED">คำนวณไม่สำเร็จ</option></select></div></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="table-responsive"><table class="table table-hover align-middle w-100" id="depreciations-table" data-url="{{ route('asset.depreciations.data') }}"><thead><tr><th>เลขที่เอกสาร</th><th>คิดถึงวันที่</th><th>สมุด</th><th class="text-end">จำนวนสินทรัพย์</th><th class="text-end">ค่าเสื่อมรวม</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var $table=$('#depreciations-table'),text=$.fn.dataTable.render.text(),labels={CALCULATING:'กำลังคำนวณ',DRAFT:'ร่าง',SUBMITTED:'รออนุมัติ',APPROVED:'พร้อมลงบัญชี',POSTED:'ลงบัญชีแล้ว',REVERSED:'ยกเลิกแล้ว',VOID:'ยกเลิกแล้ว',FAILED:'คำนวณไม่สำเร็จ'},badge={CALCULATING:'app-status-info',DRAFT:'app-status-neutral',SUBMITTED:'app-status-info',APPROVED:'app-status-info',POSTED:'app-status-success',REVERSED:'app-status-danger',VOID:'app-status-danger',FAILED:'app-status-danger'};
    var table=$table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:$table.data('url'),data:function(data){data.status=$('#depreciation-filter-status').val();data.date_from=$('#depreciation-filter-date-from').val();data.date_to=$('#depreciation-filter-date-to').val();data.book_type=$('#depreciation-filter-book').val();}},order:[[1,'desc']],buttons:[window.erpExcelButton($table)],columns:[
        {data:'document_number',name:'document_number',render:text.display},
        {data:'run_through_date',name:'run_through_date',render:function(v,t){if(t!=='display'||!v)return v;var d=String(v).slice(0,10).split('-');return d.length===3?d[2]+'/'+d[1]+'/'+d[0]:text.display(v);}},
        {data:'book_type',name:'book_type',render:function(v,t){return t==='display'?text.display(v==='BOOK'?'บัญชี (Book)':'ภาษี (Tax)'):v;}},
        {data:'asset_count',name:'asset_count',className:'text-end',render:function(v,t){return t==='display'?Number(v||0).toLocaleString():v;}},
        {data:'total_depreciation',name:'total_depreciation',className:'text-end',render:function(v,t){return t==='display'?Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}):v;}},
        {data:'status',name:'status',render:function(v,t){return t==='display'?'<span class="badge '+(badge[v]||'app-status-neutral')+'">'+text.display(labels[v]||v)+'</span>':v;}},
        {data:null,orderable:false,searchable:false,className:'text-end',render:function(v,t,row){if(t!=='display')return '';var actions=['<a class="btn btn-sm btn-app-soft" href="'+text.display(row.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-file-find" aria-hidden="true"></i></a>'];if(row.delete_url)actions.push('<button class="btn btn-sm btn-app-danger js-delete-depreciation" data-url="'+text.display(row.delete_url)+'" type="button" title="ลบร่าง" aria-label="ลบร่าง"><i class="bx bx-trash" aria-hidden="true"></i></button>');return actions.join(' ');}}
    ]}));$('#depreciation-filter-status,#depreciation-filter-date-from,#depreciation-filter-date-to,#depreciation-filter-book').on('change',function(){table.ajax.reload();});$('#depreciation-filter-reset').on('click',function(){$('#depreciation-filter-status,#depreciation-filter-date-from,#depreciation-filter-date-to,#depreciation-filter-book').val('');table.ajax.reload();});
    window.erpAjaxDelete({button:'.js-delete-depreciation',reload:'#depreciations-table',confirm:'ยืนยันการลบชุดคำนวณค่าเสื่อมร่างนี้หรือไม่?'});
});
</script>
@endpush
