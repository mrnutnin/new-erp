@extends('Asset::layout')

@section('title', 'ใบรับรู้สินทรัพย์ | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4"><div><p class="eyebrow mb-2">ASSET / CAPITALIZATION</p><h1 class="h3 mb-2">ใบรับรู้สินทรัพย์</h1><p class="text-secondary mb-0">รับรู้ต้นทุนจากใบซื้อหรือยอดยกมาเข้าสู่สินทรัพย์</p></div>@if (auth()->user()->hasPermission('asset.capitalizations.create'))<a class="btn btn-dark" href="{{ route('asset.capitalizations.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบรับรู้</a>@endif</div>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h6 mb-3">ตัวกรอง</h2><div class="row g-3"><div class="col-12 col-md-3"><label class="form-label" for="capitalization-filter-status">สถานะ</label><select class="form-select" id="capitalization-filter-status"><option value="">ทุกสถานะ</option><option value="DRAFT">ร่าง</option><option value="SUBMITTED">รออนุมัติ</option><option value="APPROVED">อนุมัติแล้ว</option><option value="POSTED">ลงบัญชีแล้ว</option><option value="REVERSED">กลับรายการแล้ว</option><option value="VOID">ยกเลิก</option></select></div><div class="col-12 col-md-3"><label class="form-label" for="capitalization-filter-source-type">แหล่งต้นทุน</label><select class="form-select" id="capitalization-filter-source-type"><option value="">ทุกแหล่งต้นทุน</option><option value="PURCHASE_DOCUMENT">ใบแจ้งหนี้ซื้อ</option><option value="PAYMENT_VOUCHER">ใบสำคัญจ่าย</option><option value="OPENING">ยอดยกมา</option><option value="MANUAL_RECLASS">ตั้งทุนใหม่</option></select></div><div class="col-12 col-md-3"><label class="form-label" for="capitalization-filter-date-from">ตั้งแต่วันที่</label><input class="form-control" id="capitalization-filter-date-from" type="date"></div><div class="col-12 col-md-3"><label class="form-label" for="capitalization-filter-date-to">ถึงวันที่</label><input class="form-control" id="capitalization-filter-date-to" type="date"></div><div class="col-12 col-md-2 ms-auto"><button class="btn btn-outline-secondary w-100" id="capitalization-filter-reset" type="button">ล้างตัวกรอง</button></div></div></div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="table-responsive"><table class="table table-hover align-middle w-100" id="capitalizations-table" data-url="{{ route('asset.capitalizations.data') }}"><thead><tr><th>เลขที่เอกสาร</th><th>วันที่</th><th>แหล่งต้นทุน</th><th>จำนวนรายการ</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    var $table=$('#capitalizations-table'),text=$.fn.dataTable.render.text(),statuses={DRAFT:'ร่าง',SUBMITTED:'รออนุมัติ',APPROVED:'อนุมัติแล้ว',POSTED:'ลงบัญชีแล้ว',REVERSED:'กลับรายการแล้ว',VOID:'ยกเลิก'},badge={DRAFT:'app-badge-soft',SUBMITTED:'app-badge-info',APPROVED:'app-badge-info',POSTED:'app-badge-success',REVERSED:'app-status-danger',VOID:'app-status-danger'};
    var table=$table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:$table.data('url'),data:function(data){data.status=$('#capitalization-filter-status').val();data.source_type=$('#capitalization-filter-source-type').val();data.document_date_from=$('#capitalization-filter-date-from').val();data.document_date_to=$('#capitalization-filter-date-to').val();}},order:[[1,'desc']],buttons:[window.erpExcelButton($table)],columns:[
        {data:'document_number',name:'document_number',render:text.display},
        {data:'document_date',name:'document_date',render:function(v,t){if(t!=='display'||!v)return v;var d=String(v).slice(0,10).split('-');return d.length===3?d[2]+'/'+d[1]+'/'+d[0]:text.display(v);}},
        {data:'source_type',name:'source_type',render:function(v,t){return t==='display'?text.display(v==='PURCHASE_DOCUMENT'?'ใบแจ้งหนี้ซื้อ':v==='OPENING'?'ยอดยกมา':v==='MANUAL_RECLASS'?'ตั้งทุนใหม่':v):v;}},
        {data:'lines_count',name:'lines_count',searchable:false},
        {data:'status',name:'status',render:function(v,t){return t==='display'?'<span class="badge '+(badge[v]||'app-badge-soft')+'">'+text.display(statuses[v]||v)+'</span>':v;}},
        {data:null,orderable:false,searchable:false,className:'text-end',render:function(v,t,row){if(t!=='display')return '';var actions=['<a class="btn btn-sm btn-outline-dark" href="'+text.display(row.show_url)+'"><i class="bx bx-show me-1" aria-hidden="true"></i>ดู</a>'];if(row.delete_url)actions.push('<button class="btn btn-sm btn-outline-danger js-delete-capitalization" data-url="'+text.display(row.delete_url)+'" type="button"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ</button>');return actions.join(' ');}}
    ]}));
    $('#capitalization-filter-status,#capitalization-filter-source-type,#capitalization-filter-date-from,#capitalization-filter-date-to').on('change',function(){table.ajax.reload();});$('#capitalization-filter-reset').on('click',function(){$('#capitalization-filter-status,#capitalization-filter-source-type,#capitalization-filter-date-from,#capitalization-filter-date-to').val('');table.ajax.reload();});
    window.erpAjaxDelete({button:'.js-delete-capitalization',reload:'#capitalizations-table',confirm:'ยืนยันการลบใบรับรู้สินทรัพย์ร่างนี้หรือไม่?'});
});
</script>
@endpush
