@extends('Production::layout')
@section('title', ($order->exists ? 'แก้ไขใบสั่งผลิต' : 'สร้างใบสั่งผลิต').' | Production')
@section('content')
@php
    $madeToOrder = $order->exists && $order->order_type === 'MAKE_TO_ORDER';
    $returnUrl = $order->exists ? route('production.orders.show', $order) : route('production.orders.index');
    $existingMaterials = $order->exists && ! $madeToOrder ? $order->materials->mapWithKeys(fn ($material) => [$material->source_bom_line_id => ['item_id' => $material->item_id, 'uom_id' => $material->uom_id]])->all() : [];
    $routingData = $revisions->mapWithKeys(function ($revision): array {
        return [$revision->id => $revision->lines->map(function ($line): array {
            return [
                'id' => $line->id,
                'label' => trim(($line->componentItem?->code ?? '').' · '.($line->componentItem?->name ?? ''), ' ·'),
                'substitutes' => $line->substitutes->map(function ($substitute): array {
                    return [
                        'id' => $substitute->id,
                        'text' => trim(($substitute->substituteItem?->code ?? '').' · '.($substitute->substituteItem?->name ?? ''), ' ·'),
                        'factor' => (string) $substitute->quantity_factor,
                        'item_id' => $substitute->substitute_item_id,
                        'uom_id' => $substitute->uom_id,
                    ];
                })->values()->all(),
            ];
        })->values()->all()];
    })->all();
@endphp
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">PRODUCTION / WORK ORDER</p><h1 class="h3 mb-1">{{ $madeToOrder ? 'แก้ไขแผนใบสั่งผลิต '.$order->document_number : ($order->exists ? 'แก้ไขใบสั่งผลิต '.$order->document_number : 'สร้างใบสั่งผลิต') }}</h1><p class="text-secondary mb-0">{{ $madeToOrder ? 'แก้ไขเฉพาะแผนผลิตและหมายเหตุของร่าง · จำนวน, BOM และข้อกำหนดจากลูกค้าคงเดิม' : 'สร้าง/แก้ไข WO แบบ Make to Stock จาก Active BOM' }}</p></div>
        <a class="btn btn-outline-secondary" href="{{ $returnUrl }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>{{ $order->exists ? 'กลับหน้ารายละเอียด' : 'กลับหน้ารายการ' }}</a>
    </div>
    <form id="production-order-form" class="card border-0 shadow-sm" action="{{ $order->exists ? route('production.orders.update', $order) : route('production.orders.store') }}" method="post">
        @csrf @if($order->exists)@method('PUT')@endif
        <div class="card-body p-4">
            @if($madeToOrder)
                <section class="border rounded-3 p-3 mb-4" aria-label="คำขอจากฝ่ายขาย"><h2 class="h6">คำขอจากฝ่ายขาย (อ่านอย่างเดียว)</h2><div class="row g-2 small"><div class="col-md-6">SO: {{ $order->salesOrder?->document_number ?: '—' }}</div><div class="col-md-6">สินค้า: {{ $order->finishedItem?->code }} · {{ $order->finishedItem?->name }}</div><div class="col-md-6">จำนวนผลิต: {{ \App\Modules\Wms\Support\WmsDecimal::format($order->planned_quantity) }} {{ $order->uom?->code }}</div><div class="col-md-6">BOM: {{ $order->bomRevision?->bom?->code }} Rev {{ $order->bomRevision?->revision_number }}</div><div class="col-md-6">คลังเบิก/รับ: {{ $order->issueWarehouse?->code }} / {{ $order->receiptWarehouse?->code }}</div><div class="col-md-6">ลูกค้าต้องการส่ง: {{ $order->required_delivery_date?->format('d/m/Y') ?: '—' }}</div><div class="col-md-6">เริ่มได้ตั้งแต่: {{ $order->salesOrderLine?->requested_start_date?->format('d/m/Y') ?: 'ไม่ระบุ' }}</div><div class="col-12"><strong>ข้อกำหนดจากลูกค้า:</strong> {{ $order->customer_specification ?: '—' }}</div></div></section>
            @endif
            <div id="production-order-error" class="alert alert-danger" role="alert" hidden></div>
            <div class="row g-3">
                @unless($madeToOrder)
                <div class="col-lg-6"><label class="form-label" for="wo-bom">Active BOM <span class="text-danger">*</span></label><select id="wo-bom" class="form-select" name="bom_revision_id" required><option value="">เลือก BOM</option>@foreach($revisions as $revision)<option value="{{ $revision->id }}" @selected((int) old('bom_revision_id', $order->bom_revision_id) === (int) $revision->id)>{{ $revision->bom?->code }} Rev {{ $revision->revision_number }} · {{ $revision->bom?->finishedItem?->code }} {{ $revision->bom?->finishedItem?->name }} ({{ $revision->bom?->baseUom?->code }})</option>@endforeach</select><div class="invalid-feedback" data-error-for="bom_revision_id"></div></div>
                <div class="col-lg-3"><label class="form-label" for="wo-qty">จำนวนผลิต <span class="text-danger">*</span></label><input id="wo-qty" class="form-control" type="number" name="planned_quantity" step="0.00000001" min="0.00000001" value="{{ old('planned_quantity', $order->planned_quantity) }}" required><div class="invalid-feedback" data-error-for="planned_quantity"></div></div>
                @endunless
                @if($order->exists && ! $madeToOrder)<div class="col-12 small text-secondary">เปลี่ยน BOM, จำนวน หรือวัตถุดิบทดแทน จะคำนวณวัตถุดิบและ Routing ใหม่; แก้เฉพาะเวลา/หมายเหตุจะคงรายการเดิม</div>@endif
                <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="wo-start-date">วันเริ่มแผน @if($madeToOrder)<span class="text-danger">*</span>@endif</label><input id="wo-start-date" class="form-control" type="date" name="planned_start_date" value="{{ old('planned_start_date', $order->planned_start_date?->format('Y-m-d') ?: now()->format('Y-m-d')) }}" @required($madeToOrder)><div class="invalid-feedback" data-error-for="planned_start_date"></div></div>
                <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="wo-finish-date">วันจบแผน</label><input id="wo-finish-date" class="form-control" type="date" name="planned_finish_date" value="{{ old('planned_finish_date', $order->planned_finish_date?->format('Y-m-d')) }}"><div class="invalid-feedback" data-error-for="planned_finish_date"></div></div>
                <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="wo-start-at">เวลาเริ่มตามแผน</label><input id="wo-start-at" class="form-control" type="datetime-local" name="planned_start_at" value="{{ old('planned_start_at', $order->planned_start_at?->format('Y-m-d\\TH:i')) }}"><div class="invalid-feedback" data-error-for="planned_start_at"></div></div>
                <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="wo-finish-at">เวลาจบตามแผน</label><input id="wo-finish-at" class="form-control" type="datetime-local" name="planned_finish_at" value="{{ old('planned_finish_at', $order->planned_finish_at?->format('Y-m-d\\TH:i')) }}"><div class="invalid-feedback" data-error-for="planned_finish_at"></div><div class="form-text">กรอกเวลาเริ่มและจบทั้งคู่เพื่อแสดงใน Timeline</div></div>
                <div class="col-12 col-lg-6"><label class="form-label" for="wo-delivery-at">กำหนดส่งตามแผน (วัน/เวลา ถ้ามี)</label><input id="wo-delivery-at" class="form-control" type="datetime-local" name="required_delivery_at" value="{{ old('required_delivery_at', $order->required_delivery_at?->format('Y-m-d\\TH:i')) }}"><div class="invalid-feedback" data-error-for="required_delivery_at"></div></div>
                <div class="col-12"><label class="form-label" for="wo-notes">หมายเหตุสำหรับงานผลิต</label><textarea id="wo-notes" class="form-control" name="notes" rows="3" maxlength="{{ $madeToOrder ? 2000 : 1000 }}">{{ old('notes', $order->notes) }}</textarea><div class="invalid-feedback" data-error-for="notes"></div></div>
                @if(! $madeToOrder && auth()->user()->hasPermission('production.orders.substitute.use'))<div class="col-12 d-none" id="substitute-panel"><div class="border rounded-3 p-3"><h2 class="h6">วัตถุดิบทดแทน</h2><p class="small text-secondary">เลือกใช้แทนวัตถุดิบหลักได้เฉพาะรายการที่กำหนดใน BOM</p><div id="substitute-lines"></div></div></div>@endif
            </div>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-end gap-2"><a class="btn btn-app-soft" href="{{ $returnUrl }}">ยกเลิก</a><button class="btn btn-app-primary" type="submit"><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกร่าง</button></div>
    </form>
