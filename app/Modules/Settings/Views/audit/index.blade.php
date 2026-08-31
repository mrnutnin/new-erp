@extends('Settings::layout')

@section('title', 'Audit Log | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <p class="eyebrow mb-2">SETTINGS</p>
            <h1 class="h3 mb-2">ประวัติการเปลี่ยนแปลง</h1>
            <p class="text-secondary mb-0">ตรวจสอบผู้ดำเนินการ รายการก่อนแก้ไข และผลลัพธ์หลังแก้ไข</p>
        </div>

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
                ajax: $table.data('url'),
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
        });
    </script>
@endpush
