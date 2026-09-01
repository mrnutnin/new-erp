@extends('Asset::layout')

@section('title', 'ค่าเสื่อมราคา | New ERP')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">ASSET / DEPRECIATION</p><h1 class="h3 mb-2">ค่าเสื่อมราคา</h1><p class="text-secondary mb-0">คำนวณ ตรวจสอบ อนุมัติ และลงบัญชีค่าเสื่อมแยกตาม Book และ Tax</p></div>
        @if(auth()->user()->hasPermission('asset.depreciation.calculate'))<a class="btn btn-dark" href="{{ route('asset.depreciations.create') }}"><i class="bx bx-calculator me-1" aria-hidden="true"></i>สร้างชุดคำนวณ</a>@endif
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h6 mb-3">ตัวกรอง</h2><div class="row g-3"><div class="col-12 col-md-3"><label class="form-label" for="depreciation-filter-date-from">คิดถึงวันที่ ตั้งแต่</label><input class="form-control" id="depreciation-filter-date-from" type="date"></div><div class="col-12 col-md-3"><label class="form-label" for="depreciation-filter-date-to">คิดถึงวันที่ ถึง</label><input class="form-control" id="depreciation-filter-date-to" type="date"></div><div class="col-12 col-md-2"><label class="form-label" for="depreciation-filter-book">สมุด</label><select class="form-select" id="depreciation-filter-book"><option value="">ทุกสมุด</option><option value="BOOK">บัญชี (Book)</option><option value="TAX">ภาษี (Tax)</option></select></div><div class="col-12 col-md-2"><label class="form-label" for="depreciation-filter-status">สถานะ</label><select class="form-select" id="depreciation-filter-status"><option value="">ทุกสถานะ</option><option value="CALCULATING">กำลังคำนวณ</option><option value="DRAFT">ร่าง</option><option value="SUBMITTED">รออนุมัติ</option><option value="APPROVED">พร้อมลงบัญชี</option><option value="POSTED">ลงบัญชีแล้ว</option><option value="REVERSED">ยกเลิกแล้ว</option><option value="VOID">ยกเลิก</option><option value="FAILED">คำนวณไม่สำเร็จ</option></select></div><div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-outline-secondary w-100" id="depreciation-filter-reset" type="button">ล้างตัวกรอง</button></div></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="table-responsive"><table class="table table-hover align-middle w-100" id="depreciations-table" data-url="{{ route('asset.depreciations.data') }}"><thead><tr><th>เลขที่เอกสาร</th><th>คิดถึงวันที่</th><th>สมุด</th><th class="text-end">จำนวนสินทรัพย์</th><th class="text-end">ค่าเสื่อมรวม</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var $table=$('#depreciations-table'),text=$.fn.dataTable.render.text(),labels={CALCULATING:'กำลังคำนวณ',DRAFT:'ร่าง',SUBMITTED:'รออนุมัติ',APPROVED:'พร้อมลงบัญชี',POSTED:'ลงบัญชีแล้ว',REVERSED:'ยกเลิกแล้ว',VOID:'ยกเลิกแล้ว',FAILED:'คำนวณไม่สำเร็จ'},badge={CALCULATING:'app-badge-info',DRAFT:'app-badge-soft',SUBMITTED:'app-badge-info',APPROVED:'app-badge-info',POSTED:'app-badge-success',REVERSED:'app-status-danger',VOID:'app-status-danger',FAILED:'app-status-danger'};
    var table=$table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:$table.data('url'),data:function(data){data.status=$('#depreciation-filter-status').val();data.date_from=$('#depreciation-filter-date-from').val();data.date_to=$('#depreciation-filter-date-to').val();data.book_type=$('#depreciation-filter-book').val();}},order:[[1,'desc']],buttons:[window.erpExcelButton($table)],columns:[
        {data:'document_number',name:'document_number',render:text.display},
        {data:'run_through_date',name:'run_through_date',render:function(v,t){if(t!=='display'||!v)return v;var d=String(v).slice(0,10).split('-');return d.length===3?d[2]+'/'+d[1]+'/'+d[0]:text.display(v);}},
        {data:'book_type',name:'book_type',render:function(v,t){return t==='display'?text.display(v==='BOOK'?'บัญชี (Book)':'ภาษี (Tax)'):v;}},
        {data:'asset_count',name:'asset_count',className:'text-end',render:function(v,t){return t==='display'?Number(v||0).toLocaleString():v;}},
        {data:'total_depreciation',name:'total_depreciation',className:'text-end',render:function(v,t){return t==='display'?Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}):v;}},
        {data:'status',name:'status',render:function(v,t){return t==='display'?'<span class="badge '+(badge[v]||'app-badge-soft')+'">'+text.display(labels[v]||v)+'</span>':v;}},
        {data:null,orderable:false,searchable:false,className:'text-end',render:function(v,t,row){return t==='display'?'<a class="btn btn-sm btn-outline-dark" href="'+text.display(row.show_url)+'"><i class="bx bx-show me-1" aria-hidden="true"></i>ดู</a>':'';}}
    ]}));$('#depreciation-filter-status,#depreciation-filter-date-from,#depreciation-filter-date-to,#depreciation-filter-book').on('change',function(){table.ajax.reload();});$('#depreciation-filter-reset').on('click',function(){$('#depreciation-filter-status,#depreciation-filter-date-from,#depreciation-filter-date-to,#depreciation-filter-book').val('');table.ajax.reload();});
});
</script>
@endpush
