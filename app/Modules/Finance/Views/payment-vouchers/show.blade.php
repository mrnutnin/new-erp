@extends('Finance::layout')
@section('title', $voucher->document_number.' | Finance')
@section('content')
@php($labels=['DRAFT'=>'ร่าง','SUBMITTED'=>'รออนุมัติ','APPROVED'=>'อนุมัติแล้ว','VOID'=>'ยกเลิก'])
@php($classes=['DRAFT'=>'app-status-neutral','SUBMITTED'=>'app-status-info','APPROVED'=>'app-status-success','VOID'=>'app-status-danger'])
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">FINANCE / VOUCHERS</p>
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-3">
        <div><h1 class="h3 mb-2">{{ $voucher->document_number }}</h1><div class="text-secondary">{{ $voucher->voucher_type === 'PRE_PAYMENT' ? 'ใบขอจ่ายล่วงหน้า' : 'ใบสำคัญจ่าย' }}</div><span class="badge mt-2 {{ $classes[$voucher->status] ?? 'app-status-neutral' }}">{{ $labels[$voucher->status] ?? $voucher->status }}</span></div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-app-soft" href="{{ route($voucher->voucher_type === 'PRE_PAYMENT' ? 'finance.pre-payment-vouchers.index' : 'finance.payment-vouchers.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
            @if($actions['submit'])<button class="btn btn-app-primary js-voucher-action" data-url="{{ route('finance.payment-vouchers.submit', $voucher) }}" data-action="ส่งอนุมัติ"><i class="bx bx-send me-1" aria-hidden="true"></i>ส่งอนุมัติ</button>@endif
            @if($actions['approve'])<button class="btn btn-app-primary js-voucher-action" data-url="{{ route('finance.payment-vouchers.approve', $voucher) }}" data-action="อนุมัติ"><i class="bx bx-check me-1" aria-hidden="true"></i>อนุมัติ</button>@endif
            @if($actions['settle'])<button class="btn btn-app-primary js-voucher-action" data-url="{{ route('finance.payment-vouchers.settle', $voucher) }}" data-method="POST" data-action="สร้าง Settlement"><i class="bx bx-transfer me-1" aria-hidden="true"></i>สร้าง Settlement</button>@endif
            @if($actions['void'])<button class="btn btn-app-danger js-voucher-action" data-url="{{ route('finance.payment-vouchers.void', $voucher) }}" data-action="ยกเลิกเอกสาร"><i class="bx bx-x-circle me-1" aria-hidden="true"></i>ยกเลิกเอกสาร</button>@endif
            @if($actions['delete'])<button class="btn btn-app-danger js-delete-voucher" type="button" data-url="{{ route('finance.payment-vouchers.destroy', $voucher) }}"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบร่าง</button>@endif
        </div>
    </div>
    @if($actions['submit'])<div class="alert alert-info">ขั้นถัดไป: ส่งเอกสารเพื่อให้ผู้มีสิทธิ์อนุมัติ ก่อนสร้าง Settlement</div>
    @elseif($actions['approve'])<div class="alert alert-info">ขั้นถัดไป: ตรวจสอบรายละเอียดและอนุมัติเอกสาร</div>
    @elseif($actions['settle'])<div class="alert alert-info">ขั้นถัดไป: สร้าง Settlement เพื่อดำเนินการรับหรือจ่ายเงินจริง</div>@endif
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="row g-3"><div class="col-md-3"><div class="small text-secondary">วันที่เอกสาร</div>{{ $voucher->document_date?->format($dateFormat) ?? '—' }}</div><div class="col-md-3"><div class="small text-secondary">คู่ค้า</div>{{ $voucher->party?->code ?? '—' }} · {{ $voucher->party?->name ?? '—' }}</div><div class="col-md-3"><div class="small text-secondary">บัญชีเงิน</div>{{ $voucher->bankAccount?->code ?? '—' }} · {{ $voucher->bankAccount?->name ?? '—' }}</div><div class="col-md-3"><div class="small text-secondary">จำนวนเงิน</div><strong>{{ number_format((float) $voucher->amount, 2) }}</strong></div><div class="col-12"><div class="small text-secondary">Settlement</div>{{ $voucher->settlement?->document_number ?? 'ยังไม่ได้สร้าง Settlement' }}</div>@if($voucher->description)<div class="col-12"><div class="small text-secondary">รายละเอียด</div>{{ $voucher->description }}</div>@endif</div></div></div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">รายการจัดสรรเจ้าหนี้</h2><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>#</th><th>เอกสารเจ้าหนี้</th><th>รายละเอียด</th><th class="text-end">จำนวนเงิน</th></tr></thead><tbody>@forelse($voucher->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->openItem?->document_number ?? $line->open_item_document_number ?? '—' }}</td><td>{{ $line->description ?? '—' }}</td><td class="text-end">{{ number_format((float) $line->amount, 2) }}</td></tr>@empty<tr><td colspan="4" class="text-center text-secondary">ยังไม่มีรายการจัดสรร</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5 mb-3">ประวัติเอกสาร</h2>@forelse($history as $event)<div class="d-flex gap-3 border-bottom py-2"><div class="small text-secondary text-nowrap">{{ $event->created_at->format('d/m/Y H:i') }}</div><div><strong>{{ $event->action }}</strong><div class="small text-secondary">{{ $event->user?->name ?? 'ระบบ' }}</div>@if($event->reason)<div class="small mt-1"><span class="text-secondary">รายละเอียด:</span> {{ $event->reason }}</div>@endif</div></div>@empty<p class="text-secondary mb-0">ยังไม่มีประวัติ</p>@endforelse</div></div>
</div>
@endsection
@push('scripts')
<script>
window.erpAjaxDelete({button:'.js-delete-voucher',redirect:@json(route($voucher->voucher_type === 'PRE_PAYMENT' ? 'finance.pre-payment-vouchers.index' : 'finance.payment-vouchers.index')),confirm:'ยืนยันการลบร่างใบสำคัญจ่ายนี้หรือไม่?',confirmButtonText:'ลบร่าง',cancelButtonText:'กลับ'});
$(document).on('click','.js-voucher-action',function(){
    var button=$(this),action=button.data('action');
    if(button.data('submitting'))return;
    Swal.fire({icon:action==='ยกเลิกเอกสาร'?'warning':'question',title:action+'?',text:'สถานะเอกสารจะเปลี่ยนตามขั้นตอนนี้',showCancelButton:true,confirmButtonText:action,cancelButtonText:'กลับ'}).then(function(result){
        if(!result.isConfirmed)return;
        button.data('submitting',1).prop('disabled',true);
        $.ajax({url:button.data('url'),method:button.data('method')||'PUT',data:{_token:$('meta[name="csrf-token"]').attr('content')}})
            .done(function(response){Swal.fire({icon:'success',text:response.msg||'ดำเนินการแล้ว'}).then(function(){location.reload();});})
            .fail(function(xhr){Swal.fire({icon:'error',text:xhr.responseJSON?.message||'ไม่สามารถดำเนินการได้'});})
            .always(function(){button.data('submitting',0).prop('disabled',false);});
    });
});
</script>
@endpush
