@extends('Accounting::layout')

@section('title', 'ปีและงวดบัญชี | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING</p>
                <h1 class="h3 mb-2">ปีและงวดบัญชี</h1>
                <p class="text-secondary mb-0">งวดระดับบริษัท ใช้ร่วมกันทุกสาขาและคลัง</p>
            </div>
            @if (auth()->user()->hasPermission('accounting.periods.create'))
                <a class="btn btn-dark" href="{{ route('accounting.fiscal-years.create') }}">
                    <i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างปีบัญชี
                </a>
            @endif
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="alert alert-info" role="status">
                    <i class="bx bx-info-circle me-1" aria-hidden="true"></i>เปิดใช้ Soft close และ Reopen แล้ว ส่วน Lock จะเปิดใช้เมื่อ posting/reconciliation gates พร้อม
                </div>
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="fiscal-years-table"
                                   data-url="{{ route('accounting.fiscal-years.data') }}"
                                   data-export-url="{{ route('accounting.fiscal-years.export') }}">
                                <thead>
                                    <tr>
                                        <th>วันเริ่ม</th>
                                        <th>รหัส</th>
                                        <th>ชื่อปีบัญชี</th>
                                        <th>วันสิ้นสุด</th>
                                        <th>Open</th>
                                        <th>Soft close</th>
                                        <th>Locked</th>
                                        <th class="text-end">จัดการ</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#fiscal-years-table');
            var text = $.fn.dataTable.render.text();

            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: $table.data('url'),
                order: [[0, 'desc']],
                buttons: [window.erpExcelButton($table)],
                columns: [
                    { data: 'start_label', name: 'start_date', render: text.display },
                    { data: 'code', name: 'code', render: text.display },
                    { data: 'name', name: 'name', render: text.display },
                    { data: 'end_label', name: 'end_date', render: text.display },
                    { data: 'open_periods_count', name: 'open_periods_count', searchable: false },
                    { data: 'soft_close_periods_count', name: 'soft_close_periods_count', searchable: false },
                    { data: 'locked_periods_count', name: 'locked_periods_count', searchable: false },
                    {
                        data: 'periods_url', orderable: false, searchable: false, className: 'text-end',
                        render: function (value) {
                            return '<a class="btn btn-sm btn-outline-dark" href="' + text.display(value) + '"><i class="bx bx-show me-1" aria-hidden="true"></i>ดูงวด</a>';
                        }
                    }
                ]
            }));
        });
    </script>
@endpush
