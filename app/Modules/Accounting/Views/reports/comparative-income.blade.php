@extends('Accounting::layout')

@section('title', 'รายงานเปรียบเทียบรายได้ | New ERP')

@section('content')
    @php($currentPeriod = $periods->first())
    @php($comparisonPeriod = $periods->skip(1)->first() ?? $currentPeriod)
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <p class="eyebrow mb-2">ACCOUNTING / COMPARATIVE INCOME</p>
            <h1 class="h3 mb-2">รายงานเปรียบเทียบรายได้</h1>
            <p class="text-secondary mb-0">เปรียบเทียบรายได้จาก GL ที่ลงบัญชีแล้วตามงวดและคลังที่คุณมีสิทธิ์</p>
        </div>

        <div class="card border-0 shadow-sm mb-4" id="comparative-income-filters">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h6 mb-0">ตัวกรองรายงาน</h2><button type="button" class="btn btn-outline-secondary btn-sm" id="report-filter-reset"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
                <div class="row g-3">
                    <div class="col-12 col-md-3"><label class="form-label" for="income-period">งวดที่เลือก</label><select class="form-select" id="income-period">@foreach ($periods as $period)<option value="{{ $period->id }}" @selected($period->id === $currentPeriod?->id)>{{ $period->fiscalYear->name }} / {{ $period->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-3"><label class="form-label" for="income-comparison-period">งวดเปรียบเทียบ</label><select class="form-select" id="income-comparison-period">@foreach ($periods as $period)<option value="{{ $period->id }}" @selected($period->id === $comparisonPeriod?->id)>{{ $period->fiscalYear->name }} / {{ $period->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-3"><label class="form-label" for="report-branch">สาขา</label><select class="form-select" id="report-branch"><option value="current" @selected(request('branch_scope', 'current') === 'current')>สาขาปัจจุบัน</option><option value="all" @selected(request('branch_scope') === 'all')>ทุกสาขาที่มีสิทธิ์</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) request('branch_scope') === (string) $branch->id)>{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-3"><label class="form-label" for="report-warehouse">คลัง</label><select class="form-select" id="report-warehouse"><option value="">ทุกคลังที่มีสิทธิ์</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" data-branch="{{ $warehouse->branch_id }}" @selected((int) request('warehouse_id') === (int) $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach</select></div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            @foreach ([['current_amount', 'รายได้งวดที่เลือก'], ['comparison_amount', 'รายได้งวดเปรียบเทียบ'], ['difference_amount', 'ผลต่างรายได้']] as [$key, $label])
                <div class="col-12 col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3"><div class="small text-secondary">{{ $label }}</div><div class="h5 mb-0" data-total="{{ $key }}">0.00</div></div></div></div>
            @endforeach
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <table class="table table-hover align-middle w-100" id="comparative-income-table"
                       data-url="{{ route('accounting.reports.comparative-income.data') }}"
                       data-export-url="{{ route('accounting.reports.comparative-income.export') }}">
                    <thead><tr><th>รหัสบัญชี</th><th>ชื่อบัญชี</th><th class="text-end">งวดที่เลือก</th><th class="text-end">งวดเปรียบเทียบ</th><th class="text-end">ผลต่าง</th><th class="text-end">เปลี่ยนแปลง</th><th class="text-end">ดู GL</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#comparative-income-table');
            var $period = $('#income-period');
            var $comparison = $('#income-comparison-period');
            var text = $.fn.dataTable.render.text();
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: {
                    url: $table.data('url'),
                    data: function (data) { data.period_id = $period.val(); data.comparison_period_id = $comparison.val(); data.branch_scope = $('#report-branch').val(); data.warehouse_id = $('#report-warehouse').val(); },
                    dataSrc: function (response) {
                        $.each(response.totals || {}, function (key, value) { $('[data-total="' + key + '"]').text(window.erpAccountingFormat(value)); });
                        return response.data;
                    }
                },
                order: [[0, 'asc']],
                buttons: [window.erpExcelButton($table, function () { return { period_id: $period.val(), comparison_period_id: $comparison.val(), branch_scope: $('#report-branch').val(), warehouse_id: $('#report-warehouse').val() }; })],
                columns: [
                    { data: 'code', name: 'accounts.code', render: text.display },
                    { data: 'name', name: 'accounts.name', render: text.display },
                    { data: 'current_amount', name: 'current_amount', className: 'text-end', render: text.display },
                    { data: 'comparison_amount', name: 'comparison_amount', className: 'text-end', render: text.display },
                    { data: 'difference_amount', name: 'difference_amount', className: 'text-end', render: text.display },
                    { data: 'change_percent', name: 'change_percent', className: 'text-end', render: function (value, type) { return type === 'display' ? (value === null ? '—' : text.display(Number(value).toFixed(2) + '%')) : value; } },
                    { data: null, orderable: false, searchable: false, className: 'text-end', render: function (value, type, row) { return type === 'display' ? '<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.current_account_url) + '">งวดเลือก</a> <a class="btn btn-sm btn-app-soft" href="' + text.display(row.comparison_account_url) + '">งวดเทียบ</a>' : ''; } }
                ]
            }));
            $period.add($comparison).on('change', function () { table.ajax.reload(); });
            $('#report-branch,#report-warehouse').on('change', function () { table.ajax.reload(); });
            $('#report-filter-reset').on('click', function () { $('#report-branch').val('current'); $('#report-warehouse').val(''); table.ajax.reload(); });
        });
    </script>
@endpush
