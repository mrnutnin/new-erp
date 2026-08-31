@extends('Pos::layout')
@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        @include('Pos::partials.sales-list-header', [
            'eyebrow' => 'SALES / RFQ',
            'title' => 'ใบขอราคา',
            'description' => 'สอบถามราคาและเงื่อนไขจากลูกค้า',
        ])
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-lg-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3"><label for="rfq-from" class="form-label">วันที่เริ่ม</label><input id="rfq-from"
                            type="date" class="form-control"></div>
                    <div class="col-md-3"><label for="rfq-to" class="form-label">ถึงวันที่</label><input id="rfq-to"
                            type="date" class="form-control"></div>
                    <div class="col-md-3"><label for="rfq-party" class="form-label">ลูกค้า</label><select id="rfq-party"
                            class="form-select"></select></div>
                    <div class="col-md-3"><label for="rfq-status" class="form-label">สถานะ</label><select id="rfq-status"
                            class="form-select">
                            <option value="">ทั้งหมด</option>
                            <option value="WAIT">รอพิจารณา</option>
                            <option value="APPROVED">อนุมัติแล้ว</option>
                            <option value="REJECTED">ไม่อนุมัติ</option>
                            <option value="CANCELLED">ยกเลิก</option>
                        </select></div>
                </div><button class="btn btn-outline-secondary mt-3" id="rfq-filter"><i
                        class="bx bx-filter-alt me-1"></i>กรอง</button>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3">
                    <h2 class="h6 mb-0">รายการใบขอราคา</h2>

                </div>
                <div class="table-responsive">
                    <table id="rfq-table" class="table table-hover align-middle w-100"
                        data-url="{{ route('pos.sales-rfqs.data') }}">
                        <thead>
                            <tr>
                                <th>เลขที่</th>
                                <th>วันที่</th>
                                <th>ใช้ได้ถึง</th>
                                <th>ลูกค้า</th>
                                <th>รายการ</th>
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
            const humanDate = d => {
                if (!d) return '-';
                const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/);
                return m ? `${m[3]}/${m[2]}/${m[1]}` : d
            };
            const esc = d => $('<div>').text(d ?? '').html();
            const statusBadge = s => ({
                WAIT: 'app-badge-warning',
                APPROVED: 'app-badge-success',
                REJECTED: 'text-bg-danger',
                CANCELLED: 'text-bg-danger'
            } [s] || 'app-badge-soft');
            const table = $('#rfq-table');
            const t = table.DataTable({
                ...window.erpDataTableDefaults,
                buttons: [window.erpExcelButton(table)],
                ajax: {
                    url: table.data('url'),
                    data: d => {
                        d.date_from = $('#rfq-from').val();
                        d.date_to = $('#rfq-to').val();
                        d.party_id = $('#rfq-party').val();
                        d.status = $('#rfq-status').val()
                    }
                },
                columns: [{
                    data: 'document_number',
                    render: (d, _, r) => `<a href="${esc(r.show_url)}">${esc(d)}</a>`
                }, {
                    data: 'document_date',
                    render: humanDate
                }, {
                    data: 'valid_until',
                    render: humanDate
                }, {
                    data: 'party_label',
                    render: esc
                }, {
                    data: 'lines_count'
                }, {
                    data: 'status_label',
                    render: (d, _, r) =>
                        `<span class="badge ${statusBadge(r.status)}">${esc(d)}</span>`
                }, {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: (_, __, r) =>
                        `<a class="btn btn-sm btn-app-soft" title="ดูรายละเอียด" aria-label="ดูรายละเอียด" href="${esc(r.show_url)}"><i class="bx bx-show"></i></a>${r.order_url?` <a class="btn btn-sm btn-app-soft" title="ดูใบสั่งขาย" aria-label="ดูใบสั่งขาย" href="${esc(r.order_url)}"><i class="bx bx-cart"></i></a>`:''}${r.quotation_url?` <a class="btn btn-sm btn-app-soft" title="ดูใบเสนอราคา" aria-label="ดูใบเสนอราคา" href="${esc(r.quotation_url)}"><i class="bx bx-file"></i></a>`:''}${r.pdf_url?` <a class="btn btn-sm btn-app-soft" title="พิมพ์ PDF" aria-label="พิมพ์ PDF" href="${esc(r.pdf_url)}"><i class="bx bx-printer"></i></a>`:''}`
                }]
            });
            $('#rfq-filter').on('click', () => t.ajax.reload());
            $('#rfq-party').select2({
                ajax: {
                    url: '{{ route('pos.sales-rfqs.party-options') }}',
                    dataType: 'json',
                    delay: 250,
                    data: p => ({
                        q: p.term,
                        page: p.page || 1
                    }),
                    processResults: d => d
                }
            });
        });
    </script>
@endpush
