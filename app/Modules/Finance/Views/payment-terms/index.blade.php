@extends('Finance::layout')

@section('title', 'เงื่อนไขการชำระเงิน | Finance')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <p class="eyebrow mb-2">FINANCE / MASTER DATA</p>
                <h1 class="h3 mb-2">เงื่อนไขการชำระเงิน</h1>
                <p class="text-secondary mb-0">ใช้กำหนดวันครบกำหนดสำหรับลูกหนี้และเจ้าหนี้</p>
            </div>
            @if (auth()->user()->hasPermission('finance.payment-terms.create'))
                <a class="btn btn-dark" href="{{ route('finance.payment-terms.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มเงื่อนไข</a>
            @endif
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="table-responsive">
                    <table
                        id="payment-terms-table"
                        class="table table-hover align-middle w-100"
                        data-url="{{ route('finance.payment-terms.data') }}"
                        data-can-update="{{ auth()->user()->hasPermission('finance.payment-terms.update') ? 1 : 0 }}"
                        data-can-delete="{{ auth()->user()->hasPermission('finance.payment-terms.delete') ? 1 : 0 }}"
                    >
                        <thead>
                            <tr>
                                <th>รหัส</th>
                                <th>ชื่อ</th>
                                <th>เครดิต</th>
                                <th>กติกาครบกำหนด</th>
                                <th>สถานะ</th>
                                @if (auth()->user()->hasPermission('finance.payment-terms.update') || auth()->user()->hasPermission('finance.payment-terms.delete'))
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
            var $table = $('#payment-terms-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                { data: 'code', name: 'code', render: text.display },
                { data: 'name', name: 'name', render: text.display },
                {
                    data: 'credit_days',
                    name: 'credit_days',
                    render: function (value, type) { return type === 'display' ? text.display(value + ' วัน') : value; }
                },
                { data: 'due_rule_label', name: 'due_rule', render: text.display },
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
                        if (row.delete_url) actions.push('<button class="btn btn-sm btn-outline-danger js-delete-term" data-url="' + text.display(row.delete_url) + '" type="button">ลบ</button>');
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
                button: '.js-delete-term',
                reload: '#payment-terms-table',
                confirm: 'ยืนยันการลบเงื่อนไขนี้หรือไม่?'
            });
        });
    </script>
@endpush
