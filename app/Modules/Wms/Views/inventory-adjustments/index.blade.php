@extends('Wms::layout')

@section('title', 'ปรับปรุงสินค้าคงเหลือ | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / INVENTORY ADJUSTMENT</p><h1 class="h3 mb-2">ปรับปรุงสินค้าคงเหลือ</h1><p class="text-secondary mb-0">บันทึกการเพิ่มหรือลดสินค้า แล้วอนุมัติและลง Stock จากหน้า Detail</p></div>
        <div class="d-flex flex-wrap gap-2 align-items-center">@include('Wms::partials.warehouse-selector') @if(auth()->user()->hasPermission('wms.inventory-adjustments.create'))<a class="btn btn-app-primary" href="{{ route('wms.inventory-adjustments.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างรายการปรับปรุง</a>@endif</div>
    </div>
    @include('Wms::partials.document-filters', ['filterId' => 'adjustment-filters', 'statusOptions' => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock และบัญชีแล้ว', 'VOID' => 'ยกเลิกเอกสาร', 'REVERSED' => 'ยกเลิกเอกสารแล้ว']])
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="mb-3"><h2 class="h5 mb-1">รายการปรับปรุงสินค้าคงเหลือ</h2><p class="text-secondary small mb-0">เปิดรายละเอียดเพื่อแก้ไข อนุมัติ ลง Stock และบัญชี หรือยกเลิกเอกสาร</p></div><div class="table-responsive"><table id="adjustment-table" class="table table-hover align-middle w-100"><thead><tr><th>เลขที่เอกสาร</th><th>วันที่</th><th>รายการ</th><th>ทิศทาง</th><th>จำนวนรวม</th><th>มูลค่ารวม</th><th>เหตุผล</th><th>สถานะ</th><th>จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function(){
 const tableElement=$('#adjustment-table'),filters=$('#adjustment-filters'),text=$.fn.dataTable.render.text(),statuses={DRAFT:'app-status-neutral',APPROVED:'app-status-info',POSTED:'app-status-success',VOID:'app-status-danger',REVERSED:'app-status-warning'};
 const table=tableElement.DataTable($.extend(true,{},window.erpDataTableDefaults,{processing:true,serverSide:true,order:[[1,'desc']],ajax:{url:'{{ route('wms.inventory-adjustments.data') }}',data:function(data){data.status=filters.find('.js-wms-filter-status').val();data.date_from=filters.find('.js-wms-filter-from').val();data.date_to=filters.find('.js-wms-filter-to').val();}},buttons:[window.erpExcelButton(tableElement)],columns:[
  {data:'document_number',name:'document_number',render:text.display},{data:'business_date',name:'document_date',render:text.display},{data:'item_label',orderable:false,render:function(value,type,row){return type==='display'?'<div>'+text.display(row.line_count+' รายการ')+'</div><small class="text-secondary d-block text-truncate" style="max-width:280px">'+text.display(value)+'</small>':value;}},{data:'direction_label',orderable:false,render:text.display},{data:'quantity',orderable:false,className:'text-end',render:text.display},{data:'value',orderable:false,className:'text-end',render:text.display},{data:'reason',name:'reason',render:text.display},{data:'status_label',name:'status',render:function(value,type,row){return type==='display'?'<span class="badge '+(statuses[row.status]||'app-status-neutral')+'">'+text.display(value)+'</span>':value;}},{data:null,orderable:false,searchable:false,className:'text-end text-nowrap',render:function(value,type,row){if(type!=='display')return '';let html='<a class="btn btn-sm btn-app-soft" href="'+row.show_url+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด '+text.display(row.document_number)+'"><i class="bx bx-file-find" aria-hidden="true"></i></a>';if(row.can_delete)html+=' <button type="button" class="btn btn-sm btn-app-danger js-adjustment-delete" data-url="'+row.delete_url+'" title="ลบร่าง" aria-label="ลบร่าง '+text.display(row.document_number)+'"><i class="bx bx-trash" aria-hidden="true"></i></button>';return html;}}
 ]}));
 filters.on('click','.js-wms-apply-filter',()=>table.ajax.reload());filters.on('click','.js-wms-reset-filter',function(){filters.find('select,input').val('');table.ajax.reload();});window.erpAjaxDelete({button:'.js-adjustment-delete',reload:'#adjustment-table',title:'ลบร่างรายการปรับปรุง?',text:'เอกสารร่างจะถูกลบและไม่สามารถกู้คืนได้',confirmButtonText:'ลบร่าง',cancelButtonText:'กลับ'});
});
</script>
@endpush
