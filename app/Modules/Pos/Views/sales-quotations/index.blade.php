@extends('Pos::layout')
@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        @include('Pos::partials.sales-list-header', [
            'eyebrow' => 'SALES / QUOTATION',
            'title' => 'ใบเสนอราคา',
            'description' => 'รายการใบเสนอราคาที่สร้างจากใบขอราคา (RFQ)',
        ])
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-lg-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3"><label for="quotation-from" class="form-label">วันที่เริ่ม</label><input
                            id="quotation-from" type="date" class="form-control"></div>
                    <div class="col-md-3"><label for="quotation-to" class="form-label">ถึงวันที่</label><input
                            id="quotation-to" type="date" class="form-control"></div>
                    <div class="col-md-3"><label for="quotation-status" class="form-label">สถานะ</label><select
                            id="quotation-status" class="form-select">
                            <option value="">ทั้งหมด</option>
                            <option value="DRAFT">ร่าง</option>
                            <option value="SENT">ส่งแล้ว</option>
                            <option value="ACCEPTED">ตอบรับแล้ว</option>
                            <option value="REJECTED">ปฏิเสธ</option>
                            <option value="CANCELLED">ยกเลิก</option>
                        </select></div>
                    <div class="col-md-3 d-flex align-items-end"><button id="quotation-filter"
                            class="btn btn-outline-secondary"><i class="bx bx-filter-alt me-1"></i>กรอง</button></div>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3">
                    <h2 class="h6 mb-0">รายการใบเสนอราคา</h2>

                </div>
                <div class="table-responsive">
                    <table id="quotation-table" class="table table-hover align-middle w-100"
                        data-url="{{ route('pos.sales-quotations.data') }}">
                        <thead>
                            <tr>
                                <th>เลขที่</th>
                                <th>วันที่</th>
                                <th>ลูกค้า</th>
                                <th>รายการ</th>
                                <th>ยอดรวม</th>
                                <th>สถานะ</th>
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
            const esc = s => $('<div>').text(s ?? '-').html(),
                badge = s => ({
                    DRAFT: 'app-badge-soft',
                    SENT: 'app-badge-info',
                    ACCEPTED: 'app-badge-success',
                    REJECTED: 'text-bg-danger',
                    CANCELLED: 'text-bg-danger'
                } [s] || 'app-badge-soft'),
                date = s => {
                    if (!s) return '-';
                    const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
                    return m ? `${m[3]}/${m[2]}/${m[1]}` : esc(s)
                };
            const table = $('#quotation-table');
            const t = table.DataTable({
                ...window.erpDataTableDefaults,
                ajax: {
                    url: table.data('url'),
                    data: d => {
                        d.date_from = $('#quotation-from').val();
                        d.date_to = $('#quotation-to').val();
                        d.status = $('#quotation-status').val()
                    }
                },
                buttons: [window.erpExcelButton(table)],
                columns: [{
                    data: 'document_number',
                    render: (d, _, r) => `<a href="${r.show_url}">${esc(d)}</a>`
                }, {
                    data: 'document_date',
                    render: date
                }, {
                    data: 'party_label',
                    render: esc
                }, {
                    data: 'lines_count'
                }, {
                    data: 'total_amount',
                    render: d => Number(d || 0).toLocaleString(undefined, {
                        minimumFractionDigits: 2
                    })
                }, {
                    data: 'status_label',
                    render: (d, _, r) => `<span class="badge ${badge(r.status)}">${esc(d)}</span>`
                }, {
                    data: null,
                    orderable: false,
                    render: (_, __, r) =>
                        `<a class="btn btn-sm btn-app-soft" title="ดูรายละเอียด" aria-label="ดูรายละเอียด" href="${r.show_url}"><i class="bx bx-show"></i></a> <a class="btn btn-sm btn-app-soft" title="พิมพ์ PDF" aria-label="พิมพ์ PDF" href="${r.pdf_url}"><i class="bx bx-printer"></i></a>${r.order_url?` <a class="btn btn-sm btn-app-soft" title="ดูใบสั่งขาย" aria-label="ดูใบสั่งขาย" href="${r.order_url}"><i class="bx bx-cart"></i></a>`:''}`
                }]
            });
            $('#quotation-filter').on('click', () => t.ajax.reload());
        });
    </script>
@endpush
