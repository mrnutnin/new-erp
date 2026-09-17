@extends('Asset::layout')

@section('title', 'ตรวจนับสินทรัพย์ | MintERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <p class="eyebrow mb-2">ASSET / PHYSICAL COUNT</p>
                <h1 class="h3 mb-1">ตรวจนับสินทรัพย์</h1>
                <p class="text-secondary mb-0">ตรวจสอบสินทรัพย์ตามขอบเขตที่ freeze ไว้ โดยไม่ปรับทะเบียนอัตโนมัติ</p>
            </div>
            @if (auth()->user()->hasPermission('asset.counts.create'))
                <a class="btn btn-app-primary" href="{{ route('asset.counts.create') }}">สร้างใบตรวจนับ</a>
            @endif
        </div>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรอง</h2><p class="small text-secondary mb-0">กรองข้อมูลก่อนค้นหาจากตาราง</p></div><button class="btn btn-sm btn-app-soft" id="count-filter-reset" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
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
                    DRAFT: 'app-status-neutral',
                    SUBMITTED: 'app-status-info',
                    APPROVED: 'app-status-success',
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
                        data: null,
                        orderable: false,
                        searchable: false,
                        className: 'text-end',
                        render: function(value, type, row) {
                            if (type !== 'display') return '';
                            var actions = ['<a class="btn btn-sm btn-app-soft" href="' + text.display(row.show_url) + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-file-find" aria-hidden="true"></i></a>'];
                            if (row.delete_url) actions.push('<button class="btn btn-sm btn-app-danger js-delete-count" data-url="' + text.display(row.delete_url) + '" type="button" title="ลบร่าง" aria-label="ลบร่าง"><i class="bx bx-trash" aria-hidden="true"></i></button>');
                            return actions.join(' ');
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
            window.erpAjaxDelete({button:'.js-delete-count',reload:'#counts-table',confirm:'ยืนยันการลบใบตรวจนับร่างนี้หรือไม่?'});
        });
    </script>
@endpush
