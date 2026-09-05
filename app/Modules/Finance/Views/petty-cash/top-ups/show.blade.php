@extends('Finance::layout')

@section('title', $topUp->document_number.' | เติมเงินสดย่อย')

@section('content')
@php($labels=['DRAFT'=>'ร่าง','SUBMITTED'=>'รออนุมัติ','APPROVED'=>'อนุมัติแล้ว','POSTED'=>'ลงบัญชีแล้ว','REVERSED'=>'ยกเลิกรายการแล้ว','VOID'=>'ยกเลิก'])
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">FINANCE / PETTY CASH</p>
            <h1 class="h3 mb-1">{{ $topUp->document_number }}</h1>
            <span class="badge app-status-neutral">{{ $labels[$topUp->status] ?? $topUp->status }}</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('finance.petty-cash-top-ups.index') }}">กลับ</a>
            @if($topUp->journalEntry && auth()->user()->hasPermission('accounting.journal-entries.view'))
                <button class="btn btn-app-soft" type="button" data-journal-preview-url="{{ route('accounting.journal-preview.show', $topUp->journalEntry) }}"><i class="bx bx-book-open me-1" aria-hidden="true"></i>ดู GL</button>
            @endif
            @if($topUp->reversalJournalEntry && auth()->user()->hasPermission('accounting.journal-entries.view'))
                <button class="btn btn-outline-danger" type="button" data-journal-preview-url="{{ route('accounting.journal-preview.show', $topUp->reversalJournalEntry) }}"><i class="bx bx-undo me-1" aria-hidden="true"></i>ดู GL ยกเลิก</button>
            @endif
            @if($topUp->status === 'DRAFT' && auth()->user()->hasPermission('finance.petty-cash-top-ups.update'))
                <a class="btn btn-outline-dark" href="{{ route('finance.petty-cash-top-ups.edit', $topUp) }}">แก้ไข</a>
                <form method="POST" action="{{ route('finance.petty-cash-top-ups.destroy', $topUp) }}" class="d-inline" onsubmit="event.preventDefault(); var form=this; Swal.fire({icon:'warning',title:'ลบเอกสาร Draft?',text:'เอกสารจะถูกลบออกจากรายการ',showCancelButton:true,confirmButtonText:'ลบเอกสาร',cancelButtonText:'ยกเลิก',confirmButtonColor:'#dc3545'}).then(function(result){if(!result.isConfirmed)return; $.ajax({url:form.action,method:'DELETE',data:{_token:$('meta[name=csrf-token]').attr('content')},headers:{Accept:'application/json'}}).done(function(response){Swal.fire({icon:'success',text:response.msg||'ลบเอกสารแล้ว'}).then(function(){window.location.href=response.redirect;});}).fail(function(xhr){Swal.fire({icon:'error',text:xhr.responseJSON?.message||'ไม่สามารถลบเอกสารได้'});});});">@csrf @method('DELETE')<button class="btn btn-outline-danger" type="submit">ลบ Draft</button></form>
            @endif
            @foreach(['submit' => 'ส่งอนุมัติ', 'approve' => 'อนุมัติ', 'post' => 'ลงบัญชี'] as $action => $actionLabel)
                @if(($action === 'submit' && $topUp->status === 'DRAFT' || $action === 'approve' && $topUp->status === 'SUBMITTED' || $action === 'post' && $topUp->status === 'APPROVED') && auth()->user()->hasPermission('finance.petty-cash-top-ups.'.$action))
                    <button class="btn btn-dark js-action" data-url="{{ route('finance.petty-cash-top-ups.'.$action, $topUp) }}" data-method="{{ $action === 'post' ? 'POST' : 'PUT' }}">{{ $actionLabel }}</button>
                @endif
            @endforeach
            @if($topUp->status === 'SUBMITTED' && auth()->user()->hasPermission('finance.petty-cash-top-ups.approve'))
                <button class="btn btn-outline-danger js-reason" data-url="{{ route('finance.petty-cash-top-ups.reject', $topUp) }}" data-title="ไม่อนุมัติเอกสาร">ไม่อนุมัติ</button>
            @endif
            @if(in_array($topUp->status, ['SUBMITTED', 'APPROVED'], true) && auth()->user()->hasPermission('finance.petty-cash-top-ups.void'))
                <button class="btn btn-outline-danger js-reason" data-url="{{ route('finance.petty-cash-top-ups.void', $topUp) }}">ยกเลิก</button>
            @endif
            @if($topUp->status === 'POSTED' && auth()->user()->hasPermission('finance.petty-cash-top-ups.reverse'))
                <button class="btn btn-outline-danger js-reason" data-reversal="1" data-url="{{ route('finance.petty-cash-top-ups.reverse', $topUp) }}">ยกเลิกรายการ</button>
            @endif
        </div>
    </div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="row g-3">
        <div class="col-md-4"><div class="small text-secondary">วันที่เอกสาร</div>{{ $topUp->document_date?->format('d/m/Y') }}</div>
        <div class="col-md-4"><div class="small text-secondary">วงเงินสดย่อย</div>{{ $topUp->fund?->name ?: '—' }}</div>
        <div class="col-md-4"><div class="small text-secondary">จำนวนเงิน</div><strong>{{ number_format((float) $topUp->amount, 2) }}</strong></div>
        <div class="col-md-4"><div class="small text-secondary">สถานะ</div>{{ $labels[$topUp->status] ?? $topUp->status }}</div>
        <div class="col-md-6"><div class="small text-secondary">บัญชีต้นทาง</div>{{ $topUp->source_bank_account_code }} · {{ $topUp->source_bank_account_name }}</div>
        <div class="col-md-6"><div class="small text-secondary">บัญชีเงินสดย่อย</div>{{ $topUp->cash_bank_account_code }} · {{ $topUp->cash_bank_account_name }}</div>
        <div class="col-12"><div class="small text-secondary">รายละเอียด</div>{{ $topUp->description ?: '—' }}</div>
        @if($topUp->journalEntry)
            <div class="col-md-6"><div class="small text-secondary">Journal</div>{{ $topUp->journalEntry->entry_number ?? $topUp->journal_entry_id }}</div>
        @endif
    </div></div></div>
