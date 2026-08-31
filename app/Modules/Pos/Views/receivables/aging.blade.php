@extends('Pos::layout')

@section('title', 'Aging ลูกหนี้ | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    @include('Pos::partials.sales-list-header', ['eyebrow' => 'SALES / RECEIVABLES', 'title' => 'Aging ลูกหนี้', 'description' => 'สรุปยอดคงค้างของใบขายเชื่อ (IV) ตามอายุหนี้ ณ วันที่เลือก'])
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-3 p-lg-4"><div class="row g-3 align-items-end">
        <div class="col-md-3"><label class="form-label" for="aging-as-of">ณ วันที่</label><input class="form-control" id="aging-as-of" type="date" value="{{ today()->toDateString() }}"></div>
        <div class="col-md-4"><label class="form-label" for="aging-party">ลูกค้า</label><select class="form-select" id="aging-party"><option value="">ทั้งหมด</option></select></div>
        <div class="col-md-2"><button class="btn btn-outline-secondary" id="aging-filter" type="button"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>กรอง</button></div>
    </div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><h2 class="h6 mb-3">ยอดคงค้างตามอายุหนี้</h2><div class="table-responsive"><table id="aging-table" class="table table-hover align-middle w-100 mb-0" data-url="{{ route('pos.receivables.aging.data') }}"><thead class="table-light"><tr><th>ลูกค้า</th><th class="text-end">ยังไม่ครบกำหนด</th><th class="text-end">1–30 วัน</th><th class="text-end">31–60 วัน</th><th class="text-end">61–90 วัน</th><th class="text-end">มากกว่า 90 วัน</th><th class="text-end">รวม</th><th class="text-end">จัดการ</th></tr></thead></table></div></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#aging-table'), esc = $.fn.dataTable.render.text(), money = $.fn.dataTable.render.number(',', '.', 2);
    const dt = table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {ajax: {url: table.data('url'), data: d => { d.as_of = $('#aging-as-of').val(); d.party_id = $('#aging-party').val(); }}, order: [[6, 'desc']], buttons: [window.erpExcelButton(table)], columns: [
        {data: 'party_label', name: 'parties.code', render: esc.display}, {data: 'current_amount', name: 'current_amount', className: 'text-end', render: money}, {data: 'days_1_30', name: 'days_1_30', className: 'text-end', render: money}, {data: 'days_31_60', name: 'days_31_60', className: 'text-end', render: money}, {data: 'days_61_90', name: 'days_61_90', className: 'text-end', render: money}, {data: 'days_over_90', name: 'days_over_90', className: 'text-end', render: money}, {data: 'total_amount', name: 'total_amount', className: 'text-end fw-semibold', render: money},
        {data: 'details_url', orderable: false, searchable: false, className: 'text-end', render: (url, type) => type === 'display' ? '<a class="btn btn-sm btn-app-soft" href="' + esc.display(url) + '"><i class="bx bx-list-ul me-1" aria-hidden="true"></i>ดูรายการ</a>' : ''}
    ]}));
    $('#aging-filter').on('click', () => dt.ajax.reload());
    $('#aging-party').select2({theme: 'bootstrap-5', width: '100%', allowClear: true, ajax: {url: '{{ route('pos.receivables.party-options') }}', dataType: 'json', delay: 250, data: p => ({q: p.term || '', page: p.page || 1, as_of: $('#aging-as-of').val()}), processResults: d => d}});
});
</script>
@endpush
