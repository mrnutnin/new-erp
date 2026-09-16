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

        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรอง</h2><p class="small text-secondary mb-0">กรองตามสถานะก่อนดูรายการ</p></div><button class="btn btn-sm btn-app-soft" id="document-sequence-filter-reset" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div><div class="row g-3"><div class="col-12 col-md-4"><label class="form-label" for="document-sequence-filter-status">สถานะ</label><select class="form-select" id="document-sequence-filter-status"><option value="">ทุกสถานะ</option><option value="1">ใช้งาน</option><option value="0">ปิดใช้งาน</option></select></div></div></div></div>
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
                ajax: { url: $table.data('url'), data: function (data) { data.is_active = $('#document-sequence-filter-status').val(); } },
                order: [[0, 'asc']],
                buttons: [window.erpExcelButton($table)],
                columns: [
                    { data: 'document_type_label', name: 'document_type', render: text.display },
                    { data: 'name', name: 'name', render: text.display },
                    { data: 'prefix', name: 'prefix', render: text.display },
                    { data: 'number_format', name: 'number_format', render: function (value, type) { return type === 'display' ? '<code>' + text.display(value) + '</code>' : value; } },
                    { data: 'reset_rule_label', name: 'reset_rule', render: text.display },
                    { data: 'next_number', name: 'next_number', render: text.display },
                    { data: 'is_active', name: 'is_active', render: function (value, type) { return type === 'display' ? '<span class="badge ' + (value ? 'app-status-success' : 'app-status-neutral') + '">' + (value ? 'ใช้งาน' : 'ปิดใช้งาน') + '</span>' : value; } },
                    { data: null, orderable: false, searchable: false, className: 'text-end', render: function (value, type, row) { return type === 'display' && row.edit_url ? '<a class="btn btn-sm btn-app-soft" href="' + text.display(row.edit_url) + '" title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit" aria-hidden="true"></i></a>' : ''; } }
                ]
            }));

            $('#document-sequence-filter-status').on('change', function () { $table.DataTable().ajax.reload(); });
            $('#document-sequence-filter-reset').on('click', function () { $('#document-sequence-filter-status').val(''); $table.DataTable().ajax.reload(); });

        });
    </script>
@endpush
