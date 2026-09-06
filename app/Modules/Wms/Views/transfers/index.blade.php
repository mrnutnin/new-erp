@extends('Wms::layout')
@php($isIncoming = $direction === 'in')
@section('title', ($isIncoming ? 'รับโอนสินค้า' : 'โอนสินค้าออก').' | WMS')
@section('content')
@push('scripts')<script>$(function(){const t=$('#transfers-table'),f=$('#transfer-filters');t.on('preXhr.dt',function(e,s,d){d.status=f.find('.js-wms-filter-status').val();d.date_from=f.find('.js-wms-filter-from').val();d.date_to=f.find('.js-wms-filter-to').val();d.source_branch_id=f.find('.js-wms-filter-source-branch').val();d.destination_branch_id=f.find('.js-wms-filter-destination-branch').val();});f.on('click','.js-wms-apply-filter,.js-wms-reset-filter',function(){if($(this).hasClass('js-wms-reset-filter'))f.find('select,input').val('');t.DataTable().ajax.reload();});});</script>@endpush
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><p class="eyebrow mb-2">WMS / TRANSFER</p><h1 class="h3 mb-2">{{ $isIncoming ? 'รับโอนสินค้าเข้า' : 'โอนสินค้าออก' }}</h1><p class="text-secondary mb-0">{{ $isIncoming ? 'ตรวจสอบและรับสินค้าจากคลังต้นทาง โดยรักษาต้นทุนเดิม' : 'สร้างรายการส่งสินค้าออกจากคลังปัจจุบันไปยังคลังปลายทาง' }}</p></div><div class="d-flex flex-wrap align-items-end gap-2">@include('Wms::partials.warehouse-selector') @if(!$isIncoming && auth()->user()->hasPermission('wms.transfers.create'))<a class="btn btn-app-primary" href="{{ route('wms.transfers.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบโอนออก</a>@endif</div></div>
    @include('Wms::partials.document-filters', ['filterId' => 'transfer-filters', 'statusOptions' => ['DRAFT' => 'ร่าง', 'DISPATCHED' => 'ส่งออกแล้ว', 'PARTIALLY_ACCEPTED' => 'รับบางส่วน', 'ACCEPTED' => 'รับครบแล้ว', 'REJECTED' => 'ปฏิเสธ', 'VOID' => 'ยกเลิก'], 'branchOptions' => $branches])
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive"><table id="transfers-table" class="table table-hover align-middle w-100" data-url="{{ route($isIncoming ? 'wms.transfers.incoming.data' : 'wms.transfers.outgoing.data') }}"><thead><tr><th>เลขที่</th><th>วันที่</th><th>ต้นทาง</th><th>ปลายทาง</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    var table = $('#transfers-table'), token = '{{ csrf_token() }}', escape = $.fn.dataTable.render.text();
    var statusClasses = {DRAFT:'app-status-neutral', DISPATCHED:'app-status-info', PARTIALLY_ACCEPTED:'app-status-warning', ACCEPTED:'app-status-success', REJECTED:'app-status-danger', VOID:'app-status-neutral'};
    var dataTable = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: table.data('url'), order: [[1, 'desc'], [0, 'desc']], buttons: [window.erpExcelButton(table)], columns: [
            {data:'document_number', render:escape}, {data:'document_date', render:escape}, {data:'source_label', render:escape}, {data:'destination_label', render:escape},
            {data:'status_label', render:function (value, type, row) { return '<span class="badge '+(statusClasses[row.status] || 'app-status-neutral')+'">'+escape.display(value || '-')+'</span>'; }},
            {data:null, orderable:false, searchable:false, className:'text-end', render:function (value, type, row) {
                var html = '<a title="ดูรายละเอียด" aria-label="ดูรายละเอียด" class="btn btn-sm btn-app-soft me-1" href="'+escape.display(row.detail_url)+'"><i class="bx bx-show" aria-hidden="true"></i></a>';
                if (row.can_dispatch) html += '<button title="ส่งออก" aria-label="ส่งออก" class="btn btn-sm btn-app-soft js-transfer-dispatch me-1" data-url="{{ url('/wms/transfers') }}/'+row.id+'/dispatch"><i class="bx bx-send"></i></button>';
                if (row.receive_url) html += '<a title="เปิดหน้ารับโอน" aria-label="เปิดหน้ารับโอน" class="btn btn-sm btn-app-soft" href="'+escape.display(row.receive_url)+'"><i class="bx bx-check-circle"></i></a>';
                if (row.void_url) html += '<button title="ยกเลิกรายการที่ถูกปฏิเสธ" aria-label="ยกเลิกรายการที่ถูกปฏิเสธ" class="btn btn-sm btn-app-soft text-danger js-transfer-void ms-1" data-url="'+escape.display(row.void_url)+'"><i class="bx bx-undo"></i></button>';
                return html || '<span class="text-secondary">-</span>';
            }}
        ]
    }));
    function post($button, payload) { $button.prop('disabled', true); return $.ajax({url:$button.data('url'), method:'POST', data:$.extend({_token:token}, payload)}).always(function () {$button.prop('disabled', false);}); }
    table.on('click', '.js-transfer-dispatch', function () {
        var button = $(this);
        Swal.fire({title:'ส่ง Transfer ออก?', html:'<label class="form-label text-start d-block">วันที่ส่งออก</label><input id="transfer-business-date" class="form-control" type="date" value="'+new Date().toISOString().slice(0,10)+'"><label class="form-label text-start d-block mt-3">เหตุผล</label><input id="transfer-reason" class="form-control" placeholder="ระบุเหตุผลการส่งออก">', showCancelButton:true, confirmButtonText:'ส่งออก', cancelButtonText:'ยกเลิก', focusConfirm:false, preConfirm:function () { var reason=$('#transfer-reason').val().trim(); if (!reason) { Swal.showValidationMessage('กรุณาระบุเหตุผล'); return false; } return {reason:reason, business_date:$('#transfer-business-date').val()}; }}).then(function (result) {
            if (!result.isConfirmed) return;
            post(button, result.value).done(function (response) { Swal.fire({icon:response.status?'success':'error', text:response.msg||'ดำเนินการแล้ว', timer:1600, showConfirmButton:false}); if (response.status) dataTable.ajax.reload(null,false); }).fail(function (xhr) { Swal.fire({icon:'error', text:xhr.responseJSON?.message||'ไม่สามารถดำเนินการได้'}); });
        });
    });
    table.on('click', '.js-transfer-void', function () {
        var button = $(this);
        Swal.fire({title:'ยกเลิก Transfer ที่ถูกปฏิเสธ?', html:'<label class="form-label text-start d-block">เหตุผลการยกเลิก</label><textarea id="transfer-void-reason" class="form-control" rows="3" placeholder="ระบุเหตุผลอย่างน้อย 10 ตัวอักษร"></textarea>', showCancelButton:true, confirmButtonText:'ยกเลิกเอกสาร', cancelButtonText:'กลับ', confirmButtonColor:'#dc3545', focusConfirm:false, preConfirm:function () { var reason=$('#transfer-void-reason').val().trim(); if (reason.length < 10) { Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร'); return false; } return {reason:reason}; }}).then(function (result) {
            if (!result.isConfirmed) return;
            post(button, result.value).done(function (response) { Swal.fire({icon:response.status?'success':'error', text:response.msg||'ดำเนินการแล้ว', timer:1600, showConfirmButton:false}); if (response.status) dataTable.ajax.reload(null,false); }).fail(function (xhr) { Swal.fire({icon:'error', text:xhr.responseJSON?.message||'ไม่สามารถยกเลิกได้'}); });
        });
    });
});
</script>
@endpush
