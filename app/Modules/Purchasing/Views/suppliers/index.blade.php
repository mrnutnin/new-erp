@extends($moduleRoutePrefix === 'purchasing' ? 'Purchasing::layout' : 'Wms::layout')

@section('title', 'Supplier | Purchasing')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">PURCHASING / MASTER DATA</p>
                <h1 class="h3 mb-2">Supplier</h1>
                <p class="text-secondary mb-0">ข้อมูลผู้ขายและเงื่อนไขการชำระเงินระดับบริษัท</p>
            </div>
            @if (auth()->user()->hasPermission($moduleRoutePrefix.'.suppliers.create'))
                <a class="btn btn-dark" href="{{ route($moduleRoutePrefix.'.suppliers.create') }}">
                    <i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่ม Supplier
                </a>
            @endif
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="table-responsive">
                    <table
                        class="table table-hover align-middle w-100"
                        id="suppliers-table"
                        data-url="{{ route($moduleRoutePrefix.'.suppliers.data') }}"
                        data-can-update="{{ auth()->user()->hasPermission($moduleRoutePrefix.'.suppliers.update') ? 1 : 0 }}"
                        data-can-delete="{{ auth()->user()->hasPermission($moduleRoutePrefix.'.suppliers.delete') ? 1 : 0 }}"
                    >
                        <thead>
                            <tr>
                                <th>รหัส</th>
                                <th>ชื่อ Supplier</th>
                                <th>ประเภท</th>
                                <th>ข้อมูลภาษี</th>
                                <th>ผู้ติดต่อ</th>
                                <th>เงื่อนไขชำระเงิน</th>
                                <th class="text-end">วงเงินเครดิต</th>
                                <th>สถานะ</th>
                                @if (auth()->user()->hasPermission($moduleRoutePrefix.'.suppliers.update') || auth()->user()->hasPermission($moduleRoutePrefix.'.suppliers.delete'))
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
            var $table = $('#suppliers-table');
            var text = $.fn.dataTable.render.text();
            var amount = $.fn.dataTable.render.number(',', '.', 2);
            var columns = [
                { data: 'code', name: 'parties.code', render: text.display },
                { data: 'name', name: 'parties.name', render: text.display },
                { data: 'type_label', name: 'parties.type', render: text.display },
                { data: 'tax_label', name: 'parties.tax_id', render: text.display },
                { data: 'contact_label', name: 'parties.contact_name', render: text.display },
                { data: 'payment_term_label', name: 'finance_payment_terms.code', render: text.display },
                { data: 'credit_limit', name: 'party_roles.credit_limit', className: 'text-end', render: amount },
                {
                    data: 'supplier_is_active',
                    name: 'party_roles.is_active',
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
                    className: 'text-end',
                    render: function (value, type, row) {
                        if (type !== 'display') return '';
                        var actions = [];
                        if (row.edit_url) actions.push('<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.edit_url) + '">แก้ไข</a>');
                        if (row.delete_url) actions.push('<button class="btn btn-sm btn-outline-danger js-delete-supplier" data-url="' + text.display(row.delete_url) + '" type="button">ลบ</button>');
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
                button: '.js-delete-supplier',
                reload: '#suppliers-table',
                confirm: 'ยืนยันการลบ Supplier นี้หรือไม่?'
            });
        });
    </script>
@endpush
