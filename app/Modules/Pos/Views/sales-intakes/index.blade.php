@extends('Pos::layout')
@section('content')
    @php($decimals = \App\Modules\Wms\Support\WmsDecimal::places())
    <div class="container-fluid py-4">
        @include('Pos::partials.sales-list-header', [
            'eyebrow' => 'SALES / INTAKE',
            'title' => 'ใบรับข้อมูลเบื้องต้น',
            'description' => 'จุดเริ่มต้นการขาย ตรวจราคามาตรฐานและส่งต่อ RFQ เมื่อจำเป็น',
            'actionUrl' => auth()->user()?->hasPermission('pos.sales-intakes.create')
                ? route('pos.sales-intakes.create')
                : null,
            'actionLabel' => 'สร้างใบรับข้อมูล',
        ])
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ตัวกรองใบรับข้อมูล</h2><p class="text-secondary mb-0 small">กรองก่อนค้นหาจากตาราง</p></div><button id="si-reset" type="button" class="btn btn-sm btn-app-soft"><i class="bx bx-reset me-1"></i>ล้างตัวกรอง</button></div>
                <div class="row g-3 align-items-end">
                    <div class="col-md-3"><label for="si-from" class="form-label">วันที่เริ่ม</label><input id="si-from"
                            type="date" class="form-control"></div>
                    <div class="col-md-3"><label for="si-to" class="form-label">ถึงวันที่</label><input id="si-to"
                            type="date" class="form-control"></div>
                    <div class="col-md-3"><label for="si-party" class="form-label">ลูกค้า</label><select id="si-party"
                            class="form-select"></select></div>
                    <div class="col-md-3"><label for="si-status" class="form-label">สถานะ</label><select id="si-status"
                            class="form-select">
                            <option value="">ทั้งหมด</option>
                            <option value="DRAFT">ร่าง</option>
                            <option value="COMPLETED">เสร็จสิ้น</option>
                            <option value="CANCELLED">ยกเลิก</option>
                        </select></div>
                </div>
                <button id="si-filter" class="btn btn-app-primary mt-3"><i
                        class="bx bx-filter-alt me-1"></i>ใช้ตัวกรอง</button>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3">
                    <h2 class="h5 mb-1">รายการใบรับข้อมูล</h2><p class="text-secondary mb-0 small">เลือกดูรายละเอียดเพื่อตรวจราคา แก้ไขเอกสารร่าง หรือส่งต่อขั้นตอนขาย</p>
                </div>
                <div class="table-responsive">
                    <table id="si-table" class="table table-hover align-middle w-100 mb-0"
                        data-url="{{ route('pos.sales-intakes.data') }}">
                        <thead class="table-light">
                            <tr>
                                <th>เลขที่</th>
                                <th>วันที่</th>
                                <th>ลูกค้า</th>
                                <th class="text-center">รายการ</th>
                                <th>RFQ</th>
                                <th>สถานะ</th>
                                <th>ความคืบหน้า</th>
                                <th class="text-end">VAT</th>
                                <th class="text-end">รวมทั้งสิ้น</th>
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
            const human = d => {
                if (!d) return '-';
                const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/);
                return m ? `${m[3]}/${m[2]}/${m[1]}` : d;
            };
            const esc = d => $('<div>').text(d ?? '').html();
            const money = (d, type) => type === 'display' || type === 'filter' ? Number(d || 0).toLocaleString(
                'en-US', {
                    minimumFractionDigits: {{ $decimals }},
                    maximumFractionDigits: {{ $decimals }}
                }) : d;
            const statusBadge = s => ({
                COMPLETED: 'app-badge-success',
                CANCELLED: 'text-bg-danger'
            } [s] || 'app-badge-soft');
            const progressBadge = s => ({
                warning: 'app-badge-warning',
                info: 'app-badge-info',
                success: 'app-badge-success',
                danger: 'text-bg-danger'
            } [s] || 'app-badge-soft');
            const actions = row => `<a class="btn btn-sm btn-app-soft" title="ดูรายละเอียด" aria-label="ดูรายละเอียด" href="${esc(row.show_url)}"><i class="bx bx-file-find"></i></a>${row.edit_url ? ` <a class="btn btn-sm btn-app-soft" title="แก้ไข" aria-label="แก้ไข" href="${esc(row.edit_url)}"><i class="bx bx-edit"></i></a>` : ''}${row.pdf_url ? ` <a class="btn btn-sm btn-app-soft" title="พิมพ์ PDF" aria-label="พิมพ์ PDF" href="${esc(row.pdf_url)}" target="_blank" rel="noopener"><i class="bx bx-printer"></i></a>` : ''}${row.delete_url ? ` <button class="btn btn-sm btn-app-danger js-delete-intake" title="ลบร่าง" aria-label="ลบร่าง" type="button" data-url="${esc(row.delete_url)}" data-method="DELETE"><i class="bx bx-trash"></i></button>` : ''}`;
            const table = $('#si-table');
            const t = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                order: [
                    [1, 'desc']
                ],
                language: {
                    search: 'ค้นหา:'
                },
                buttons: [window.erpExcelButton(table)],
                ajax: {
                    url: table.data('url'),
                    data: d => {
                        d.date_from = $('#si-from').val();
                        d.date_to = $('#si-to').val();
                        d.party_id = $('#si-party').val();
                        d.status = $('#si-status').val();
                    }
                },
                columns: [{
                    data: 'document_number',
                    render: (d, _, r) => `<a href="${esc(r.show_url)}">${esc(d)}</a>`
                }, {
                    data: 'document_date',
                    render: human
                }, {
                    data: 'party_label',
                    render: esc
                }, {
                    data: 'lines_count',
                    className: 'text-center',
                    render: d => `${esc(d || 0)} รายการ`
                }, {
                    data: 'requires_rfq',
                    render: d => d ?
                        '<span class="badge app-badge-warning">ต้องผ่าน RFQ</span>' :
                        '<span class="badge app-badge-success">ราคาปกติ</span>'
                }, {
                    data: 'status_label',
                    render: (d, _, r) =>
                        `<span class="badge ${statusBadge(r.status)}">${esc(d)}</span>`
                }, {
                    data: 'progress',
                    orderable: false,
                    searchable: false,
                    render: d =>
                        `<span class="badge ${progressBadge(d?.badge)}">${esc(d?.label || '-')}</span>`
                }, {
                    data: 'tax_amount',
                    className: 'text-end',
                    render: money
                }, {
                    data: 'grand_total',
                    className: 'text-end fw-semibold',
                    render: money
                }, {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-end',
                    render: (_, __, r) => actions(r)
                }]
            }));
            $('#si-filter').on('click', () => t.ajax.reload());
            $('#si-reset').on('click', () => { $('#si-from,#si-to').val(''); $('#si-status').val(''); $('#si-party').val(null).trigger('change'); t.ajax.reload(); });
            $('#si-party').select2({
                ajax: {
                    url: '{{ route('pos.sales-intakes.party-options') }}',
                    dataType: 'json',
                    delay: 250,
                    data: p => ({
                        q: p.term,
                        page: p.page || 1
                    }),
                    processResults: d => d
                }
            });
            window.erpAjaxDelete({
                button: '.js-delete-intake',
                reload: '#si-table',
                confirm: 'ยืนยันการลบร่างใบรับข้อมูลนี้หรือไม่?'
            });
        });
    </script>
@endpush
