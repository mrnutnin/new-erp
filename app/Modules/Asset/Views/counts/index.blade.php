@extends('Asset::layout')

@section('title', 'ตรวจนับสินทรัพย์ | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <p class="eyebrow mb-2">ASSET / PHYSICAL COUNT</p>
                <h1 class="h3 mb-1">ตรวจนับสินทรัพย์</h1>
                <p class="text-secondary mb-0">ตรวจสอบสินทรัพย์ตามขอบเขตที่ freeze ไว้ โดยไม่ปรับทะเบียนอัตโนมัติ</p>
            </div>
            @if (auth()->user()->hasPermission('asset.counts.create'))
                <a class="btn btn-dark" href="{{ route('asset.counts.create') }}">สร้างใบตรวจนับ</a>
            @endif
        </div>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h6 mb-3">ตัวกรอง</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-4 col-xl-3"><label class="form-label"
                            for="count-filter-status">สถานะ</label><select class="form-select" id="count-filter-status">
                            <option value="">ทุกสถานะ</option>
                            <option value="DRAFT">ร่าง</option>
                            <option value="SUBMITTED">รออนุมัติ</option>
                            <option value="APPROVED">อนุมัติแล้ว</option>
                            <option value="CANCELLED">ยกเลิก</option>
                        </select></div>
                    <div class="col-12 col-md-4 col-xl-3"><label class="form-label" for="count-filter-date-from">วัน freeze
                            ตั้งแต่</label><input class="form-control" id="count-filter-date-from" type="date"></div>
                    <div class="col-12 col-md-4 col-xl-3"><label class="form-label" for="count-filter-date-to">วัน freeze
                            ถึง</label><input class="form-control" id="count-filter-date-to" type="date"></div>
                    <div class="col-12 col-xl-2 ms-auto"><label for="count-filter-reset">&nbsp;</label><button class="btn btn-outline-secondary w-100"
                            id="count-filter-reset" type="button">ล้างตัวกรอง</button></div>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="counts-table"
                        data-url="{{ route('asset.counts.data') }}">
                        <thead>
                            <tr>
                                <th>เลขที่เอกสาร</th>
                                <th>วัน freeze</th>
                                <th>จำนวนรายการ</th>
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
        $(function() {
            var $table = $('#counts-table'),
                text = $.fn.dataTable.render.text(),
                labels = {
                    DRAFT: 'ร่าง',
                    SUBMITTED: 'รออนุมัติ',
                    APPROVED: 'อนุมัติแล้ว',
                    CANCELLED: 'ยกเลิก'
                },
                badges = {
                    DRAFT: 'app-badge-soft',
                    SUBMITTED: 'app-badge-info',
                    APPROVED: 'app-badge-success',
                    CANCELLED: 'app-status-danger'
                };
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: {
                    url: $table.data('url'),
                    data: function(data) {
                        data.status = $('#count-filter-status').val();
                        data.date_from = $('#count-filter-date-from').val();
                        data.date_to = $('#count-filter-date-to').val();
                    }
                },
                order: [
                    [1, 'desc']
                ],
                buttons: [window.erpExcelButton($table)],
                columns: [{
                        data: 'document_number',
                        render: text.display
                    }, {
                        data: 'freeze_date_label',
                        name: 'freeze_date',
                        render: text.display
                    }, {
                        data: 'lines_count'
                    },
                    {
                        data: 'status',
                        render: function(value, type) {
                            return type === 'display' ? '<span class="badge ' + badges[value] +
                                '">' + labels[value] + '</span>' : value;
                        }
                    },
                    {
                        data: 'show_url',
                        orderable: false,
                        searchable: false,
                        className: 'text-end',
                        render: function(value, type) {
                            return type === 'display' ?
                                '<a class="btn btn-sm btn-outline-dark" href="' + text.display(
                                    value) + '">ดู</a>' : value;
                        }
                    }
                ]
            }));
            $('#count-filter-status, #count-filter-date-from, #count-filter-date-to').on('change', function() {
                table.ajax.reload();
            });
            $('#count-filter-reset').on('click', function() {
                $('#count-filter-status, #count-filter-date-from, #count-filter-date-to').val('');
                table.ajax.reload();
            });
        });
    </script>
@endpush
