@extends('Wms::layout')

@section('title', 'Stock Card | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / STOCK</p><h1 class="h3 mb-2">Stock Card</h1><p class="text-secondary mb-0">ตรวจสอบความเคลื่อนไหวและยอดคงเหลือตามคลัง</p></div>
        @include('Wms::partials.warehouse-selector')
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body row g-3 align-items-end">
        <div class="col-md-6"><label class="form-label" for="stock-item">สินค้า (ไม่เลือก = แสดงทั้งหมด)</label><select id="stock-item" class="form-select"><option value="">สินค้าทั้งหมด</option></select></div>
        <div class="col-md-3"><label class="form-label" for="stock-as-of">ณ วันที่</label><input id="stock-as-of" class="form-control" type="date" value="{{ now()->format('Y-m-d') }}"></div>
        <div class="col-md-3"><button id="stock-load" class="btn btn-dark w-100" type="button">แสดงข้อมูล</button></div>
    </div></div>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-secondary small">On-hand</div><div class="h4 mb-0" id="stock-on-hand">-</div></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-secondary small">Reserved</div><div class="h4 mb-0" id="stock-reserved">-</div></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-secondary small">Available</div><div class="h4 mb-0" id="stock-available">-</div></div></div></div>
    </div>
    <table id="stock-table" class="table table-hover w-100" data-url="{{ route('wms.stock.summary') }}" data-movement-url="{{ route('wms.stock.data') }}"><thead><tr><th>สินค้า</th><th>หน่วย</th><th>คงเหลือ ณ วันที่</th><th>ต้นทุนเฉลี่ย</th><th>มูลค่า</th><th>จัดการ</th></tr></thead></table>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var tableElement = $('#stock-table');
    var textRenderer = $.fn.dataTable.render.text();
    var table = tableElement.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
        ajax: {url: tableElement.data('url'), data: function (data) { data.as_of = $('#stock-as-of').val(); }},
        buttons: [window.erpExcelButton(tableElement)],
        columns: [
            {data: 'item_label', render: textRenderer.display},
            {data: 'uom_label', render: textRenderer.display},
            {data: 'on_hand', className: 'text-end', render: textRenderer.display},
            {data: 'average_unit_cost', className: 'text-end', render: textRenderer.display},
            {data: 'inventory_value', className: 'text-end', render: textRenderer.display},
            {data: null, orderable: false, searchable: false, className: 'text-end text-nowrap', render: function (value, type, row) { return type === 'display' ? '<a class="btn btn-sm btn-app-soft" href="' + textRenderer.display(row.detail_url) + '" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>' : ''; }}
        ],
        drawCallback: function (settings) {
            var balance = settings.json && settings.json.balance || {};
            $('#stock-on-hand').text(balance.on_hand || '-');
            $('#stock-reserved').text(balance.reserved || '-');
            $('#stock-available').text(balance.available || '-');
        }
    }));
    window.erpInitSelect2('#stock-item', {ajax: {url: '{{ route('wms.stock.item-options') }}', delay: 250, data: function (params) { return {q: params.term || '', page: params.page || 1}; }, processResults: function (data) { return data; }}});
    $('#stock-load').on('click', function () { table.ajax.reload(); });
    $('#stock-item').on('change', function () { var id = $(this).val(); if (id) window.location.href = '{{ url('/wms/stock') }}/' + id; });
});
</script>
@endpush
