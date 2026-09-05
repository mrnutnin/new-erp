@extends('Finance::layout')

@section('title', 'เติมเงินสดย่อย | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4"><div><p class="eyebrow mb-2">FINANCE / PETTY CASH</p><h1 class="h3 mb-1">เติมเงินสดย่อย</h1><p class="text-secondary mb-0">โอนเงินจากบัญชีธนาคารเข้าวงเงินสดย่อยของคลังที่เลือก</p></div>@if(auth()->user()->hasPermission('finance.petty-cash-top-ups.create'))<a class="btn btn-dark" href="{{ route('finance.petty-cash-top-ups.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างเอกสารเติมเงิน</a>@endif</div>

        <div class="card border-0 shadow-sm mb-4" aria-labelledby="top-up-filter-title"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 id="top-up-filter-title" class="h5 mb-1">ตัวกรอง</h2><p class="small text-secondary mb-0">กรองข้อมูลก่อนดูรายการ</p></div><button type="button" id="top-up-filter-reset" class="btn btn-sm btn-outline-secondary">ล้างตัวกรอง</button></div><div class="row g-3"><div class="col-md-4"><label class="form-label" for="top-up-status">สถานะ</label><select id="top-up-status" class="form-select"><option value="">ทุกสถานะ</option>@foreach(['DRAFT'=>'ร่าง','SUBMITTED'=>'รออนุมัติ','APPROVED'=>'อนุมัติแล้ว','POSTED'=>'ลงบัญชีแล้ว','REVERSED'=>'กลับรายการแล้ว','VOID'=>'ยกเลิก'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div><div class="col-md-5"><label class="form-label" for="top-up-fund">วงเงินสดย่อย</label><select id="top-up-fund" class="form-select"><option value="">ทุกวงเงินสดย่อย</option>@foreach($fundOptions as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></div></div></div></div>

        <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 class="h5 mb-1">รายการเติมเงินสดย่อย</h2><p class="small text-secondary mb-0">ค้นหา เรียงลำดับ ส่งออก และติดตามสถานะเอกสารได้จากตารางนี้</p></div></div><div class="table-responsive"><table id="top-up-table" class="table table-hover align-middle w-100" data-url="{{ route('finance.petty-cash-top-ups.data') }}"><thead><tr><th>เลขที่</th><th>วันที่</th><th>วงเงินสดย่อย</th><th>บัญชีต้นทาง</th><th class="text-end">จำนวนเงิน</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var text = $.fn.dataTable.render.text(), labels = {DRAFT:'ร่าง',SUBMITTED:'รออนุมัติ',APPROVED:'อนุมัติแล้ว',POSTED:'ลงบัญชีแล้ว',REVERSED:'กลับรายการแล้ว',VOID:'ยกเลิก'}, classes = {DRAFT:'app-status-neutral',SUBMITTED:'app-status-info',APPROVED:'app-status-success',POSTED:'app-status-success',REVERSED:'app-status-warning',VOID:'app-status-danger'}, $table = $('#top-up-table');
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, { ajax: {url:$table.data('url'), data:function (data) { data.status=$('#top-up-status').val(); data.petty_cash_fund_id=$('#top-up-fund').val(); }}, order:[[1,'desc']], buttons:[window.erpExcelButton($table)], columns:[{data:'document_number',render:text.display},{data:'document_date_label',render:text.display},{data:'fund_label',render:text.display},{data:'source_label',render:text.display},{data:'amount',className:'text-end',render:$.fn.dataTable.render.number(',','.',2)},{data:'status',render:function(value,type){return type==='display'?'<span class="badge '+(classes[value]||'app-status-neutral')+'">'+text.display(labels[value]||value)+'</span>':value;}},{data:null,orderable:false,searchable:false,className:'text-end text-nowrap',render:function(_,type,row){if(type!=='display')return '';var actions=['<a class="btn btn-sm btn-app-soft" href="'+text.display(row.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>'];if(row.edit_url)actions.push('<a class="btn btn-sm btn-app-soft" href="'+text.display(row.edit_url)+'" title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit" aria-hidden="true"></i></a>');return actions.join(' ');}}] }));
            $('#top-up-status,#top-up-fund').on('change', function () { table.ajax.reload(); });
            $('#top-up-filter-reset').on('click', function () { $('#top-up-status,#top-up-fund').val(''); table.ajax.reload(); });
        });
    </script>
@endpush
