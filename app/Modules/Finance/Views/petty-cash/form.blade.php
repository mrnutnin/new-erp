@extends('Finance::layout')

@php
    $editing = (bool) (($voucher ?? null)?->exists);
    $payeeType = old('payee_type', $voucher->payee_type ?? 'OTHER');
    $optionValue = static fn ($key, $item) => is_numeric($key) ? $key : data_get($item, 'id');
    $optionLabel = static fn ($item) => is_scalar($item) ? $item : (data_get($item, 'label') ?? data_get($item, 'name') ?? data_get($item, 'code'));
    $existingLines = old('lines', ($voucher->lines ?? collect())->map(fn ($line) => [
        'expense_category_id' => $line->expense_category_id,
        'description' => $line->description,
        'receipt_reference' => $line->receipt_reference,
        'tax_code_id' => $line->tax_code_id,
        'withholding_tax_code_id' => $line->withholding_tax_code_id,
        'amount' => $line->amount,
    ])->values()->all());
@endphp

@section('title', ($editing ? 'แก้ไข' : 'สร้าง').'ใบสำคัญเงินสดย่อย | Finance')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">FINANCE / PETTY CASH</p><h1 class="h3 mb-2">{{ $editing ? 'แก้ไขใบสำคัญเงินสดย่อย' : 'สร้างใบสำคัญเงินสดย่อย' }}</h1><p class="text-secondary mb-0">บันทึกได้เฉพาะ Draft และระบบจะคำนวณ VAT, WHT และยอดจ่ายสุทธิจากรายการ</p></div>
        <a class="btn btn-outline-secondary" href="{{ $editing ? route('finance.petty-cash.show', $voucher) : route('finance.petty-cash.index') }}">กลับ</a>
    </div>

    <form id="petty-cash-form" method="POST" action="{{ $editing ? route('finance.petty-cash.update', $voucher) : route('finance.petty-cash.store') }}">
        @csrf @if($editing) @method('PUT') @endif
        <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="row g-3">
            <div class="col-md-4"><label class="form-label">วงเงินสดย่อย <span class="text-danger">*</span></label><select class="form-select" name="petty_cash_fund_id" required><option value="">เลือกวงเงินสดย่อย</option>@foreach(($fundOptions ?? []) as $key => $item)<option value="{{ $optionValue($key, $item) }}" @selected(old('petty_cash_fund_id', $voucher->petty_cash_fund_id ?? null) == $optionValue($key, $item))>{{ $optionLabel($item) }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label">วันที่เอกสาร <span class="text-danger">*</span></label><input class="form-control" type="date" name="document_date" value="{{ old('document_date', ($voucher->document_date ?? now())->format('Y-m-d')) }}" required></div>
            <div class="col-md-4"><label class="form-label">ประเภทผู้รับเงิน <span class="text-danger">*</span></label><select class="form-select" name="payee_type" id="petty-cash-payee-type" required><option value="EMPLOYEE" @selected($payeeType === 'EMPLOYEE')>Employee</option><option value="SUPPLIER" @selected($payeeType === 'SUPPLIER')>Supplier</option><option value="OTHER" @selected($payeeType === 'OTHER')>อื่น ๆ</option></select></div>
            <div class="col-md-4" data-payee-field="EMPLOYEE"><label class="form-label">พนักงานผู้รับเงิน <span class="text-danger">*</span></label><select class="form-select" name="payee_user_id"><option value="">เลือกพนักงาน</option>@foreach(($payeeUserOptions ?? []) as $id => $name)<option value="{{ $id }}" @selected((string) old('payee_user_id', $voucher->payee_user_id ?? '') === (string) $id)>{{ $name }}</option>@endforeach</select></div>
            <div class="col-md-4" data-payee-field="SUPPLIER"><label class="form-label">Supplier ผู้รับเงิน <span class="text-danger">*</span></label><select class="form-select" name="payee_party_id"><option value="">เลือก Supplier</option>@foreach(($payeeSupplierOptions ?? []) as $id => $name)<option value="{{ $id }}" @selected((string) old('payee_party_id', $voucher->payee_party_id ?? '') === (string) $id)>{{ $name }}</option>@endforeach</select></div>
            <div class="col-md-4" data-payee-field="OTHER"><label class="form-label">ชื่อผู้รับเงิน <span class="text-danger">*</span></label><input class="form-control" name="payee_name" maxlength="255" value="{{ old('payee_name', $voucher->payee_name ?? '') }}"></div>
            @if(!empty($sequenceOptions))<div class="col-md-4"><label class="form-label">รูปแบบเลขเอกสาร</label><select class="form-select" name="document_sequence_id"><option value="">ใช้รูปแบบเริ่มต้น</option>@foreach($sequenceOptions as $key => $item)<option value="{{ $optionValue($key, $item) }}">{{ $optionLabel($item) }}</option>@endforeach</select></div>@endif
            <div class="col-12"><label class="form-label">รายละเอียด</label><textarea class="form-control" name="description" rows="2" maxlength="500">{{ old('description', $voucher->description ?? '') }}</textarea></div>
        </div></div></div>

        <div class="card border-0 shadow-sm mt-4"><div class="card-body p-3 p-lg-4">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">รายการค่าใช้จ่าย</h2><p class="text-secondary small mb-0">VAT และ WHT จะถูกเก็บเป็น snapshot ของเอกสาร เพื่อไม่เปลี่ยนตาม master data ภายหลัง</p></div><button class="btn btn-sm btn-app-soft" id="add-petty-cash-line" type="button"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มรายการ</button></div>
            <div class="table-responsive"><table class="table align-middle" id="petty-cash-lines"><thead><tr><th style="min-width:220px">หมวดค่าใช้จ่าย</th><th style="min-width:180px">รายละเอียด</th><th style="min-width:140px">เลขใบเสร็จ</th><th style="min-width:190px">VAT</th><th style="min-width:190px">WHT</th><th class="text-end" style="min-width:140px">ฐานค่าใช้จ่าย</th><th></th></tr></thead><tbody></tbody><tfoot><tr><th colspan="5" class="text-end">ยอดรวม</th><th class="text-end"><div id="petty-cash-total">0.00</div><small class="d-block text-secondary">VAT <span id="petty-cash-vat-total">0.00</span> · WHT <span id="petty-cash-wht-total">0.00</span></small><strong>จ่ายสุทธิ <span id="petty-cash-net-total">0.00</span></strong></th><th></th></tr></tfoot></table></div>
        </div></div>
        <div class="mt-4 d-flex gap-2"><button class="btn btn-dark" type="submit">{{ $editing ? 'บันทึกการแก้ไข' : 'บันทึกฉบับร่าง' }}</button><a class="btn btn-outline-secondary" href="{{ route('finance.petty-cash.index') }}">ยกเลิก</a></div>
    </form>
</div>

<template id="petty-cash-line-template"><tr>
    <td><select class="form-select" data-name="expense_category_id" required><option value="">เลือกหมวดค่าใช้จ่าย</option>@foreach(($expenseCategoryOptions ?? []) as $key => $item)<option value="{{ $optionValue($key, $item) }}">{{ $optionLabel($item) }}</option>@endforeach</select></td>
    <td><input class="form-control" data-name="description" maxlength="500"></td><td><input class="form-control" data-name="receipt_reference" maxlength="100"></td>
    <td><select class="form-select" data-name="tax_code_id"><option value="">ไม่คิด VAT</option>@foreach(($taxCodeOptions ?? []) as $tax)@if(in_array($tax['kind'], ['VAT_IN', 'NONE_VAT'], true))<option value="{{ $tax['id'] }}" data-rate="{{ $tax['rate'] }}">{{ $tax['code'] }} · {{ $tax['name'] }} ({{ $tax['rate'] }}%)</option>@endif @endforeach</select></td>
    <td><select class="form-select" data-name="withholding_tax_code_id"><option value="">ไม่หัก WHT</option>@foreach(($taxCodeOptions ?? []) as $tax)@if($tax['kind'] === 'WHT')<option value="{{ $tax['id'] }}" data-rate="{{ $tax['rate'] }}">{{ $tax['code'] }} · {{ $tax['name'] }} ({{ $tax['rate'] }}%)</option>@endif @endforeach</select></td>
    <td><input class="form-control text-end js-line-amount" data-name="amount" type="number" min="0.01" step="0.01" required></td><td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-line" type="button" aria-label="ลบรายการ"><i class="bx bx-trash" aria-hidden="true"></i></button></td>
</tr></template>
@endsection

@push('scripts')
<script>
$(function(){var $body=$('#petty-cash-lines tbody'),n=0,template=document.getElementById('petty-cash-line-template'),existing=@json($existingLines);function payeeFields(){var type=$('#petty-cash-payee-type').val();$('[data-payee-field]').each(function(){var active=$(this).data('payee-field')===type;$(this).toggle(active).find('select,input').prop('disabled',!active).prop('required',active);});}function money(v){return (parseFloat(v)||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}function totals(){var base=0,vat=0,wht=0;$body.find('tr').each(function(){var $r=$(this),amount=parseFloat($r.find('[data-name="amount"]').val())||0;base+=amount;var $vat=$r.find('[data-name="tax_code_id"] option:selected'),$wht=$r.find('[data-name="withholding_tax_code_id"] option:selected');vat+=amount*(parseFloat($vat.data('rate'))||0)/100;wht+=amount*(parseFloat($wht.data('rate'))||0)/100;});$('#petty-cash-total').text(money(base));$('#petty-cash-vat-total').text(money(vat));$('#petty-cash-wht-total').text(money(wht));$('#petty-cash-net-total').text(money(base+vat-wht));}function add(line){var $row=$(template.content.firstElementChild.cloneNode(true));$row.find('[data-name]').each(function(){var $input=$(this),name=$input.data('name');$input.attr('name','lines['+n+']['+name+']');if(line&&line[name]!=null)$input.val(line[name]);});n++;$body.append($row);totals();}payeeFields();$('#petty-cash-payee-type').on('change',payeeFields);$.each(existing,function(_,line){add(line);});if(!$body.children().length)add({});$('#add-petty-cash-line').on('click',function(){add({});});$body.on('input change','.js-line-amount,[data-name="tax_code_id"],[data-name="withholding_tax_code_id"]',totals).on('click','.js-remove-line',function(){$(this).closest('tr').remove();totals();});window.erpAjaxForm({form:'#petty-cash-form',redirect:true});});
</script>
@endpush
