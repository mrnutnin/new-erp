@extends('Accounting::layout')

@section('title', 'ผังบัญชี | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING</p>
                <h1 class="h3 mb-2">ผังบัญชี</h1>
                <p class="text-secondary mb-0">โครงสร้าง 1–5 ระดับ แยกบัญชีรวม บัญชีย่อย และบัญชีคุม พร้อม profile รายงาน PAE/NPAE</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if (auth()->user()->hasPermission('accounting.accounts.import'))
                    <a class="btn btn-outline-dark" href="{{ route('accounting.account-import.template') }}">
                        <i class="bx bx-download me-1" aria-hidden="true"></i>ดาวน์โหลด Template
                    </a>
                    <a class="btn btn-outline-dark" href="{{ route('accounting.account-import.create') }}">
                        <i class="bx bx-upload me-1" aria-hidden="true"></i>Import Excel
                    </a>
                @endif
                @if (auth()->user()->hasPermission('accounting.accounts.create'))
                    <a class="btn btn-dark" href="{{ route('accounting.accounts.create') }}">
                        <i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มบัญชี
                    </a>
                @endif
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="accounts-table"
                                   data-url="{{ route('accounting.accounts.data') }}"
                                   data-export-url="{{ route('accounting.accounts.export') }}"
                                   data-can-update="{{ auth()->user()->hasPermission('accounting.accounts.update') ? '1' : '0' }}"
                                   data-can-delete="{{ auth()->user()->hasPermission('accounting.accounts.delete') ? '1' : '0' }}">
                                <thead>
                                    <tr>
                                        <th>รหัส</th>
                                        <th>ชื่อบัญชี</th>
                                        <th>หมวด</th>
                                        <th>บัญชีแม่</th>
                                        <th>ระดับ</th>
                                        <th>ประเภทบัญชี</th>
                                        <th>Control</th>
                                        <th>Profile</th>
                                        <th>สถานะ</th>
                                        @if (auth()->user()->hasPermission('accounting.accounts.update') || auth()->user()->hasPermission('accounting.accounts.delete'))
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
            var $table = $('#accounts-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                { data: 'code', name: 'code', render: text.display },
                { data: 'name', name: 'name', render: text.display },
                { data: 'type_name', name: 'type_name', orderable: false, render: text.display },
                { data: 'parent_label', name: 'parent_label', orderable: false, render: text.display },
                { data: 'level', name: 'level', searchable: false },
                { data: 'class_label', name: 'class_label', orderable: false, searchable: false, render: text.display },
                { data: 'control_account_type', name: 'control_account_type', orderable: false, searchable: false, defaultContent: '—', render: text.display },
                { data: 'reporting_profile', name: 'reporting_profile', orderable: false, searchable: false, defaultContent: 'ทั้งสอง', render: text.display },
                {
                    data: 'is_active', name: 'is_active', searchable: false,
                    render: function (value, type) {
                        return type === 'display'
                            ? '<span class="badge ' + (value ? 'text-bg-dark">ใช้งาน' : 'text-bg-secondary">ปิดใช้งาน') + '</span>'
                            : value;
                    }
                }
            ];

            if ($table.data('can-update') || $table.data('can-delete')) {
                columns.push({
                    data: null, orderable: false, searchable: false, className: 'text-end',
                    render: function (value, type, row) {
                        var actions = [];
                        if (row.edit_url) {
                            actions.push('<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.edit_url) + '"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข</a>');
                        }
                        if (row.delete_url) {
                            actions.push('<button class="btn btn-sm btn-outline-danger js-delete-account" type="button" data-url="' + text.display(row.delete_url) + '"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ</button>');
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
                button: '.js-delete-account',
                reload: '#accounts-table',
                confirm: 'ยืนยันการลบบัญชีนี้หรือไม่?'
            });
        });
    </script>
@endpush
