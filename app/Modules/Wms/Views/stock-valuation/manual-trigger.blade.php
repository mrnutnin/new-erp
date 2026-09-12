@extends('Wms::layout')
@section('title', 'สั่งคำนวณต้นทุน | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / COST RECOVERY</p><h1 class="h3 mb-2">สั่งคำนวณต้นทุน</h1><p class="text-secondary mb-0">สั่งจากเอกสารเฉพาะรายการ หรือคำนวณใหม่ตามวันที่ สาขา คลัง และสินค้า</p></div>
        <a class="btn btn-outline-secondary" href="{{ route('wms.stock-valuation.revaluation.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับ Revaluation Queue</a>
    </div>
    @if(session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger border-0"><strong>ยังส่งงานไม่ได้</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="alert alert-warning border-0 small"><i class="bx bx-info-circle me-1" aria-hidden="true"></i>หน้านี้สร้าง Trigger และส่งงานเข้าคิวเท่านั้น ไม่คำนวณ Graph, ไม่แก้ Stock และไม่ลง Journal ภายใน HTTP request</div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-body"><label class="form-label" for="manual-trigger-mode">วิธีสั่งคำนวณ</label><select class="form-select" id="manual-trigger-mode"><option value="DOCUMENT">จากเอกสารต้นทาง</option><option value="SCOPE">ตามวันที่ / สาขา / คลัง / สินค้า</option></select></div></div>

    <form method="post" action="{{ route('wms.stock-valuation.manual-trigger.dispatch') }}" id="manual-trigger-form" onsubmit="return confirm('ยืนยันส่งเอกสารเข้าคิวคำนวณต้นทุนหรือไม่?')">
        @csrf
        <input type="hidden" name="trigger_mode" value="DOCUMENT">
        <div class="card border-0 shadow-sm mb-4"><div class="card-body"><div class="row g-3 align-items-end">
            <div class="col-lg-4"><label class="form-label" for="manual-document-type">ประเภทเอกสาร</label><select class="form-select" id="manual-document-type" name="document_type" required>@foreach($documentTypes as $code => $label)<option value="{{ $code }}" @selected(old('document_type') === $code)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-lg-5"><label class="form-label" for="manual-document">เอกสารต้นทาง</label><select class="form-select" id="manual-document" name="document_id" required><option value="">ค้นหาเลขที่เอกสาร</option></select></div>
            <div class="col-lg-3"><button class="btn btn-dark w-100" id="manual-preview" type="button"><i class="bx bx-search-alt me-1" aria-hidden="true"></i>ตรวจสอบก่อนส่งงาน</button></div>
        </div></div></div>

        <div id="manual-result" hidden>
            <div class="row g-3 mb-4">
                <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">เอกสาร</div><div class="fw-semibold" id="manual-reference">-</div></div></div></div>
                <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">Root Lines</div><div class="h4 mb-0" id="manual-lines">-</div></div></div></div>
                <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">Root Allocations</div><div class="h4 mb-0" id="manual-allocations">-</div></div></div></div>
                <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">Partitions / Jobs</div><div class="h4 mb-0" id="manual-partitions">-</div></div></div></div>
            </div>
            <div id="manual-readiness" class="alert border-0"></div>
            <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5 mb-3">แผนแบ่งงาน</h2><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Partition</th><th>Warehouse</th><th>Item</th><th>UOM</th><th>Method</th><th>วันที่เริ่มคำนวณ</th><th>Roots</th><th>สถานะ</th></tr></thead><tbody id="manual-partition-rows"></tbody></table></div></div></div>
            <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5 mb-3">ต้นทุน Root Allocation</h2><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Allocation</th><th>ต้นทุนปัจจุบัน</th><th>ต้นทุนที่ใช้ Trigger</th></tr></thead><tbody id="manual-cost-rows"></tbody></table></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><label class="form-label" for="manual-reason">เหตุผลที่สั่งด้วยตนเอง <span class="text-danger">*</span></label><textarea class="form-control mb-3" id="manual-reason" name="reason" rows="3" minlength="10" maxlength="1000" required>{{ old('reason') }}</textarea><button class="btn btn-dark" id="manual-submit" type="submit" disabled><i class="bx bx-play-circle me-1" aria-hidden="true"></i>ส่งเข้าคิวคำนวณต้นทุน</button></div></div>
        </div>
    </form>

    <form method="post" action="{{ route('wms.stock-valuation.manual-trigger.dispatch') }}" id="scope-trigger-form" hidden onsubmit="return confirm('ยืนยันส่งขอบเขตนี้เข้าคิวคำนวณต้นทุนหรือไม่?')">
        @csrf
        <input type="hidden" name="trigger_mode" value="SCOPE">
        <div class="card border-0 shadow-sm mb-4"><div class="card-body">
            <div class="row g-3">
                <div class="col-lg-3"><label class="form-label" for="scope-start-date">คำนวณใหม่ตั้งแต่วันที่</label><input class="form-control" id="scope-start-date" name="start_date" type="date" max="{{ now()->toDateString() }}" value="{{ old('start_date', now()->startOfMonth()->toDateString()) }}" required></div>
                <div class="col-lg-3"><label class="form-label" for="scope-branch">สาขา</label><select class="form-select" id="scope-branch" name="branch_id" required>@foreach($scopeBranches as $branch)<option value="{{ $branch['id'] }}" @selected((int) old('branch_id', $selectedBranchId) === $branch['id'])>{{ $branch['code'] }} · {{ $branch['name'] }}</option>@endforeach</select></div>
                <div class="col-lg-3"><label class="form-label" for="scope-warehouse-mode">ขอบเขตคลัง</label><select class="form-select" id="scope-warehouse-mode" name="warehouse_mode"><option value="ALL">ทุกคลังในสาขา</option><option value="SELECTED">เลือกหลายคลัง</option></select></div>
                <div class="col-lg-3" id="scope-warehouses-wrap" hidden><label class="form-label" for="scope-warehouses">คลังสินค้า</label><select class="form-select" id="scope-warehouses" name="warehouse_ids[]" multiple></select></div>
                <div class="col-lg-3"><label class="form-label" for="scope-item-mode">ขอบเขตสินค้า</label><select class="form-select" id="scope-item-mode" name="item_mode"><option value="ALL">ทุกสินค้า</option><option value="SELECTED">เลือกหลายสินค้า</option></select></div>
                <div class="col-lg-6" id="scope-items-wrap" hidden><label class="form-label" for="scope-items">สินค้า</label><select class="form-select" id="scope-items" name="item_ids[]" multiple></select></div>
                <div class="col-lg-3 d-flex align-items-end"><button class="btn btn-dark w-100" id="scope-preview" type="button"><i class="bx bx-search-alt me-1" aria-hidden="true"></i>ตรวจสอบขอบเขต</button></div>
            </div>
        </div></div>
        <div id="scope-result" hidden>
            <div class="row g-3 mb-4">
                <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">คลัง</div><div class="h4 mb-0" id="scope-warehouse-count">-</div></div></div></div>
                <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">สินค้า</div><div class="h4 mb-0" id="scope-item-count">-</div></div></div></div>
                <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">Partitions</div><div class="h4 mb-0" id="scope-partition-count">-</div></div></div></div>
                <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">Planning / Calculation Jobs</div><div class="h4 mb-0" id="scope-job-count">-</div></div></div></div>
            </div>
            <div id="scope-readiness" class="alert border-0"></div>
            <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5 mb-3">ตัวอย่าง Partition (สูงสุด 20)</h2><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Partition</th><th>Warehouse</th><th>Item</th><th>UOM</th><th>Method</th></tr></thead><tbody id="scope-partition-rows"></tbody></table></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><label class="form-label" for="scope-reason">เหตุผลที่สั่งด้วยตนเอง <span class="text-danger">*</span></label><textarea class="form-control mb-3" id="scope-reason" name="scope_reason" rows="3" minlength="10" maxlength="1000" required>{{ old('scope_reason') }}</textarea><button class="btn btn-dark" id="scope-submit" type="submit" disabled><i class="bx bx-play-circle me-1" aria-hidden="true"></i>สร้าง Batch และส่งเข้าคิว</button></div></div>
        </div>
    </form>
    <div id="manual-error" class="alert alert-danger border-0 mt-4" hidden></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    var esc = $.fn.dataTable.render.text().display;
    var scopeBranches = @json($scopeBranches);
    $('#manual-trigger-mode').on('change', function () { var scope = this.value === 'SCOPE'; $('#manual-trigger-form').prop('hidden', scope); $('#scope-trigger-form').prop('hidden', !scope); $('#manual-error').prop('hidden', true); });
    var documentSelect = window.erpInitSelect2('#manual-document', { ajax: { url: '{{ route('wms.stock-valuation.manual-trigger.options') }}', delay: 250, data: function (p) { return { document_type: $('#manual-document-type').val(), q: p.term || '' }; }, processResults: function (d) { return d; } } });
    $('#manual-document-type').on('change', function () { documentSelect.val(null).trigger('change'); $('#manual-result').prop('hidden', true); $('#manual-error').prop('hidden', true); });
    $('#manual-preview').on('click', function () {
        var button = $(this), documentId = documentSelect.val();
        if (!documentId) { $('#manual-error').text('กรุณาเลือกเอกสารต้นทาง').prop('hidden', false); return; }
        button.prop('disabled', true).text('กำลังตรวจสอบ...'); $('#manual-error').prop('hidden', true); $('#manual-result').prop('hidden', true); $('#manual-submit').prop('disabled', true);
        $.getJSON('{{ route('wms.stock-valuation.manual-trigger.preview') }}', { document_type: $('#manual-document-type').val(), document_id: documentId }).done(function (r) {
            var summary = r.summary || {}, source = r.source || {}, blockers = r.blockers || [];
            $('#manual-reference').text((source.document_reference || '#'+source.document_id) + ' · ' + (source.document_date || '-') + ' · revision ' + (source.revision || 0));
            $('#manual-lines').text((summary.resolved_root_lines || 0) + ' / ' + (summary.expected_root_lines || 0));
            $('#manual-allocations').text(summary.root_allocations || 0); $('#manual-partitions').text(summary.expected_partitions || 0);
            $('#manual-readiness').toggleClass('alert-success', !!summary.ready).toggleClass('alert-danger', !summary.ready).text(summary.ready ? 'พร้อมส่งงานเข้าคิว' : 'ยังไม่พร้อม: ' + (blockers.join(', ') || 'ไม่พบสาเหตุ'));
            $('#manual-partition-rows').html((r.partitions || []).map(function (p) { return '<tr><td>'+esc(p.partition_key)+'</td><td>'+esc(p.warehouse_id)+'</td><td>'+esc(p.item_id)+'</td><td>'+esc(p.uom_id)+'</td><td>'+esc(p.method)+'</td><td>'+esc(p.effective_start_date || '-')+'</td><td>'+esc((p.root_allocation_ids || []).length)+'</td><td><span class="badge '+(p.ready?'app-status-success':'app-status-danger')+'">'+(p.ready?'พร้อม':'ตรวจสอบ')+'</span></td></tr>'; }).join('') || '<tr><td colspan="8" class="text-center text-secondary">ไม่พบ Partition</td></tr>');
            $('#manual-cost-rows').html((r.root_costs || []).map(function (c) { return '<tr><td>#'+esc(c.allocation_id)+'</td><td>'+esc(c.current_unit_cost)+'</td><td>'+esc(c.proposed_unit_cost)+'</td></tr>'; }).join('') || '<tr><td colspan="3" class="text-center text-secondary">ไม่พบ Root Allocation</td></tr>');
            $('#manual-submit').prop('disabled', !summary.ready); $('#manual-result').prop('hidden', false);
        }).fail(function (xhr) { $('#manual-error').text(xhr.responseJSON?.message || 'ตรวจสอบเอกสารไม่สำเร็จ').prop('hidden', false); }).always(function () { button.prop('disabled', false).html('<i class="bx bx-search-alt me-1" aria-hidden="true"></i>ตรวจสอบก่อนส่งงาน'); });
    });
    function branchWarehouses() { var branchId = Number($('#scope-branch').val()), branch = scopeBranches.find(function (b) { return Number(b.id) === branchId; }); return branch ? branch.warehouses : []; }
    function selectedWarehouseIds() { return $('#scope-warehouse-mode').val() === 'ALL' ? branchWarehouses().map(function (w) { return w.id; }) : ($('#scope-warehouses').val() || []); }
    function resetScope() { $('#scope-result').prop('hidden', true); $('#scope-submit').prop('disabled', true); $('#manual-error').prop('hidden', true); }
    function loadWarehouses() { var options = branchWarehouses().map(function (w) { return new Option(w.code + ' · ' + w.name, w.id, false, false); }); $('#scope-warehouses').empty().append(options).trigger('change'); $('#scope-items').val(null).trigger('change'); resetScope(); }
    var scopeItems = window.erpInitSelect2('#scope-items', { ajax: { url: '{{ route('wms.stock-valuation.manual-trigger.scope-items') }}', delay: 250, data: function (p) { return { branch_id: $('#scope-branch').val(), warehouse_ids: selectedWarehouseIds(), q: p.term || '' }; }, processResults: function (d) { return d; } } });
    $('#scope-branch').on('change', loadWarehouses);
    $('#scope-warehouse-mode').on('change', function () { $('#scope-warehouses-wrap').prop('hidden', this.value !== 'SELECTED'); loadWarehouses(); });
    $('#scope-item-mode').on('change', function () { $('#scope-items-wrap').prop('hidden', this.value !== 'SELECTED'); scopeItems.val(null).trigger('change'); resetScope(); });
    $('#scope-start-date,#scope-warehouses,#scope-items').on('change', resetScope);
    loadWarehouses();
    $('#scope-preview').on('click', function () {
        var button = $(this), payload = { branch_id: $('#scope-branch').val(), warehouse_mode: $('#scope-warehouse-mode').val(), warehouse_ids: selectedWarehouseIds(), item_mode: $('#scope-item-mode').val(), item_ids: $('#scope-items').val() || [], start_date: $('#scope-start-date').val() };
        if (payload.warehouse_mode === 'SELECTED' && !payload.warehouse_ids.length) { $('#manual-error').text('กรุณาเลือกคลังสินค้าอย่างน้อยหนึ่งคลัง').prop('hidden', false); return; }
        if (payload.item_mode === 'SELECTED' && !payload.item_ids.length) { $('#manual-error').text('กรุณาเลือกสินค้าอย่างน้อยหนึ่งรายการ').prop('hidden', false); return; }
        button.prop('disabled', true).text('กำลังตรวจสอบ...'); resetScope();
        $.getJSON('{{ route('wms.stock-valuation.manual-trigger.scope-preview') }}', payload).done(function (r) {
            var s = r.summary || {}, blockers = r.blockers || [], messages = r.messages || [];
            $('#scope-warehouse-count').text(s.warehouses || 0); $('#scope-item-count').text(s.items === 'ALL' ? 'ทุกสินค้า' : (s.items || 0)); $('#scope-partition-count').text(s.partitions || 0); $('#scope-job-count').text((s.planning_jobs || 0) + ' / ' + (s.calculation_jobs || 0));
            $('#scope-readiness').toggleClass('alert-success', !!s.ready).toggleClass('alert-danger', !s.ready).text(s.ready ? 'พร้อมสร้าง Batch แบบ bounded' : (messages.join(' ') || 'ยังไม่พบข้อมูลในขอบเขตที่เลือก'));
            $('#scope-partition-rows').html((r.sample_partitions || []).map(function (p) { return '<tr><td>'+esc(p.partition_key)+'</td><td>'+esc(p.warehouse_id)+'</td><td>'+esc(p.item_id)+'</td><td>'+esc(p.uom_id)+'</td><td>'+esc(p.method)+'</td></tr>'; }).join('') || '<tr><td colspan="5" class="text-center text-secondary">ไม่พบ Partition</td></tr>');
            $('#scope-submit').prop('disabled', !s.ready); $('#scope-result').prop('hidden', false);
        }).fail(function (xhr) { $('#manual-error').text(xhr.responseJSON?.message || 'ตรวจสอบขอบเขตไม่สำเร็จ').prop('hidden', false); }).always(function () { button.prop('disabled', false).html('<i class="bx bx-search-alt me-1" aria-hidden="true"></i>ตรวจสอบขอบเขต'); });
    });
});
</script>
@endpush
