@extends('Settings::layout')

@section('title', 'คลัง | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">SETTINGS</p>
                <h1 class="h3 mb-2">คลัง</h1>
                <p class="text-secondary mb-0">จัดการคลังและสาขาที่สังกัด</p>
            </div>
            @if (auth()->user()->hasPermission('settings.warehouses.create'))
                <a class="btn btn-dark" href="{{ route('settings.warehouses.create') }}">
                    <i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มคลัง
                </a>
            @endif
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="warehouses-table"
                                   data-url="{{ route('settings.warehouses.data') }}"
                                   data-export-url="{{ route('settings.warehouses.export') }}"
                                   data-can-update="{{ auth()->user()->hasPermission('settings.warehouses.update') ? '1' : '0' }}"
                                   data-can-delete="{{ auth()->user()->hasPermission('settings.warehouses.delete') ? '1' : '0' }}">
                                <thead>
                                    <tr>
                                        <th>รหัส</th>
                                        <th>ชื่อคลัง</th>
                                        <th>สาขา</th>
                                        <th>สถานะ</th>
                                        @if (auth()->user()->hasPermission('settings.warehouses.update') || auth()->user()->hasPermission('settings.warehouses.delete'))
                                            <th class="text-end">จัดการ</th>
                                        @endif
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
            var $table = $('#warehouses-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                { data: 'code', name: 'code', render: text.display },
                { data: 'name', name: 'name', render: text.display },
                {
                    data: 'branch_code',
                    name: 'branch_code',
                    render: function (value, type, row) {
                        return type === 'display' ? text.display(value) + ' — ' + text.display(row.branch_name) : value;
                    }
                },
                {
                    data: 'is_active',
                    name: 'is_active',
                    searchable: false,
                    render: function (value, type) {
                        return type === 'display'
                            ? '<span class="badge ' + (value ? 'text-bg-dark">ใช้งาน' : 'text-bg-secondary">ปิดใช้งาน') + '</span>'
                            : value;
                    }
                }
            ];

            if ($table.data('can-update') || $table.data('can-delete')) {
                columns.push({
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-end',
                    render: function (value, type, row) {
                        var actions = [];
                        if (row.edit_url) {
                            actions.push('<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.edit_url) + '"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข</a>');
                        }
                        if (row.delete_url) {
                            actions.push('<button class="btn btn-sm btn-outline-danger js-delete-warehouse" type="button" data-url="' + text.display(row.delete_url) + '"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ</button>');
                        }

                        return actions.join(' ');
                    }
                });
            }

            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: $table.data('url'),
                order: [[0, 'asc']],
                buttons: [window.erpExcelButton($table)],
                columns: columns
            }));

            window.erpAjaxDelete({
                button: '.js-delete-warehouse',
                reload: '#warehouses-table',
                confirm: 'ยืนยันการลบคลังนี้หรือไม่?'
            });
        });
    </script>
@endpush
