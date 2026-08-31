@extends('Pos::layout')

@section('title', 'ลูกค้า | POS / Sales')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <p class="eyebrow mb-2">POS / SALES / MASTER DATA</p>
                <h1 class="h3 mb-2">ลูกค้า</h1>
                <p class="text-secondary mb-0">ข้อมูลกลางของลูกค้า ใช้ร่วมกันทุกสาขา</p>
            </div>
            @if (auth()->user()->hasPermission('pos.customers.create'))
                <a class="btn btn-dark" href="{{ route('pos.customers.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มลูกค้า</a>
            @endif
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="table-responsive">
                    <table
                        id="customers-table"
                        class="table table-hover align-middle w-100"
                        data-url="{{ route('pos.customers.data') }}"
                        data-can-update="{{ auth()->user()->hasPermission('pos.customers.update') ? 1 : 0 }}"
                        data-can-delete="{{ auth()->user()->hasPermission('pos.customers.delete') ? 1 : 0 }}"
                    >
                        <thead>
                            <tr>
                                <th>รหัส</th>
                                <th>ชื่อลูกค้า</th>
                                <th>กลุ่มลูกค้า</th>
                                <th>ประเภท</th>
                                <th>เลขผู้เสียภาษี</th>
                                <th>ผู้ติดต่อ / โทรศัพท์</th>
                                <th>เงื่อนไขชำระเงิน</th>
                                <th class="text-end">วงเงินเครดิต</th>
                                <th>สถานะ</th>
                                @if (auth()->user()->hasPermission('pos.customers.update') || auth()->user()->hasPermission('pos.customers.delete'))
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
            var $table = $('#customers-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                { data: 'code', name: 'parties.code', render: text.display },
                { data: 'name', name: 'parties.name', render: text.display },
                { data: 'group_label', name: 'customer_group_name', render: text.display },
                { data: 'type_label', name: 'parties.type', render: text.display },
                { data: 'tax_label', name: 'parties.tax_id', render: text.display },
                { data: 'contact_label', name: 'parties.contact_name', render: text.display },
                { data: 'payment_term_label', name: 'payment_terms.code', render: text.display },
                {
                    data: 'credit_limit',
                    name: 'customer_roles.credit_limit',
                    className: 'text-end',
                    render: $.fn.dataTable.render.number(',', '.', 2)
                },
                {
                    data: 'is_active',
                    name: 'customer_roles.is_active',
                    render: function (value, type) {
                        if (type !== 'display') return value;
                        return value
                            ? '<span class="badge bg-success-subtle text-success-emphasis">ใช้งาน</span>'
                            : '<span class="badge bg-secondary-subtle text-secondary-emphasis">ปิดใช้งาน</span>';
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
                        if (row.delete_url) actions.push('<button class="btn btn-sm btn-outline-danger js-delete-customer" data-url="' + text.display(row.delete_url) + '" type="button">ลบ</button>');
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
                button: '.js-delete-customer',
                reload: '#customers-table',
                confirm: 'ยืนยันการลบลูกค้านี้หรือไม่?'
            });
        });
    </script>
@endpush
