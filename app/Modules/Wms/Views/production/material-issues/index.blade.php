@extends('Wms::layout')

@section('title', 'เบิกวัตถุดิบผลิต | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / PRODUCTION MATERIAL ISSUE</p><h1 class="h3 mb-2">เบิกวัตถุดิบผลิต</h1><p class="text-secondary mb-0">เบิกวัตถุดิบเพื่อการผลิต แล้วดำเนินการอนุมัติและลง Stock จากหน้า Detail</p></div>
        @if(auth()->user()->hasPermission('wms.issues.create'))<a class="btn btn-app-primary" href="{{ route('wms.production.material-issues.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบเบิกวัตถุดิบ</a>@endif
    </div>
    @include('Wms::partials.document-filters', ['filterId' => 'production-issue-filters', 'statusOptions' => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิกเอกสาร']])
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="mb-3"><h2 class="h5 mb-1">รายการใบเบิกวัตถุดิบ</h2><p class="text-secondary small mb-0">เปิดรายละเอียดเพื่อดำเนินการต่อหรือสร้างเอกสารที่เกี่ยวข้อง</p></div><div class="table-responsive"><table id="production-issue-table" class="table table-hover align-middle w-100"><thead><tr><th>ลำดับ</th><th>เลขที่เอกสาร</th><th>วันที่</th><th>รายการ</th><th>จำนวน</th><th>ใบรับผลิตเสร็จ</th><th>เหตุผล</th><th>สถานะ</th><th>จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const tableElement = $('#production-issue-table'), filters = $('#production-issue-filters'), text = $.fn.dataTable.render.text();
    const statuses = {DRAFT:'app-status-neutral', APPROVED:'app-status-info', POSTED:'app-status-success', VOID:'app-status-danger'};
    const table = tableElement.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        processing:true, serverSide:true, order:[[2,'desc']],
        ajax:{url:'{{ route('wms.production.material-issues.data') }}',data:function(data){data.status=filters.find('.js-wms-filter-status').val();data.date_from=filters.find('.js-wms-filter-from').val();data.date_to=filters.find('.js-wms-filter-to').val();}},
        buttons:[window.erpExcelButton(tableElement)],
        columns:[
            window.erpRowNumberColumn(),
            {data:'document_number',name:'document_number',render:text.display}, {data:'business_date',name:'document_date',render:text.display},
            {data:'line_count',orderable:false,render:function(value,type){return type==='display'?text.display(value+' รายการ'):value;}}, {data:'quantity',orderable:false,className:'text-end',render:text.display},
            {data:'finished_receipt_label',orderable:false,render:text.display}, {data:'reason',name:'reason',render:text.display},
            {data:'status_label',name:'status',render:function(value,type,row){return type==='display'?'<span class="badge '+(statuses[row.status]||'app-status-neutral')+'">'+text.display(value)+'</span>':value;}},
            {data:null,orderable:false,searchable:false,className:'text-end text-nowrap',render:function(value,type,row){if(type!=='display')return '';let html='<a class="btn btn-sm btn-app-soft" href="'+text.display(row.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด '+text.display(row.document_number)+'"><i class="bx bx-file-find" aria-hidden="true"></i></a>';if(row.can_edit)html+=' <a class="btn btn-sm btn-app-soft" href="'+text.display(row.edit_url)+'" title="แก้ไข" aria-label="แก้ไข '+text.display(row.document_number)+'"><i class="bx bx-edit" aria-hidden="true"></i></a>';if(row.can_delete)html+=' <button type="button" class="btn btn-sm btn-app-danger js-production-issue-delete" data-url="'+row.delete_url+'" title="ลบร่าง" aria-label="ลบร่าง '+text.display(row.document_number)+'"><i class="bx bx-trash" aria-hidden="true"></i></button>';return html;}}
        ]
    }));
    filters.on('click','.js-wms-apply-filter',()=>table.ajax.reload());
    filters.on('click','.js-wms-reset-filter',function(){filters.find('select,input').val('');table.ajax.reload();});
    window.erpAjaxDelete({button:'.js-production-issue-delete',reload:'#production-issue-table',title:'ลบร่างใบเบิกวัตถุดิบ?',text:'เอกสารร่างจะถูกลบและไม่สามารถกู้คืนได้',confirmButtonText:'ลบร่าง',cancelButtonText:'กลับ'});
});
</script>
@endpush
