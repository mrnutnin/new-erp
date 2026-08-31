@extends('Settings::layout')

@section('title', 'ผู้ใช้งาน | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">SETTINGS</p>
                <h1 class="h3 mb-2">ผู้ใช้งานและสิทธิ์เข้าถึง</h1>
                <p class="text-secondary mb-0">จัดการสถานะ โปรแกรม และคลังที่ผู้ใช้เข้าถึงได้</p>
            </div>
            @if (auth()->user()->hasPermission('settings.users.create'))
                <a class="btn btn-dark" href="{{ route('settings.users.create') }}">
                    <i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มผู้ใช้งาน
                </a>
            @endif
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table
                                class="table table-hover align-middle w-100"
                                id="users-table"
                                data-url="{{ route('settings.users.data') }}"
                                data-export-url="{{ route('settings.users.export') }}"
                                data-can-update="{{ auth()->user()->hasPermission('settings.users.update') ? '1' : '0' }}"
                                data-can-delete="{{ auth()->user()->hasPermission('settings.users.delete') ? '1' : '0' }}"
                            >
                                <thead>
                                    <tr>
                                        <th>ผู้ใช้งาน</th>
                                        <th>สาขาหลัก</th>
                                        <th>โปรแกรม</th>
                                        <th>คลัง</th>
                                        <th>สถานะ</th>
                                        @if (auth()->user()->hasPermission('settings.users.update') || auth()->user()->hasPermission('settings.users.delete'))
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
            var $table = $('#users-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                {
                    data: 'name',
                    name: 'name',
                    render: function (value, type, row) {
                        if (type !== 'display') {
                            return value;
                        }

                        var details = text.display(row.username);
                        if (row.employee_code) {
                            details += ' · ' + text.display(row.employee_code);
                        }
                        if (row.email) {
                            details += ' · ' + text.display(row.email);
                        }

                        return '<div class="fw-semibold">' + text.display(value) + '</div><div class="small text-secondary">' + details + '</div>';
                    }
                },
                {
                    data: 'primary_branch',
                    name: 'primaryBranch.name',
                    orderable: false,
                    render: function (value, type) {
                        if (type !== 'display') {
                            return value || '';
                        }

                        return value ? text.display(value.code + ' — ' + value.name) : '<span class="text-secondary">—</span>';
                    }
                },
                { data: 'programs_count', name: 'programs_count', searchable: false },
                { data: 'warehouses_count', name: 'warehouses_count', searchable: false },
                {
                    data: 'is_active',
                    name: 'is_active',
                    searchable: false,
                    render: function (value, type) {
                        if (type !== 'display') {
                            return value;
                        }

                        return '<span class="badge ' + (value ? 'text-bg-dark">ใช้งาน' : 'text-bg-secondary">ปิดใช้งาน') + '</span>';
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
                            actions.push('<button class="btn btn-sm btn-outline-danger js-delete-user" type="button" data-url="' + text.display(row.delete_url) + '"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ</button>');
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
                button: '.js-delete-user',
                reload: '#users-table',
                confirm: 'ยืนยันการลบผู้ใช้งานนี้หรือไม่?'
            });
        });
    </script>
@endpush
