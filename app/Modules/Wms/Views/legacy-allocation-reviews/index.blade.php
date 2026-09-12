@extends('Wms::layout')

@section('title', 'Legacy Allocation Review | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / COSTING CONTROL</p><h1 class="h3 mb-2">ตรวจสอบ Legacy Allocation</h1><p class="text-secondary mb-0">ตรวจหลักฐาน Allocation รุ่นเก่าที่ไม่สอดคล้องกับเอกสารหรือ Journal ก่อนตัดสินใจแก้ไข</p></div>
        <span class="badge app-status-warning px-3 py-2"><i class="bx bx-time-five me-1" aria-hidden="true"></i>เปิดรอตรวจสอบ</span>
    </div>
    <div class="alert alert-warning border-0 shadow-sm"><i class="bx bx-shield-quarter me-2" aria-hidden="true"></i><strong>หน้านี้ใช้เมื่อพบข้อมูลต้นทุน Legacy ไม่ตรงกัน</strong> — เช่นวันที่ Movement ไม่ตรง Document date, Allocation ซ้ำ หรือไม่มี Journal proof โดยระบบจะยังไม่แก้ข้อมูลให้อัตโนมัติ</div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ต้องทำอะไรในหน้านี้?</h2><p class="small text-secondary mb-0">ให้ Accounting ตรวจหลักฐานก่อนอนุมัติการแก้ไข Legacy</p></div><span class="badge app-status-info">Accounting Review</span></div><div class="row g-3"><div class="col-md-4"><div class="border rounded p-3 h-100"><div class="fw-semibold mb-1"><span class="badge rounded-pill text-bg-secondary me-1">1</span>เปิดตรวจหลักฐาน</div><div class="small text-secondary">เลือก Allocation ที่ต้องตรวจ แล้วกด “เปิดตรวจหลักฐาน”</div></div></div><div class="col-md-4"><div class="border rounded p-3 h-100"><div class="fw-semibold mb-1"><span class="badge rounded-pill text-bg-secondary me-1">2</span>เปรียบเทียบข้อมูล</div><div class="small text-secondary">ตรวจ Document date, Movement, Cost Layer, Journal และ Evidence hash</div></div></div><div class="col-md-4"><div class="border rounded p-3 h-100"><div class="fw-semibold mb-1"><span class="badge rounded-pill text-bg-secondary me-1">3</span>ยืนยันการแก้ไข</div><div class="small text-secondary">ดำเนินการเฉพาะเมื่อมีหลักฐานและ approval จาก Accounting ระบบจะบันทึก Audit Log</div></div></div></div></div></div>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">ขอบเขตข้อมูล</div><div class="h5 mb-1">คลังปัจจุบัน</div><div class="small text-secondary">แสดงเฉพาะ Review ที่ผูกกับ Warehouse ที่เลือก</div></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">สถานะที่แสดง</div><div class="h5 mb-1 text-warning">OPEN</div><div class="small text-secondary">รายการที่รอ Accounting/ผู้มีสิทธิ์ตรวจสอบ</div></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">ขั้นตอนถัดไป</div><div class="h5 mb-1">เปิดตรวจหลักฐาน</div><div class="small text-secondary">ตรวจ Movement, Journal, Cost Layer และ Evidence hash</div></div></div></div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">รายการที่ต้องตรวจสอบ</h2><p class="small text-secondary mb-0">ค้นหาและเรียงลำดับได้จากตารางด้านล่าง</p></div><span class="small text-secondary">ข้อมูลโหลดแบบ server-side</span></div>
            <div class="table-responsive"><table id="legacy-review-table" class="table table-hover align-middle w-100" data-url="{{ route('wms.legacy-allocation-reviews.data') }}"><thead><tr><th>Allocation / Revision</th><th>Warehouse</th><th>สินค้า</th><th>Movement</th><th>สถานะ</th><th>จัดการ</th></tr></thead></table></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () { var table = $('#legacy-review-table'); table.DataTable($.extend(true, {}, window.erpDataTableDefaults, { processing: true, serverSide: true, ajax: table.data('url'), buttons: [window.erpExcelButton(table)], columns: [
 {data:'allocation_label',name:'allocation_id'}, {data:'warehouse_label',name:'warehouse_label',orderable:false}, {data:'item_label',name:'item_label',orderable:false}, {data:'movement_label',name:'movement_label',orderable:false}, {data:'status_label',name:'status',orderable:false,searchable:false}, {data:'action',name:'action',orderable:false,searchable:false,className:'text-end'}
] })); });
</script>
@endpush
