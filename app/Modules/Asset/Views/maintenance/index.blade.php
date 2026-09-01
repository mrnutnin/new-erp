@extends('Asset::layout')

@section('title', 'แจ้งซ่อมและบำรุงรักษา | New ERP')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">ASSET / MAINTENANCE</p><h1 class="h3 mb-1">แจ้งซ่อมและบำรุงรักษา</h1><p class="text-secondary mb-0">ติดตามงานซ่อม ค่าใช้จ่าย ผู้รับผิดชอบ และเวลาหยุดใช้งาน โดยไม่ลงบัญชีซ้ำ</p></div>
        <div class="d-flex gap-2"><a class="btn btn-outline-dark" href="{{ route('asset.maintenance.schedules.index') }}"><i class="bx bx-calendar-check me-1" aria-hidden="true"></i>แผนบำรุงรักษา</a>@if(auth()->user()->hasPermission('asset.maintenance.create'))<a class="btn btn-dark" href="{{ route('asset.maintenance.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>แจ้งซ่อม</a>@endif</div>
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h6 mb-3">ตัวกรอง</h2><div class="row g-3"><div class="col-12 col-md-3"><label class="form-label" for="maintenance-status">สถานะ</label><select class="form-select" id="maintenance-status"><option value="">ทุกสถานะ</option><option value="OPEN">เปิดงาน</option><option value="ASSIGNED">มอบหมายแล้ว</option><option value="IN_PROGRESS">กำลังซ่อม</option><option value="WAITING_PARTS">รออะไหล่</option><option value="COMPLETED">ปิดงานแล้ว</option><option value="CANCELLED">ยกเลิก</option></select></div><div class="col-12 col-md-3"><label class="form-label" for="maintenance-priority">ความเร่งด่วน</label><select class="form-select" id="maintenance-priority"><option value="">ทุกระดับ</option><option value="LOW">ต่ำ</option><option value="NORMAL">ปกติ</option><option value="HIGH">สูง</option><option value="CRITICAL">วิกฤต</option></select></div><div class="col-12 col-md-3"><label class="form-label" for="maintenance-date-from">วันที่แจ้งตั้งแต่</label><input class="form-control" id="maintenance-date-from" type="date"></div><div class="col-12 col-md-3"><label class="form-label" for="maintenance-date-to">วันที่แจ้งถึง</label><input class="form-control" id="maintenance-date-to" type="date"></div><div class="col-12 col-md-2 ms-auto"><button class="btn btn-outline-secondary w-100" id="maintenance-filter-reset" type="button">ล้างตัวกรอง</button></div></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="table-responsive"><table class="table table-hover align-middle w-100" id="maintenance-table" data-url="{{ route('asset.maintenance.data') }}"><thead class="table-light"><tr><th>เลขที่เอกสาร</th><th>วันที่แจ้ง</th><th>สินทรัพย์</th><th>ความเร่งด่วน</th><th>ผู้รับผิดชอบ</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var $table=$('#maintenance-table'), text=$.fn.dataTable.render.text(), labels={OPEN:'เปิดงาน',ASSIGNED:'มอบหมายแล้ว',IN_PROGRESS:'กำลังซ่อม',WAITING_PARTS:'รออะไหล่',COMPLETED:'ปิดงานแล้ว',CANCELLED:'ยกเลิก'}, badges={OPEN:'app-status-neutral',ASSIGNED:'app-status-info',IN_PROGRESS:'app-status-warning',WAITING_PARTS:'app-status-warning',COMPLETED:'app-status-success',CANCELLED:'app-status-danger'}, priorities={LOW:'ต่ำ',NORMAL:'ปกติ',HIGH:'สูง',CRITICAL:'วิกฤต'};
    var table=$table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:$table.data('url'),data:function(data){data.status=$('#maintenance-status').val();data.priority=$('#maintenance-priority').val();data.date_from=$('#maintenance-date-from').val();data.date_to=$('#maintenance-date-to').val();}},order:[[1,'desc']],buttons:[window.erpExcelButton($table)],columns:[
        {data:'document_number',name:'document_number',render:text.display},{data:'reported_date_label',name:'reported_date',render:text.display},{data:'asset_label',orderable:false,render:text.display},
        {data:'priority',render:function(value,type){return type==='display'?text.display(priorities[value]||value):value;}},{data:'assigned_to_label',orderable:false,render:text.display},
        {data:'status',render:function(value,type){return type==='display'?'<span class="badge '+(badges[value]||'app-badge-soft')+'">'+text.display(labels[value]||value)+'</span>':value;}},
        {data:'show_url',orderable:false,searchable:false,className:'text-end',render:function(value,type){return type==='display'?'<a class="btn btn-sm btn-outline-dark" href="'+text.display(value)+'">ดู</a>':value;}}
    ]}));
    $('#maintenance-status,#maintenance-priority,#maintenance-date-from,#maintenance-date-to').on('change',function(){table.ajax.reload();});$('#maintenance-filter-reset').on('click',function(){$('#maintenance-status,#maintenance-priority,#maintenance-date-from,#maintenance-date-to').val('');table.ajax.reload();});
});
</script>
@endpush
