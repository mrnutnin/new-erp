@extends('Accounting::layout')

@section('title', 'บัญชีแยกประเภท | New ERP')

@section('content')
    @php($selectedPeriodId = (int) request('period_id', optional($periods->first())->id))
    @php($selectedAccountId = (int) optional($selectedAccount)->id)
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / GENERAL LEDGER</p>
                <h1 class="h3 mb-2">บัญชีแยกประเภท</h1>
                <p class="text-secondary mb-0">แสดงรายการ POSTED ของบัญชีและ Warehouse session ปัจจุบัน</p>
                @if(request()->boolean('asset_scope'))<div class="alert alert-info py-2 px-3 mt-2 mb-0 small"><i class="bx bx-link me-1" aria-hidden="true"></i>กำลังดูเฉพาะรายการ Journal จากโมดูลสินทรัพย์</div>@endif
            </div>
            <div class="row g-2 col-12 col-xl-7">
                <div class="col-12 col-md-5"><label class="form-label" for="ledger-period">งวดบัญชี</label><select class="form-select" id="ledger-period">@foreach ($periods as $period)<option value="{{ $period->id }}" @selected($selectedPeriodId === $period->id)>{{ $period->fiscalYear->name }} / {{ $period->name }}</option>@endforeach</select></div>
                <div class="col-12 col-md-7"><label class="form-label" for="ledger-account">บัญชี</label><select class="form-select" id="ledger-account" data-options-url="{{ route('accounting.reports.general-ledger.account-options') }}">@if($selectedAccount)<option value="{{ $selectedAccount->id }}" selected>{{ $selectedAccount->code }} · {{ $selectedAccount->name }}{{ $selectedAccount->deleted_at ? ' (ปิด/ลบ)' : '' }}</option>@endif</select></div>
            </div>
        </div>

        <div class="row g-3 mb-4" id="ledger-summary">
            @foreach ([['opening_balance', 'ยอดยกมา'], ['period_debit', 'เดบิตงวด'], ['period_credit', 'เครดิตงวด'], ['closing_balance', 'ยอดคงเหลือปลายงวด']] as [$key, $label])
                <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3"><div class="small text-secondary">{{ $label }}</div><div class="h5 mb-0" data-summary="{{ $key }}">0.00</div></div></div></div>
            @endforeach
        </div>

                @if(request()->boolean('asset_scope') && ! $selectedAccount)<div class="alert alert-info border-0 shadow-sm" role="alert"><i class="bx bx-info-circle me-1" aria-hidden="true"></i>ยังไม่ได้เลือกบัญชี ระบบจะแสดงรายการ Journal ของ Asset ทุกบัญชี</div>@endif
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <table class="table table-hover align-middle w-100" id="general-ledger-table"
                       data-url="{{ route('accounting.reports.general-ledger.data') }}"
                       data-export-url="{{ route('accounting.reports.general-ledger.export') }}">
                    <thead><tr><th>วันที่</th><th>เลขที่</th><th>สมุด</th><th>บัญชี</th><th>เอกสารอ้างอิง</th><th>คำอธิบาย</th><th>Subledger</th><th class="text-end">เดบิต</th><th class="text-end">เครดิต</th><th class="text-end">จัดการ</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#general-ledger-table');
            var $period = $('#ledger-period');
            var $account = $('#ledger-account');
            var text = $.fn.dataTable.render.text();
            $account.select2({
                width: '100%',
                theme: 'bootstrap-5',
                placeholder: 'ค้นหารหัสหรือชื่อบัญชี',
                ajax: {
                    url: $account.data('options-url'),
                    delay: 250,
                    data: function (params) { return { q: params.term || '', page: params.page || 1 }; },
                    processResults: function (response) { return response; }
                }
            });
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: {
                    url: $table.data('url'),
                    data: function (data) { data.period_id = $period.val(); data.account_id = $account.val(); data.asset_scope = @json(request()->boolean('asset_scope')); },
                    dataSrc: function (response) {
                        $.each(response.summary || {}, function (key, value) { $('[data-summary="' + key + '"]').text(value || '0.00'); });
                        return response.data;
                    }
                },
                order: [[0, 'asc'], [1, 'asc']],
                buttons: [window.erpExcelButton($table, function () { return { period_id: $period.val(), account_id: $account.val() }; })],
                columns: [
                    { data: 'entry_date_label', name: 'entries.entry_date', render: text.display },
                    { data: 'entry_number', name: 'entries.entry_number', render: text.display },
                    { data: 'book_code', name: 'books.code', render: text.display },
                    { data: null, name: 'accounts.code', render: function (value, type, row) { return type === 'display' ? text.display(row.account_code + ' · ' + row.account_name) : row.account_code; } },
                    { data: 'source_reference', name: 'entries.source_reference', render: text.display },
                    { data: 'entry_description', name: 'entries.description', render: text.display },
                    { data: null, orderable: false, searchable: false, render: function (value, type, row) { return type === 'display' ? text.display(row.subledger_type && row.subledger_id ? row.subledger_type + ' · ' + row.subledger_id : '') : ''; } },
                    { data: 'debit', name: 'journal_entry_lines.debit', className: 'text-end', render: text.display },
                    { data: 'credit', name: 'journal_entry_lines.credit', className: 'text-end', render: text.display },
                    { data: null, orderable: false, searchable: false, className: 'text-end', render: function (value, type, row) { return type === 'display' ? '<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.entry_url) + '"><i class="bx bx-show me-1" aria-hidden="true"></i>ดู</a>' : ''; } }
                ]
            }));
            $period.add($account).on('change', function () { table.ajax.reload(); });
        });
    </script>
@endpush
