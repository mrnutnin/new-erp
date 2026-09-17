@extends('Wms::layout')
@php
    $isEdit = $document?->exists ?? false;
    $productionMode = $productionMode ?? false;
    $formLines = $isEdit ? $document->lines : collect([null]);
    $indexRoute = $productionMode ? 'wms.production.material-issues.index' : 'wms.issues.index';
    $storeRoute = $productionMode ? 'wms.production.material-issues.store' : 'wms.issues.store';
    $updateRoute = $productionMode ? 'wms.production.material-issues.update' : 'wms.issues.update';
    $itemRoute = $productionMode ? 'wms.production.material-issues.item-options' : 'wms.issues.item-options';
    $quantityStep = \App\Modules\Wms\Support\WmsDecimal::step();
@endphp
@section('title', ($isEdit ? 'แก้ไขร่าง' : 'สร้าง').($productionMode ? 'ใบเบิกวัตถุดิบผลิต' : 'ใบเบิกสินค้า').' | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / {{ $productionMode ? 'PRODUCTION MATERIAL ISSUE' : 'ISSUE' }}</p><h1 class="h3 mb-2">{{ $isEdit ? 'แก้ไขร่าง' : 'สร้าง' }}{{ $productionMode ? 'ใบเบิกวัตถุดิบผลิต' : 'ใบเบิกสินค้า' }}</h1><p class="text-secondary mb-0">{{ $isEdit ? $document->document_number : 'เอกสารใช้หน่วย Stock ของสินค้า และจะตัด Stock เมื่อกดลง Stock' }}</p></div>
        <a class="btn btn-outline-secondary" href="{{ $isEdit ? route($productionMode ? 'wms.production.material-issues.show' : 'wms.issues.show', $document) : route($indexRoute) }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
    </div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4">
        <form id="issue-form" method="post" action="{{ $isEdit ? route($updateRoute, $document) : route($storeRoute) }}">
            @csrf @if($isEdit) @method('PUT') @endif
            <div class="row g-3 mb-4">
                <div class="col-md-3"><label class="form-label" for="document_date">วันที่เอกสาร</label><input class="form-control" id="document_date" type="date" name="document_date" value="{{ old('document_date', $document?->document_date?->format('Y-m-d') ?: now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required></div>
                <div class="col-md-3"><label class="form-label" for="issue_type">ประเภทการเบิก</label><select class="form-select" id="issue_type" name="issue_type" required>@foreach($issueTypes as $type)<option value="{{ $type->code }}" @selected(old('issue_type', $document?->issue_type) === $type->code)>{{ $type->name }} ({{ $type->code }})</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label" for="reason">เหตุผล</label><input class="form-control" id="reason" name="reason" minlength="5" maxlength="500" value="{{ old('reason', $document?->reason) }}" placeholder="ระบุเหตุผลการเบิก" required></div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h5 mb-0">รายการเบิก</h2><button type="button" class="btn btn-sm btn-app-soft" id="add-line"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มรายการ</button></div>
            <div class="table-responsive"><table class="table align-middle" id="issue-lines"><thead><tr><th style="min-width: 360px">สินค้า</th><th>หน่วย Stock</th><th class="text-end">คงเหลือพร้อมใช้</th><th class="text-end">จำนวนเบิก</th><th>จัดการ</th></tr></thead><tbody>
                @foreach($formLines as $index => $line)
                    <tr><td><select class="form-select js-item" name="lines[{{ $index }}][item_id]" required>@if($line)<option value="{{ $line->item_id }}" selected>{{ $line->item?->code }} · {{ $line->item?->name }}</option>@endif</select></td><td><select class="form-select js-uom" name="lines[{{ $index }}][uom_id]" required>@if($line)<option value="{{ $line->uom_id }}" selected>{{ $line->uom?->code ?: $line->uom?->name }}</option>@else<option value="">หน่วย Stock</option>@endif</select></td><td class="text-end"><span class="js-available text-secondary">เลือกสินค้าใหม่เพื่อตรวจยอด</span></td><td><input class="form-control text-end" type="number" name="lines[{{ $index }}][quantity]" min="{{ $quantityStep }}" step="{{ $quantityStep }}" value="{{ \App\Modules\Wms\Support\WmsDecimal::input($line?->quantity) }}" required></td><td><button type="button" class="btn btn-sm btn-app-danger js-remove" title="ลบรายการ" aria-label="ลบรายการ"><i class="bx bx-trash" aria-hidden="true"></i></button></td></tr>
                @endforeach
            </tbody></table></div>
            <div class="d-flex flex-wrap gap-2 mt-4"><button class="btn btn-app-primary" type="submit"><i class="bx bx-save me-1" aria-hidden="true"></i>{{ $isEdit ? 'บันทึกการแก้ไข' : 'บันทึกร่าง' }}</button><a class="btn btn-outline-secondary" href="{{ $isEdit ? route($productionMode ? 'wms.production.material-issues.show' : 'wms.issues.show', $document) : route($indexRoute) }}">ยกเลิก</a></div>
        </form>
    </div></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    const tbody=$('#issue-lines tbody'), itemUrl=@json(route($itemRoute));
    function init(select){window.erpInitSelect2(select,{ajax:{url:itemUrl,delay:250,data:p=>({q:p.term||'',page:p.page||1}),processResults:d=>d}});select.on('select2:select',function(e){const d=e.params.data,row=select.closest('tr');row.find('.js-uom').empty().append(new Option(d.uom_label||'หน่วย Stock',d.uom_id,true,true));row.find('.js-available').text(d.available_label||'คงเหลือ 0').toggleClass('text-success',Number(d.available_quantity)>0).toggleClass('text-danger',Number(d.available_quantity)<=0);}).on('select2:clear',()=>select.closest('tr').find('.js-available').text('เลือกสินค้า').removeClass('text-success text-danger'));}
    function reindex(){tbody.find('tr').each(function(i){$(this).find('[name]').each(function(){this.name=this.name.replace(/lines\[\d+\]/,'lines['+i+']');});});}
    tbody.find('.js-item').each(function(){init($(this));});
    $('#add-line').on('click',function(){const row=$('<tr><td><select class="form-select js-item" required></select></td><td><select class="form-select js-uom" required><option value="">หน่วย Stock</option></select></td><td class="text-end"><span class="js-available text-secondary">เลือกสินค้า</span></td><td><input class="form-control text-end" type="number" min="{{ $quantityStep }}" step="{{ $quantityStep }}" required></td><td><button type="button" class="btn btn-sm btn-app-danger js-remove" title="ลบรายการ" aria-label="ลบรายการ"><i class="bx bx-trash" aria-hidden="true"></i></button></td></tr>');tbody.append(row);reindex();init(row.find('.js-item'));});
    tbody.on('click','.js-remove',function(){if(tbody.find('tr').length>1){$(this).closest('tr').remove();reindex();}});
    window.erpAjaxForm({form:'#issue-form',redirect:true});
});
</script>
@endpush
