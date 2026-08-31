@extends('Finance::layout')

@section('title', $ledgerType === 'AR' ? 'Aging ลูกหนี้ | Finance' : 'Aging เจ้าหนี้ | Finance')

@section('content')
    @php($isAr = $ledgerType === 'AR')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">FINANCE / {{ $ledgerType }} / AGING</p>
                <h1 class="h3 mb-2">{{ $isAr ? 'Aging ลูกหนี้' : 'Aging เจ้าหนี้' }}</h1>
                <p class="text-secondary mb-0">สรุปยอดคงค้างตามอายุหนี้ ณ วันที่เลือก</p>
            </div>
            <a class="btn btn-outline-dark" href="{{ $openItemsUrl }}"><i class="bx bx-list-ul me-1" aria-hidden="true"></i>ดูรายการคงค้าง</a>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-4 col-xl-3">
                        <label class="form-label" for="aging-as-of">ณ วันที่</label>
                        <input class="form-control" id="aging-as-of" type="date" value="{{ today()->toDateString() }}">
                    </div>
                    <div class="col-12 col-md-8 col-xl-5">
                        <label class="form-label" for="aging-party">{{ $isAr ? 'ลูกค้า' : 'Supplier' }}</label>
                        <select class="form-select" id="aging-party" data-url="{{ $partyOptionsUrl }}"><option value="">ทั้งหมด</option></select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="aging-table" data-url="{{ $dataUrl }}">
                        <thead><tr><th>{{ $isAr ? 'ลูกค้า' : 'Supplier' }}</th><th class="text-end">ยังไม่ครบกำหนด</th><th class="text-end">1–30 วัน</th><th class="text-end">31–60 วัน</th><th class="text-end">61–90 วัน</th><th class="text-end">มากกว่า 90 วัน</th><th class="text-end">รวม</th><th>จัดการ</th></tr></thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#aging-table');
            var $asOf = $('#aging-as-of');
            var $party = $('#aging-party');
            var text = $.fn.dataTable.render.text();
            var amount = $.fn.dataTable.render.number(',', '.', 2);
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: { url: $table.data('url'), data: function (data) { data.as_of = $asOf.val(); data.party_id = $party.val(); } },
                order: [[6, 'desc']],
                buttons: [window.erpExcelButton($table)],
                columns: [
                    { data: 'party_label', name: 'parties.code', render: text.display },
                    { data: 'current_amount', name: 'current_amount', className: 'text-end', render: amount },
                    { data: 'days_1_30', name: 'days_1_30', className: 'text-end', render: amount },
                    { data: 'days_31_60', name: 'days_31_60', className: 'text-end', render: amount },
                    { data: 'days_61_90', name: 'days_61_90', className: 'text-end', render: amount },
                    { data: 'days_over_90', name: 'days_over_90', className: 'text-end', render: amount },
                    { data: 'total_amount', name: 'total_amount', className: 'text-end', render: amount },
                    { data: 'details_url', name: 'details_url', orderable: false, searchable: false, className: 'text-center', render: function (url, type) { return type === 'display' ? '<a class="btn btn-sm btn-app-soft" href="' + text.display(url) + '" title="ดูรายการคงค้างของคู่ค้า" aria-label="ดูรายการคงค้างของคู่ค้า"><i class="bx bx-show" aria-hidden="true"></i></a>' : ''; } }
                ]
            }));

            $party.select2({ width: '100%', placeholder: 'ค้นหารหัสคู่ค้า', allowClear: true, ajax: { url: $party.data('url'), dataType: 'json', delay: 250, data: function (params) { return { q: params.term || '', page: params.page || 1, as_of: $asOf.val() }; }, processResults: function (data) { return data; }, cache: true } });
            $asOf.add($party).on('change', function () { table.ajax.reload(); });
        });
    </script>
@endpush