</div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">ประวัติเอกสาร</h2>@forelse($history ?? [] as $event)<div class="d-flex gap-3 border-bottom py-2"><div class="small text-secondary text-nowrap">{{ $event->created_at?->format('d/m/Y H:i') }}</div><div><strong>{{ $event->action }}</strong><div class="small text-secondary">{{ $event->user?->name ?? 'ระบบ' }}</div>@if($event->reason)<div class="small mt-1"><span class="text-secondary">รายละเอียด:</span> {{ $event->reason }}</div>@endif</div></div>@empty<p class="text-secondary mb-0">ยังไม่มีประวัติเอกสาร</p>@endforelse</div></div>
@endsection

@push('scripts')<script>$(function(){function send($b,data){$.ajax({url:$b.data('url'),method:$b.data('method')||'PUT',data:data||{},headers:{Accept:'application/json','X-CSRF-TOKEN':$('meta[name="csrf-token"]').attr('content')}}).done(function(r){Swal.fire({icon:'success',text:r.msg}).then(function(){location.reload();});}).fail(function(x){Swal.fire({icon:'error',text:x.responseJSON?.message||x.responseJSON?.errors?.reason?.[0]||'ไม่สามารถดำเนินการได้'});});}$('.js-action').on('click',function(){var $b=$(this);Swal.fire({icon:'question',text:'ยืนยันการดำเนินการ?',showCancelButton:true}).then(function(r){if(r.isConfirmed)send($b);});});$('.js-reason').on('click',function(){var $b=$(this);Swal.fire({input:'textarea',inputLabel:'เหตุผล',showCancelButton:true,preConfirm:function(v){if(!$.trim(v||'')){Swal.showValidationMessage('กรุณาระบุเหตุผล');return false;}return v;}}).then(function(r){if(r.isConfirmed)send($b,{reason:r.value,reversal_date:$b.data('reversal')?new Date().toISOString().slice(0,10):undefined});});});});</script>@endpush