</div>
@endsection
@push('scripts')
<script>
$(function(){
    const routing=@json($routingData), existing=@json($existingMaterials), bom=$('[name=bom_revision_id]'),panel=$('#substitute-panel'),body=$('#substitute-lines');
    function renderSubstitutes(){
        body.empty();
        const lines=routing[bom.val()]||[];
        lines.filter(line=>line.substitutes.length).forEach(function(line){
            const select=$('<select class="form-select"></select>').attr({name:'substitutes['+line.id+']',id:'wo-sub-'+line.id});
            select.append(new Option('ใช้วัตถุดิบหลัก: '+line.label,''));
            line.substitutes.forEach(s=>select.append(new Option('ใช้แทน: '+s.text+' × '+s.factor,s.id)));
            const previous=existing[line.id];
            if(previous) select.val(line.substitutes.find(s=>Number(s.item_id)===Number(previous.item_id) && Number(s.uom_id)===Number(previous.uom_id))?.id||'');
            body.append($('<div class="mb-2"></div>').append($('<label class="form-label small"></label>').attr('for','wo-sub-'+line.id).text(line.label),select));
        });
        panel.toggleClass('d-none',!lines.some(line=>line.substitutes.length));
    }
    bom.on('change',renderSubstitutes);renderSubstitutes();
    $('[name=planned_start_at],[name=planned_finish_at]').on('change',function(){
        if(this.value) $('[name='+this.name.replace('_at','_date')+']').val(this.value.slice(0,10));
    });
    $('#production-order-form').on('submit',function(event){
        event.preventDefault();
        const form=$(this),button=form.find('[type=submit]').prop('disabled',true);
        form.find('.is-invalid').removeClass('is-invalid');
        $('#production-order-error').prop('hidden',true);
        $.ajax({url:form.attr('action'),method:'POST',data:form.serialize()}).done(response=>window.location.assign(response.redirect)).fail(xhr=>{
            button.prop('disabled',false);
            const errors=xhr.responseJSON?.errors||{};
            Object.entries(errors).forEach(([name,messages])=>{
                form.find('[name='+name+']').addClass('is-invalid');
                form.find('[data-error-for='+name+']').text(messages[0]);
            });
            $('#production-order-error').text(xhr.responseJSON?.message||'บันทึกใบสั่งผลิตไม่สำเร็จ กรุณาลองใหม่').prop('hidden',false);
            form.find('.is-invalid').first().trigger('focus');
        });
    });
});
</script>
@endpush
