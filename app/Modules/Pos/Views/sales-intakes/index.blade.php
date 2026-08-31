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
                <button id="si-filter" class="btn btn-outline-secondary mt-3"><i
                        class="bx bx-filter-alt me-1"></i>กรอง</button>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3">
                    <h2 class="h6 mb-0">รายการใบรับข้อมูล</h2>
                    {{-- <small class="text-secondary">ค้นหาด้วยเลขที่เอกสารหรือชื่อลูกค้า</small> --}}
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
            const actionItem = (url, label, icon = 'bx-file-plus') => url ?
                `<li><form method="post" action="${esc(url)}" class="js-sales-intake-convert"><input type="hidden" name="_token" value="{{ csrf_token() }}"><button class="dropdown-item" type="submit"><i class="bx ${icon} me-2" aria-hidden="true"></i>${label}</button></form></li>` :
                '';
            const actions = row =>
                `<div class="dropup d-inline-block js-intake-actions" data-menu-id="si-actions-${row.id}"><button class="btn btn-sm btn-app-soft dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bx bx-dots-horizontal-rounded" aria-hidden="true"></i><span class="visually-hidden">จัดการ</span></button><ul id="si-actions-${row.id}" class="dropdown-menu dropdown-menu-end shadow"><li><a class="dropdown-item" href="${esc(row.show_url)}"><i class="bx bx-show me-2" aria-hidden="true"></i>ดูรายละเอียด</a></li>${actionItem(row.to_rfq_url, 'สร้าง RFQ')}${actionItem(row.to_quotation_url, 'สร้างใบเสนอราคา')}${actionItem(row.to_order_url, 'สร้างใบสั่งขาย', 'bx-cart-add')}${row.edit_url ? `<li><a class="dropdown-item" href="${esc(row.edit_url)}"><i class="bx bx-edit me-2" aria-hidden="true"></i>แก้ไข</a></li>` : ''}${row.delete_url ? `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger js-delete-intake" type="button" data-url="${esc(row.delete_url)}" data-method="DELETE"><i class="bx bx-trash me-2" aria-hidden="true"></i>ลบ</button></li>` : ''}</ul></div>`;
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
            $(document).on('shown.bs.dropdown', '.js-intake-actions', function() {
                const dropdown = $(this);
                const menu = $('#' + dropdown.data('menu-id'));
                const button = dropdown.find('[data-bs-toggle="dropdown"]')[0].getBoundingClientRect();
                menu.appendTo(document.body).css({
                    position: 'fixed',
                    top: 'auto',
                    left: Math.max(8, button.right - menu.outerWidth()),
                    bottom: window.innerHeight - button.top + 4,
                    zIndex: 1080
                });
            }).on('hidden.bs.dropdown', '.js-intake-actions', function() {
                const dropdown = $(this);
                $('#' + dropdown.data('menu-id')).removeAttr('style').appendTo(dropdown);
            });
            window.erpAjaxForm({
                form: '.js-sales-intake-convert',
                redirect: true
            });
            window.erpAjaxDelete({
                button: '.js-delete-intake',
                reload: '#si-table',
                confirm: 'ยืนยันการลบใบรับข้อมูลนี้หรือไม่?'
            });
        });
    </script>
@endpush
