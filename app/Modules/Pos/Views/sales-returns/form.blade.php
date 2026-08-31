@extends('Pos::layout')

@section('title', 'สร้างใบรับคืน / ใบลดหนี้ | POS')

@php($quantityStep = 1 / (10 ** $quantityDecimalPlaces))

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><p class="eyebrow mb-2">SALES / RETURN</p><h1 class="h3 mb-1">สร้างใบรับคืน / ใบลดหนี้</h1><p class="text-secondary mb-0">เลือกรายการจาก HS/IV ที่ลงบัญชีแล้วเพื่อรับสินค้าและลดหนี้</p></div><a class="btn btn-app-soft" href="{{ route('pos.sales-returns.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับรายการ</a></div>
    <div class="alert alert-info mb-4">เมื่อ Post ระบบจะตรวจยอดคงเหลือ คืน Stock และกลับ COGS/GL พร้อมกัน</div>
    <form id="sales-return-form" method="post" action="{{ route('pos.sales-returns.store') }}" data-ajax-form>@csrf
        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h6 text-uppercase text-secondary mb-3">ข้อมูลเอกสาร</h2><div class="row g-3"><div class="col-md-6"><label for="sales-return-source" class="form-label">เอกสาร HS/IV ต้นทาง <span class="text-danger">*</span></label><select id="sales-return-source" name="physical_sale_id" class="form-select" data-placeholder="ค้นหา HS/IV ที่ลงบัญชีแล้ว" required></select><div class="form-text">ค้นหาด้วยเลขที่เอกสารหรือลูกค้า</div></div><div class="col-md-3"><label for="sales-return-date" class="form-label">วันที่เอกสาร <span class="text-danger">*</span></label><input id="sales-return-date" type="date" name="document_date" class="form-control" value="{{ today()->format('Y-m-d') }}" required></div><div class="col-12"><label for="sales-return-reason" class="form-label">เหตุผลการคืน <span class="text-danger">*</span></label><textarea id="sales-return-reason" name="reason" class="form-control" rows="2" required placeholder="เช่น สินค้าชำรุด / ส่งผิดรายการ"></textarea></div></div></div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="mb-3"><h2 class="h5 mb-1">รายการสินค้าที่คืน</h2><p class="text-secondary small mb-0" id="source-help">เลือกเอกสาร HS/IV ก่อน ระบบจะแสดงสินค้าจากเอกสารนั้น</p></div><div id="return-lines"><div class="text-secondary text-center py-4">ยังไม่ได้เลือกเอกสารต้นทาง</div></div><div class="d-flex gap-2 mt-4"><a class="btn btn-outline-secondary" href="{{ route('pos.sales-returns.index') }}">ยกเลิก</a><button class="btn btn-dark" type="submit">บันทึกร่าง</button></div></div></div>
    </form>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const sourceUrl = '{{ route('pos.sales-returns.sale-options') }}', lineUrl = '{{ url('/pos/sales-returns/source-lines') }}', quantityStep = {{ $quantityStep }}, $source = $('#sales-return-source'), $lines = $('#return-lines');
    $source.select2({ theme: 'bootstrap-5', width: '100%', placeholder: $source.data('placeholder'), allowClear: true, ajax: { url: sourceUrl, dataType: 'json', delay: 250, data: p => ({ q: p.term || '', page: p.page || 1 }), processResults: d => d, cache: true } });
    const row = (option, index) => `<div class="row g-2 align-items-center border rounded-3 p-3 mb-2 return-line"><div class="col-md-1"><div class="form-check"><input class="form-check-input return-line-include" type="checkbox" id="return-line-${index}" checked><label class="form-check-label" for="return-line-${index}">คืน</label></div></div><div class="col-md-8"><label class="form-label small mb-1">รายการจาก HS/IV</label><div class="form-control-plaintext py-0">${$('<div>').text(option.text).html()}</div><input class="return-line-id" type="hidden" name="lines[${index}][physical_sale_line_id]" value="${option.id}"></div><div class="col-md-3"><label class="form-label small mb-1">จำนวนคืน</label><input class="form-control text-end return-line-quantity" name="lines[${index}][quantity]" type="number" min="${quantityStep}" step="${quantityStep}" value="${option.quantity}" required placeholder="ไม่เกินจำนวนขาย"></div></div>`;
    const toggle = checkbox => { const row = $(checkbox).closest('.return-line'), enabled = checkbox.checked; row.find('.return-line-id,.return-line-quantity').prop('disabled', !enabled).prop('required', enabled); if (!enabled) row.find('.return-line-quantity').val(''); };
    const load = id => { $lines.html('<div class="text-secondary text-center py-4">กำลังโหลดรายการจากเอกสาร...</div>'); $.getJSON(`${lineUrl}/${id}`).done(data => { const options = data.results || []; if (!options.length) { $lines.html('<div class="alert alert-warning mb-0">เอกสารนี้ไม่มีรายการสินค้าที่คืนได้</div>'); return; } $lines.html(options.map(row).join('')); $('#source-help').text('แก้ไขจำนวน หรือนำเครื่องหมายถูกออกจากรายการที่ไม่ต้องการคืนได้'); }).fail(() => $lines.html('<div class="alert alert-danger mb-0">โหลดรายการจากเอกสารไม่สำเร็จ กรุณาลองใหม่</div>')); };
    $source.on('change', function () { const id = $(this).val(); id ? load(id) : $lines.html('<div class="text-secondary text-center py-4">ยังไม่ได้เลือกเอกสารต้นทาง</div>'); });
    $lines.on('change', '.return-line-include', function () { toggle(this); });
    window.erpAjaxForm({ form: '#sales-return-form', redirect: true });
});
</script>
@endpush
