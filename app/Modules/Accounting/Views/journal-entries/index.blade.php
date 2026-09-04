@extends('Accounting::layout')

@section('title', 'รายการสมุดรายวัน | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / JOURNAL ENTRIES</p>
                @php($bookTypes = ['PURCHASE' => 'สมุดรายวันซื้อ', 'SALES' => 'สมุดรายวันขาย', 'RECEIPT' => 'สมุดรายวันรับ', 'PAYMENT' => 'สมุดรายวันจ่าย', 'GENERAL' => 'สมุดรายวันทั่วไป'])
                @php($selectedBook = request()->input('book_type'))
                <h1 class="h3 mb-2">{{ $bookTypes[$selectedBook] ?? 'สมุดรายวันรวม' }}</h1>
                <p class="text-secondary mb-0">แสดงรายการของคลังที่คุณมีสิทธิ์ในสาขาปัจจุบัน โดย Draft ยังไม่กระทบบัญชีแยกประเภท</p>
            </div>
            @if (auth()->user()->hasPermission('accounting.journal-entries.create'))
                <a class="btn btn-dark" href="{{ route('accounting.journal-entries.create') }}">
                    <i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มรายการทั่วไป
                </a>
            @endif
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex align-items-center justify-content-between mb-3"><h2 class="h6 mb-0">ตัวกรอง</h2><button class="btn btn-sm btn-outline-secondary" id="reset-journal-filters" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
                <div class="row g-3 align-items-end">
                    <div class="col-md-3"><label class="form-label" for="journal-date-from">วันที่เริ่มต้น</label><input class="form-control" id="journal-date-from" type="date"></div>
                    <div class="col-md-3"><label class="form-label" for="journal-date-to">วันที่สิ้นสุด</label><input class="form-control" id="journal-date-to" type="date"></div>
                    <div class="col-md-3"><label class="form-label" for="journal-status">สถานะ</label><select class="form-select" id="journal-status"><option value="">ทุกสถานะ</option><option value="DRAFT">Draft</option><option value="VALIDATED" @selected(request('status') === 'VALIDATED')>รออนุมัติ</option><option value="POSTED">ลงบัญชีแล้ว</option><option value="REVERSED" @selected(request('status') === 'REVERSED')>กลับรายการแล้ว</option></select></div>
                    <div class="col-md-3"><label class="form-label" for="journal-branch">สาขา</label><select class="form-select" id="journal-branch"><option value="">สาขาปัจจุบัน</option><option value="all">ทุกสาขาที่มีสิทธิ์</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><button class="btn btn-dark w-100" id="apply-journal-filters" type="button"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรองรายการ</button></div>
                </div>
            </div>
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
            var statusClasses = { DRAFT: 'text-bg-secondary', VALIDATED: 'text-bg-warning', POSTED: 'text-bg-success', REVERSED: 'text-bg-danger' };

            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: { url: $table.data('url'), data: function (data) { data.book_type = @json($selectedBook); data.date_from = $('#journal-date-from').val(); data.date_to = $('#journal-date-to').val(); data.status = $('#journal-status').val(); data.branch_id = $('#journal-branch').val(); } },
                order: [[0, 'desc']],
                buttons: [window.erpExcelButton($table, function () { return { book_type: @json($selectedBook), date_from: $('#journal-date-from').val(), date_to: $('#journal-date-to').val(), status: $('#journal-status').val(), branch_id: $('#journal-branch').val() }; })],
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
                            return type === 'display' ? '<span class="badge ' + (statusClasses[value] || 'text-bg-secondary') + '">' + text.display(statusLabels[value] || value) + '</span>' : value;
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
            $('#apply-journal-filters').on('click', function () { $table.DataTable().ajax.reload(); });
            $('#reset-journal-filters').on('click', function () { $('#journal-date-from,#journal-date-to,#journal-status,#journal-branch').val(''); $table.DataTable().ajax.reload(); });
        });
    </script>
@endpush
