@extends('Wms::layout')
@section('title', 'ยอดยกมาสินค้า')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><p class="eyebrow mb-2">WMS / OPENING BALANCE</p><h1 class="h3 mb-2">ยอดยกมาสินค้า</h1><p class="text-secondary mb-0">เตรียมจำนวนและต้นทุนสินค้าเริ่มต้นก่อนรับ–จ่ายจริง</p></div><div class="d-flex gap-2"><a class="btn btn-outline-primary" href="{{ route('wms.opening-balances.template') }}"><i class="bx bx-download me-1" aria-hidden="true"></i>ดาวน์โหลด Template</a><a class="btn btn-dark" href="{{ route('wms.opening-balances.create') }}"><i class="bx bx-plus me-1"></i>สร้างยอดยกมา</a></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="table-responsive"><table class="table table-hover align-middle w-100" id="opening-balances-table" data-url="{{ route('wms.opening-balances.data') }}"><thead><tr><th>วันที่เริ่มต้น</th><th>วิธีต้นทุน</th><th>จำนวนรายการ</th><th class="text-end">มูลค่ารวม</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection
@push('scripts')
<script>$(function(){var t=$('#opening-balances-table'),x=$.fn.dataTable.render.text();t.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:t.data('url'),order:[[0,'desc']],columns:[{data:'cutover_date',name:'cutover_date',render:x.display},{data:'costing_method',name:'costing_method',render:x.display},{data:'line_count',name:'line_count',render:x.display},{data:'total_value',name:'total_value',className:'text-end',render:x.display},{data:'status_label',name:'status',render:x.display},{data:null,orderable:false,searchable:false,className:'text-end',render:function(v,type,row){return type==='display'?'<a class="btn btn-sm btn-outline-dark" href="'+x.display(row.show_url)+'">เปิด</a>':'';}}]}));});</script>
@endpush
