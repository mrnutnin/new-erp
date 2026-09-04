@extends('Accounting::layout')

@section('title', ($journalEntry->exists ? 'แก้ไข' : 'เพิ่ม').'รายการบัญชี | New ERP')

@section('content')
    @php($lines = old('lines', $journalEntry->exists ? $journalEntry->lines->map->only(['account_id', 'description', 'debit', 'credit', 'tax_code_id', 'tax_base', 'tax_amount', 'tax_point_date', 'tax_settlement_date'])->all() : [['account_id' => '', 'description' => '', 'debit' => '0.00', 'credit' => '0.00', 'tax_code_id' => '', 'tax_base' => '', 'tax_amount' => '', 'tax_point_date' => '', 'tax_settlement_date' => ''], ['account_id' => '', 'description' => '', 'debit' => '0.00', 'credit' => '0.00', 'tax_code_id' => '', 'tax_base' => '', 'tax_amount' => '', 'tax_point_date' => '', 'tax_settlement_date' => '']]))
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <p class="eyebrow mb-2">ACCOUNTING / GENERAL JOURNAL</p>
            <h1 class="h3 mb-2">{{ $journalEntry->exists ? 'แก้ไขรายการบัญชี Draft' : 'เพิ่มรายการบัญชีทั่วไป' }}</h1>
            <p class="text-secondary mb-0">รายการต้องมีเดบิตเท่ากับเครดิต และบันทึกเป็น Draft ก่อนเข้าสู่ขั้นอนุมัติ</p>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <form id="journal-entry-form" action="{{ $journalEntry->exists ? route('accounting.journal-entries.update', $journalEntry) : route('accounting.journal-entries.store') }}" method="post" novalidate>
                    @csrf
                    @if ($journalEntry->exists) @method('PUT') @endif

                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="entry_date">วันที่ลงบัญชี</label>
                            <input class="form-control" type="date" id="entry_date" name="entry_date" value="{{ old('entry_date', $journalEntry->entry_date?->format('Y-m-d')) }}" required>
                            <div class="invalid-feedback" data-error-for="entry_date"></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="document_date">วันที่เอกสาร</label>
                            <input class="form-control" type="date" id="document_date" name="document_date" value="{{ old('document_date', $journalEntry->document_date?->format('Y-m-d')) }}">
                            <div class="invalid-feedback" data-error-for="document_date"></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="source_reference">เอกสารอ้างอิง</label>
                            <input class="form-control" id="source_reference" name="source_reference" value="{{ old('source_reference', $journalEntry->source_reference) }}" maxlength="100">
                            <div class="invalid-feedback" data-error-for="source_reference"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="description">คำอธิบายรายการ</label>
                            <input class="form-control" id="description" name="description" value="{{ old('description', $journalEntry->description) }}" maxlength="500" required>
                            <div class="invalid-feedback" data-error-for="description"></div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                        <h2 class="h5 mb-0">รายการเดบิต / เครดิต</h2>
                        <button class="btn btn-sm btn-outline-dark" id="add-line" type="button"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มบรรทัด</button>
                    </div>
                    <div class="invalid-feedback d-block" data-error-for="lines"></div>
                    <div class="table-responsive">
                        <table class="table align-middle journal-lines-table" id="journal-lines-table">
                            <thead>
                                <tr>
                                    <th>บัญชี</th>
                                    <th>คำอธิบาย</th>
                                    <th class="text-end">เดบิต</th>
                                    <th class="text-end">เครดิต</th>
                                    <th>Tax Code</th>
                                    <th class="text-end">ฐานภาษี</th>
                                    <th class="text-end">ภาษี</th>
                                    <th>Tax Point</th>
                                    <th>รับ/จ่ายจริง</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lines as $index => $line)
                                    <tr>
                                        <td>
                                            <select class="form-select js-account-select journal-line-account" name="lines[{{ $index }}][account_id]" required>
                                                <option value="">กรุณาเลือก</option>
                                                @foreach ($accounts as $account)
                                                    <option value="{{ $account->id }}" @selected((string) ($line['account_id'] ?? '') === (string) $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                                                @endforeach
                                            </select>
                                            <div class="invalid-feedback" data-error-for="lines.{{ $index }}.account_id"></div>
                                        </td>
                                        <td>
                                            <input class="form-control journal-line-description" name="lines[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" maxlength="500">
                                            <div class="invalid-feedback" data-error-for="lines.{{ $index }}.description"></div>
                                        </td>
                                        <td>
                                            <input class="form-control text-end js-amount journal-line-amount" name="lines[{{ $index }}][debit]" value="{{ $line['debit'] ?? '' }}" inputmode="decimal" required>
                                            <div class="invalid-feedback" data-error-for="lines.{{ $index }}.debit"></div>
                                        </td>
                                        <td>
                                            <input class="form-control text-end js-amount journal-line-amount" name="lines[{{ $index }}][credit]" value="{{ $line['credit'] ?? '' }}" inputmode="decimal" required>
                                            <div class="invalid-feedback" data-error-for="lines.{{ $index }}.credit"></div>
                                        </td>
                                        <td>
                                            <select class="form-select journal-line-tax-code" name="lines[{{ $index }}][tax_code_id]"><option value="">ไม่มี</option>@foreach ($taxCodes as $taxCode)<option value="{{ $taxCode->id }}" @selected((string) ($line['tax_code_id'] ?? '') === (string) $taxCode->id)>{{ $taxCode->code }} · {{ $taxCode->kind }} ({{ $taxCode->rate }}%)</option>@endforeach</select>
                                            <div class="invalid-feedback" data-error-for="lines.{{ $index }}.tax_code_id"></div>
                                        </td>
                                        <td><input class="form-control text-end journal-line-tax-number" name="lines[{{ $index }}][tax_base]" value="{{ $line['tax_base'] ?? '' }}" inputmode="decimal"><div class="invalid-feedback" data-error-for="lines.{{ $index }}.tax_base"></div></td>
                                        <td><input class="form-control text-end journal-line-tax-number" name="lines[{{ $index }}][tax_amount]" value="{{ $line['tax_amount'] ?? '' }}" inputmode="decimal"><div class="invalid-feedback" data-error-for="lines.{{ $index }}.tax_amount"></div></td>
                                        <td><input class="form-control journal-line-date" type="date" name="lines[{{ $index }}][tax_point_date]" value="{{ filled($line['tax_point_date'] ?? null) ? \Illuminate\Support\Carbon::parse($line['tax_point_date'])->toDateString() : '' }}"><div class="invalid-feedback" data-error-for="lines.{{ $index }}.tax_point_date"></div></td>
                                        <td><input class="form-control journal-line-date" type="date" name="lines[{{ $index }}][tax_settlement_date]" value="{{ filled($line['tax_settlement_date'] ?? null) ? \Illuminate\Support\Carbon::parse($line['tax_settlement_date'])->toDateString() : '' }}"><div class="invalid-feedback" data-error-for="lines.{{ $index }}.tax_settlement_date"></div></td>
                                        <td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-line" type="button" aria-label="ลบบรรทัด"><i class="bx bx-trash" aria-hidden="true"></i></button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td colspan="2" class="text-end">รวม</td>
                                    <td class="text-end" id="debit-total">0.00</td>
                                    <td class="text-end" id="credit-total">0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <a class="btn btn-outline-dark" href="{{ route('accounting.journal-entries.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>ยกเลิก</a>
                        <button class="btn btn-dark" type="submit" data-busy-text="กำลังบันทึก..."><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึก Draft</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <template id="journal-line-template">
        <tr>
            <td><select class="form-select js-account-select journal-line-account" data-field="account_id" required><option value="">กรุณาเลือก</option></select><div class="invalid-feedback"></div></td>
            <td><input class="form-control journal-line-description" data-field="description" maxlength="500"><div class="invalid-feedback"></div></td>
            <td><input class="form-control text-end js-amount journal-line-amount" data-field="debit" value="0.00" inputmode="decimal" required><div class="invalid-feedback"></div></td>
            <td><input class="form-control text-end js-amount journal-line-amount" data-field="credit" value="0.00" inputmode="decimal" required><div class="invalid-feedback"></div></td>
            <td><select class="form-select journal-line-tax-code" data-field="tax_code_id"><option value="">ไม่มี</option>@foreach ($taxCodes as $taxCode)<option value="{{ $taxCode->id }}">{{ $taxCode->code }} · {{ $taxCode->kind }} ({{ $taxCode->rate }}%)</option>@endforeach</select><div class="invalid-feedback"></div></td>
            <td><input class="form-control text-end journal-line-tax-number" data-field="tax_base" inputmode="decimal"><div class="invalid-feedback"></div></td>
            <td><input class="form-control text-end journal-line-tax-number" data-field="tax_amount" inputmode="decimal"><div class="invalid-feedback"></div></td>
            <td><input class="form-control journal-line-date" type="date" data-field="tax_point_date"><div class="invalid-feedback"></div></td>
            <td><input class="form-control journal-line-date" type="date" data-field="tax_settlement_date"><div class="invalid-feedback"></div></td>
            <td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-line" type="button" aria-label="ลบบรรทัด"><i class="bx bx-trash" aria-hidden="true"></i></button></td>
        </tr>
    </template>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $body = $('#journal-lines-table tbody');
            var accountOptionsUrl = @json(route('accounting.journal-entries.account-options'));

            function initAccountSelects($scope) {
                $scope.find('.js-account-select').each(function () {
                    var $select = $(this);
                    if ($select.hasClass('select2-hidden-accessible')) return;
                    $select.select2({ width: '100%', placeholder: 'ค้นหาบัญชี GL', allowClear: true, ajax: { url: accountOptionsUrl, dataType: 'json', delay: 250, data: function (params) { return { q: params.term || '', page: params.page || 1 }; }, processResults: function (data) { return data; }, cache: true } });
                });
            }

            function reindex() {
                $body.find('tr').each(function (index) {
                    $(this).find('[name], [data-field]').each(function () {
                        var field = $(this).data('field') || String($(this).attr('name')).replace(/^lines\[\d+\]\[|\]$/g, '');
                        $(this).attr('name', 'lines[' + index + '][' + field + ']');
                        $(this).siblings('.invalid-feedback').attr('data-error-for', 'lines.' + index + '.' + field);
                    });
                });
            }

            function totals() {
                var debit = 0;
                var credit = 0;
                $body.find('tr').each(function () {
                    debit += Number($(this).find('[name$="[debit]"]').val()) || 0;
                    credit += Number($(this).find('[name$="[credit]"]').val()) || 0;
                });
                $('#debit-total').text(window.erpAccountingFormat(debit));
                $('#credit-total').text(window.erpAccountingFormat(credit));
            }

            $('#add-line').on('click', function () {
                $body.append($($('#journal-line-template').html()));
                reindex();
                initAccountSelects($body.find('tr').last());
            });

            $(document).on('click', '.js-remove-line', function () {
                if ($body.find('tr').length > 2) {
                    $(this).closest('tr').remove();
                    reindex();
                    totals();
                }
            }).on('input', '.js-amount', totals);

            reindex();
            initAccountSelects($body);
            totals();
            window.erpAjaxForm({ form: '#journal-entry-form', redirect: @json(! $journalEntry->exists), reload: false });
        });
    </script>
@endpush
