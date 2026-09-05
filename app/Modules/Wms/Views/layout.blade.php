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
            $('.container-fluid.px-3.px-lg-4.py-4 .js-act, .container-fluid.px-3.px-lg-4.py-4 .js-action, .container-fluid.px-3.px-lg-4.py-4 [data-transfer-action="accept"]').each(function () {
                $(this).removeClass('btn-app-soft btn-app-primary').addClass('btn-dark');
            });
            $('.container-fluid.px-3.px-lg-4.py-4 > .d-flex a[href*="/create"]').each(function () {
                $(this).removeClass('btn-app-primary').addClass('btn-dark');
            });

            var actionOrder = {
                'ดูรายละเอียด': 10,
                'เปิดรายละเอียด': 10,
                'แก้ไข': 20,
                'แก้ไขร่าง': 20,
                'ลบร่าง': 30,
                'อนุมัติ': 40,
                'ลง Stock': 50,
                'ลงบัญชี': 50,
                'ส่งออก': 50,
                'เปิดหน้ารับโอน': 50,
                'กลับรายการ': 60,
                'ยกเลิก': 60,
            };

            $(document).on('draw.dt', 'table.dataTable', function () {
                $(this).find('tbody tr').each(function () {
                    var cell = $(this).find('td').last();
                    var actions = cell.children('a, button').toArray();

                    actions.forEach(function (action) {
                        var label = $(action).attr('title') || $(action).attr('aria-label') || '';
                        if (/อนุมัติ|ลง Stock|ลงบัญชี|ส่งออก|รับเข้า|รับโอน/.test(label)) {
                            $(action).removeClass('btn-app-soft btn-app-primary').addClass('btn-dark');
                        }
                        if (/ลบ|ยกเลิก|กลับรายการ|ปฏิเสธ/.test(label)) {
                            $(action).removeClass('btn-app-soft').addClass('btn-app-danger');
                        }
                    });
                    if (actions.length < 2) return;

                    actions.sort(function (a, b) {
                        var titleA = $(a).attr('title') || $(a).attr('aria-label') || '';
                        var titleB = $(b).attr('title') || $(b).attr('aria-label') || '';
                        var orderA = Object.keys(actionOrder).find(function (key) { return titleA.indexOf(key) === 0; });
                        var orderB = Object.keys(actionOrder).find(function (key) { return titleB.indexOf(key) === 0; });
                        return (orderA ? actionOrder[orderA] : 999) - (orderB ? actionOrder[orderB] : 999);
                    });
                    $(actions).appendTo(cell);
                });
            });
        });
    </script>
@endpush
