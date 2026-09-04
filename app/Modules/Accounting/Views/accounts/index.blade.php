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

        <div class="card border-0 shadow-sm mb-4" id="account-filters">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h6 mb-0">ตัวกรองผังบัญชี</h2><button type="button" class="btn btn-outline-secondary btn-sm" id="account-filter-reset"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
                <div class="row g-3">
                    <div class="col-12 col-md-3"><label class="form-label" for="account-type-filter">หมวดบัญชี</label><select class="form-select" id="account-type-filter"><option value="">ทุกหมวดบัญชี</option>@foreach($accountTypes as $type)<option value="{{ $type->id }}">{{ $type->code }} · {{ $type->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-3"><label class="form-label" for="account-class-filter">ลักษณะบัญชี</label><select class="form-select" id="account-class-filter"><option value="">ทุกลักษณะ</option><option value="control">บัญชีคุม</option><option value="postable">บัญชีย่อย (ลงรายการได้)</option><option value="group">บัญชีรวม</option></select></div>
                    <div class="col-12 col-md-3"><label class="form-label" for="account-status-filter">สถานะ</label><select class="form-select" id="account-status-filter"><option value="active">ใช้งาน</option><option value="inactive">ปิดใช้งาน</option><option value="">ทุกสถานะ</option></select></div>
                    <div class="col-12 col-md-3"><label class="form-label" for="account-profile-filter">มาตรฐานรายงาน</label><select class="form-select" id="account-profile-filter"><option value="">ทุกมาตรฐาน</option><option value="PAE">PAE</option><option value="NPAE">NPAE</option></select></div>
                </div>
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
                ajax: { url: $table.data('url'), data: function (data) { data.account_type_id = $('#account-type-filter').val(); data.account_class = $('#account-class-filter').val(); data.status = $('#account-status-filter').val(); data.reporting_profile = $('#account-profile-filter').val(); } },
                order: [[0, 'asc']],
                buttons: [window.erpExcelButton($table, function () { return { account_type_id: $('#account-type-filter').val(), account_class: $('#account-class-filter').val(), status: $('#account-status-filter').val(), reporting_profile: $('#account-profile-filter').val() }; })],
                columns: columns
            }));

            window.erpAjaxDelete({
                button: '.js-delete-account',
                reload: '#accounts-table',
                confirm: 'ยืนยันการลบบัญชีนี้หรือไม่?'
            });

            $('#account-type-filter,#account-class-filter,#account-status-filter,#account-profile-filter').on('change', function () { $table.DataTable().ajax.reload(); });
            $('#account-filter-reset').on('click', function () { $('#account-type-filter,#account-class-filter,#account-profile-filter').val(''); $('#account-status-filter').val('active'); $table.DataTable().ajax.reload(); });
        });
    </script>
@endpush
