@extends('Pos::layout')

@section('title', 'กลุ่มลูกค้า | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div><p class="eyebrow mb-2">POS / MASTER DATA</p><h1 class="h3 mb-2">กลุ่มลูกค้า</h1><p class="text-secondary mb-0">จัดกลุ่มลูกค้าเพื่อใช้ร่วมกับ Credit และ Price List ในขั้นถัดไป</p></div>
        @if (auth()->user()->hasPermission('pos.customer-groups.create'))
            <a class="btn btn-dark" href="{{ route('pos.customer-groups.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มกลุ่มลูกค้า</a>
        @endif
    </div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive">
        <table id="customer-groups-table" class="table table-hover align-middle w-100" data-url="{{ route('pos.customer-groups.data') }}" data-can-update="{{ auth()->user()->hasPermission('pos.customer-groups.update') ? 1 : 0 }}" data-can-delete="{{ auth()->user()->hasPermission('pos.customer-groups.delete') ? 1 : 0 }}">
            <thead><tr><th>รหัส</th><th>ชื่อกลุ่ม</th><th>ลูกค้าที่ใช้</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
        </table>
    </div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var table = $('#customer-groups-table'), text = $.fn.dataTable.render.text();
    table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: table.data('url'), order: [[0, 'asc']], buttons: [window.erpExcelButton(table)],
        columns: [
            {data:'code', name:'code', render:text.display},
            {data:'name', name:'name', render:text.display},
            {data:'party_count', name:'party_count', className:'text-end'},
            {data:'is_active', name:'is_active', render:function(v,t){ return t === 'display' ? '<span class="badge '+(v?'text-bg-success">ใช้งาน':'text-bg-secondary">ปิดใช้งาน')+'</span>' : v; }},
            {data:null, orderable:false, searchable:false, className:'text-end', render:function(v,t,row){ if(t!=='display') return ''; var a=[]; if(row.edit_url) a.push('<a class="btn btn-sm btn-outline-dark" title="แก้ไข" href="'+text.display(row.edit_url)+'"><i class="bx bx-edit" aria-hidden="true"></i><span class="visually-hidden">แก้ไข</span></a>'); if(row.delete_url) a.push('<button class="btn btn-sm btn-outline-danger js-delete-group" title="ลบ" data-url="'+text.display(row.delete_url)+'" type="button"><i class="bx bx-trash" aria-hidden="true"></i><span class="visually-hidden">ลบ</span></button>'); return a.join(' '); }}
        ]
    }));
    window.erpAjaxDelete({button:'.js-delete-group', reload:'#customer-groups-table', confirm:'ยืนยันการลบกลุ่มลูกค้านี้หรือไม่? หากมีลูกค้าใช้งานอยู่ ระบบจะให้ปิดใช้งานแทน'});
});
</script>
@endpush
