@extends('Settings::layout')

@section('title', 'สาขา | MintERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">SETTINGS</p>
                <h1 class="h3 mb-2">สาขา</h1>
                <p class="text-secondary mb-0">จัดการโครงสร้างสาขาของบริษัท</p>
            </div>
            @if (auth()->user()->hasPermission('settings.branches.create'))
                <a class="btn btn-app-primary" href="{{ route('settings.branches.create') }}">
                    <i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มสาขา
                </a>
            @endif
        </div>

        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรอง</h2><p class="small text-secondary mb-0">กรองตามสถานะก่อนดูรายการ</p></div><button class="btn btn-sm btn-app-soft" id="branch-filter-reset" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div><div class="row g-3"><div class="col-12 col-md-4"><label class="form-label" for="branch-filter-status">สถานะ</label><select class="form-select" id="branch-filter-status"><option value="">ทุกสถานะ</option><option value="1">ใช้งาน</option><option value="0">ปิดใช้งาน</option></select></div></div></div></div>
        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="branches-table"
                                   data-url="{{ route('settings.branches.data') }}"
                                   data-export-url="{{ route('settings.branches.export') }}"
                                   data-can-update="{{ auth()->user()->hasPermission('settings.branches.update') ? '1' : '0' }}"
                                   data-can-delete="{{ auth()->user()->hasPermission('settings.branches.delete') ? '1' : '0' }}">
                                <thead>
                                    <tr>
                                        <th>รหัส</th>
                                        <th>ชื่อสาขา</th>
                                        <th>ผู้ออกใบกำกับภาษี</th>
                                        <th>คลังที่ใช้งาน</th>
                                        <th>สถานะ</th>
                                        @if (auth()->user()->hasPermission('settings.branches.update') || auth()->user()->hasPermission('settings.branches.delete'))
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
            var $table = $('#branches-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                { data: 'code', name: 'code', render: text.display },
                { data: 'name', name: 'name', render: text.display },
                { data: null, name: 'tax_branch_code', render: function (value, type, row) { var label = row.tax_branch_code ? 'สาขา ' + text.display(row.tax_branch_code) : 'ยังไม่กำหนด'; return type === 'display' ? '<div>' + label + '</div><small class="text-secondary">' + (row.tax_address ? text.display(row.tax_address) : 'ไม่มีที่อยู่') + '</small>' : (row.tax_branch_code || ''); } },
                { data: 'active_warehouses_count', name: 'active_warehouses_count', searchable: false },
                {
                    data: 'is_active',
                    name: 'is_active',
                    searchable: false,
                    render: function (value, type) {
                        return type === 'display'
                            ? '<span class="badge ' + (value ? 'app-status-success">ใช้งาน' : 'app-status-neutral">ปิดใช้งาน') + '</span>'
                            : value;
                    }
                }
            ];

            if ($table.data('can-update') || $table.data('can-delete')) {
                columns.push({
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-end text-nowrap',
                    render: function (value, type, row) {
                        var actions = [];
                        if (row.edit_url) {
                            actions.push('<a class="btn btn-sm btn-app-soft" href="' + text.display(row.edit_url) + '" title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit" aria-hidden="true"></i></a>');
                        }
                        if (row.delete_url) {
                            actions.push('<button class="btn btn-sm btn-app-danger js-delete-branch" type="button" data-url="' + text.display(row.delete_url) + '" title="ลบ" aria-label="ลบ"><i class="bx bx-trash" aria-hidden="true"></i></button>');
                        }

                        return actions.join(' ');
                    }
                });
            }

            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: { url: $table.data('url'), data: function (data) { data.is_active = $('#branch-filter-status').val(); } },
                order: [[0, 'asc']],
                buttons: [window.erpExcelButton($table)],
                columns: columns
            }));

            $('#branch-filter-status').on('change', function () { $table.DataTable().ajax.reload(); });
            $('#branch-filter-reset').on('click', function () { $('#branch-filter-status').val(''); $table.DataTable().ajax.reload(); });

            window.erpAjaxDelete({
                button: '.js-delete-branch',
                reload: '#branches-table',
                confirm: 'ยืนยันการลบสาขานี้หรือไม่?'
            });
        });
    </script>
@endpush
