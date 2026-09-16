@extends('layouts.app')

@section('sidebar')
    @include('Wms::partials.sidebar')
@endsection

@push('scripts')
    <script>
        $(function () {
            // Keep WMS document pages aligned with the Finance header/action pattern.
            $('.container-fluid.px-3.px-lg-4.py-4 > .d-flex.justify-content-between').each(function () {
                $(this)
                    .removeClass('flex-wrap align-items-end align-items-start')
                    .addClass('flex-column flex-lg-row justify-content-between align-items-lg-end gap-3');
                $(this).children(':last-child').addClass('d-flex flex-wrap gap-2');
            });
            $('.container-fluid.px-3.px-lg-4.py-4 > .d-flex.flex-wrap.justify-content-between').each(function () {
                $(this)
                    .removeClass('flex-wrap align-items-end')
                    .addClass('flex-column flex-md-row justify-content-between align-items-md-end gap-3');
            });
            $('.container-fluid.px-3.px-lg-4.py-4 .card-body').each(function () {
                $(this).removeClass('p-4').addClass('p-3 p-lg-4');
            });

            // Navigation back is one UI action across WMS.  Workflow reversal
            // uses a button and remains explicitly labelled "ยกเลิกเอกสาร".
            $('a.btn[href]').filter(function () {
                var label = $(this).clone().children().remove().end().text().replace(/\s+/g, ' ').trim();

                return /^(?:←\s*)?กลับ(?:.*)?$/.test(label) || ($(this).find('.bx-arrow-back').length && !label);
            }).each(function () {
                $(this)
                    .removeClass('btn-outline-secondary')
                    .addClass('btn-app-soft')
                    .attr({'title': 'กลับหน้ารายการ', 'aria-label': 'กลับหน้ารายการ'})
                    .empty()
                    .append('<i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ');
            });
        });
    </script>
@endpush
