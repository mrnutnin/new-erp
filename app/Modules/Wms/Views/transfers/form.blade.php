@extends('Wms::layout')
@php
    $isEdit = $transfer->exists;
    $formLines = $isEdit ? $transfer->lines : collect([null]);
    $quantityStep = \App\Modules\Wms\Support\WmsDecimal::step();
@endphp
@section('title', ($isEdit ? 'แก้ไข' : 'สร้าง').' Transfer | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / TRANSFER OUT</p>
            <h1 class="h3 mb-2">{{ $isEdit ? 'แก้ไขร่างใบโอนสินค้าออก' : 'สร้างใบโอนสินค้าออก' }}</h1>
            @if ($isEdit)<p class="text-secondary mb-0">{{ $transfer->document_number }}</p>@endif
        </div>
        <a class="btn btn-outline-secondary" href="{{ $isEdit ? route('wms.transfers.show', $transfer) : route('wms.transfers.outgoing.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
    </div>

    <form id="transfer-form" method="POST" action="{{ $isEdit ? route('wms.transfers.update', $transfer) : route('wms.transfers.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="source_warehouse_id">คลังต้นทาง</label>
                        @if ($isEdit)
                            <input class="form-control" value="{{ $sourceWarehouse->name }}" disabled>
                            <input type="hidden" name="source_warehouse_id" value="{{ $sourceWarehouse->id }}">
                        @else
                            <select class="form-select" id="source_warehouse_id" name="source_warehouse_id" required>
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected((int) $warehouse->id === (int) $sourceWarehouse->id)>{{ $warehouse->name }} · สาขา{{ $warehouse->branch?->name ?: $warehouse->branch?->code ?: '-' }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="destination_warehouse_id">คลังปลายทาง</label>
                        <select class="form-select" id="destination_warehouse_id" name="destination_warehouse_id" required>
                            <option value="">เลือกคลังปลายทาง</option>
                            @foreach ($warehouses as $warehouse)
                                @if ((int) $warehouse->id !== (int) $sourceWarehouse->id)
                                    <option value="{{ $warehouse->id }}" @selected((int) old('destination_warehouse_id', $transfer->destination_warehouse_id) === (int) $warehouse->id)>{{ $warehouse->name }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="document_date">วันที่เอกสาร</label>
                        <input class="form-control" id="document_date" type="date" name="document_date" value="{{ old('document_date', $transfer->document_date?->format('Y-m-d') ?: now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                        <div class="form-text">เลขที่เอกสารเดิมจะไม่เปลี่ยนเมื่อแก้ไข</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="note">หมายเหตุ</label>
                        <textarea class="form-control" id="note" name="note" rows="3" maxlength="1000" placeholder="ระบุรายละเอียดเพิ่มเติม (ถ้ามี)">{{ old('note', $transfer->note) }}</textarea>
                        <div class="invalid-feedback" data-error-for="note"></div>
                    </div>
                    @unless ($isEdit)<input type="hidden" name="idempotency_key" value="transfer-{{ now()->format('YmdHisv') }}">@endunless
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div><h2 class="h5 mb-1">รายการสินค้า</h2><div class="form-text">ใช้หน่วย Stock ของสินค้าโดยอัตโนมัติ</div></div>
                    <button class="btn btn-app-soft btn-sm" type="button" id="add-transfer-line"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มรายการ</button>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle transfer-lines-table">
                        <thead><tr><th>สินค้า</th><th>หน่วย Stock</th><th>จำนวน</th><th>จัดการ</th></tr></thead>
                        <tbody id="transfer-lines">
                            @foreach ($formLines as $index => $line)
                                <tr class="transfer-line">
                                    <td>
                                        <select class="form-select js-item" name="lines[{{ $index }}][item_id]" required>
                                            @if ($line)<option value="{{ $line->item_id }}" data-uom-id="{{ $line->uom_id }}" data-uom-label="{{ $line->uom?->code ?: $line->uom?->name }}" selected>{{ $line->item?->code }} · {{ $line->item?->name }}</option>@endif
                                        </select>
                                    </td>
                                    <td><span class="js-stock-uom text-secondary">{{ $line?->uom?->code ?: $line?->uom?->name ?: 'เลือกสินค้า' }}</span><input type="hidden" class="js-uom-id" name="lines[{{ $index }}][uom_id]" value="{{ $line?->uom_id }}" required><input type="hidden" class="js-base-quantity" name="lines[{{ $index }}][planned_base_quantity]" value="{{ \App\Modules\Wms\Support\WmsDecimal::input($line?->planned_base_quantity ?? 1) }}" required></td>
                                    <td><input class="form-control text-end js-quantity" type="number" min="{{ $quantityStep }}" step="{{ $quantityStep }}" name="lines[{{ $index }}][planned_quantity]" value="{{ \App\Modules\Wms\Support\WmsDecimal::input($line?->planned_quantity ?? 1) }}" required></td>
                                    <td><button class="btn btn-sm btn-app-danger js-remove-line" type="button" title="ลบรายการ" aria-label="ลบรายการ"><i class="bx bx-trash" aria-hidden="true"></i></button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button class="btn btn-app-primary" type="submit"><i class="bx bx-save me-1" aria-hidden="true"></i>{{ $isEdit ? 'บันทึกการแก้ไข' : 'บันทึกร่าง' }}</button>
                    <a class="btn btn-outline-secondary" href="{{ $isEdit ? route('wms.transfers.show', $transfer) : route('wms.transfers.outgoing.index') }}">ยกเลิก</a>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var rows = $('#transfer-lines'), itemUrl = @json(route('wms.transfers.item-options'));
    var warehouses = @json($warehouses->map(fn ($warehouse) => ['id' => $warehouse->id, 'label' => $warehouse->name])->values());
    var destination = $('#destination_warehouse_id'), initialDestination = String(@json(old('destination_warehouse_id', $transfer->destination_warehouse_id)) || '');

    function sourceId() { return String($('[name="source_warehouse_id"]').val() || ''); }
    function sync(row, item) {
        var option = row.find('.js-item option:selected')[0];
        var uomId = item?.uom_id || option?.dataset.uomId || '';
        var uomLabel = item?.uom_label || option?.dataset.uomLabel || 'เลือกสินค้า';
        var quantity = row.find('.js-quantity').val() || '';
        row.find('.js-stock-uom').text(uomLabel);
        row.find('.js-uom-id').val(uomId);
        row.find('.js-base-quantity').val(quantity);
        row.find('.js-stock-available').remove();
        if (item?.available_label) $('<small class="js-stock-available text-secondary d-block mt-1"></small>').text(item.available_label).appendTo(row.find('.js-item').closest('td'));
    }
    function init(row) {
        var select = row.find('.js-item');
        window.erpInitSelect2(select, {theme:'bootstrap-5', placeholder:'ค้นหาสินค้า', allowClear:true, ajax:{url:itemUrl, dataType:'json', delay:250, data:function (params) { return {q:params.term || '', page:params.page || 1, warehouse_id:sourceId()}; }, processResults:function (data) { return data; }, cache:true}});
        select.on('select2:select', function (event) { sync(row, event.params.data); }).on('select2:clear', function () { sync(row, null); });
        row.find('.js-quantity').on('input change', function () { row.find('.js-base-quantity').val(this.value); });
    }
    function reindex() {
        rows.find('.transfer-line').each(function (index) { $(this).find('[name]').each(function () { this.name = this.name.replace(/lines\[\d+\]/, 'lines[' + index + ']'); }); });
    }
    function refreshDestinations() {
        var selected = destination.val() || initialDestination, source = sourceId();
        destination.empty().append(new Option('เลือกคลังปลายทาง', ''));
        warehouses.forEach(function (warehouse) { if (String(warehouse.id) !== source) destination.append(new Option(warehouse.label, warehouse.id, false, String(warehouse.id) === String(selected))); });
        initialDestination = '';
    }

    rows.find('.transfer-line').each(function () { init($(this)); });
    $('#add-transfer-line').on('click', function () {
        var row = $('<tr class="transfer-line"><td><select class="form-select js-item" required></select></td><td><span class="js-stock-uom text-secondary">เลือกสินค้า</span><input type="hidden" class="js-uom-id" required><input type="hidden" class="js-base-quantity" value="1" required></td><td><input class="form-control text-end js-quantity" type="number" min="{{ $quantityStep }}" step="{{ $quantityStep }}" value="1" required></td><td><button class="btn btn-sm btn-app-danger js-remove-line" type="button" title="ลบรายการ" aria-label="ลบรายการ"><i class="bx bx-trash" aria-hidden="true"></i></button></td></tr>');
        rows.append(row); reindex(); init(row);
    });
    rows.on('click', '.js-remove-line', function () { if (rows.find('.transfer-line').length > 1) { $(this).closest('tr').remove(); reindex(); } });
    $('#source_warehouse_id').on('change', function () { refreshDestinations(); rows.find('.js-item').val(null).trigger('change'); });
    refreshDestinations();
    window.erpAjaxForm({form:'#transfer-form', redirect:true});
});
</script>
@endpush
