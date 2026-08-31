@extends('Pos::layout')

@section('title', 'รับชำระเงิน '.$sale->document_number)

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">POS / RECEIVE PAYMENT</p>
        <h1 class="h3 mb-2">รับชำระเงิน</h1>
        <p class="text-secondary mb-4">บันทึกรับเงินจากลูกค้าโดยไม่ต้องออกจาก POS</p>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-5"><div class="text-secondary small">ลูกค้า</div><strong>{{ $sale->party_code }} · {{ $sale->party_name }}</strong></div>
                    <div class="col-md-3"><div class="text-secondary small">เอกสาร</div><strong>{{ $sale->document_number }}</strong></div>
                    <div class="col-md-2"><div class="text-secondary small">คงเหลือ</div><strong>{{ number_format((float) $remaining, 2) }}</strong></div>
                    <div class="col-md-2"><div class="text-secondary small">หัก ณ ที่จ่าย</div><strong id="withholding-amount">{{ number_format((float) $withholding, 2) }}</strong></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form id="physical-sale-receipt-form" method="post" action="{{ route('pos.physical-sales.receive-payment.store', $sale) }}" data-summary-url="{{ route('pos.physical-sales.receive-payment.summary', $sale) }}">
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label" for="settlement-date">วันที่รับชำระ</label><input class="form-control" id="settlement-date" type="date" name="settlement_date" value="{{ old('settlement_date', today()->format('Y-m-d')) }}" required><div class="invalid-feedback" data-error-for="settlement_date"></div></div>
                        <div class="col-md-3"><label class="form-label" for="allocation-amount">ยอดตัดชำระ <span class="text-danger">*</span></label><input class="form-control text-end" id="allocation-amount" type="number" name="allocation_amount" min="0.01" max="{{ $remaining }}" step="0.01" value="{{ old('allocation_amount', $remaining) }}" required><div class="form-text">ตัดชำระบางส่วนได้ สูงสุด {{ number_format((float) $remaining, 2) }}</div></div>
                        <div class="col-md-3"><label class="form-label">ยอดสุทธิที่ต้องรับ</label><input class="form-control text-end" id="net-amount" value="{{ number_format((float) $net, 2) }}" disabled></div>
                        <div class="col-md-3"><label class="form-label">ยอดรับจริง</label><input class="form-control text-end" id="received-total" value="{{ number_format((float) $net, 2) }}" disabled></div>
                        <div class="col-12"><label class="form-label" for="description">รายละเอียด</label><textarea class="form-control" id="description" name="description" rows="2" maxlength="500" placeholder="เช่น เลขสลิป หรือหมายเหตุ">{{ old('description') }}</textarea><div class="invalid-feedback" data-error-for="description"></div></div>
                    </div>

                    <div class="mt-4 d-flex justify-content-between align-items-center gap-2"><div><h2 class="h5 mb-1">ช่องทางรับเงิน</h2><p class="text-secondary mb-0">เพิ่มได้หลายช่องทาง เช่น เงินสดและโอนเงินในบิลเดียว</p></div><button class="btn btn-app-soft" id="add-tender" type="button"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มช่องทาง</button></div>
                    <div class="table-responsive mt-3"><table class="table align-middle mb-0"><thead><tr><th>บัญชีเงินสด/ธนาคาร</th><th class="text-end">จำนวนเงิน</th><th>เลขอ้างอิง</th><th></th></tr></thead><tbody id="tender-rows"><tr><td><select class="form-select" name="tenders[0][bank_account_id]" required><option value="">เลือกบัญชี</option>@foreach ($bankAccounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></td><td><input class="form-control text-end js-tender-amount" type="number" name="tenders[0][amount]" min="0.01" step="0.01" value="{{ old('tenders.0.amount', $net) }}" required></td><td><input class="form-control" name="tenders[0][reference]" maxlength="100" placeholder="เช่น เลขสลิป"></td><td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-tender" type="button" disabled>ลบ</button></td></tr></tbody></table></div>
                    <div class="invalid-feedback d-block" data-error-for="tenders"></div>
                    <div class="alert alert-warning mt-4 mb-0 d-none" id="overpayment-note">ยอดรับจริงเกินยอดสุทธิ ส่วนเกินจะบันทึกเป็นเงินรับล่วงหน้าของลูกค้ารายนี้</div>
                    <div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit">บันทึกรับชำระ</button><a class="btn btn-outline-secondary" href="{{ route('pos.physical-sales.show', $sale) }}">ย้อนกลับ</a></div>
                </form>
            </div>
        </div>
    </div>

    <template id="tender-row-template"><tr><td><select class="form-select" name="tenders[__INDEX__][bank_account_id]" required><option value="">เลือกบัญชี</option>@foreach ($bankAccounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></td><td><input class="form-control text-end js-tender-amount" type="number" name="tenders[__INDEX__][amount]" min="0.01" step="0.01" required></td><td><input class="form-control" name="tenders[__INDEX__][reference]" maxlength="100" placeholder="เช่น เลขสลิป"></td><td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-tender" type="button">ลบ</button></td></tr></template>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $rows = $('#tender-rows'), nextIndex = 1, net = {{ Js::from((float) $net) }}, remaining = {{ Js::from((float) $remaining) }}, withholding = {{ Js::from((float) $withholding) }};
            function sync() {
                var total = 0;
                $rows.find('.js-tender-amount').each(function () { total += parseFloat($(this).val()) || 0; });
                $('#received-total').val(total.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                $('#overpayment-note').toggleClass('d-none', total <= net + 0.00001);
            }
            $('#add-tender').on('click', function () { $rows.append($('#tender-row-template').html().replaceAll('__INDEX__', nextIndex++)); });
            $(document).on('click', '.js-remove-tender', function () { $(this).closest('tr').remove(); sync(); }).on('input', '.js-tender-amount', sync);
            function refreshSummary() {
                var allocation = $('#allocation-amount').val(), date = $('#settlement-date').val();
                if (!allocation || !date) return;
                $.getJSON($('#physical-sale-receipt-form').data('summary-url'), { allocation_amount: allocation, settlement_date: date }).done(function (data) {
                    remaining = parseFloat(data.remaining) || 0; withholding = parseFloat(data.withholding) || 0; net = parseFloat(data.net) || 0;
                    $('#allocation-amount').attr('max', remaining.toFixed(2)); $('#withholding-amount').text(withholding.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })); $('#net-amount').val(net.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                    if ($rows.find('.js-tender-amount').length === 1) $rows.find('.js-tender-amount').val(net.toFixed(2));
                    sync();
                });
            }
            $('#allocation-amount, #settlement-date').on('change input', refreshSummary);
            $('#physical-sale-receipt-form').on('submit', function (event) {
                var received = 0;
                $rows.find('.js-tender-amount').each(function () { received += parseFloat($(this).val()) || 0; });
                var allocation = parseFloat($('#allocation-amount').val()) || 0;
                if (received + withholding + 0.00001 < allocation) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    Swal.fire({ icon: 'error', text: 'ยอดเงินรับรวมภาษีหัก ณ ที่จ่ายต้องไม่น้อยกว่ายอดบิล' });
                }
            });
            sync();
            window.erpAjaxForm({ form: '#physical-sale-receipt-form', redirect: true });
        });
    </script>
@endpush
