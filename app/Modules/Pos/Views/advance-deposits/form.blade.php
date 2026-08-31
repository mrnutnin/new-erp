@extends('Pos::layout')
@section('title', 'สร้างใบรับเงินล่วงหน้า | POS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">POS / ADVANCE DEPOSIT</p><h1 class="h3 mb-4">สร้างใบรับเงินล่วงหน้า</h1>
    <form id="advance-deposit-form" method="post" action="{{ route('pos.advance-deposits.store') }}">@csrf
        <div class="card border-0 shadow-sm mb-3"><div class="card-body p-4"><div class="row g-3">
            <div class="col-md-6"><div class="d-flex align-items-center justify-content-between gap-2 mb-2"><label class="form-label mb-0">ลูกค้า <span class="text-danger">*</span></label>@if(auth()->user()->hasPermission('pos.customers.create'))<button class="btn btn-sm btn-app-soft js-quick-customer" type="button"><i class="bx bx-plus" aria-hidden="true"></i> เพิ่มลูกค้า</button>@endif</div><select class="form-select" id="ai-party-id" name="party_id" required></select><div class="invalid-feedback" data-error-for="party_id"></div></div>
            <div class="col-md-3"><label class="form-label">วันที่เอกสาร *</label><input class="form-control" type="date" name="document_date" value="{{ today()->format('Y-m-d') }}" required></div>
            <div class="col-md-3"><label class="form-label">การคำนวณภาษี *</label><input type="hidden" id="ai-prices-include-vat" name="prices_include_vat" value="1"><select class="form-select" id="ai-tax-treatment" name="tax_treatment"><option value="VAT_INCLUSIVE">รวมภาษี</option><option value="VAT_EXCLUSIVE">ภาษีนอก</option><option value="NONE">ไม่มีภาษี</option></select></div>
            <div class="col-md-4" id="ai-tax-code-wrap"><label class="form-label">อัตราภาษี VAT <span class="text-danger">*</span></label><select class="form-select" id="ai-tax-code" name="tax_code_id"><option value="">เลือก Tax Code ภาษีขาย</option>@foreach($vatTaxCodes as $code)<option value="{{ $code->id }}" data-rate="{{ $code->rate }}">{{ $code->code }} · {{ $code->name }} ({{ $code->rate }}%)</option>@endforeach</select><div class="form-text">ใช้เมื่อเลือก “รวมภาษี” หรือ “ภาษีนอก” เท่านั้น</div><div class="invalid-feedback" data-error-for="tax_code_id"></div></div>
            <div class="col-md-4"><label class="form-label" id="ai-amount-label">ยอดรับ (รวม VAT) <span class="text-danger">*</span></label><input class="form-control text-end" type="number" name="gross_amount" min="0.01" step="0.01" required><div class="invalid-feedback" data-error-for="gross_amount"></div></div>
            <div class="col-md-4"><label class="form-label">หัก ณ ที่จ่าย</label><select class="form-select" id="ai-wht-code" name="withholding_tax_code_id"><option value="">ไม่หัก ณ ที่จ่าย</option>@foreach($whtTaxCodes as $code)<option value="{{ $code->id }}" data-rate="{{ $code->rate }}">{{ $code->code }} · {{ $code->name }} ({{ $code->rate }}%)</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label">ฐาน WHT</label><input class="form-control text-end" id="ai-wht-base" type="number" name="withholding_base" min="0" step="0.01" value="0.00"></div>
            <div class="col-md-4"><div class="border rounded-3 bg-body-tertiary p-3"><div class="d-flex justify-content-between small"><span>ยอดก่อน WHT</span><strong id="ai-gross-calculated">0.00</strong></div><div class="d-flex justify-content-between small mt-1"><span>หัก ณ ที่จ่าย</span><strong id="ai-wht-calculated">0.00</strong></div><div class="border-top mt-2 pt-2 d-flex justify-content-between"><span>รับสุทธิ</span><strong id="ai-net-amount">0.00</strong></div></div></div>
            <div class="col-12"><label class="form-label">รายละเอียด</label><textarea class="form-control" name="description" maxlength="500" rows="2"></textarea></div>
        </div></div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">ช่องทางรับเงิน</h2><button class="btn btn-app-soft d-inline-flex align-items-center gap-1" id="ai-add-tender" type="button"><i class="bx bx-plus"></i>เพิ่มช่องทาง</button></div><table class="table mt-3"><thead><tr><th>บัญชีเงินสด/ธนาคาร</th><th>จำนวนเงิน</th><th>เลขอ้างอิง</th><th></th></tr></thead><tbody id="ai-tender-rows"></tbody></table><div class="invalid-feedback d-block" data-error-for="tenders"></div><div class="d-flex gap-2 mt-4"><a class="btn btn-outline-secondary" href="{{ route('pos.advance-deposits.index') }}">ยกเลิก</a><button class="btn btn-dark" type="submit">บันทึกใบรับเงินล่วงหน้า</button></div></div></div>
    </form>
</div>
<template id="ai-tender-template"><tr><td><select class="form-select" name="tenders[__INDEX__][bank_account_id]" required><option value="">เลือกบัญชีเงินสด/ธนาคาร</option>@foreach($bankAccounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></td><td><input class="form-control text-end js-ai-tender-amount" type="number" name="tenders[__INDEX__][amount]" min="0.01" step="0.01" required></td><td><input class="form-control" name="tenders[__INDEX__][reference]" maxlength="100"></td><td><button class="btn btn-outline-danger js-ai-remove" type="button">ลบ</button></td></tr></template>
@if(auth()->user()->hasPermission('pos.customers.create'))<div class="modal fade" id="quick-customer-modal" tabindex="-1" aria-labelledby="quick-customer-title" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="quick-customer-form"><div class="modal-header"><h2 class="modal-title fs-5" id="quick-customer-title">เพิ่มลูกค้า</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div><div class="modal-body"><div class="alert alert-info py-2 small">รหัสลูกค้าระบบกำหนดอัตโนมัติเมื่อบันทึก</div><div class="row g-3"><div class="col-12"><label class="form-label">ชื่อลูกค้า <span class="text-danger">*</span></label><input class="form-control" name="name" maxlength="255" required></div><div class="col-md-6"><label class="form-label">ประเภท <span class="text-danger">*</span></label><select class="form-select" name="type"><option value="COMPANY">นิติบุคคล</option><option value="INDIVIDUAL">บุคคลธรรมดา</option></select></div><div class="col-md-6"><label class="form-label">เลขประจำตัวผู้เสียภาษี</label><input class="form-control" name="tax_id" maxlength="13" inputmode="numeric"></div><div class="col-md-6"><label class="form-label">โทรศัพท์ <span class="text-danger">*</span></label><input class="form-control" name="phone" maxlength="50" required></div><div class="col-md-6"><label class="form-label">อีเมล</label><input class="form-control" name="email" type="email" maxlength="255"></div><div class="col-12 d-none" data-quick-customer-matches></div></div><input type="hidden" name="branch_code" value="00000"><input type="hidden" name="credit_limit" value="0"><input type="hidden" name="is_active" value="1"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary" type="submit">บันทึกและเลือก</button></div></form></div></div>@endif
@endsection
@push('scripts')
<script>
$(function () {
    let i = 0;
    const rows = $('#ai-tender-rows');
    const money = n => Number(n || 0).toLocaleString('th-TH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const net = () => Number($('#ai-net-amount').data('raw') || 0);
    const sync = () => {
        const treatment = $('#ai-tax-treatment').val();
        const hasVat = treatment !== 'NONE';
        const included = treatment === 'VAT_INCLUSIVE';
        const tax = $('#ai-tax-code');
        if (hasVat && !tax.val()) tax.val(tax.find('option[value!=""]').first().val());
        const rate = Number(tax.find(':selected').data('rate') || 0);
        const amount = Number($('[name="gross_amount"]').val() || 0);
        const gross = treatment === 'VAT_EXCLUSIVE' ? amount * (1 + rate / 100) : amount;
        const wht = $('#ai-wht-code').val() ? Number($('#ai-wht-base').val() || 0) * Number($('#ai-wht-code :selected').data('rate') || 0) / 100 : 0;
        $('#ai-prices-include-vat').val(included ? '1' : '0');
        tax.prop('disabled', !hasVat).prop('required', hasVat);
        if (!hasVat) tax.val('');
        $('#ai-tax-code-wrap').toggle(hasVat);
        $('#ai-amount-label').html(treatment === 'VAT_EXCLUSIVE' ? 'ยอดก่อน VAT <span class="text-danger">*</span>' : treatment === 'NONE' ? 'ยอดรับ <span class="text-danger">*</span>' : 'ยอดรับ (รวม VAT) <span class="text-danger">*</span>');
        $('#ai-gross-calculated').text(money(gross));
        $('#ai-wht-calculated').text(money(wht));
        $('#ai-net-amount').text(money(Math.max(0, gross - wht))).data('raw', Math.max(0, gross - wht));
        const first = rows.children('tr').first();
        if (first.data('auto-default')) first.find('.js-ai-tender-amount').val(net().toFixed(2));
    };
    const add = auto => {
        const row = $($('#ai-tender-template').html().replaceAll('__INDEX__', i++));
        if (auto) row.data('auto-default', true);
        rows.append(row);
        sync();
    };
    add(true);
    $('#ai-add-tender').on('click', () => add(false));
    rows.on('click', '.js-ai-remove', function () { $(this).closest('tr').remove(); })
        .on('input', '.js-ai-tender-amount', function () { $(this).closest('tr').data('auto-default', false); });
    $('#ai-tax-treatment,#ai-tax-code,#ai-wht-code,#ai-wht-base,[name="gross_amount"]').on('change input', sync);
    sync();
    $('#ai-party-id').select2({theme: 'bootstrap-5', width: '100%', ajax: {url: '{{ route('pos.sales-documents.party-options') }}', dataType: 'json', delay: 250, data: p => ({q: p.term || '', page: p.page || 1}), processResults: d => d}});
    @if(auth()->user()->hasPermission('pos.customers.create'))
    let quickCustomerTimer, quickCustomerRequest = 0;
    const useQuickCustomer = customer => { $('#ai-party-id').append(new Option(customer.text, customer.id, true, true)).trigger('change'); bootstrap.Modal.getInstance(document.getElementById('quick-customer-modal')).hide(); };
    const quickCustomerMatches = response => { const form = $('#quick-customer-form'), box = form.find('[data-quick-customer-matches]').empty(), save = form.find('[type=submit]').prop('disabled', !!response.hard_match); if (!(response.results || []).length) return box.addClass('d-none'); box.removeClass('d-none').append($('<div class="alert mb-0 py-2"></div>').addClass(response.hard_match ? 'alert-warning' : 'alert-info').text(response.hard_match ? 'พบข้อมูลที่มีรหัสหรือเลขภาษีตรงกัน กรุณาใช้ข้อมูลเดิม' : 'พบลูกค้าที่คล้ายกัน โปรดตรวจสอบก่อนบันทึก')); response.results.forEach(customer => { const row = $('<div class="d-flex justify-content-between align-items-center gap-2 border-top pt-2 mt-2"></div>').append($('<span></span>').text(customer.text)); if (customer.can_select) row.append($('<button class="btn btn-sm btn-app-soft js-use-existing-customer" type="button">เลือก</button>').data('customer', customer)); box.append(row); }); };
    const checkQuickCustomer = () => { const form = $('#quick-customer-form'), request = ++quickCustomerRequest; if (!form.find('[name=name],[name=tax_id],[name=phone],[name=email]').filter(function () { return this.value.trim(); }).length) { form.find('[data-quick-customer-matches]').empty().addClass('d-none'); form.find('[type=submit]').prop('disabled', false); return; } $.get('{{ route('pos.customers.quick-options') }}', form.serialize()).done(response => { if (request === quickCustomerRequest) quickCustomerMatches(response); }); };
    $('.js-quick-customer').on('click', () => { const form = $('#quick-customer-form'); form[0].reset(); form.find('[data-quick-customer-matches]').empty().addClass('d-none'); form.find('[type=submit]').prop('disabled', false); bootstrap.Modal.getOrCreateInstance(document.getElementById('quick-customer-modal')).show(); });
    $('#quick-customer-form').on('input change', '[name=name],[name=tax_id],[name=phone],[name=email]', () => { clearTimeout(quickCustomerTimer); quickCustomerTimer = setTimeout(checkQuickCustomer, 250); }).on('click', '.js-use-existing-customer', function () { useQuickCustomer($(this).data('customer')); }).on('submit', function (event) { event.preventDefault(); const form = $(this), button = form.find('[type=submit]').prop('disabled', true); $.post('{{ route('pos.customers.store') }}', form.serialize()).done(response => { useQuickCustomer(response.customer); form[0].reset(); }).fail(xhr => Swal.fire({icon: 'error', title: 'เพิ่มลูกค้าไม่สำเร็จ', text: xhr.responseJSON?.message || Object.values(xhr.responseJSON?.errors || {}).flat()[0] || 'กรุณาตรวจสอบข้อมูล'})).always(() => button.prop('disabled', false)); });
    @endif
    window.erpAjaxForm({form: '#advance-deposit-form', redirect: true});
});
</script>
@endpush
