@extends('Accounting::layout')

@section('title', 'Account Mapping | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / SETTINGS</p>
                <h1 class="h3 mb-2">Account Mapping</h1>
                <p class="text-secondary mb-0">กำหนดบัญชีมาตรฐานสำหรับเอกสารขายและซื้อ</p>
            </div>
            @if (auth()->user()->hasPermission('accounting.account-mappings.create'))
                <a class="btn btn-dark" href="{{ route('accounting.account-mappings.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่ม Mapping</a>
            @endif
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="account-mappings-table" data-url="{{ route('accounting.account-mappings.data') }}" data-can-update="{{ auth()->user()->hasPermission('accounting.account-mappings.update') ? 1 : 0 }}">
                        <thead><tr><th>ประเภท Mapping</th><th>บัญชี GL</th><th>สถานะ</th>@if (auth()->user()->hasPermission('accounting.account-mappings.update'))<th class="text-end">จัดการ</th>@endif</tr></thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#account-mappings-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                { data: 'key_label', name: 'accounting_account_mappings.key', render: text.display },
                { data: 'account_label', name: 'accounts.code', render: text.display },
                { data: 'is_active', name: 'accounting_account_mappings.is_active', render: function (value, type) { return type === 'display' ? '<span class="badge ' + (value ? 'text-bg-success">ใช้งาน' : 'text-bg-secondary">ปิดใช้งาน') + '</span>' : value; } }
            ];
            if ($table.data('can-update')) {
                columns.push({ data: null, orderable: false, searchable: false, className: 'text-end', render: function (value, type, row) { return type === 'display' && row.edit_url ? '<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.edit_url) + '" title="แก้ไข Mapping" aria-label="แก้ไข Mapping"><i class="bx bx-edit-alt" aria-hidden="true"></i></a>' : ''; } });
            }
            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, { ajax: $table.data('url'), order: [[0, 'asc']], buttons: [window.erpExcelButton($table)], columns: columns }));
        });
    </script>
@endpush
