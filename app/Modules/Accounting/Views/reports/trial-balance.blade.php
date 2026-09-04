@extends('Accounting::layout')

@section('title', 'งบทดลอง | New ERP')

@section('content')
    @php($selectedPeriod = $periods->first(fn ($period) => now()->between($period->start_date, $period->end_date)) ?: $periods->first())
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4"><p class="eyebrow mb-2">ACCOUNTING / TRIAL BALANCE</p><h1 class="h3 mb-2">งบทดลอง</h1><p class="text-secondary mb-0">คำนวณจากรายการที่ลงบัญชีแล้วของคลังที่คุณมีสิทธิ์ในสาขาปัจจุบัน</p></div>
        <div class="card border-0 shadow-sm mb-4" id="trial-balance-filters"><div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h6 mb-0">ตัวกรองรายงาน</h2><button type="button" class="btn btn-outline-secondary btn-sm" id="report-filter-reset"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
            <div class="row g-3">
                <div class="col-12 col-md-4"><label class="form-label" for="trial-period">งวดบัญชี</label><select class="form-select" id="trial-period">@foreach ($periods as $period)<option value="{{ $period->id }}" @selected($period->id === (int) request('period_id', $selectedPeriod?->id))>{{ $period->fiscalYear->name }} / {{ $period->name }}</option>@endforeach</select></div>
                <div class="col-12 col-md-4"><label class="form-label" for="report-branch">สาขา</label><select class="form-select" id="report-branch"><option value="current" @selected(request('branch_scope', 'current') === 'current')>สาขาปัจจุบัน</option><option value="all" @selected(request('branch_scope') === 'all')>ทุกสาขาที่มีสิทธิ์</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) request('branch_scope') === (string) $branch->id)>{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div>
                <div class="col-12 col-md-4"><label class="form-label" for="report-warehouse">คลัง</label><select class="form-select" id="report-warehouse"><option value="">ทุกคลังที่มีสิทธิ์</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((int) request('warehouse_id') === (int) $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach</select></div>
            </div>
        </div></div>

        <div class="row g-3 mb-4" id="trial-totals">
            @foreach ([['opening_debit', 'ยอดยกมาเดบิต'], ['opening_credit', 'ยอดยกมาเครดิต'], ['period_debit', 'เดบิตงวด'], ['period_credit', 'เครดิตงวด'], ['closing_debit', 'ยอดปิดเดบิต'], ['closing_credit', 'ยอดปิดเครดิต']] as [$key, $label])
                <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3"><div class="small text-secondary">{{ $label }}</div><div class="h5 mb-0" data-total="{{ $key }}">0.00</div></div></div></div>
            @endforeach
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <table class="table table-hover align-middle w-100" id="trial-balance-table"
                       data-url="{{ route('accounting.reports.trial-balance.data') }}"
                       data-export-url="{{ route('accounting.reports.trial-balance.export') }}">
                    <thead><tr><th>รหัสบัญชี</th><th>ชื่อบัญชี</th><th class="text-end">ยอดยกมาเดบิต</th><th class="text-end">ยอดยกมาเครดิต</th><th class="text-end">เดบิตงวด</th><th class="text-end">เครดิตงวด</th><th class="text-end">ยอดปิดเดบิต</th><th class="text-end">ยอดปิดเครดิต</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#trial-balance-table');
            var $period = $('#trial-period');
            var $branch = $('#report-branch'), $warehouse = $('#report-warehouse');
            var text = $.fn.dataTable.render.text();
            var amountColumns = [2, 3, 4, 5, 6, 7];
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: {
                    url: $table.data('url'),
                    data: function (data) { data.period_id = $period.val(); data.branch_scope = $branch.val(); data.warehouse_id = $warehouse.val(); },
                    dataSrc: function (response) {
                        $.each(response.totals || {}, function (key, value) { $('[data-total="' + key + '"]').text(window.erpAccountingFormat(value)); });
                        return response.data;
                    }
                },
                order: [[0, 'asc']],
                buttons: [window.erpExcelButton($table, function () { return { period_id: $period.val(), branch_scope: $branch.val(), warehouse_id: $warehouse.val() }; })],
                columns: [
                    { data: 'code', name: 'accounts.code', render: text.display },
                    { data: 'name', name: 'accounts.name', render: text.display },
                    { data: 'opening_debit', name: 'opening_debit', className: 'text-end', render: text.display },
                    { data: 'opening_credit', name: 'opening_credit', className: 'text-end', render: text.display },
                    { data: 'period_debit', name: 'period_debit', className: 'text-end', render: text.display },
                    { data: 'period_credit', name: 'period_credit', className: 'text-end', render: text.display },
                    { data: 'closing_debit', name: 'closing_debit', className: 'text-end', render: text.display },
                    { data: 'closing_credit', name: 'closing_credit', className: 'text-end', render: text.display }
                ]
            }));
            $period.add($branch).add($warehouse).on('change', function () { table.ajax.reload(); });
            $('#report-filter-reset').on('click', function () { $branch.val('current'); $warehouse.val(''); table.ajax.reload(); });
        });
    </script>
@endpush
