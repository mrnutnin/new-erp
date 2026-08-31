@extends('Accounting::layout')

@section('title', 'รายการสมุดรายวัน | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / JOURNAL ENTRIES</p>
                <h1 class="h3 mb-2">รายการสมุดรายวัน</h1>
                <p class="text-secondary mb-0">แสดงรายการของคลังที่คุณมีสิทธิ์ในสาขาปัจจุบัน โดย Draft ยังไม่กระทบบัญชีแยกประเภท</p>
            </div>
            @if (auth()->user()->hasPermission('accounting.journal-entries.create'))
                <a class="btn btn-dark" href="{{ route('accounting.journal-entries.create') }}">
                    <i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มรายการทั่วไป
                </a>
            @endif
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="journal-entries-table"
                           data-url="{{ route('accounting.journal-entries.data') }}"
                           data-export-url="{{ route('accounting.journal-entries.export') }}">
                        <thead>
                            <tr>
                                <th>วันที่</th>
                                <th>เลขที่</th>
                                <th>สมุดบัญชี</th>
                                <th>สาขา</th>
                                <th>คำอธิบาย</th>
                                <th class="text-end">เดบิต</th>
                                <th class="text-end">เครดิต</th>
                                <th>สถานะ</th>
                                <th class="text-end">จัดการ</th>
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
            var $table = $('#journal-entries-table');
            var text = $.fn.dataTable.render.text();
            var statusLabels = { DRAFT: 'Draft', VALIDATED: 'รออนุมัติ', POSTED: 'ลงบัญชีแล้ว', REVERSED: 'กลับรายการแล้ว' };

            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: $table.data('url'),
                order: [[0, 'desc']],
                buttons: [window.erpExcelButton($table)],
                columns: [
                    { data: 'entry_date_label', name: 'entry_date', render: text.display },
                    { data: 'entry_number', name: 'entry_number', render: text.display },
                    { data: 'book_label', name: 'book_label', orderable: false, render: text.display },
                    { data: 'branch_label', name: 'branch_label', orderable: false, render: text.display },
                    { data: 'description', name: 'description', render: text.display },
                    { data: 'debit_total', name: 'debit_total', orderable: false, searchable: false, className: 'text-end', render: text.display },
                    { data: 'credit_total', name: 'credit_total', orderable: false, searchable: false, className: 'text-end', render: text.display },
                    {
                        data: 'status', name: 'status', searchable: false,
                        render: function (value, type) {
                            return type === 'display' ? '<span class="badge text-bg-secondary">' + text.display(statusLabels[value] || value) + '</span>' : value;
                        }
                    },
                    {
                        data: null, orderable: false, searchable: false, className: 'text-end',
                        render: function (value, type, row) {
                            var actions = ['<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.show_url) + '"><i class="bx bx-show me-1" aria-hidden="true"></i>ดู</a>'];
                            if (row.edit_url) {
                                actions.push('<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.edit_url) + '"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข</a>');
                            }
                            return actions.join(' ');
                        }
                    }
                ]
            }));
        });
    </script>
@endpush
