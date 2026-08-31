@extends('Wms::layout')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div><div class="eyebrow">WMS / CONTROL</div><h1 class="h3 mb-1">รายการ Cost Allocation ที่ต้องตรวจสอบ</h1><p class="text-secondary mb-0">รายการ Legacy ที่พบความไม่สอดคล้อง — หน้านี้อ่านอย่างเดียว ห้ามแก้ไขหรือลบข้อมูลต้นฉบับ</p></div>
    </div>
    <div class="alert alert-warning border-0 shadow-sm"><i class="bx bx-shield-quarter me-2" aria-hidden="true"></i>การตรวจสอบจะไม่เปลี่ยนสถานะ Allocation, Journal, Movement หรือ Cost Layer โดยอัตโนมัติ ต้องมีการตัดสินใจและหลักฐานตามขั้นตอนควบคุมภายหลัง</div>
    <div class="card border-0 shadow-sm"><div class="card-body"><table id="legacy-review-table" class="table table-hover w-100" data-url="{{ route('wms.legacy-allocation-reviews.data') }}"><thead><tr><th>Allocation</th><th>สินค้า</th><th>Movement</th><th>สถานะ</th><th>Revision</th><th>หลักฐาน</th></tr></thead></table></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () { var table = $('#legacy-review-table'); table.DataTable($.extend(true, {}, window.erpDataTableDefaults, { processing: true, serverSide: true, ajax: table.data('url'), buttons: [window.erpExcelButton(table)], columns: [
 {data:'allocation_label',name:'allocation_id'}, {data:'item_label',name:'item_label',orderable:false}, {data:'movement_label',name:'movement_label',orderable:false}, {data:'status_label',name:'status',orderable:false}, {data:'revision',name:'revision'}, {data:'action',name:'action',orderable:false,searchable:false,className:'text-end'}
] })); });
</script>
@endpush
