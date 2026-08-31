@extends('Pos::layout')

@section('title', 'รายงาน POS รายวัน | POS')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <p class="eyebrow mb-2">POS / REPORTS</p>
            <h1 class="h3 mb-2">รายงาน POS รายวัน</h1>
            <p class="text-secondary mb-0">ยอดขายและกระแสเงินสดจาก HS/IV และใบรับคืนที่ลงบัญชีแล้ว</p>
        </div>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-lg-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3"><label for="sales-report-from" class="form-label">วันที่เริ่ม</label><input
                            id="sales-report-from" type="date" class="form-control"
                            value="{{ now()->startOfMonth()->format('Y-m-d') }}"></div>
                    <div class="col-md-3"><label for="sales-report-to" class="form-label">ถึงวันที่</label><input
                            id="sales-report-to" type="date" class="form-control"
                            value="{{ now()->endOfMonth()->format('Y-m-d') }}"></div>
                    <div class="col-md-4"><label class="form-label">คลังสินค้า</label><input class="form-control"
                            value="{{ $warehouse->code }} · {{ $warehouse->name }}" readonly>
                        {{-- <div class="form-text">เปลี่ยนคลังได้จากตัวเลือกด้านบน</div> --}}
                    </div>
                    <div class="col-md-2"><button id="sales-report-filter" class="btn btn-outline-secondary w-100"
                            type="button"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรอง</button></div>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 class="h5 mb-1">สรุปรายวัน</h2>
                        <p class="text-secondary small mb-0">ยึดวันที่ Post สำหรับ HS/IV และใบรับคืน; รับชำระ IV
                            ยึดวันที่รับเงิน</p>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="daily-sales-table" class="table table-hover align-middle w-100"
                        data-url="{{ route('pos.sales-reports.daily.data') }}">
                        <thead>
                            <tr>
                                <th>วันที่</th>
                                <th class="text-end">HS</th>
                                <th class="text-end">IV</th>
                                <th class="text-end">ยอดขายสุทธิ</th>
                                <th class="text-end">รับ HS</th>
                                <th class="text-end">รับ IV</th>
                                <th class="text-end">ใบรับคืน</th>
                                <th class="text-end">คืนเงินสด/ธนาคาร</th>
                                <th class="text-end">เงินสดสุทธิ</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3">
                    <h2 class="h5 mb-1">เงินสด/ธนาคารตามช่องทางรับเงิน</h2>
                    <p class="text-secondary small mb-0">รวมเฉพาะ Tender ที่ผูกกับ HS หรือใบรับชำระของ IV โดยตรง</p>
                </div>
                <div class="table-responsive">
                    <table id="daily-tenders-table" class="table table-hover align-middle w-100"
                        data-url="{{ route('pos.sales-reports.daily.tenders') }}">
                        <thead>
                            <tr>
                                <th>บัญชีเงินสด/ธนาคาร</th>
                                <th>ประเภท</th>
                                <th class="text-end">รับ HS</th>
                                <th class="text-end">รับ IV</th>
                                <th class="text-end">คืนเงิน</th>
                                <th class="text-end">เงินสดสุทธิ</th>
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
            const filters = data => {
                data.date_from = $('#sales-report-from').val();
                data.date_to = $('#sales-report-to').val();
            };
            const date = value => {
                const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
                return m ? `${m[3]}/${m[2]}/${m[1]}` : '-'
            };
            const money = value => Number(value || 0).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            const text = $.fn.dataTable.render.text();
            const sales = $('#daily-sales-table').DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: {
                    url: $('#daily-sales-table').data('url'),
                    data: filters
                },
                order: [
                    [0, 'desc']
                ],
                buttons: [window.erpExcelButton($('#daily-sales-table'))],
                columns: [{
                    data: 'report_date',
                    render: date
                }, {
                    data: 'hs_sales',
                    className: 'text-end',
                    render: money
                }, {
                    data: 'iv_sales',
                    className: 'text-end',
                    render: money
                }, {
                    data: 'net_sales',
                    className: 'text-end fw-semibold',
                    render: money
                }, {
                    data: 'hs_cash_received',
                    className: 'text-end',
                    render: money
                }, {
                    data: 'iv_cash_received',
                    className: 'text-end',
                    render: money
                }, {
                    data: 'return_amount',
                    className: 'text-end text-danger',
                    render: money
                }, {
                    data: 'cash_refund',
                    className: 'text-end text-danger',
                    render: money
                }, {
                    data: 'net_cash',
                    className: 'text-end fw-semibold',
                    render: money
                }]
            }));
            const tenders = $('#daily-tenders-table').DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: {
                    url: $('#daily-tenders-table').data('url'),
                    data: filters
                },
                order: [
                    [0, 'asc']
                ],
                buttons: [window.erpExcelButton($('#daily-tenders-table'))],
                columns: [{
                    data: null,
                    render: (row, type) => type === 'display' ? text.display(
                        `${row.code} · ${row.name}`) : `${row.code} ${row.name}`
                }, {
                    data:'type_label',
                    render: text.display
                }, {
                    data: 'hs_received',
                    className: 'text-end',
                    render: money
                }, {
                    data: 'iv_received',
                    className: 'text-end',
                    render: money
                }, {
                    data: 'cash_refund',
                    className: 'text-end text-danger',
                    render: money
                }, {
                    data: 'net_cash',
                    className: 'text-end fw-semibold',
                    render: money
                }]
            }));
            $('#sales-report-filter').on('click', () => {
                sales.ajax.reload();
                tenders.ajax.reload();
            });
        });
    </script>
@endpush
