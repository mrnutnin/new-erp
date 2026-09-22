@extends('Production::layout')
@section('title', ($order->exists ? 'แก้ไขใบสั่งผลิต' : 'สร้างใบสั่งผลิต').' | Production')
@section('content')
@php
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
                    ];
                })->values()->all(),
            ];
        })->values()->all()];
    })->all();
@endphp
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><p class="eyebrow mb-2">PRODUCTION / WORK ORDER</p><h1 class="h3 mb-1">{{ $order->exists ? 'แก้ไขใบสั่งผลิต '.$order->document_number : 'สร้างใบสั่งผลิต' }}</h1><p class="text-secondary mb-0">สร้าง/แก้ไข WO แบบ Make to Stock จาก Active BOM</p></div>
        <a class="btn btn-outline-secondary" href="{{ route('production.orders.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
    </div>
    <form id="production-order-form" class="card border-0 shadow-sm" action="{{ $order->exists ? route('production.orders.update', $order) : route('production.orders.store') }}" method="post">
        @csrf @if($order->exists)@method('PUT')@endif
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-lg-6"><label class="form-label">Active BOM <span class="text-danger">*</span></label><select class="form-select" name="bom_revision_id" required><option value="">เลือก BOM</option>@foreach($revisions as $revision)<option value="{{ $revision->id }}" @selected((int) old('bom_revision_id', $order->bom_revision_id) === (int) $revision->id)>{{ $revision->bom?->code }} Rev {{ $revision->revision_number }} · {{ $revision->bom?->finishedItem?->code }} {{ $revision->bom?->finishedItem?->name }} ({{ $revision->bom?->baseUom?->code }})</option>@endforeach</select></div>
                <div class="col-lg-3"><label class="form-label">จำนวนผลิต <span class="text-danger">*</span></label><input class="form-control" type="number" name="planned_quantity" step="0.00000001" min="0.00000001" value="{{ old('planned_quantity', $order->planned_quantity) }}" required></div>
                <div class="col-lg-3"><label class="form-label">เริ่มแผน</label><input class="form-control" type="date" name="planned_start_date" value="{{ old('planned_start_date', $order->planned_start_date?->format('Y-m-d') ?: now()->format('Y-m-d')) }}"></div>
                <div class="col-lg-3"><label class="form-label">จบแผน</label><input class="form-control" type="date" name="planned_finish_date" value="{{ old('planned_finish_date', $order->planned_finish_date?->format('Y-m-d')) }}"></div>
                <div class="col-lg-3"><label class="form-label">เวลาเริ่มตามแผน</label><input class="form-control" type="datetime-local" name="planned_start_at" value="{{ old('planned_start_at', $order->planned_start_at?->format('Y-m-d\\TH:i')) }}"></div>
                <div class="col-lg-3"><label class="form-label">เวลาจบตามแผน</label><input class="form-control" type="datetime-local" name="planned_finish_at" value="{{ old('planned_finish_at', $order->planned_finish_at?->format('Y-m-d\\TH:i')) }}"></div>
                <div class="col-lg-3"><label class="form-label">กำหนดส่ง</label><input class="form-control" type="datetime-local" name="required_delivery_at" value="{{ old('required_delivery_at', $order->required_delivery_at?->format('Y-m-d\\TH:i')) }}"></div>
                <div class="col-12"><label class="form-label">หมายเหตุ</label><textarea class="form-control" name="notes" rows="3" maxlength="1000">{{ old('notes', $order->notes) }}</textarea></div>
                @if(auth()->user()->hasPermission('production.orders.substitute.use'))<div class="col-12 d-none" id="substitute-panel"><div class="border rounded-3 p-3"><h2 class="h6">วัตถุดิบทดแทน</h2><p class="small text-secondary">เลือกใช้แทนวัตถุดิบหลักได้เฉพาะรายการที่กำหนดใน BOM</p><div id="substitute-lines"></div></div></div>@endif
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end gap-2"><a class="btn btn-app-soft" href="{{ route('production.orders.index') }}">ยกเลิก</a><button class="btn btn-app-primary" type="submit"><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกร่าง</button></div>
    </form>
</div>
@endsection
@push('scripts')<script>$(function(){const routing=@json($routingData);const bom=$('[name=bom_revision_id]'),panel=$('#substitute-panel'),body=$('#substitute-lines');function renderSubstitutes(){body.empty();const lines=routing[bom.val()]||[];lines.filter(x=>x.substitutes.length).forEach(function(line){const select=$('<select class="form-select" name="substitutes['+line.id+']"><option value="">ใช้วัตถุดิบหลัก: '+line.label+'</option></select>');line.substitutes.forEach(function(s){select.append(new Option('ใช้แทน: '+s.text+' × '+s.factor,s.id));});body.append($('<div class="mb-2"><label class="form-label small">'+line.label+'</label></div>').append(select));});panel.toggleClass('d-none',!lines.some(x=>x.substitutes.length));}bom.on('change',renderSubstitutes);renderSubstitutes();$('#production-order-form').on('submit',function(e){e.preventDefault();const form=$(this),btn=form.find('button[type=submit]').prop('disabled',true);$.ajax({url:form.attr('action'),method:'POST',data:form.serialize()}).done(res=>{window.location=res.redirect}).fail(xhr=>{Swal.fire({icon:'error',text:xhr.responseJSON?.message||'บันทึกไม่สำเร็จ'});btn.prop('disabled',false)})})});</script>@endpush
