@extends('Finance::layout')
@section('title', 'ตัดเงินล่วงหน้า / เงินมัดจำ | Finance')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">FINANCE / ADVANCE & DEPOSIT</p>
    <h1 class="h3 mb-2">ตัดเงินล่วงหน้า / เงินมัดจำ</h1>
    <p class="text-secondary mb-4">{{ $advance->document_number }} · {{ $advance->party_type === 'CUSTOMER' ? 'ลูกค้า' : 'ผู้ขาย' }}</p>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="alert alert-info">ระบบจะสร้าง Journal การตัดรายการให้โดยอัตโนมัติใน transaction เดียวกับ subledger และไม่ใช้ AR/AP allocation โดยตรง</div>
            @if($options->isEmpty())
                <div class="alert alert-warning border-0"><strong>ยังไม่มีเอกสารที่ตัดได้</strong><div class="small mt-1">เอกสารของคู่ค้านี้อาจถูกตัดครบแล้ว หรือยังไม่มี Open Item ที่ลงบัญชีและอยู่ใน Warehouse เดียวกัน เงินคงเหลือของรายการนี้ยังไม่สามารถนำไปตัดกับเอกสารอื่นได้</div><a class="btn btn-sm btn-app-soft mt-3" href="{{ route('finance.advance-deposits.show', $advance) }}">ดูรายละเอียดเงินล่วงหน้า/เงินมัดจำ</a></div>
            @endif
            <form id="advance-application-form" method="post" action="{{ route('finance.advance-deposits.applications.store', $advance) }}">
                <input type="hidden" name="source_id" value="UI-ADV-{{ $advance->id }}-{{ now()->format('YmdHis') }}">
                <div class="row g-3">
                    <div class="col-md-5"><label class="form-label">เอกสารปลายทาง</label><select class="form-select" name="open_item_id" required><option value="">เลือกเอกสาร</option>@foreach($options as $item)<option value="{{ $item->id }}" data-remaining="{{ $item->advance_remaining_amount }}">{{ $item->document_number }} · {{ $item->document_date?->format('d/m/Y') }} · คงเหลือ {{ number_format((float)$item->advance_remaining_amount,2) }}</option>@endforeach</select><div class="form-text" id="open-item-remaining">เลือกเอกสารเพื่อดูยอดคงเหลือ</div></div>
                    <div class="col-md-3"><label class="form-label">วันที่ตัด</label><input class="form-control" type="date" name="application_date" value="{{ today()->format('Y-m-d') }}" required></div>
                <div class="col-md-3"><label class="form-label">จำนวนเงิน</label><input class="form-control" type="number" min="0.01" step="0.01" name="amount" required><div class="form-text" id="amount-hint">กรุณาเลือกเอกสารก่อนกรอกจำนวนเงิน</div></div>
                </div>
                <div class="mt-4"><button class="btn btn-dark" type="submit" @disabled($options->isEmpty())>บันทึกการตัด</button> <a class="btn btn-outline-secondary" href="{{ route('finance.advance-deposits.index') }}">ยกเลิก</a></div>
            </form>
        </div>
    </div>
</div>
@endsection
@push('scripts')<script>$(function(){var $form=$('#advance-application-form'),$item=$form.find('[name="open_item_id"]'),$amount=$form.find('[name="amount"]'),$remaining=$('#open-item-remaining'),$hint=$('#amount-hint');function syncLimit(){var $option=$item.find('option:selected'),remaining=parseFloat($option.data('remaining'));if(Number.isFinite(remaining)&&remaining>0){$amount.attr('max',remaining.toFixed(2));$remaining.text('ยอดคงเหลือปัจจุบัน: '+remaining.toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}));$hint.text('กรอกได้ไม่เกิน '+remaining.toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}));if(parseFloat($amount.val())>remaining)$amount.val('');}else{$amount.removeAttr('max').val('');$remaining.text('เลือกเอกสารเพื่อดูยอดคงเหลือ');$hint.text('กรุณาเลือกเอกสารก่อนกรอกจำนวนเงิน');}}$item.on('change',syncLimit);syncLimit();window.erpAjaxForm({form:'#advance-application-form',redirect:true});});</script>@endpush
