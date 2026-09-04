@extends('Accounting::layout')

@section('title', 'บัญชีแยกประเภท | New ERP')

@section('content')
    @php($defaultPeriod = $periods->first(fn ($period) => now()->between($period->start_date, $period->end_date)) ?: $periods->first())
    @php($selectedPeriodId = (int) request('period_id', optional($defaultPeriod)->id))
    @php($selectedAccountId = (int) optional($selectedAccount)->id)
    @php($selectedBranchScope = (string) request('branch_scope', 'current'))
    @php($selectedWarehouseId = (int) request('warehouse_id'))
    @php($selectedBookId = (int) request('journal_book_id'))
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / GENERAL LEDGER</p>
                <h1 class="h3 mb-2">บัญชีแยกประเภท</h1>
                <p class="text-secondary mb-0">แสดงรายการ POSTED ของบัญชีและ Warehouse session ปัจจุบัน</p>
                @if(request()->boolean('asset_scope'))<div class="alert alert-info py-2 px-3 mt-2 mb-0 small"><i class="bx bx-link me-1" aria-hidden="true"></i>กำลังดูเฉพาะรายการ Journal จากโมดูลสินทรัพย์</div>@endif
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4" id="ledger-filters">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h6 mb-0">ตัวกรองรายงาน</h2><button type="button" class="btn btn-outline-secondary btn-sm" id="ledger-reset"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
                <div class="row g-3">
                    <div class="col-12 col-md-4"><label class="form-label" for="ledger-period">งวดบัญชี</label><select class="form-select" id="ledger-period">@foreach ($periods as $period)<option value="{{ $period->id }}" @selected($selectedPeriodId === $period->id)>{{ $period->fiscalYear->name }} / {{ $period->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="ledger-branch">สาขา</label><select class="form-select" id="ledger-branch"><option value="current" @selected($selectedBranchScope === 'current')>สาขาปัจจุบัน</option><option value="all" @selected($selectedBranchScope === 'all')>ทุกสาขาที่มีสิทธิ์</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($selectedBranchScope === (string) $branch->id)>{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="ledger-warehouse">คลัง</label><select class="form-select" id="ledger-warehouse"><option value="">ทุกคลังที่มีสิทธิ์</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" data-branch="{{ $warehouse->branch_id }}" @selected($selectedWarehouseId === $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="ledger-account">บัญชี</label><select class="form-select" id="ledger-account" data-options-url="{{ route('accounting.reports.general-ledger.account-options') }}">@if($selectedAccount)<option value="{{ $selectedAccount->id }}" selected>{{ $selectedAccount->code }} · {{ $selectedAccount->name }}{{ $selectedAccount->deleted_at ? ' (ปิด/ลบ)' : '' }}</option>@endif</select></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="ledger-book">สมุดรายวัน</label><select class="form-select" id="ledger-book"><option value="">ทุกสมุดรายวัน</option>@foreach($journalBooks as $book)<option value="{{ $book->id }}" @selected($selectedBookId === $book->id)>{{ $book->code }} · {{ $book->name }}</option>@endforeach</select></div>
                    <div class="col-6 col-md-2"><label class="form-label" for="ledger-date-from">วันที่เริ่มต้น</label><input type="date" class="form-control" id="ledger-date-from" value="{{ request('date_from') }}"></div>
                    <div class="col-6 col-md-2"><label class="form-label" for="ledger-date-to">วันที่สิ้นสุด</label><input type="date" class="form-control" id="ledger-date-to" value="{{ request('date_to') }}"></div>
                </div>
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
            var $branch = $('#ledger-branch'), $warehouse = $('#ledger-warehouse'), $book = $('#ledger-book'), $dateFrom = $('#ledger-date-from'), $dateTo = $('#ledger-date-to');
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
                    data: function (data) { data.period_id = $period.val(); data.account_id = $account.val(); data.branch_scope = $branch.val(); data.warehouse_id = $warehouse.val(); data.journal_book_id = $book.val(); data.date_from = $dateFrom.val(); data.date_to = $dateTo.val(); data.asset_scope = @json(request()->boolean('asset_scope')); },
                    dataSrc: function (response) {
                        $.each(response.summary || {}, function (key, value) { $('[data-summary="' + key + '"]').text(window.erpAccountingFormat(value)); });
                        return response.data;
                    }
                },
                order: [[0, 'asc'], [1, 'asc']],
                buttons: [window.erpExcelButton($table, function () { return { period_id: $period.val(), account_id: $account.val(), branch_scope: $branch.val(), warehouse_id: $warehouse.val(), journal_book_id: $book.val(), date_from: $dateFrom.val(), date_to: $dateTo.val(), asset_scope: @json(request()->boolean('asset_scope')) }; })],
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
            function reload() { table.ajax.reload(); }
            $period.add($account).add($branch).add($warehouse).add($book).add($dateFrom).add($dateTo).on('change', reload);
            $branch.on('change', function () { var scope = $branch.val(); $warehouse.find('option').each(function () { var $option = $(this); $option.toggle(!$option.val() || scope === 'all' || scope === 'current' || $option.data('branch') == scope); if (!$option.is(':visible') && $option.is(':selected')) $warehouse.val(''); }); reload(); });
            $('#ledger-reset').on('click', function () { window.location.href = '{{ route('accounting.reports.general-ledger.index') }}?period_id=' + encodeURIComponent($period.val()) + (@json(request()->boolean('asset_scope')) ? '&asset_scope=1' : ''); });
        });
    </script>
@endpush
