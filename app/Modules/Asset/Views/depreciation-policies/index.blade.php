@extends('Asset::layout')

@section('title', 'คำขอเปลี่ยนนโยบายค่าเสื่อม | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ASSET / DEPRECIATION POLICY</p>
                <h1 class="h3 mb-2">คำขอเปลี่ยนนโยบายค่าเสื่อม</h1>
                <p class="text-secondary mb-0">ตรวจสอบ baseline และผลที่ขอ ก่อนอนุมัติให้มีผลในอนาคต</p>
            </div>
            @if (auth()->user()->hasPermission('asset.depreciation.calculate'))
                <a class="btn btn-dark" href="{{ route('asset.depreciation-policies.create') }}"><i class="bx bx-plus me-1"
                        aria-hidden="true"></i>สร้างคำขอ</a>
            @endif
        </div>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h6 mb-3">ตัวกรอง</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-2"><label class="form-label" for="policy-filter-date-from">วันมีผล
                            ตั้งแต่</label><input class="form-control" id="policy-filter-date-from" type="date"></div>
                    <div class="col-12 col-md-2"><label class="form-label" for="policy-filter-date-to">วันมีผล
                            ถึง</label><input class="form-control" id="policy-filter-date-to" type="date"></div>
                    <div class="col-12 col-md-2"><label class="form-label" for="policy-filter-book">สมุด</label><select
                            class="form-select" id="policy-filter-book">
                            <option value="">ทุกสมุด</option>
                            <option value="BOOK">บัญชี (Book)</option>
                            <option value="TAX">ภาษี (Tax)</option>
                        </select></div>
                    <div class="col-12 col-md-3"><label class="form-label"
                            for="policy-filter-requester">ผู้ขอ</label><select class="form-select"
                            id="policy-filter-requester" data-url="{{ route('asset.depreciation-policies.requesters') }}">
                            <option value="">ทุกคน</option>
                        </select></div>
                    <div class="col-12 col-md-2"><label class="form-label" for="policy-filter-status">สถานะ</label><select
                            class="form-select" id="policy-filter-status">
                            <option value="">ทุกสถานะ</option>
                            <option value="DRAFT">รออนุมัติ</option>
                            <option value="APPROVED">อนุมัติแล้ว</option>
                            <option value="VOID">ยกเลิก</option>
                        </select></div>
                    <div class="col-12 col-md-1 d-flex align-items-end"><button class="btn btn-outline-secondary w-100"
                            id="policy-filter-reset" type="button">ล้างตัวกรอง</button></div>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="policy-changes-table"
                        data-url="{{ route('asset.depreciation-policies.data') }}">
                        <thead>
                            <tr>
                                <th>สินทรัพย์</th>
                                <th>สมุด</th>
                                <th>วันมีผล</th>
                                <th>ผู้ขอ</th>
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
            var $table = $('#policy-changes-table'),
                text = $.fn.dataTable.render.text(),
                labels = {
                    DRAFT: 'รออนุมัติ',
                    APPROVED: 'อนุมัติแล้ว',
                    VOID: 'ยกเลิก'
                },
                classes = {
                    DRAFT: 'app-badge-info',
                    APPROVED: 'app-badge-success',
                    VOID: 'app-status-danger'
                };
            $('#policy-filter-requester').select2({
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: true,
                placeholder: 'ค้นหาผู้ขอ',
                ajax: {
                    url: $('#policy-filter-requester').data('url'),
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            q: params.term || '',
                            page: params.page || 1
                        };
                    },
                    processResults: function(data) {
                        return data;
                    }
                }
            });
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: {
                    url: $table.data('url'),
                    data: function(data) {
                        data.status = $('#policy-filter-status').val();
                        data.date_from = $('#policy-filter-date-from').val();
                        data.date_to = $('#policy-filter-date-to').val();
                        data.book_type = $('#policy-filter-book').val();
                        data.created_by = $('#policy-filter-requester').val();
                    }
                },
                order: [
                    [2, 'desc']
                ],
                buttons: [window.erpExcelButton($table)],
                columns: [{
                    data: 'asset_number',
                    name: 'depreciationBook.asset.asset_number',
                    render: function(v, t, row) {
                        return t === 'display' ? '<div class="fw-semibold">' + text.display(
                                v || '-') + '</div><div class="small text-secondary">' +
                            text.display(row.asset_name || '-') + '</div>' : v;
                    }
                }, {
                    data: 'book_type',
                    name: 'depreciationBook.book_type',
                    render: function(v, t) {
                        return t === 'display' ? text.display(v === 'BOOK' ?
                            'บัญชี (Book)' : 'ภาษี (Tax)') : v;
                    }
                }, {
                    data: 'effective_date',
                    name: 'effective_date',
                    render: function(v, t) {
                        if (t !== 'display' || !v) return v;
                        var d = String(v).slice(0, 10).split('-');
                        return d.length === 3 ? d[2] + '/' + d[1] + '/' + d[0] : text
                            .display(v);
                    }
                }, {
                    data: 'created_by_name',
                    name: 'created_by',
                    render: text.display
                }, {
                    data: 'status',
                    name: 'status',
                    render: function(v, t) {
                        return t === 'display' ? '<span class="badge ' + (classes[v] ||
                                'app-badge-soft') + '">' + text.display(labels[v] || v) +
                            '</span>' : v;
                    }
                }, {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-end',
                    render: function(v, t, row) {
                        return t === 'display' ?
                            '<a class="btn btn-sm btn-outline-dark" href="' + text.display(
                                row.show_url) +
                            '"><i class="bx bx-show me-1" aria-hidden="true"></i>ตรวจสอบ</a>' :
                            '';
                    }
                }]
            }));
            $('#policy-filter-status,#policy-filter-date-from,#policy-filter-date-to,#policy-filter-book,#policy-filter-requester')
                .on('change', function() {
                    table.ajax.reload();
                });
            $('#policy-filter-reset').on('click', function() {
                $('#policy-filter-status,#policy-filter-date-from,#policy-filter-date-to,#policy-filter-book')
                    .val('');
                $('#policy-filter-requester').val(null).trigger('change.select2');
                table.ajax.reload();
            });
        });
    </script>
@endpush
