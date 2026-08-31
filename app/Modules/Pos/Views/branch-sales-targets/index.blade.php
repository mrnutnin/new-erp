@extends('Pos::layout')

@section('title', 'เป้ายอดขายสาขา | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><p class="eyebrow mb-2">POS / TARGETS</p><h1 class="h3 mb-2">เป้ายอดขายสาขา</h1><p class="text-secondary mb-0">กำหนดเป้ายอดขายและกำไรขั้นต้นของสาขาที่กำลังใช้งาน เพื่อใช้เทียบผลงานในรายงาน</p></div>@if(auth()->user()->hasPermission('pos.branch-sales-targets.create'))<a class="btn btn-dark" href="{{ route('pos.branch-sales-targets.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มเป้าสาขา</a>@endif</div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive"><table id="branch-sales-targets-table" class="table table-hover align-middle w-100" data-url="{{ route('pos.branch-sales-targets.data') }}"><thead><tr><th>เริ่มต้น</th><th>สิ้นสุด</th><th class="text-end">เป้ายอดขาย</th><th class="text-end">เป้ากำไรขั้นต้น</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>$(function(){var table=$('#branch-sales-targets-table'),text=$.fn.dataTable.render.text(),money=$.fn.dataTable.render.number(',', '.', 2);table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:table.data('url'),order:[[0,'desc']],buttons:[window.erpExcelButton(table)],columns:[{data:'period_start',render:text.display},{data:'period_end',render:text.display},{data:'target_sales_amount',className:'text-end',render:function(v){return v===null?'—':money.display(v);}},{data:'target_gross_profit_amount',className:'text-end',render:function(v){return v===null?'—':money.display(v);}},{data:null,orderable:false,searchable:false,className:'text-end',render:function(v,t,row){if(t!=='display')return '';var a=[];if(row.edit_url)a.push('<a class="btn btn-sm btn-outline-dark" href="'+text.display(row.edit_url)+'" title="แก้ไข"><i class="bx bx-edit-alt" aria-hidden="true"></i><span class="visually-hidden">แก้ไข</span></a>');if(row.delete_url)a.push('<button class="btn btn-sm btn-outline-danger js-delete-target" type="button" data-url="'+text.display(row.delete_url)+'" title="ลบ"><i class="bx bx-trash" aria-hidden="true"></i><span class="visually-hidden">ลบ</span></button>');return a.join(' ');}}]}));window.erpAjaxDelete({button:'.js-delete-target',reload:'#branch-sales-targets-table',confirm:'ยืนยันการลบเป้ายอดขายสาขานี้หรือไม่?'});});</script>
@endpush
