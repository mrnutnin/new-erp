@extends('Finance::layout')

@section('title', 'รับเงิน/จ่ายเงิน | Finance')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">FINANCE / SETTLEMENTS</p>
                <h1 class="h3 mb-2">รับเงิน / จ่ายเงิน</h1>
                <p class="text-secondary mb-0">อนุมัติเอกสารก่อนจัดสรร AR/AP และลงบัญชี</p>
            </div>
            @if (auth()->user()->hasPermission('finance.settlements.create'))
                <a class="btn btn-dark" href="{{ route('finance.settlements.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างเอกสาร</a>
            @endif
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="settlements-table"
                           data-url="{{ route('finance.settlements.data') }}"
                           data-can-approve="{{ auth()->user()->hasPermission('finance.settlements.approve') ? '1' : '0' }}"
                           data-can-void="{{ auth()->user()->hasPermission('finance.settlements.void') ? '1' : '0' }}">
                        <thead>
                            <tr>
                                <th>เลขที่</th>
                                <th>ประเภท</th>
                                <th>วันที่รับ/จ่าย</th>
                                <th>คู่ค้า</th>
                                <th>บัญชีเงิน</th>
                                <th class="text-end">ยอดสุทธิ</th>
                                <th>Journal</th>
                                <th>สถานะ</th>
                                <th class="text-end">จัดสรร</th>
                                <th>เงินล่วงหน้า/มัดจำ</th>
                                <th class="text-end">จัดการ</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#settlements-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                { data: 'document_number', name: 'finance_settlements.document_number', render: text.display },
                {
                    data: 'document_type_label',
                    name: 'finance_settlements.document_type',
                    render: function (value, type) {
                        if (type !== 'display') return value;
                        return value === 'รับเงิน'
                            ? '<span class="badge text-bg-success">รับเงิน</span>'
                            : '<span class="badge text-bg-warning">จ่ายเงิน</span>';
                    }
                },
                { data: 'settlement_date_label', name: 'finance_settlements.settlement_date', render: text.display },
                { data: 'party_label', name: 'finance_settlements.party_type', render: text.display },
                { data: 'bank_label', name: 'bank_accounts.code', render: text.display },
                { data: 'net_amount', name: 'finance_settlements.net_amount', className: 'text-end', render: $.fn.dataTable.render.number(',', '.', 2) },
                { data: 'journal_entry_number', name: 'journal_entries.entry_number', defaultContent: '—', render: text.display },
                {
                    data: 'status_label',
                    name: 'finance_settlements.status',
                    render: function (value, type) {
                        if (type !== 'display') return value;
                        var classes = { 'ร่าง': 'text-bg-secondary', 'อนุมัติแล้ว': 'text-bg-info', 'ลงบัญชีแล้ว': 'text-bg-success', 'ยกเลิก': 'text-bg-danger' };
                        return '<span class="badge ' + (classes[value] || 'text-bg-secondary') + '">' + text.display(value) + '</span>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-end text-nowrap',
                    render: function (value, type, row) {
                        var count = parseInt(row.intent_count, 10) || 0;
                        var amount = parseFloat(row.intent_amount) || 0;
                        if (type !== 'display') return amount;
                        return '<span class="badge text-bg-info">' + count + ' รายการ</span><span class="ms-2">' + amount.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span>';
                    }
                },
                {
                    data: 'advance_document_number',
                    orderable: false,
                    searchable: false,
                    render: function (value, type, row) {
                        if (type !== 'display') return value || '';
                        if (value) return '<span class="badge app-status-success">' + text.display(value) + '</span>';
                        return row.advance_url ? '<span class="text-secondary">ยังไม่ได้สร้าง</span>' : '<span class="text-secondary">—</span>';
                    }
                }
            ];

            columns.push({
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-end text-nowrap',
                    render: function (value, type, row) {
                        if (type !== 'display') return '';
                        var actions = ['<a class="btn btn-sm btn-app-soft" href="' + text.display(row.show_url) + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>'];
                        var documentNumber = text.display(row.document_number);
                        if (row.approve_url) {
                            actions.push('<button class="btn btn-sm btn-app-soft js-settlement-approve" type="button" data-url="' + text.display(row.approve_url) + '" data-document="' + documentNumber + '" title="อนุมัติ" aria-label="อนุมัติ"><i class="bx bx-check" aria-hidden="true"></i></button>');
                        }
                        if (row.void_url) {
                            actions.push('<button class="btn btn-sm btn-outline-danger js-settlement-void" type="button" data-url="' + text.display(row.void_url) + '" data-document="' + documentNumber + '" title="ยกเลิกเอกสาร" aria-label="ยกเลิกเอกสาร"><i class="bx bx-x" aria-hidden="true"></i></button>');
                        }
                        if (row.post_url) {
                            actions.push('<button class="btn btn-sm btn-app-soft js-settlement-post" type="button" data-url="' + text.display(row.post_url) + '" data-document="' + documentNumber + '" title="ลงบัญชี" aria-label="ลงบัญชี"><i class="bx bx-send" aria-hidden="true"></i></button>');
                        }
                        if (row.reverse_url) {
                            actions.push('<button class="btn btn-sm btn-outline-danger js-settlement-reverse" type="button" data-url="' + text.display(row.reverse_url) + '" data-document="' + documentNumber + '" title="กลับรายการ" aria-label="กลับรายการ"><i class="bx bx-revision" aria-hidden="true"></i></button>');
                        }
                        if (row.advance_url) {
                            actions.push('<button class="btn btn-sm btn-app-soft js-settlement-advance" type="button" data-url="' + text.display(row.advance_url) + '" data-document="' + documentNumber + '" data-status="' + text.display(row.status || '') + '" title="สร้างเงินล่วงหน้า/เงินมัดจำ" aria-label="สร้างเงินล่วงหน้า/เงินมัดจำ"><i class="bx bx-wallet" aria-hidden="true"></i></button>');
                        }
                        return actions.join(' ');
                    }
                });

            var dataTable = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: $table.data('url'),
                order: [[2, 'desc']],
                buttons: [window.erpExcelButton($table)],
                columns: columns
            }));

            function submitSettlementState($button, reason) {
                if ($button.data('submitting')) return;
                $button.data('submitting', true).prop('disabled', true);
                $.ajax({
                    url: $button.data('url'), method: 'PUT', data: { reason: reason || '' },
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
                }).done(function (response) {
                    Swal.fire({ icon: 'success', text: response.msg }).then(function () { dataTable.ajax.reload(null, false); });
                }).fail(function (xhr) {
                    var response = xhr.responseJSON || {};
                    Swal.fire({ icon: 'error', text: response.errors?.reason?.[0] || response.message || 'ไม่สามารถดำเนินการได้' });
                }).always(function () { $button.data('submitting', false).prop('disabled', false); });
            }

            $(document).on('click', '.js-settlement-approve', function () {
                var $button = $(this);
                Swal.fire({ icon: 'question', title: 'อนุมัติ ' + $button.data('document') + '?', text: 'ยืนยันการอนุมัติเอกสารนี้หรือไม่', showCancelButton: true, confirmButtonText: 'อนุมัติ', cancelButtonText: 'ย้อนกลับ' }).then(function (result) {
                    if (result.isConfirmed) submitSettlementState($button, '');
                });
            });

            $(document).on('click', '.js-settlement-void', function () {
                var $button = $(this);
                if ($button.data('submitting')) return;

                Swal.fire({
                    title: 'ยกเลิกเอกสาร ' + $button.data('document'),
                    input: 'textarea',
                    inputLabel: 'เหตุผล (อย่างน้อย 10 ตัวอักษร)',
                    inputAttributes: { maxlength: 500 },
                    showCancelButton: true,
                    confirmButtonText: 'ยกเลิกเอกสาร',
                    cancelButtonText: 'ย้อนกลับ',
                    preConfirm: function (reason) {
                        reason = $.trim(reason || '');
                        if (reason.length < 10) {
                            Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร');
                            return false;
                        }
                        return reason;
                    }
                }).then(function (result) {
                    if (!result.isConfirmed) return;
                    submitSettlementState($button, result.value);
                });
            });

            $(document).on('click', '.js-settlement-post', function () {
                var $button = $(this);
                if ($button.data('submitting')) return;
                Swal.fire({ title: 'ลงบัญชี ' + $button.data('document') + '?', text: 'MVP รองรับเฉพาะ NONE VAT และไม่หัก ณ ที่จ่าย', icon: 'question', showCancelButton: true, confirmButtonText: 'ลงบัญชี', cancelButtonText: 'ย้อนกลับ' }).then(function (result) {
                    if (!result.isConfirmed) return;
                    $button.data('submitting', true).prop('disabled', true);
                    $.ajax({ url: $button.data('url'), method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } })
                        .done(function (response) { Swal.fire({ icon: 'success', text: response.msg }).then(function () { dataTable.ajax.reload(null, false); }); })
                        .fail(function (xhr) { var response = xhr.responseJSON || {}; Swal.fire({ icon: 'error', text: response.message || 'ไม่สามารถลงบัญชีได้' }); })
                        .always(function () { $button.data('submitting', false).prop('disabled', false); });
                });
            });

            $(document).on('click', '.js-settlement-advance', function () {
                var $button = $(this);
                if ($button.data('submitting')) return;
                var isApproved = $button.data('status') === 'APPROVED';
                Swal.fire({
                    title: 'สร้างเงินล่วงหน้า/เงินมัดจำ',
                    text: isApproved ? 'Settlement อนุมัติแล้วและยังไม่ได้จัดสรร ระบบจะลงบัญชีและสร้างรายการให้' : 'Settlement ลงบัญชีแล้วและยังไม่ได้จัดสรร ระบบจะเชื่อมรายการให้',
                    input: 'select',
                    inputOptions: { ADVANCE: 'เงินล่วงหน้า', DEPOSIT: 'เงินมัดจำ' },
                    inputValue: 'ADVANCE',
                    inputPlaceholder: 'เลือกประเภท',
                    showCancelButton: true,
                    confirmButtonText: 'สร้างรายการ',
                    cancelButtonText: 'ย้อนกลับ',
                    preConfirm: function (value) { if (!value) { Swal.showValidationMessage('กรุณาเลือกประเภท'); return false; } return value; }
                }).then(function (result) {
                    if (!result.isConfirmed) return;
                    $button.data('submitting', true).prop('disabled', true);
                    $.ajax({ url: $button.data('url'), method: 'POST', data: { instrument_type: result.value }, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } })
                        .done(function (response) { Swal.fire({ icon: 'success', text: response.msg }).then(function () { dataTable.ajax.reload(null, false); }); })
                        .fail(function (xhr) { var response = xhr.responseJSON || {}; Swal.fire({ icon: 'error', text: response.errors?.settlement?.[0] || response.errors?.instrument_type?.[0] || response.message || 'ไม่สามารถสร้างเงินล่วงหน้า/เงินมัดจำได้' }); })
                        .always(function () { $button.data('submitting', false).prop('disabled', false); });
                });
            });

            $(document).on('click', '.js-settlement-reverse', function () {
                var $button = $(this);
                Swal.fire({ title: 'กลับรายการ ' + $button.data('document'), html: '<input id="reverse-date" class="swal2-input" type="date" value="' + new Date().toISOString().slice(0, 10) + '"><textarea id="reverse-reason" class="swal2-textarea" placeholder="เหตุผลอย่างน้อย 10 ตัวอักษร"></textarea>', showCancelButton: true, confirmButtonText: 'กลับรายการ', cancelButtonText: 'ย้อนกลับ', preConfirm: function () { var reason = $.trim($('#reverse-reason').val() || ''); if (reason.length < 10) { Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร'); return false; } return { reversal_date: $('#reverse-date').val(), reason: reason }; } }).then(function (result) {
                    if (!result.isConfirmed) return;
                    $.ajax({ url: $button.data('url'), method: 'PUT', data: result.value, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } }).done(function (response) { Swal.fire({ icon: 'success', text: response.msg }).then(function () { dataTable.ajax.reload(null, false); }); }).fail(function (xhr) { var response = xhr.responseJSON || {}; Swal.fire({ icon: 'error', text: response.message || 'ไม่สามารถกลับรายการได้' }); });
                });
            });
        });
    </script>
@endpush
