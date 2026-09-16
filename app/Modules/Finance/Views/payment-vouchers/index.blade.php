@extends('Finance::layout')
@php($isPrePayment = $voucherType === 'PRE_PAYMENT')
@section('title', ($isPrePayment ? 'ใบขอจ่ายล่วงหน้า' : 'ใบสำคัญจ่าย').' | Finance')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">FINANCE / VOUCHERS</p><h1 class="h3 mb-2">{{ $isPrePayment ? 'ใบขอจ่ายล่วงหน้า' : 'ใบสำคัญจ่าย' }}</h1><p class="text-secondary mb-0">{{ $isPrePayment ? 'เตรียมและอนุมัติรายการจ่ายล่วงหน้าก่อนสร้าง Settlement' : 'เตรียมและอนุมัติรายการจ่ายก่อนสร้าง Settlement' }}</p></div>
        @if(auth()->user()->hasPermission('finance.payment-vouchers.create'))<a class="btn btn-app-primary" href="{{ route($isPrePayment ? 'finance.pre-payment-vouchers.create' : 'finance.payment-vouchers.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>{{ $isPrePayment ? 'สร้างใบขอจ่ายล่วงหน้า' : 'สร้างใบสำคัญจ่าย' }}</a>@endif
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><h2 class="h6 mb-0">ตัวกรอง</h2><button class="btn btn-sm btn-app-soft" id="voucher-filter-reset" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div><div class="row g-3"><div class="col-12 col-md-3"><label class="form-label" for="voucher-filter-status">สถานะ</label><select class="form-select" id="voucher-filter-status"><option value="">ทั้งหมด</option><option value="DRAFT">ร่าง</option><option value="SUBMITTED">รออนุมัติ</option><option value="APPROVED">อนุมัติแล้ว</option><option value="VOID">ยกเลิก</option></select></div><div class="col-12 col-md-3"><label class="form-label" for="voucher-filter-bank">บัญชีเงิน</label><select class="form-select" id="voucher-filter-bank"><option value="">ทั้งหมด</option>@foreach($bankAccounts as $bank)<option value="{{ $bank->id }}">{{ $bank->code }} · {{ $bank->name }}</option>@endforeach</select></div><div class="col-12 col-md-3"><label class="form-label" for="voucher-filter-from">วันที่เอกสาร ตั้งแต่</label><input class="form-control" id="voucher-filter-from" type="date"></div><div class="col-12 col-md-3"><label class="form-label" for="voucher-filter-to">วันที่เอกสาร ถึง</label><input class="form-control" id="voucher-filter-to" type="date"></div><div class="col-12 col-md-3"><label class="form-label" for="voucher-filter-min">จำนวนเงินขั้นต่ำ</label><input class="form-control" id="voucher-filter-min" type="number" min="0" step="0.01"></div><div class="col-12 col-md-3"><label class="form-label" for="voucher-filter-max">จำนวนเงินสูงสุด</label><input class="form-control" id="voucher-filter-max" type="number" min="0" step="0.01"></div></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h6 mb-3">รายการ{{ $isPrePayment ? 'ใบขอจ่ายล่วงหน้า' : 'ใบสำคัญจ่าย' }}</h2><div class="table-responsive"><table id="payment-vouchers-table" class="table table-hover align-middle w-100" data-url="{{ route($isPrePayment ? 'finance.pre-payment-vouchers.data' : 'finance.payment-vouchers.data') }}"><thead><tr><th>เลขที่</th><th>ประเภท</th><th>วันที่</th><th>คู่ค้า</th><th>บัญชีเงิน</th><th class="text-end">จำนวนเงิน</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection
@push('scripts')
<script>
$(function(){
    var table=$('#payment-vouchers-table'),text=$.fn.dataTable.render.text(),dt=table.DataTable($.extend(true,{},window.erpDataTableDefaults,{
        ajax:{url:table.data('url'),data:function(data){data.status=$('#voucher-filter-status').val();data.bank_account_id=$('#voucher-filter-bank').val();data.date_from=$('#voucher-filter-from').val();data.date_to=$('#voucher-filter-to').val();data.amount_min=$('#voucher-filter-min').val();data.amount_max=$('#voucher-filter-max').val();}},
        order:[[2,'desc']],buttons:[window.erpExcelButton(table)],
        columns:[
            {data:'document_number',name:'finance_payment_vouchers.document_number',render:text.display},{data:'type_label',name:'finance_payment_vouchers.voucher_type',render:text.display},{data:'date_label',name:'finance_payment_vouchers.document_date',render:text.display},{data:'party_label',name:'parties.code',render:text.display},{data:'bank_label',name:'bank_accounts.code',render:text.display},{data:'amount',name:'finance_payment_vouchers.amount',className:'text-end',render:$.fn.dataTable.render.number(',','.',2)},
            {data:'status',name:'finance_payment_vouchers.status',render:function(value,type){if(type!=='display')return value;var classes={DRAFT:'app-status-neutral',SUBMITTED:'app-status-info',APPROVED:'app-status-success',VOID:'app-status-danger'},labels={DRAFT:'ร่าง',SUBMITTED:'รออนุมัติ',APPROVED:'อนุมัติแล้ว',VOID:'ยกเลิก'};return '<span class="badge '+(classes[value]||'app-status-neutral')+'">'+text.display(labels[value]||value)+'</span>';}},
            {data:null,orderable:false,searchable:false,className:'text-end text-nowrap',render:function(_,type,row){if(type!=='display')return '';var actions=['<a class="btn btn-sm btn-app-soft" href="'+text.display(row.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-file-find" aria-hidden="true"></i></a>'];if(row.delete_url)actions.push('<button class="btn btn-sm btn-app-danger js-delete-voucher" type="button" data-url="'+text.display(row.delete_url)+'" title="ลบร่าง" aria-label="ลบร่าง"><i class="bx bx-trash" aria-hidden="true"></i></button>');return actions.join(' ');}}
        ]
    }));
    $('#voucher-filter-status,#voucher-filter-bank,#voucher-filter-from,#voucher-filter-to').on('change',function(){dt.ajax.reload();});
    $('#voucher-filter-min,#voucher-filter-max').on('keyup',function(event){if(event.key==='Enter')dt.ajax.reload();});
    $('#voucher-filter-reset').on('click',function(){$('#voucher-filter-status,#voucher-filter-bank').val('');$('#voucher-filter-from,#voucher-filter-to,#voucher-filter-min,#voucher-filter-max').val('');dt.ajax.reload();});
    window.erpAjaxDelete({button:'.js-delete-voucher',reload:'#payment-vouchers-table',confirm:'ยืนยันการลบร่างใบสำคัญจ่ายนี้หรือไม่?',confirmButtonText:'ลบร่าง',cancelButtonText:'กลับ'});
});
</script>
@endpush
