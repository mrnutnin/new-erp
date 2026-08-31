@extends('Settings::layout')

@section('title', 'รหัสและรูปแบบเอกสาร | Settings')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <p class="eyebrow mb-2">SETTINGS / DOCUMENT SEQUENCES</p>
                <h1 class="h3 mb-2">รหัสและรูปแบบเอกสาร</h1>
                <p class="text-secondary mb-0">กำหนดรูปแบบกลางของทั้งระบบ โดยเลขรันแยกตามประเภทเอกสารและสาขา</p>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <table class="table table-hover align-middle w-100" id="document-sequences-table"
                       data-url="{{ route('settings.document-sequences.data') }}"
                       >
                    <thead><tr><th>ประเภท</th><th>ชื่อ</th><th>Prefix</th><th>รูปแบบ</th><th>Reset</th><th>เลขถัดไป</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#document-sequences-table');
            var text = $.fn.dataTable.render.text();

            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: $table.data('url'),
                order: [[0, 'asc']],
                buttons: [window.erpExcelButton($table)],
                columns: [
                    { data: 'document_type_label', name: 'document_type', render: text.display },
                    { data: 'name', name: 'name', render: text.display },
                    { data: 'prefix', name: 'prefix', render: text.display },
                    { data: 'number_format', name: 'number_format', render: function (value, type) { return type === 'display' ? '<code>' + text.display(value) + '</code>' : value; } },
                    { data: 'reset_rule_label', name: 'reset_rule', render: text.display },
                    { data: 'next_number', name: 'next_number', render: text.display },
                    { data: 'is_active', name: 'is_active', render: function (value, type) { return type === 'display' ? '<span class="badge ' + (value ? 'text-bg-success' : 'text-bg-secondary') + '">' + (value ? 'ใช้งาน' : 'ปิดใช้งาน') + '</span>' : value; } },
                    { data: null, orderable: false, searchable: false, className: 'text-end', render: function (value, type, row) { return type === 'display' && row.edit_url ? '<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.edit_url) + '">แก้ไข</a>' : ''; } }
                ]
            }));

        });
    </script>
@endpush
