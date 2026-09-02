@extends('Accounting::layout')

@section('title', 'การตั้งค่าการลงบัญชี | New ERP')

@section('content')
    @php($events = app(\App\Modules\Accounting\Services\AccountMappingService::class)->configurationEvents())
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div><p class="eyebrow mb-2">ACCOUNTING / POSTING CONFIGURATION</p><h1 class="h3 mb-1">การตั้งค่าการลงบัญชี</h1><p class="text-secondary mb-0">กำหนดบัญชี GL ตาม Event และบทบาทบัญชีของแต่ละเอกสาร</p></div>
            @if (auth()->user()->hasPermission('accounting.account-mappings.create'))<a class="btn btn-dark" href="{{ route('accounting.account-mappings.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มการตั้งค่า</a>@endif
        </div>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><div class="d-flex align-items-center justify-content-between mb-3"><h2 class="h6 mb-0">ตัวกรอง</h2><button class="btn btn-sm btn-outline-secondary" id="reset-filters" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div><div class="row g-3">
            <div class="col-md-3"><label class="form-label" for="filter-module">Module</label><select class="form-select" id="filter-module"><option value="">ทุก Module</option>@foreach(collect($events)->pluck('module')->unique()->sort() as $module)<option value="{{ $module }}">{{ $module }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label" for="filter-event">Event/เอกสาร</label><select class="form-select" id="filter-event"><option value="">ทุก Event</option>@foreach($events as $code => $event)<option value="{{ $code }}">{{ $event['document'] }} ({{ $code }})</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label" for="filter-status">สถานะ</label><select class="form-select" id="filter-status"><option value="">ทุกสถานะ</option><option value="1">ใช้งาน</option><option value="0">ปิดใช้งาน</option></select></div>
            <div class="col-md-2 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="filter-legacy"><label class="form-check-label" for="filter-legacy">เฉพาะ Legacy</label></div></div>
        </div></div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="account-mappings-table" data-url="{{ route('accounting.account-mappings.data') }}" data-can-update="{{ auth()->user()->hasPermission('accounting.account-mappings.update') ? 1 : 0 }}" data-can-create="{{ auth()->user()->hasPermission('accounting.account-mappings.create') ? 1 : 0 }}"><thead><tr><th>Module</th><th>Event / เอกสาร</th><th>บทบาทบัญชี</th><th>บัญชี GL</th><th>Version</th><th>สถานะ</th>@if(auth()->user()->hasPermission('accounting.account-mappings.update') || auth()->user()->hasPermission('accounting.account-mappings.create'))<th class="text-end">จัดการ</th>@endif</tr></thead></table>
        </div></div></div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    var $table = $('#account-mappings-table'), text = $.fn.dataTable.render.text();
    var columns = [{data:'module_label',name:'accounting_account_mappings.event_code',render:text.display},{data:'document_label',name:'accounting_account_mappings.event_code',render:text.display},{data:'key_label',name:'accounting_account_mappings.key',render:text.display},{data:'account_label',name:'accounts.code',render:text.display},{data:'version',name:'accounting_account_mappings.version',className:'text-end'},{data:'is_active',name:'accounting_account_mappings.is_active',render:function(v,t){return t==='display'?'<span class="badge '+(v?'text-bg-success':'text-bg-secondary')+'">'+(v?'ใช้งาน':'ปิดใช้งาน')+'</span>':v;}}];
    if ($table.data('can-update') || $table.data('can-create')) columns.push({data:null,orderable:false,searchable:false,className:'text-end',render:function(v,t,row){if(t!=='display')return '';var actions=[];if(row.edit_url)actions.push('<a class="btn btn-sm btn-outline-dark" href="'+text.display(row.edit_url)+'" title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit-alt" aria-hidden="true"></i></a>');if(row.copy_url)actions.push('<a class="btn btn-sm btn-outline-primary" href="'+text.display(row.copy_url)+'" title="คัดลอกเป็น Event" aria-label="คัดลอกเป็น Event"><i class="bx bx-copy" aria-hidden="true"></i></a>');return actions.join(' ');}});
    var table = $table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:$table.data('url'),data:function(d){d.module=$('#filter-module').val();d.event_code=$('#filter-event').val();d.is_active=$('#filter-status').val();d.legacy_only=$('#filter-legacy').is(':checked')?1:0;}},order:[[1,'asc']],buttons:[window.erpExcelButton($table)],columns:columns}));
    $('#filter-module,#filter-event,#filter-status,#filter-legacy').on('change',function(){table.ajax.reload();});$('#reset-filters').on('click',function(){$('#filter-module,#filter-event,#filter-status').val('');$('#filter-legacy').prop('checked',false);table.ajax.reload();});
});
</script>
@endpush
