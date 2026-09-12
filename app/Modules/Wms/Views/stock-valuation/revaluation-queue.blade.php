@extends('Wms::layout')
@section('title', 'Revaluation Approval | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><p class="eyebrow mb-2">WMS / COSTING CONTROL</p><h1 class="h3 mb-2">Revaluation Approval Queue</h1><p class="text-secondary mb-0">ตรวจ Shadow Snapshot และ Planned Delta ก่อนอนุมัติ โดยยังไม่แก้ Stock หรือ Journal</p></div><a class="btn btn-outline-secondary" href="{{ route('wms.stock-valuation.index') }}">กลับมูลค่าสินค้า</a></div>
    <div class="alert alert-warning border-0 small"><i class="bx bx-lock-alt me-1" aria-hidden="true"></i>การอนุมัติเป็นเพียงการยืนยันแผน Revaluation; Apply จริงยังปิดด้วย Feature Gate และต้องผ่าน Reconciliation Gate</div>
    <div class="card border-0 shadow-sm mb-3" id="revaluation-filters"><div class="card-body"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h6 mb-1">ตัวกรอง Revaluation Run</h2><p class="small text-secondary mb-0">กรองที่ server-side ตามสถานะและวันที่สร้าง (เวลาไทย UTC+7)</p></div><button type="button" class="btn btn-sm btn-outline-secondary js-wms-reset-filter">ล้างตัวกรอง</button></div><div class="row g-3 align-items-end"><div class="col-12 col-md-4"><label class="form-label" for="revaluation-status-filter">สถานะ</label><select id="revaluation-status-filter" class="form-select js-revaluation-filter"><option value="">ทุกสถานะ</option><option value="PENDING_APPROVAL">รออนุมัติ</option><option value="APPROVED">อนุมัติแล้ว</option><option value="CALCULATING">กำลังคำนวณ</option><option value="WAITING_CONTINUATION">รอประมวลผลต่อ</option><option value="APPLYING">กำลัง Apply</option><option value="STOCK_PROJECTED">ปรับ Stock แล้ว</option><option value="GL_POSTED">ลง Journal แล้ว</option><option value="COMPLETED">เสร็จสมบูรณ์</option><option value="REQUIRES_REVIEW">ต้องตรวจสอบ</option><option value="FAILED_RETRYABLE">รอลองใหม่</option><option value="CANCELLED">ยกเลิกแล้ว</option></select></div><div class="col-12 col-md-3"><label class="form-label" for="revaluation-date-from">วันที่สร้างตั้งแต่</label><input id="revaluation-date-from" class="form-control js-revaluation-filter" type="date"></div><div class="col-12 col-md-3"><label class="form-label" for="revaluation-date-to">ถึงวันที่</label><input id="revaluation-date-to" class="form-control js-revaluation-filter" type="date"></div><div class="col-12 col-md-2"><button type="button" class="btn btn-dark w-100 js-wms-apply-filter">ค้นหา</button></div></div></div></div>
    @if($scopeBatches->isNotEmpty())
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h2 class="h6 mb-0">Scope Batch ที่กำลังเข้าคิว</h2>
                    <span class="text-secondary small">รอ worker สร้าง Revaluation Run ตามแต่ละ partition</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Batch</th><th>สถานะ</th><th>Partitions</th><th>วางแผนแล้ว</th><th>เสร็จแล้ว</th><th>สร้างเมื่อ</th></tr></thead>
                        <tbody>
                        @foreach($scopeBatches as $batch)
                            <tr>
                                <td>#{{ $batch->id }}</td>
                                <td><span class="badge app-status-info">{{ $batch->status }}</span></td>
                                <td>{{ $batch->expected_partitions }}</td>
                                <td>{{ $batch->resolved_root_lines }}</td>
                                <td>{{ $batch->completed_partitions }}</td>
                                <td>{{ optional($batch->created_at)->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
    <div class="card border-0 shadow-sm"><div class="card-body"><table id="revaluation-table" class="table table-hover w-100" data-url="{{ route('wms.stock-valuation.revaluation.data') }}"><thead><tr><th>Run</th><th>Source Allocation</th><th>Source</th><th>วันที่สร้าง</th><th>ต้นทุนใหม่</th><th>Delta โดยประมาณ</th><th>Nodes</th><th>สถานะ</th><th>จัดการ</th></tr></thead></table></div></div>
</div>
@endsection
@push('scripts')
<script>$(function () { var table = $('#revaluation-table'), filters = $('#revaluation-filters'); var dt = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, { processing: true, serverSide: true, ajax: { url: table.data('url'), data: function (d) { d.status = $('#revaluation-status-filter').val(); d.date_from = $('#revaluation-date-from').val(); d.date_to = $('#revaluation-date-to').val(); } }, buttons: [window.erpExcelButton(table)], order: [[3, 'desc']], columns: [{data:'id',name:'id'}, {data:'allocation_label',name:'root_allocation_id',orderable:false}, {data:'source_label',name:'source_label',orderable:false}, {data:'created_at_label',name:'created_at'}, {data:'proposed_unit_cost',name:'proposed_unit_cost'}, {data:'estimated_delta_value',name:'estimated_delta_value'}, {data:'nodes_affected',name:'nodes_affected'}, {data:'status_label',name:'status',orderable:false,searchable:false}, {data:'action',name:'action',orderable:false,searchable:false,className:'text-end'}] })); filters.on('click', '.js-wms-apply-filter', function () { var from = $('#revaluation-date-from').val(), to = $('#revaluation-date-to').val(); if (from && to && from > to) { if (window.Swal) Swal.fire({icon:'error', text:'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด'}); return; } dt.ajax.reload(); }); filters.on('click', '.js-wms-reset-filter', function () { filters.find('select,input').val(''); dt.ajax.reload(); }); });</script>
@endpush
