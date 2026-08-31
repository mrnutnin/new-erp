@extends('Finance::layout')

@section('title', 'รายได้/รายจ่ายอื่น | Finance')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <p class="eyebrow mb-2">FINANCE / MASTER DATA</p>
                <h1 class="h3 mb-2">รายได้อื่น / รายจ่ายอื่น</h1>
                <p class="text-secondary mb-0">กำหนดรายการเบ็ดเตล็ดที่ผูกกับบัญชี GL และ Tax Code</p>
            </div>
            @if(auth()->user()->hasPermission('finance.other-categories.create'))
                <a class="btn btn-dark" href="{{ route('finance.other-categories.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มรายการ</a>
            @endif
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <table class="table table-hover align-middle w-100" id="other-categories-table"
                       data-url="{{ route('finance.other-categories.data') }}"
                       >
                    <thead><tr><th>ประเภท</th><th>รหัส</th><th>ชื่อ</th><th>บัญชี GL</th><th>Tax Code</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#other-categories-table');
            var text = $.fn.dataTable.render.text();

            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: $table.data('url'),
                order: [[0, 'asc'], [1, 'asc']],
                buttons: [window.erpExcelButton($table)],
                columns: [
                    { data: 'kind_label', name: 'finance_other_categories.kind', render: function (value, type, row) { return type === 'display' ? '<span class="badge ' + (row.kind === 'INCOME' ? 'text-bg-success' : 'text-bg-warning') + '">' + text.display(value) + '</span>' : value; } },
                    { data: 'code', name: 'finance_other_categories.code', render: text.display },
                    { data: 'name', name: 'finance_other_categories.name', render: text.display },
                    { data: null, name: 'accounts.code', render: function (value, type, row) { return type === 'display' ? text.display(row.account_code + ' · ' + row.account_name) : row.account_code; } },
                    { data: 'tax_code', name: 'tax_codes.code', render: function (value, type) { return type === 'display' ? text.display(value || 'NONE') : value; } },
                    { data: 'is_active', name: 'finance_other_categories.is_active', render: function (value, type) { return type === 'display' ? '<span class="badge ' + (value ? 'text-bg-success' : 'text-bg-secondary') + '">' + (value ? 'ใช้งาน' : 'ปิดใช้งาน') + '</span>' : value; } },
                    { data: null, orderable: false, searchable: false, className: 'text-end', render: function (value, type, row) { if (type !== 'display') return ''; var actions = []; if (row.edit_url) actions.push('<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.edit_url) + '">แก้ไข</a>'); if (row.delete_url) actions.push('<button class="btn btn-sm btn-outline-danger js-delete-other-category" data-url="' + text.display(row.delete_url) + '" type="button">ลบ</button>'); return actions.join(' '); } }
                ]
            }));

            window.erpAjaxDelete({ button: '.js-delete-other-category', reload: '#other-categories-table', confirm: 'ยืนยันการลบรายการนี้หรือไม่?' });
        });
    </script>
@endpush
