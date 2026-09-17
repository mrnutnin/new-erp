@extends('Pos::layout')
@section('title', 'ขายสด / ขายเชื่อ | POS')
@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        @include('Pos::partials.sales-list-header', [
            'eyebrow' => 'SALES / PHYSICAL SALES',
            'title' => 'ขายสด / ขายเชื่อ (HS/IV)',
            'description' => 'เอกสารขายจริงจะกระทบ Stock และบัญชีเมื่อเปิดใช้งานการ Post',
            'actionUrl' => route('pos.physical-sales.create'),
            'actionLabel' => 'สร้างใบขาย',
            'actionClass' => 'btn-app-primary',
        ])
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรอง HS/IV</h2><p class="text-secondary mb-0 small">กรองก่อนค้นหาจากตาราง</p></div><button id="physical-sale-reset" type="button" class="btn btn-sm btn-app-soft"><i class="bx bx-reset me-1"></i>ล้างตัวกรอง</button></div>
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-4 col-lg-2"><label class="form-label"
                            for="physical-sale-from">วันที่เริ่ม</label><input id="physical-sale-from" type="date"
                            class="form-control"></div>
                    <div class="col-12 col-md-4 col-lg-2"><label class="form-label"
                            for="physical-sale-to">ถึงวันที่</label><input id="physical-sale-to" type="date"
                            class="form-control"></div>
                    <div class="col-12 col-md-4 col-lg-2"><label class="form-label"
                            for="physical-sale-type">ประเภท</label><select id="physical-sale-type" class="form-select">
                            <option value="">ทั้งหมด</option>
                            <option value="HS">ขายสด (HS)</option>
                            <option value="IV">ขายเชื่อ (IV)</option>
                        </select></div>
                    <div class="col-12 col-md-4 col-lg-2"><label class="form-label"
                            for="physical-sale-status">สถานะเอกสาร</label><select id="physical-sale-status"
                            class="form-select">
                            <option value="">ทั้งหมด</option>
                            <option value="DRAFT">ร่าง</option>
                            <option value="POSTED">ลงบัญชีแล้ว</option>
                            <option value="VOID">ยกเลิก</option>
                        </select></div>
                    <div class="col-12 col-md-4 col-lg-2"><label class="form-label"
                            for="physical-sale-payment-status">สถานะชำระเงิน</label><select
                            id="physical-sale-payment-status" class="form-select">
                            <option value="">ทั้งหมด</option>
                            <option value="UNPAID">ยังไม่ชำระ</option>
                            <option value="PARTIAL">ชำระบางส่วน</option>
                            <option value="PAID">ชำระครบ</option>
                            <option value="CHECK">ต้องตรวจสอบ AR</option>
                        </select></div>
                    <div class="col-12 col-md-4 col-lg-2"><button id="physical-sale-filter"
                            class="btn btn-app-primary w-100" type="button"><i class="bx bx-filter-alt me-1"
                                aria-hidden="true"></i>ใช้ตัวกรอง</button></div>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3">
                <h2 class="h5 mb-1">รายการใบ HS/IV</h2><p class="text-secondary mb-0 small">เลือกดูรายละเอียดเพื่อยืนยันขาย รับชำระหนี้ หรือยกเลิกเอกสารตามสถานะ</p>
                </div>
                <div class="table-responsive">
                    <table id="physical-sales-table" class="table table-hover align-middle w-100"
                        data-url="{{ route('pos.physical-sales.data') }}">
                        <thead>
                            <tr><th>ลำดับ</th>
                                <th>เลขที่</th>
                                <th>ประเภท</th>
                                <th>วันที่เอกสาร</th>
                                <th>ลูกค้า</th>
                                <th>สถานะเอกสาร</th>
                                <th>สถานะการชำระ</th>
                                <th class="text-end">ยอดรวม</th>
                                <th>จัดการ</th>
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
            var t = $('#physical-sales-table'),
                esc = $.fn.dataTable.render.text(),
                payment = {
                    UNPAID: ['warning', 'ยังไม่ชำระ'],
                    PARTIAL: ['info', 'ชำระบางส่วน'],
                    PAID: ['success', 'ชำระครบ'],
                    CHECK: ['danger', 'ต้องตรวจสอบ AR']
                };
            t.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: {
                    url: t.data('url'),
                    data: function(data) {
                        data.date_from = $('#physical-sale-from').val();
                        data.date_to = $('#physical-sale-to').val();
                        data.document_type = $('#physical-sale-type').val();
                        data.status = $('#physical-sale-status').val();
                        data.payment_status = $('#physical-sale-payment-status').val();
                    }
                },
                order: [
                    [3, 'desc']
                ],
                buttons: [window.erpExcelButton(t)],
                columns: [window.erpRowNumberColumn(),{
                        data: 'document_number',
                        render: esc
                    }, {
                        data: 'type_label',
                        render: esc
                    }, {
                        data: 'document_date_label',
                        name: 'document_date',
                        render: esc
                    }, {
                        data: 'party_label',
                        render: esc
                    },
                    {
                        data: 'status_label',
                        render: function(v, type, row) {
                            if (type !== 'display') return v;
                            var c = {
                                DRAFT: 'app-badge-soft',
                                POSTED: 'app-badge-success',
                                VOID: 'text-bg-danger'
                            } [row.status] || 'app-badge-soft';
                            return '<span class="badge ' + c + '">' + esc.display(v) +
                            '</span>';
                        }
                    },
                    {
                        data: 'payment_status_label',
                        orderable: false,
                        searchable: false,
                        render: function(v, type, row) {
                            if (type !== 'display' || !row.payment_status) return v;
                            var item = payment[row.payment_status] || ['secondary', v];
                            return '<span class="badge bg-' + item[0] + '-subtle text-' + item[
                                0] + '-emphasis">' + esc.display(v) + '</span>';
                        }
                    },
                    {
                        data: 'total_amount',
                        className: 'text-end',
                        render: $.fn.dataTable.render.number(',', '.', {{ $decimalPlaces }})
                    },
                    {
                        data: null,
                        orderable: false,
                        searchable: false,
                        className: 'text-end text-nowrap',
                        render: function(_, type, row) {
                            if (type !== 'display') return '';
                            var button = function(url, style, icon, label, newTab) {
                                return url ? '<a class="btn btn-sm ' + style + '" title="' +
                                    label + '" aria-label="' + label + '" href="' + esc
                                    .display(url) + (newTab ? '" target="_blank" rel="noopener' : '') + '"><i class="bx ' + icon +
                                    '" aria-hidden="true"></i><span class="visually-hidden">' +
                                    label + '</span></a> ' : '';
                            };
                            return button(row.show_url, 'btn-app-soft', 'bx-file-find',
                                'ดูรายละเอียด') + button(row.pdf_url, 'btn-app-soft',
                                'bx-printer', 'พิมพ์ PDF', true) + (row.delete_url ? '<button class="btn btn-sm btn-app-danger js-delete-physical-sale" type="button" data-url="' + esc.display(row.delete_url) + '" title="ลบร่าง" aria-label="ลบร่าง"><i class="bx bx-trash" aria-hidden="true"></i></button>' : '');
                        }
                    }
                ]
            }));
            $('#physical-sale-filter').on('click', function() {
                t.DataTable().ajax.reload();
            });
            $('#physical-sale-reset').on('click', function() {
                $('#physical-sale-from,#physical-sale-to').val('');
                $('#physical-sale-type,#physical-sale-status,#physical-sale-payment-status').val('');
                t.DataTable().ajax.reload();
            });
            window.erpAjaxDelete({button: '.js-delete-physical-sale', reload: '#physical-sales-table', confirm: 'ยืนยันการลบร่าง HS/IV นี้หรือไม่?', confirmButtonText: 'ลบร่าง', cancelButtonText: 'กลับ'});
        });
    </script>
@endpush
