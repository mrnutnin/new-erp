@extends('Settings::layout')

@section('title', 'Audit Log | MintERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <p class="eyebrow mb-2">SETTINGS</p>
            <h1 class="h3 mb-2">ประวัติการเปลี่ยนแปลง</h1>
            <p class="text-secondary mb-0">ตรวจสอบผู้ดำเนินการ รายการก่อนแก้ไข และผลลัพธ์หลังแก้ไข</p>
        </div>

        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรอง</h2><p class="small text-secondary mb-0">กำหนดช่วงเวลาของประวัติที่ต้องการตรวจสอบ</p></div><button class="btn btn-sm btn-app-soft" id="audit-filter-reset" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div><div class="row g-3"><div class="col-12 col-md-4"><label class="form-label" for="audit-filter-date-from">ตั้งแต่วันที่</label><input class="form-control" id="audit-filter-date-from" type="date"></div><div class="col-12 col-md-4"><label class="form-label" for="audit-filter-date-to">ถึงวันที่</label><input class="form-control" id="audit-filter-date-to" type="date"></div></div></div></div>
        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="audit-table" data-url="{{ route('settings.audit.data') }}" data-export-url="{{ route('settings.audit.export') }}">
                                <thead>
                                    <tr>
                                        <th>เวลา</th>
                                        <th>ผู้ดำเนินการ</th>
                                        <th>Action</th>
                                        <th>Subject</th>
                                        <th>ก่อนแก้ไข</th>
                                        <th>หลังแก้ไข</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#audit-table');
            var text = $.fn.dataTable.render.text();

            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: { url: $table.data('url'), data: function (data) { data.date_from = $('#audit-filter-date-from').val(); data.date_to = $('#audit-filter-date-to').val(); } },
                order: [[0, 'desc']],
                buttons: [window.erpExcelButton($table)],
                columns: [
                    { data: 'occurred_at', name: 'created_at' },
                    { data: 'actor', name: 'actor' },
                    { data: 'action', name: 'action', render: text.display },
                    { data: 'subject', name: 'subject', render: text.display },
                    { data: 'before_summary', name: 'before_summary', orderable: false, searchable: false, render: text.display },
                    { data: 'after_summary', name: 'after_summary', orderable: false, searchable: false, render: text.display }
                ]
            }));

            $('#audit-filter-date-from,#audit-filter-date-to').on('change', function () { $table.DataTable().ajax.reload(); });
            $('#audit-filter-reset').on('click', function () { $('#audit-filter-date-from,#audit-filter-date-to').val(''); $table.DataTable().ajax.reload(); });
        });
    </script>
@endpush
