@extends('Accounting::layout')

@section('title', 'รายงานเปรียบเทียบรายได้ | New ERP')

@section('content')
    @php($currentPeriod = $periods->first())
    @php($comparisonPeriod = $periods->skip(1)->first() ?? $currentPeriod)
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / COMPARATIVE INCOME</p>
                <h1 class="h3 mb-2">รายงานเปรียบเทียบรายได้</h1>
                <p class="text-secondary mb-0">เปรียบเทียบรายได้จาก GL ที่ลงบัญชีแล้วตามงวดและคลังที่คุณมีสิทธิ์ในสาขาปัจจุบัน</p>
            </div>
            <div class="row g-2 col-12 col-md-7 col-xl-6">
                <div class="col-6">
                    <label class="form-label" for="income-period">งวดที่เลือก</label>
                    <select class="form-select" id="income-period">
                        @foreach ($periods as $period)
                            <option value="{{ $period->id }}" @selected($period->id === $currentPeriod?->id)>{{ $period->fiscalYear->name }} / {{ $period->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label" for="income-comparison-period">งวดเปรียบเทียบ</label>
                    <select class="form-select" id="income-comparison-period">
                        @foreach ($periods as $period)
                            <option value="{{ $period->id }}" @selected($period->id === $comparisonPeriod?->id)>{{ $period->fiscalYear->name }} / {{ $period->name }}</option>
                        @endforeach
                    </select>
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
                    data: function (data) { data.period_id = $period.val(); data.comparison_period_id = $comparison.val(); },
                    dataSrc: function (response) {
                        $.each(response.totals || {}, function (key, value) { $('[data-total="' + key + '"]').text(value || '0.00'); });
                        return response.data;
                    }
                },
                order: [[0, 'asc']],
                buttons: [window.erpExcelButton($table, function () { return { period_id: $period.val(), comparison_period_id: $comparison.val() }; })],
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
        });
    </script>
@endpush
