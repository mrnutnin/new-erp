@extends($layout ?? 'Finance::layout')

@section('title', ($posReceiptMode ?? false) ? 'รับชำระหนี้ | POS' : 'สร้างเอกสารรับเงิน / จ่ายเงิน | Finance')

@section('content')
    @php
        $allocationRows = array_values(old('allocations', $preselectedOpenItem ? [[
            'open_item_id' => $preselectedOpenItem->id,
            'amount' => $preselectedOpenItem->remaining_amount,
            'label' => $preselectedOpenItem->document_number.' · คงเหลือ '.$preselectedOpenItem->remaining_amount,
        ]] : []));
        $selectedDocumentType = old('document_type', $settlement->document_type);
        $selectedPartyId = old('party_id', $settlement->party_id);
    @endphp
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">{{ ($posReceiptMode ?? false) ? 'POS / RECEIPTS' : 'FINANCE / SETTLEMENTS' }}</p>
        <h1 class="h3 mb-2">{{ ($posReceiptMode ?? false) ? 'รับชำระหนี้' : 'สร้างเอกสารรับเงิน / จ่ายเงิน' }}</h1>
        <p class="text-secondary mb-4">{{ ($posReceiptMode ?? false) ? 'เลือกใบแจ้งหนี้ของลูกค้าและระบุช่องทางรับเงิน' : 'บันทึกร่างพร้อมรายการจัดสรรก่อนส่งอนุมัติ' }}</p>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form id="settlement-form" method="post" action="{{ $storeUrl ?? route('finance.settlements.store') }}"
                      data-party-options-url="{{ $partyOptionsUrl ?? route('finance.settlements.party-options') }}"
                      data-open-item-options-url="{{ $openItemOptionsUrl ?? route('finance.settlements.open-item-options') }}">
                    <div class="row g-3">
                        @if ($posReceiptMode ?? false)
                            <input id="document-type" type="hidden" name="document_type" value="RECEIPT">
                        @else
                        <div class="col-md-3">
                            <label class="form-label" for="document-type">ประเภทเอกสาร</label>
                            <select class="form-select" id="document-type" name="document_type" required>
                                <option value="RECEIPT" @selected($selectedDocumentType === 'RECEIPT')>รับเงิน</option>
                                <option value="PAYMENT" @selected($selectedDocumentType === 'PAYMENT')>จ่ายเงิน</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="document_type"></div>
                        </div>
                        @endif
                        <div class="col-md-4">
                            <label class="form-label">เลขที่เอกสาร</label>
                            <input class="form-control" value="ระบบออกเลขอัตโนมัติเมื่อบันทึก" disabled>
                        </div>
                        <div class="col-md-5"><label class="form-label">ช่องทางรับ/จ่าย</label><input class="form-control" value="ระบุได้หลายช่องทางด้านล่าง" disabled></div>
                        <div class="col-md-3">
                            <label class="form-label" for="document-date">วันที่เอกสาร</label>
                            <input class="form-control" id="document-date" type="date" name="document_date" value="{{ old('document_date', $settlement->document_date?->format('Y-m-d')) }}" required>
                            <div class="invalid-feedback" data-error-for="document_date"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="settlement-date">วันที่รับ/จ่ายจริง</label>
                            <input class="form-control" id="settlement-date" type="date" name="settlement_date" value="{{ old('settlement_date', $settlement->settlement_date?->format('Y-m-d')) }}" required>
                            <div class="invalid-feedback" data-error-for="settlement_date"></div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">ประเภทคู่ค้า</label>
                            <input class="form-control" id="party-type-label" value="{{ $selectedDocumentType === 'PAYMENT' ? 'Supplier' : 'ลูกค้า' }}" disabled>
                            <input id="party-type" type="hidden" name="party_type" value="{{ $selectedDocumentType === 'PAYMENT' ? 'SUPPLIER' : 'CUSTOMER' }}">
                            <div class="invalid-feedback" data-error-for="party_type"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="party-id">คู่ค้า</label>
                            <select class="form-select js-settlement-party" id="party-id" name="party_id" required>
                                @if ($selectedPartyId)
                                    <option value="{{ $selectedPartyId }}" selected>{{ $preselectedOpenItem?->party?->code ?? $selectedPartyId }} · {{ $preselectedOpenItem?->party?->name ?? $selectedPartyId }}</option>
                                @endif
                            </select>
                            <div class="invalid-feedback" data-error-for="party_id"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="payment-term">เงื่อนไขการชำระเงิน</label>
                            <select class="form-select" id="payment-term" name="payment_term_id">
                                <option value="">ไม่ระบุ</option>
                                @foreach ($paymentTerms as $term)
                                    <option value="{{ $term->id }}" @selected((string) old('payment_term_id') === (string) $term->id)>{{ $term->code }} · {{ $term->name }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback" data-error-for="payment_term_id"></div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="gross-amount">ยอดรวม</label>
                            <input class="form-control text-end" id="gross-amount" type="number" min="0.01" step="0.01" name="gross_amount" value="{{ old('gross_amount', $settlement->gross_amount) }}" required>
                            <div class="invalid-feedback" data-error-for="gross_amount"></div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="tax-amount">ภาษี (รวมในยอด)</label>
                            <input class="form-control text-end" id="tax-amount" type="number" min="0" step="0.01" name="tax_amount" value="{{ old('tax_amount', $settlement->tax_amount) }}" required>
                            <div class="invalid-feedback" data-error-for="tax_amount"></div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="withholding-amount">หัก ณ ที่จ่าย</label>
                            <input class="form-control text-end" id="withholding-amount" type="number" min="0" step="0.01" name="withholding_amount" value="{{ old('withholding_amount', $settlement->withholding_amount) }}" @readonly($preselectedOpenItem?->withholding_tax_code_id) required>
                            @if ($preselectedOpenItem?->withholding_tax_code_id)<div class="form-text">คำนวณจาก WHT snapshot ของเอกสาร</div>@endif
                            <div class="invalid-feedback" data-error-for="withholding_amount"></div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="net-amount">ยอดสุทธิ</label>
                            <input class="form-control text-end" id="net-amount" type="number" min="0" step="0.01" name="net_amount" value="{{ old('net_amount', $settlement->net_amount) }}" required>
                            <div class="invalid-feedback" data-error-for="net_amount"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="description">รายละเอียด</label>
                            <textarea class="form-control" id="description" rows="3" name="description" maxlength="500">{{ old('description') }}</textarea>
                            <div class="invalid-feedback" data-error-for="description"></div>
                        </div>
                    </div>

                    <div class="mt-4"><div class="d-flex justify-content-between align-items-center mb-2"><div><h2 class="h5 mb-1">ช่องทางรับ/จ่ายเงิน</h2><p class="text-secondary mb-0">ผลรวมต้องเท่ากับยอดสุทธิหลังหัก ณ ที่จ่าย@if($posReceiptMode ?? false) · ระบบจะแสดง IV ตามคลังของบัญชีที่เลือก@endif</p></div><div><span class="badge text-bg-info" id="tender-summary">0.00</span><button class="btn btn-sm btn-app-soft ms-2" id="add-tender" type="button"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มช่องทาง</button></div></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>บัญชีเงินสด/ธนาคาร</th><th class="text-end">จำนวนเงิน</th><th>เลขอ้างอิง</th><th></th></tr></thead><tbody id="tender-rows"><tr class="js-tender-row"><td><select class="form-select" name="tenders[0][bank_account_id]" required><option value="">เลือกบัญชี</option>@foreach($bankAccounts as $bankAccount)<option value="{{ $bankAccount->id }}">{{ $bankAccount->code }} · {{ $bankAccount->name }}@if(($posReceiptMode ?? false) && $bankAccount->relationLoaded('warehouse')) · {{ $bankAccount->warehouse->name }}@endif</option>@endforeach</select></td><td><input class="form-control text-end js-tender-amount" type="number" min="0.01" step="0.01" name="tenders[0][amount]" value="{{ old('net_amount', $settlement->net_amount) }}" required></td><td><input class="form-control" maxlength="100" name="tenders[0][reference]" placeholder="เช่น เลขสลิป"></td><td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-tender" type="button" disabled>ลบ</button></td></tr></tbody></table></div><div class="invalid-feedback d-block" data-error-for="tenders"></div></div>

                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mt-4 mb-3">
                        <div>
                            <h2 class="h5 mb-1">จัดสรรรายการคงค้าง</h2>
                            <p class="text-secondary mb-0">เลือกได้สูงสุด 100 รายการต่อเอกสาร; เงินรับที่เกินยอดจัดสรรจะบันทึกเป็นเงินรับล่วงหน้า</p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge text-bg-info" id="allocation-summary">0 รายการ · 0.00</span>
                            <span class="badge text-bg-secondary d-none" id="advance-summary"></span>
                            <button class="btn btn-sm btn-app-soft" id="add-allocation" type="button"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มรายการ</button>
                        </div>
                    </div>
                    <div class="invalid-feedback d-block" data-error-for="allocations"></div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th>เอกสารคงค้าง</th><th class="text-end">ยอดจัดสรร</th><th class="text-end">จัดการ</th></tr></thead>
                            <tbody id="allocation-rows">
                                @foreach ($allocationRows as $index => $allocation)
                                    <tr class="js-allocation-row">
                                        <td>
                                            <select class="form-select js-open-item" name="allocations[{{ $index }}][open_item_id]" required>
                                                @if (! empty($allocation['open_item_id']))
                                                    <option value="{{ $allocation['open_item_id'] }}" selected>{{ $allocation['label'] ?? 'รายการ #'.$allocation['open_item_id'] }}</option>
                                                @endif
                                            </select>
                                            <div class="invalid-feedback d-block" data-error-for="allocations.{{ $index }}.open_item_id"></div>
                                        </td>
                                        <td>
                                            <input class="form-control text-end js-allocation-amount" type="number" min="0.01" max="9999999999999999.99" step="0.01" name="allocations[{{ $index }}][amount]" value="{{ $allocation['amount'] ?? '' }}" required>
                                            <div class="invalid-feedback d-block" data-error-for="allocations.{{ $index }}.amount"></div>
                                        </td>
                                        <td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-allocation" type="button"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        <button class="btn btn-dark" type="submit">บันทึกร่าง</button>
                        <a class="btn btn-outline-secondary" href="{{ $backUrl ?? route('finance.settlements.index') }}">ย้อนกลับ</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <template id="allocation-row-template">
        <tr class="js-allocation-row">
            <td><select class="form-select js-open-item" name="allocations[__INDEX__][open_item_id]" required></select><div class="invalid-feedback d-block" data-error-for="allocations.__INDEX__.open_item_id"></div></td>
            <td><input class="form-control text-end js-allocation-amount" type="number" min="0.01" max="9999999999999999.99" step="0.01" name="allocations[__INDEX__][amount]" required><div class="invalid-feedback d-block" data-error-for="allocations.__INDEX__.amount"></div></td>
            <td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-allocation" type="button"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ</button></td>
        </tr>
    </template>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $form = $('#settlement-form');
            var $documentType = $('#document-type');
            var $settlementDate = $('#settlement-date');
            var $party = $('#party-id');
            var $rows = $('#allocation-rows');
            var posReceiptMode = {{ ($posReceiptMode ?? false) ? 'true' : 'false' }};
            var nextIndex = {{ count($allocationRows) }};
            var maxRows = 100;

            function syncPartyType() {
                var isPayment = $documentType.val() === 'PAYMENT';
                $('#party-type').val(isPayment ? 'SUPPLIER' : 'CUSTOMER');
                $('#party-type-label').val(isPayment ? 'Supplier' : 'ลูกค้า');
            }

            function updateSummary() {
                var total = 0;
                $rows.find('.js-allocation-amount').each(function () { total += parseFloat($(this).val()) || 0; });
                $('#allocation-summary').text($rows.find('.js-allocation-row').length + ' รายการ · ' + total.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                var difference = (parseFloat($('#gross-amount').val()) || 0) - total;
                var $advance = $('#advance-summary');
                if ($documentType.val() === 'RECEIPT' && difference > 0) {
                    $advance.removeClass('d-none text-bg-danger').addClass('text-bg-warning').text('เงินรับล่วงหน้า · ' + difference.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                } else if (difference < 0) {
                    $advance.removeClass('d-none text-bg-warning').addClass('text-bg-danger').text('ยอดจัดสรรเกิน · ' + Math.abs(difference).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                } else {
                    $advance.addClass('d-none').removeClass('text-bg-warning text-bg-danger').text('');
                }
            }

            function initOpenItem($select) {
                $select.prop('disabled', !$party.val() || (posReceiptMode && !selectedBankAccount())).select2({
                    width: '100%',
                    placeholder: 'เลือกเอกสารคงค้าง',
                    allowClear: true,
                    ajax: {
                        url: $form.data('open-item-options-url'),
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return { q: params.term || '', page: params.page || 1, document_type: $documentType.val(), party_id: $party.val(), settlement_date: $settlementDate.val(), bank_account_id: selectedBankAccount() };
                        },
                        processResults: function (response) { return response; }
                    }
                }).on('select2:select', function (event) {
                    var remaining = parseFloat(event.params.data.remaining_amount);
                    var $amount = $(this).closest('tr').find('.js-allocation-amount');
                    if (Number.isFinite(remaining) && remaining > 0) {
                        $amount.attr('max', remaining.toFixed(2));
                        if (!$amount.val()) $amount.val(remaining.toFixed(2));
                    }
                    updateSummary();
                }).on('select2:clear', function () {
                    $(this).closest('tr').find('.js-allocation-amount').removeAttr('max').val('');
                    updateSummary();
                });
            }

            function appendRow() {
                var html = $('#allocation-row-template').html().split('__INDEX__').join(nextIndex++);
                var $row = $(html).appendTo($rows);
                initOpenItem($row.find('.js-open-item'));
                updateSummary();
            }

            function resetAllocations() {
                $rows.empty();
                updateSummary();
            }

            function selectedBankAccount() {
                return $('#tender-rows select[name="tenders[0][bank_account_id]"]').val();
            }

            $party.select2({
                width: '100%',
                placeholder: 'ค้นหาคู่ค้า',
                allowClear: true,
                ajax: {
                    url: $form.data('party-options-url'),
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return { q: params.term || '', page: params.page || 1, document_type: $documentType.val(), settlement_date: $settlementDate.val(), bank_account_id: selectedBankAccount() };
                    },
                    processResults: function (response) { return response; }
                }
            }).on('change', function () { resetAllocations(); });

            if (posReceiptMode) {
                $party.prop('disabled', !selectedBankAccount()).trigger('change.select2');
                $(document).on('change', '#tender-rows select[name="tenders[0][bank_account_id]"]', function () {
                    $party.prop('disabled', !selectedBankAccount()).val(null).trigger('change').trigger('change.select2');
                });
            }

            $rows.find('.js-open-item').each(function () { initOpenItem($(this)); });
            $documentType.on('change', function () { syncPartyType(); $party.val(null).trigger('change'); });
            $settlementDate.on('change', function () { $party.val(null).trigger('change'); });
            $('#add-allocation').on('click', function () {
                if (!$party.val()) {
                    Swal.fire({ icon: 'info', text: 'กรุณาเลือกคู่ค้าก่อนเพิ่มรายการจัดสรร' });
                    return;
                }
                if ($rows.find('.js-allocation-row').length >= maxRows) {
                    Swal.fire({ icon: 'warning', text: 'เพิ่มรายการจัดสรรได้สูงสุด 100 รายการ' });
                    return;
                }
                appendRow();
            });
            $(document).on('click', '.js-remove-allocation', function () {
                $(this).closest('.js-allocation-row').remove();
                updateSummary();
            }).on('input', '.js-allocation-amount, #gross-amount', updateSummary);

            var tenderIndex = 1;
            function syncTenders() { var total = 0; $('#tender-rows .js-tender-amount').each(function () { total += parseFloat($(this).val()) || 0; }); $('#tender-summary').text(total.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })); }
            $('#add-tender').on('click', function () { var i = tenderIndex++; $('#tender-rows').append('<tr class="js-tender-row"><td><select class="form-select" name="tenders['+i+'][bank_account_id]" required><option value="">เลือกบัญชี</option>@foreach($bankAccounts as $bankAccount)<option value="{{ $bankAccount->id }}">{{ $bankAccount->code }} · {{ $bankAccount->name }}</option>@endforeach</select></td><td><input class="form-control text-end js-tender-amount" type="number" min="0.01" step="0.01" name="tenders['+i+'][amount]" required></td><td><input class="form-control" maxlength="100" name="tenders['+i+'][reference]" placeholder="เช่น เลขสลิป"></td><td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-tender" type="button">ลบ</button></td></tr>'); });
            $(document).on('click', '.js-remove-tender', function () { $(this).closest('tr').remove(); syncTenders(); }).on('input', '.js-tender-amount', syncTenders);
            syncPartyType(); syncTenders();
            updateSummary();
            window.erpAjaxForm({ form: '#settlement-form', redirect: true });
        });
    </script>
@endpush
