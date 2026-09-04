@extends('layouts.app')

@section('sidebar')
    @include('Accounting::partials.sidebar')
@endsection

@push('scripts')
    @php($accountingDecimalPlaces = max(0, min(4, (int) (\App\Models\CompanySetting::query()->value('tax_decimal_places') ?? 2))))
    <script>
        window.erpAccountingDecimalPlaces = {{ $accountingDecimalPlaces }};
        window.erpAccountingFormat = window.erpAccountingFormat || function (value) {
            if (value === null || value === undefined || value === '') return '0.' + '0'.repeat(window.erpAccountingDecimalPlaces);
            var number = Number(String(value).replace(/,/g, ''));
            return Number.isFinite(number) ? number.toLocaleString('en-US', { minimumFractionDigits: window.erpAccountingDecimalPlaces, maximumFractionDigits: window.erpAccountingDecimalPlaces }) : value;
        };
        $(document).on('draw.dt', function (event, settings) {
            $(settings.nTable).find('tbody td.text-end').each(function () {
                var $cell = $(this), value = $.trim($cell.text());
                if (!value || /[%/]/.test(value) || !/^-?[\d,]+(?:\.\d+)?$/.test(value)) return;
                $cell.text(window.erpAccountingFormat(value));
            });
        });
    </script>
@endpush
