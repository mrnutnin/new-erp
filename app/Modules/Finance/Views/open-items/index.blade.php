@extends('Finance::layout')

@section('title', $ledgerType === 'AR' ? 'ลูกหนี้คงค้าง | Finance' : 'เจ้าหนี้คงค้าง | Finance')

@section('content')
    @php($isAr = $ledgerType === 'AR')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">FINANCE / {{ $ledgerType }} / OPEN ITEMS</p>
                <h1 class="h3 mb-2">{{ $isAr ? 'ลูกหนี้คงค้าง' : 'เจ้าหนี้คงค้าง' }}</h1>
                <p class="text-secondary mb-0">ยอดคงเหลือตามการจัดสรร ณ วันที่เลือกของคลังปัจจุบัน</p>
            </div>
            <a class="btn btn-app-soft" href="{{ $agingUrl }}"><i class="bx bx-time-five me-1" aria-hidden="true"></i>ดู Aging</a>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-4 col-xl-3">
                        <label class="form-label" for="open-item-as-of">ณ วันที่</label>
                        <input class="form-control" id="open-item-as-of" type="date" value="{{ today()->toDateString() }}">
                    </div>
                    <div class="col-12 col-md-8 col-xl-5">
                        <label class="form-label" for="open-item-party">{{ $isAr ? 'ลูกค้า' : 'Supplier' }}</label>
                        <select class="form-select" id="open-item-party" data-url="{{ $partyOptionsUrl }}">
                            <option value="">ทั้งหมด</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="open-items-table" data-url="{{ $dataUrl }}">
                        <thead><tr><th>เลขที่อ้างอิง</th><th>วันที่เอกสาร</th><th>ครบกำหนด</th><th>{{ $isAr ? 'ลูกค้า' : 'Supplier' }}</th><th class="text-end">ยอดตั้งต้น</th><th class="text-end">จัดสรรแล้ว</th><th class="text-end">คงเหลือ</th><th class="text-end">เกินกำหนด</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#open-items-table');
            var $asOf = $('#open-item-as-of');
            var $party = $('#open-item-party');
            var text = $.fn.dataTable.render.text();
            var amount = $.fn.dataTable.render.number(',', '.', 2);
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: { url: $table.data('url'), data: function (data) { data.as_of = $asOf.val(); data.party_id = $party.val(); } },
                order: [[2, 'asc']],
                buttons: [window.erpExcelButton($table)],
                columns: [
                    { data: 'document_number', name: 'finance_open_items.document_number', render: text.display },
                    { data: 'document_date_label', name: 'finance_open_items.document_date', render: text.display },
                    { data: 'due_date_label', name: 'finance_open_items.due_date', render: text.display },
                    { data: 'party_label', name: 'parties.code', render: text.display },
                    { data: 'signed_original_amount', name: 'signed_original_amount', className: 'text-end', render: amount },
                    { data: 'signed_allocated_amount', name: 'signed_allocated_amount', className: 'text-end', render: amount },
                    { data: 'signed_outstanding_amount', name: 'signed_outstanding_amount', className: 'text-end', render: amount },
                    { data: 'days_overdue', name: 'days_overdue', className: 'text-end', render: text.display },
                    { data: 'status_label', name: 'status_label', orderable: false, render: function (value, type, row) { return type === 'display' ? '<span class="badge ' + (row.status_class || 'text-bg-secondary') + '">' + text.display(value) + '</span>' : value; } },
                    { data: 'show_url', name: 'show_url', orderable: false, searchable: false, className: 'text-center', render: function (url, type) { return type === 'display' ? '<a class="btn btn-sm btn-app-soft" href="' + text.display(url) + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>' : ''; } }
                ]
            }));

            var params = new URLSearchParams(window.location.search);
            if (params.get('as_of')) $asOf.val(params.get('as_of'));
            if (params.get('party_id')) {
                var initialParty = params.get('party_id');
                $party.append(new Option(initialParty, initialParty, true, true));
                $.getJSON($party.data('url'), { q: initialParty, page: 1, as_of: $asOf.val() }).done(function (data) {
                    if (data.results && data.results.length) {
                        $party.empty().append(new Option(data.results[0].text, data.results[0].id, true, true)).trigger('change.select2');
                    }
                });
            }

            $party.select2({ width: '100%', placeholder: 'ค้นหารหัสคู่ค้า', allowClear: true, ajax: { url: $party.data('url'), dataType: 'json', delay: 250, data: function (params) { return { q: params.term || '', page: params.page || 1, as_of: $asOf.val() }; }, processResults: function (data) { return data; }, cache: true } });
            $asOf.add($party).on('change', function () { table.ajax.reload(); });
        });
    </script>
@endpush
