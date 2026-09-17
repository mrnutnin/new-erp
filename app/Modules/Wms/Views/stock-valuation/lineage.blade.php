@extends('Wms::layout')
@section('title', 'Cost Lineage Explorer | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / COST LINEAGE</p><h1 class="h3 mb-2">Cost Lineage Explorer</h1><p class="text-secondary mb-0">Read-only ตรวจสอบเส้นทางต้นทุนจาก Cost Allocation จริง ยังไม่สร้าง Delta หรือ Journal</p></div>
        <div class="d-flex gap-2"><a class="btn btn-outline-primary" href="{{ route('wms.stock-valuation.shadow-calculation') }}"><i class="bx bx-calculator me-1" aria-hidden="true"></i>Shadow Calculation</a><a class="btn btn-outline-secondary" href="{{ route('wms.stock-valuation.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับมูลค่าสินค้า</a></div>
    </div>
    <div class="alert alert-info border-0 small"><i class="bx bx-info-circle me-1" aria-hidden="true"></i>แสดงเฉพาะข้อมูลในคลังที่เลือก และดึง parent allocation ข้ามคลังเพื่อให้เห็น Transfer lineage ได้ครบที่สุดใน Phase 1</div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body row g-3 align-items-end"><div class="col-md-8"><label class="form-label">สินค้า (ไม่เลือก = ทั้งหมด)</label><select id="lineage-item" class="form-select"><option value="">สินค้าทั้งหมด</option></select></div><div class="col-md-4"><button id="lineage-load" class="btn btn-dark w-100" type="button">ตรวจสอบ Lineage</button></div></div></div>
    <div class="row g-3 mb-4"><div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Nodes</div><div class="h4 mb-0" id="lineage-nodes">-</div></div></div></div><div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Edges</div><div class="h4 mb-0" id="lineage-edges">-</div></div></div></div><div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Roots</div><div class="h4 mb-0" id="lineage-roots">-</div></div></div></div><div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Missing Parent</div><div class="h4 mb-0 text-danger" id="lineage-missing-parents">-</div></div></div></div><div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Missing Movement</div><div class="h4 mb-0 text-warning" id="lineage-missing-movements">-</div></div></div></div><div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Cycles</div><div class="h4 mb-0 text-danger" id="lineage-cycles">-</div></div></div></div></div>
    <div id="lineage-limit" class="alert alert-warning border-0 small" hidden>ผลลัพธ์ถูกจำกัดจำนวนรายการเพื่อความปลอดภัยของหน้าอ่านข้อมูล</div>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="table-responsive"><table id="lineage-table" class="table table-hover align-middle w-100" data-url="{{ route('wms.stock-valuation.lineage.data') }}"><thead><tr><th>ลำดับ</th><th>Allocation</th><th>Parent</th><th>Movement</th><th>Source</th><th>Type</th><th>Direction</th><th>Quantity</th><th>Value</th><th>Status</th><th>Issue</th></tr></thead></table></div></div></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    var tableElement = $('#lineage-table'), text = $.fn.dataTable.render.text();
    var table = tableElement.DataTable($.extend(true, {}, window.erpDataTableDefaults, { processing: true, ajax: { url: tableElement.data('url'), data: function (d) { d.item_id = $('#lineage-item').val(); }, dataSrc: function (r) { var s = r.summary || {}; ['nodes','edges','roots','missing_parents','missing_movements','cycles'].forEach(function (key) { $('#lineage-' + key.replace('_', '-')).text(s[key] || 0); }); $('#lineage-limit').prop('hidden', !r.limited); return r.rows || []; } }, buttons: [window.erpExcelButton(tableElement)], pageLength: 25, order: [[1, 'asc']], columns: [
        window.erpRowNumberColumn(),
        { data: 'allocation_id', render: function (value, type) { return type === 'display' ? '#' + text.display(value) : value; } },
        { data: 'parent_allocation_id', render: function (value, type) { return type === 'display' ? (value ? '#' + text.display(value) : '-') : value; } },
        { data: 'movement_id', render: function (value, type) { return type === 'display' ? (value ? '#' + text.display(value) : '-') : value; } },
        { data: null, orderable: false, render: function (value, type, row) { var source = (row.source_type || '-') + ' / ' + (row.source_reference || row.source_id || '-'); return type === 'display' ? text.display(source) : source; } },
        { data: 'allocation_type', render: text.display }, { data: 'direction', render: text.display }, { data: 'quantity', className: 'text-end', render: text.display }, { data: 'value', className: 'text-end', render: text.display },
        { data: null, render: function (value, type, row) { var status = (row.status || '-') + ' / ' + (row.cost_status || '-'); return type === 'display' ? text.display(status) : status; } },
        { data: 'issues', orderable: false, render: function (value, type) { var issues = (value || []).join(', ') || '-'; return type === 'display' ? '<span class="text-danger small">' + text.display(issues) + '</span>' : issues; } }
    ] }));
    window.erpInitSelect2('#lineage-item', { ajax: { url: '{{ route('wms.stock.item-options') }}', delay: 250, data: function (p) { return { q: p.term || '', page: p.page || 1 }; }, processResults: function (d) { return d; } } });
    $('#lineage-load').on('click', function () { table.ajax.reload(); });
});
</script>
@endpush
