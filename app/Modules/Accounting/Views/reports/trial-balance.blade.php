@extends('Accounting::layout')

@section('title', 'งบทดลอง | New ERP')

@section('content')
    @php($selectedPeriod = $periods->first())
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / TRIAL BALANCE</p>
                <h1 class="h3 mb-2">งบทดลอง</h1>
                <p class="text-secondary mb-0">คำนวณจากรายการที่ลงบัญชีแล้วของคลังที่คุณมีสิทธิ์ในสาขาปัจจุบัน</p>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="trial-period">งวดบัญชี</label>
                <select class="form-select" id="trial-period">
                    @foreach ($periods as $period)
                        <option value="{{ $period->id }}">{{ $period->fiscalYear->name }} / {{ $period->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

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
            var text = $.fn.dataTable.render.text();
            var amountColumns = [2, 3, 4, 5, 6, 7];
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: {
                    url: $table.data('url'),
                    data: function (data) { data.period_id = $period.val(); },
                    dataSrc: function (response) {
                        $.each(response.totals || {}, function (key, value) { $('[data-total="' + key + '"]').text(value || '0.00'); });
                        return response.data;
                    }
                },
                order: [[0, 'asc']],
                buttons: [window.erpExcelButton($table, function () { return { period_id: $period.val() }; })],
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
            $period.on('change', function () { table.ajax.reload(); });
        });
    </script>
@endpush
