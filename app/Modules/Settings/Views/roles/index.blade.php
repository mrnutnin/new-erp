@extends('Settings::layout')

@section('title', 'บทบาทและสิทธิ์ | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">SETTINGS</p>
                <h1 class="h3 mb-2">บทบาทและสิทธิ์</h1>
                <p class="text-secondary mb-0">รวมสิทธิ์เป็นบทบาทเพื่อกำหนดให้ผู้ใช้งาน</p>
            </div>
            @if (auth()->user()->hasPermission('settings.roles.manage'))
                <a class="btn btn-dark" href="{{ route('settings.roles.create') }}">
                    <i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มบทบาท
                </a>
            @endif
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="roles-table"
                                   data-url="{{ route('settings.roles.data') }}"
                                   data-export-url="{{ route('settings.roles.export') }}"
                                   data-can-update="{{ auth()->user()->hasPermission('settings.roles.manage') ? '1' : '0' }}"
                                   data-can-delete="{{ auth()->user()->hasPermission('settings.roles.delete') ? '1' : '0' }}">
                                <thead>
                                    <tr>
                                        <th>บทบาท</th>
                                        <th>สิทธิ์</th>
                                        <th>ผู้ใช้</th>
                                        <th>สถานะ</th>
                                        @if (auth()->user()->hasPermission('settings.roles.manage') || auth()->user()->hasPermission('settings.roles.delete'))
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
            var $table = $('#roles-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                {
                    data: 'name',
                    name: 'name',
                    render: function (value, type, row) {
                        return type === 'display'
                            ? '<div class="fw-semibold">' + text.display(value) + '</div><div class="small text-secondary">' + text.display(row.code) + '</div>'
                            : value;
                    }
                },
                { data: 'permissions_count', name: 'permissions_count', searchable: false },
                { data: 'users_count', name: 'users_count', searchable: false },
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
                            actions.push('<button class="btn btn-sm btn-outline-danger js-delete-role" type="button" data-url="' + text.display(row.delete_url) + '"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ</button>');
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
                button: '.js-delete-role',
                reload: '#roles-table',
                confirm: 'ยืนยันการลบบทบาทนี้หรือไม่?'
            });
        });
    </script>
@endpush
