@extends('Pos::layout')

@section('title', $receipt->document_number.' | POS')

@section('content')
@php($labels=['DRAFT'=>'ร่าง','APPROVED'=>'อนุมัติแล้ว','POSTED'=>'ลงบัญชีแล้ว','VOID'=>'ยกเลิก','REVERSED'=>'ยกเลิกแล้ว'])
@php($classes=['DRAFT'=>'app-badge-soft','APPROVED'=>'app-badge-success','POSTED'=>'app-badge-success','VOID'=>'text-bg-danger','REVERSED'=>'text-bg-danger'])
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><p class="eyebrow mb-2">POS / RECEIPT</p><h1 class="h3 mb-1">{{ $receipt->document_number }}</h1><p class="text-secondary mb-0">เอกสารรับชำระหนี้</p></div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge {{ $classes[$receipt->status] ?? 'app-badge-soft' }}">{{ $labels[$receipt->status] ?? $receipt->status }}</span>
            @if ($receipt->journalEntry && auth()->user()->hasPermission('accounting.journal-entries.view'))
                <button class="btn btn-app-soft" type="button" data-journal-preview-url="{{ route('accounting.journal-preview.show', $receipt->journalEntry) }}"><i class="bx bx-book-open me-1"></i>ดู GL</button>
            @endif
            @if ($receipt->status === 'DRAFT' && auth()->user()->hasPermission('pos.receipts.approve'))
                <button class="btn btn-app-soft js-receipt-action" data-method="PUT" data-url="{{ route('pos.receipts.approve', $receipt) }}">อนุมัติ</button>
            @endif
            @if ($receipt->status === 'APPROVED' && auth()->user()->hasPermission('pos.receipts.post'))
                <button class="btn btn-primary js-receipt-action" data-method="POST" data-url="{{ route('pos.receipts.post', $receipt) }}" @disabled(!($postReadiness['ready'] ?? true))>ยืนยันรับชำระและลงบัญชี</button>
            @endif
            @if ($receipt->status === 'POSTED' && auth()->user()->hasPermission('pos.receipts.reverse'))
                <button class="btn btn-outline-danger js-receipt-reverse" data-url="{{ route('pos.receipts.reverse', $receipt) }}" data-document="{{ $receipt->document_number }}">ยกเลิกเอกสาร</button>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('pos.receipts.index') }}">ย้อนกลับ</a>
        </div>
    </div>
    @if($receipt->status === 'APPROVED' && !($postReadiness['ready'] ?? true))<div class="alert alert-warning"><strong>ยังลงบัญชีไม่ได้</strong><ul class="mb-0 mt-1">@foreach($postReadiness['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>@endif
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="row g-3"><div class="col-md-3"><div class="small text-secondary">วันที่เอกสาร</div>{{ $receipt->document_date?->format($dateFormat) ?: '—' }}</div><div class="col-md-3"><div class="small text-secondary">วันที่รับเงินจริง</div>{{ $receipt->settlement_date?->format($dateFormat) ?: '—' }}</div><div class="col-md-3"><div class="small text-secondary">ลูกค้า</div>{{ $receipt->party?->code }} · {{ $receipt->party?->name }}</div><div class="col-md-3"><div class="small text-secondary">ยอดรับสุทธิ</div><strong>{{ number_format((float) $receipt->net_amount, 2) }}</strong></div><div class="col-md-4"><div class="small text-secondary">ยอดรวม</div>{{ number_format((float) $receipt->gross_amount, 2) }}</div><div class="col-md-4"><div class="small text-secondary">หัก ณ ที่จ่าย</div>{{ number_format((float) $receipt->withholding_amount, 2) }}</div><div class="col-md-4"><div class="small text-secondary">รายละเอียด</div>{{ $receipt->description ?: '—' }}</div></div></div></div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">ใบแจ้งหนี้ที่นำมารับชำระ</h2><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>#</th><th>ใบแจ้งหนี้</th><th class="text-end">ยอดจัดสรร</th></tr></thead><tbody>@forelse($receipt->allocationIntents as $intent)<tr><td>{{ $intent->line_number }}</td><td>{{ $intent->openItem?->document_number ?: '—' }}</td><td class="text-end">{{ number_format((float) $intent->amount, 2) }}</td></tr>@empty<tr><td colspan="3" class="text-center text-secondary">ไม่มีรายการจัดสรร</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">ช่องทางรับเงิน</h2><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>บัญชีเงินสด/ธนาคาร</th><th>เลขอ้างอิง</th><th class="text-end">จำนวนเงิน</th></tr></thead><tbody>@foreach($receipt->tenders as $tender)<tr><td>{{ $tender->bankAccount?->code }} · {{ $tender->bankAccount?->name }}</td><td>{{ $tender->reference ?: '—' }}</td><td class="text-end fw-semibold">{{ number_format((float) $tender->amount, 2) }}</td></tr>@endforeach</tbody></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>$(function(){const csrf=$('meta[name="csrf-token"]').attr('content');$('.js-receipt-action').on('click',function(){const b=$(this);$.ajax({url:b.data('url'),method:b.data('method'),headers:{Accept:'application/json','X-CSRF-TOKEN':csrf}}).done(r=>Swal.fire({icon:'success',text:r.msg}).then(()=>location.reload())).fail(x=>Swal.fire({icon:'error',text:x.responseJSON?.message||'ไม่สามารถดำเนินการได้'}));});$('.js-receipt-reverse').on('click',function(){const b=$(this);Swal.fire({title:'ยกเลิกเอกสาร '+b.data('document'),html:'<input id="reverse-date" class="swal2-input" type="date" value="{{ today()->format('Y-m-d') }}"><textarea id="reverse-reason" class="swal2-textarea" placeholder="เหตุผลอย่างน้อย 10 ตัวอักษร"></textarea>',showCancelButton:true,confirmButtonText:'ยกเลิกเอกสาร',cancelButtonText:'ย้อนกลับ',preConfirm:()=>{const reason=$.trim($('#reverse-reason').val()||''),date=$('#reverse-date').val();if(!date||reason.length<10){Swal.showValidationMessage('กรุณาระบุวันที่และเหตุผลอย่างน้อย 10 ตัวอักษร');return false;}return {reversal_date:date,reason:reason};}}).then(r=>{if(!r.isConfirmed)return;$.ajax({url:b.data('url'),method:'PUT',data:r.value,headers:{Accept:'application/json','X-CSRF-TOKEN':csrf}}).done(x=>Swal.fire({icon:'success',text:x.msg}).then(()=>location.reload())).fail(x=>Swal.fire({icon:'error',text:x.responseJSON?.message||'ไม่สามารถยกเลิกเอกสารได้'}));});});});</script>
@endpush
