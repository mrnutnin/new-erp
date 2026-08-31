@extends('Finance::layout')

@section('title', 'บัญชีเงินสด/ธนาคาร | Finance')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <p class="eyebrow mb-2">FINANCE / MASTER DATA</p>
                <h1 class="h3 mb-2">บัญชีเงินสด/ธนาคาร</h1>
                <p class="text-secondary mb-0">บัญชีรับ–จ่ายที่ผูกกับบัญชีคุม GL ของ Warehouse ปัจจุบัน</p>
            </div>
            @if (auth()->user()->hasPermission('finance.bank-accounts.create'))
                <a class="btn btn-dark" href="{{ route('finance.bank-accounts.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มบัญชี</a>
            @endif
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="table-responsive">
                    <table
                        id="bank-accounts-table"
                        class="table table-hover align-middle w-100"
                        data-url="{{ route('finance.bank-accounts.data') }}"
                        data-can-update="{{ auth()->user()->hasPermission('finance.bank-accounts.update') ? 1 : 0 }}"
                        data-can-delete="{{ auth()->user()->hasPermission('finance.bank-accounts.delete') ? 1 : 0 }}"
                    >
                        <thead>
                            <tr>
                                <th>รหัส</th>
                                <th>ชื่อ</th>
                                <th>ประเภท</th>
                                <th>ธนาคาร/เลขบัญชี</th>
                                <th>บัญชีคุม GL</th>
                                <th>สถานะ</th>
                                @if (auth()->user()->hasPermission('finance.bank-accounts.update') || auth()->user()->hasPermission('finance.bank-accounts.delete'))
                                    <th class="text-end">จัดการ</th>
                                @endif
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#bank-accounts-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                { data: 'code', name: 'code', render: text.display },
                { data: 'name', name: 'name', render: text.display },
                { data: 'type_label', name: 'type', render: text.display },
                { data: 'bank_details', name: 'bank_name', render: text.display },
                { data: 'account_label', name: 'account_id', render: text.display },
                {
                    data: 'is_active',
                    name: 'is_active',
                    render: function (value, type) {
                        return type === 'display'
                            ? '<span class="badge ' + (value ? 'text-bg-success">ใช้งาน' : 'text-bg-secondary">ปิดใช้งาน') + '</span>'
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
                        if (type !== 'display') return '';
                        var actions = [];
                        if (row.edit_url) actions.push('<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.edit_url) + '">แก้ไข</a>');
                        if (row.delete_url) actions.push('<button class="btn btn-sm btn-outline-danger js-delete-bank-account" data-url="' + text.display(row.delete_url) + '" type="button">ลบ</button>');
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
                button: '.js-delete-bank-account',
                reload: '#bank-accounts-table',
                confirm: 'ยืนยันการลบบัญชีเงินสด/ธนาคารนี้หรือไม่?'
            });
        });
    </script>
@endpush
