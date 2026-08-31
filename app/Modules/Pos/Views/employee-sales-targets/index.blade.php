@extends('Pos::layout')

@section('title', 'เป้าหมายพนักงาน | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex justify-content-between align-items-end gap-3 mb-4"><div><p class="eyebrow mb-2">POS / SALES / MASTER DATA</p><h1 class="h3 mb-2">เป้าหมายพนักงาน</h1><p class="text-secondary mb-0">กำหนดเป้ายอดขายและกำไรขั้นต้นของพนักงานในสาขาปัจจุบันตามช่วงเวลา</p></div>@if(auth()->user()->hasPermission('pos.employee-sales-targets.create'))<a class="btn btn-dark" href="{{ route('pos.employee-sales-targets.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มเป้าหมาย</a>@endif</div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive"><table id="employee-sales-targets-table" class="table table-hover align-middle w-100" data-url="{{ route('pos.employee-sales-targets.data') }}"><thead><tr><th>เริ่มงวด</th><th>สิ้นสุดงวด</th><th>พนักงาน</th><th class="text-end">เป้ายอดขาย</th><th class="text-end">เป้ากำไรขั้นต้น</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function(){var $table=$('#employee-sales-targets-table'),text=$.fn.dataTable.render.text(),money=$.fn.dataTable.render.number(',', '.', 2),table=$table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:$table.data('url'),order:[[0,'desc']],buttons:[window.erpExcelButton($table)],columns:[{data:'period_start',render:text.display},{data:'period_end',render:text.display},{data:'employee_label',render:text.display},{data:'sales_target',className:'text-end',render:money},{data:'gross_profit_target',className:'text-end',render:money},{data:null,orderable:false,searchable:false,className:'text-end',render:function(v,t,row){if(t!=='display')return '';var a=[];if(row.edit_url)a.push('<a class="btn btn-sm btn-outline-dark" title="แก้ไข" href="'+text.display(row.edit_url)+'"><i class="bx bx-edit-alt" aria-hidden="true"></i><span class="visually-hidden">แก้ไข</span></a>');if(row.delete_url)a.push('<button class="btn btn-sm btn-outline-danger js-delete-target" type="button" title="ลบ" data-url="'+text.display(row.delete_url)+'"><i class="bx bx-trash" aria-hidden="true"></i><span class="visually-hidden">ลบ</span></button>');return a.join(' ');}}]}));window.erpAjaxDelete({button:'.js-delete-target',reload:'#employee-sales-targets-table',confirm:'ยืนยันการลบเป้าหมายพนักงานนี้หรือไม่?'});});
</script>
@endpush
