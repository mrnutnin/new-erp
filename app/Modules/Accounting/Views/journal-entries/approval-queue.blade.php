@extends('Accounting::layout')

@section('title', 'Journal Approval Queue | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / AUDIT &amp; CONTROL</p>
                <h1 class="h3 mb-2">Journal Approval Queue</h1>
                <p class="text-secondary mb-0">ตรวจสอบรายการบัญชีที่ส่งอนุมัติและยังไม่ได้ลงบัญชี</p>
            </div>
            <a class="btn btn-outline-dark" href="{{ route('accounting.journal-entries.index') }}"><i class="bx bx-book-open me-1" aria-hidden="true"></i>ดูสมุดรายวัน</a>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h6 mb-0">ตัวกรอง</h2>
                    <button class="btn btn-sm btn-outline-secondary" id="reset-approval-filters" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button>
                </div>
                <div class="row g-3 align-items-end">
                    <div class="col-md-3"><label class="form-label" for="approval-date-from">วันที่เริ่มต้น</label><input class="form-control" id="approval-date-from" type="date"></div>
                    <div class="col-md-3"><label class="form-label" for="approval-date-to">วันที่สิ้นสุด</label><input class="form-control" id="approval-date-to" type="date"></div>
                    <div class="col-md-3"><label class="form-label" for="approval-branch">สาขา</label><select class="form-select" id="approval-branch"><option value="">สาขาปัจจุบัน</option><option value="all">ทุกสาขาที่มีสิทธิ์</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><button class="btn btn-dark w-100" id="apply-approval-filters" type="button"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรองรายการ</button></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h6 mb-0">รายการรออนุมัติ</h2>
                    <span class="badge text-bg-warning">รออนุมัติ</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="approval-queue-table" data-url="{{ route('accounting.journal-approval-queue.data') }}">
                        <thead><tr><th>วันที่</th><th>เลขที่</th><th>สมุดบัญชี</th><th>สาขา</th><th>คำอธิบาย</th><th class="text-end">เดบิต</th><th class="text-end">เครดิต</th><th class="text-end">จัดการ</th></tr></thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#approval-queue-table');
            var text = $.fn.dataTable.render.text();
            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: { url: $table.data('url'), data: function (data) { data.date_from = $('#approval-date-from').val(); data.date_to = $('#approval-date-to').val(); data.branch_id = $('#approval-branch').val(); } },
                order: [[0, 'desc']],
                columns: [
                    { data: 'entry_date_label', name: 'entry_date', render: text.display },
                    { data: 'entry_number', name: 'entry_number', render: text.display },
                    { data: 'book_label', name: 'book_label', orderable: false, render: text.display },
                    { data: 'branch_label', name: 'branch_label', orderable: false, render: text.display },
                    { data: 'description', name: 'description', render: text.display },
                    { data: 'debit_total', name: 'debit_total', orderable: false, searchable: false, className: 'text-end', render: text.display },
                    { data: 'credit_total', name: 'credit_total', orderable: false, searchable: false, className: 'text-end', render: text.display },
                    { data: null, orderable: false, searchable: false, className: 'text-end', render: function (value, type, row) { return '<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.show_url) + '"><i class="bx bx-show me-1" aria-hidden="true"></i>ตรวจสอบ</a>'; } }
                ]
            }));
            $('#apply-approval-filters').on('click', function () { $table.DataTable().ajax.reload(); });
            $('#reset-approval-filters').on('click', function () { $('#approval-date-from,#approval-date-to,#approval-branch').val(''); $table.DataTable().ajax.reload(); });
        });
    </script>
@endpush
