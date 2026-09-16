@extends($moduleRoutePrefix === 'purchasing' ? 'Purchasing::layout' : 'Wms::layout')
@section('title', ($order?->exists ? 'แก้ไข' : 'สร้าง').' Purchase Order | Purchasing')
@section('content')
@php($moduleRoutePrefix=$moduleRoutePrefix??'wms')
@php($requisition = $requisition ?? $order?->purchaseRequisition)
@php($fromPr = (bool) ($order?->purchase_requisition_id || $requisition?->exists))
@php($isPurchasing = $moduleRoutePrefix === 'purchasing')
@php($rows = old('lines', $order?->lines?->map(fn($line) => ['item_id'=>$line->item_id,'uom_id'=>$line->uom_id,'tax_code_id'=>$line->tax_code_id,'quantity'=>$line->quantity,'line_amount'=>$order->prices_include_vat ? ($line->gross_amount ?? $line->line_total) : ($line->tax_base ?? $line->line_total),'unit_price'=>$line->unit_price,'description'=>$line->description,'purchase_requisition_line_id'=>$line->purchase_requisition_line_id])->all() ?: ($requisition?->lines?->map(fn($line) => ['item_id'=>$line->item_id,'uom_id'=>$line->uom_id,'tax_code_id'=>'','quantity'=>$line->quantity,'line_amount'=>'0.00','unit_price'=>'0.0000','description'=>$line->description,'purchase_requisition_line_id'=>$line->id])->all() ?: [['item_id'=>'','uom_id'=>'','tax_code_id'=>'','quantity'=>'1.0000','line_amount'=>'0.00','unit_price'=>'0.0000','description'=>'','purchase_requisition_line_id'=>'']])))
@php($taxCalculation = old('tax_calculation', ($order?->tax_treatment ?? 'NONE_VAT') === 'NONE_VAT' ? 'NONE' : ($order?->prices_include_vat ? 'VAT_INCLUSIVE' : 'VAT_EXCLUSIVE')))
@php($rows = collect($rows)->map(function ($row) { $quantity = (float) ($row['quantity'] ?? 0); $row['unit_price'] = $row['unit_price'] ?? ($quantity > 0 ? number_format((float) ($row['line_amount'] ?? 0) / $quantity, 4, '.', '') : '0.0000'); return $row; })->all())
@php($taxCodeOptions = $taxCodes->map(fn ($taxCode) => $taxCode->only(['id', 'code', 'name', 'rate']))->values())
@php($lineTaxIds = collect($rows)->pluck('tax_code_id')->filter()->unique())
@php($defaultTaxCodeId = $lineTaxIds->count() === 1 ? (string) $lineTaxIds->first() : '')
@php($quantityDecimals = \App\Modules\Wms\Support\WmsDecimal::places())
<div class="container-fluid px-3 px-lg-4 py-4"><p class="eyebrow mb-2">PURCHASING / PROCUREMENT</p><h1 class="h3 mb-2">{{$order?->exists ? 'แก้ไข' : 'สร้าง'}}ใบสั่งซื้อ</h1>@if($fromPr)<p class="text-secondary mb-4">สร้างจาก PR {{$requisition?->document_number}} · ฝ่ายจัดซื้อกรอกราคาและเงื่อนไขการซื้อในขั้นตอนนี้</p>@else<p class="text-secondary mb-4">ระบุ Supplier รายการสินค้า จำนวน และราคาที่ตกลงก่อนบันทึกร่าง</p>@endif
<form id="purchase-order-form" method="POST" action="{{$order?->exists ? route($moduleRoutePrefix.'.purchase-orders.update',$order) : route($moduleRoutePrefix.'.purchase-orders.store')}}">@csrf @if($order?->exists)@method('PUT')@endif @if($fromPr)<input type="hidden" name="purchase_requisition_id" value="{{$order?->purchase_requisition_id ?: $requisition->id}}">@endif
<div class="card border-0 shadow-sm mb-4"><div class="card-body"><div class="row g-3"><div class="col-md-4"><label class="form-label">Supplier <span class="text-danger">*</span></label><select id="po-supplier" name="supplier_id" class="form-select" data-url="{{route($moduleRoutePrefix.'.purchase-documents.supplier-options')}}" required>@if($order?->supplier)<option value="{{$order->supplier_id}}" selected>{{$order->supplier_code}} · {{$order->supplier_name}}</option>@endif</select></div>@if($isPurchasing)<div class="col-md-4"><label class="form-label">คลังรับสินค้า <span class="text-danger">*</span></label>@if($fromPr || $order?->exists)<input class="form-control" value="{{$warehouse?->code}} · {{$warehouse?->name}}" disabled><input type="hidden" name="warehouse_id" value="{{$warehouse?->id}}"><div class="form-text">{{$fromPr ? 'ล็อกตามใบขอซื้อ' : 'เปลี่ยนคลังไม่ได้หลังสร้าง PO'}}</div>@else<select name="warehouse_id" class="form-select" required><option value="">เลือกคลังรับสินค้า</option>@foreach($warehouses as $item)<option value="{{$item->id}}" @selected((string) $item->id === (string) old('warehouse_id', $warehouse?->id))>{{$item->code}} · {{$item->name}}</option>@endforeach</select><div class="form-text">แสดงเฉพาะคลังในสาขาปัจจุบันที่คุณมีสิทธิ์</div>@endif</div>@endif<div class="col-md-4"><label class="form-label">เงื่อนไขชำระเงิน</label><select name="payment_term_id" class="form-select"><option value="">ไม่กำหนด</option>@foreach($terms as $term)<option value="{{$term->id}}" @selected((string) $term->id === (string) old('payment_term_id',$order?->payment_term_id))>{{$term->code}} · {{$term->name}}</option>@endforeach</select></div><div class="col-md-2"><label class="form-label">วันที่เอกสาร</label><input class="form-control" type="date" name="document_date" value="{{old('document_date',$order?->document_date?->format('Y-m-d') ?? today()->format('Y-m-d'))}}" required></div><div class="col-md-2"><label class="form-label">คาดรับสินค้า</label><input class="form-control" type="date" name="expected_date" value="{{old('expected_date',$order?->expected_date?->format('Y-m-d'))}}"></div><div class="col-md-4"><label class="form-label" for="po-tax-calculation">การคำนวณภาษี <span class="text-danger">*</span></label><select id="po-tax-calculation" name="tax_calculation" class="form-select" required><option value="VAT_INCLUSIVE" @selected($taxCalculation === 'VAT_INCLUSIVE')>รวมภาษี</option><option value="VAT_EXCLUSIVE" @selected($taxCalculation === 'VAT_EXCLUSIVE')>ภาษีนอก</option><option value="NONE" @selected($taxCalculation === 'NONE')>ไม่มีภาษี</option></select><div class="form-text">ราคารวมที่กรอกจะตีความตามตัวเลือกนี้ทั้งเอกสาร</div><div class="invalid-feedback" data-error-for="tax_calculation"></div></div><div class="col-md-4"><label class="form-label" for="po-default-tax-code">Tax Code เริ่มต้น</label><select id="po-default-tax-code" class="form-select"><option value="">เลือก Tax Code</option>@foreach($taxCodes as $taxCode)<option value="{{$taxCode->id}}" @selected($defaultTaxCodeId === (string)$taxCode->id)>{{$taxCode->code}} · {{$taxCode->name}} ({{$taxCode->rate}}%)</option>@endforeach</select><div class="form-text">ใช้กับทุกบรรทัดเป็นค่าเริ่มต้น และแก้เฉพาะบรรทัดได้</div></div><div class="col-12"><label class="form-label">หมายเหตุ</label><textarea name="description" class="form-control" rows="2">{{old('description',$order?->description)}}</textarea></div></div></div></div>
<div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between mb-3"><div><h2 class="h5 mb-1">รายการสั่งซื้อ</h2><small class="text-secondary">กรอกราคารวมของแต่ละรายการ ระบบจะคำนวณราคาต่อหน่วยให้ · Tax Code เริ่มต้นแก้แยกรายการได้</small></div>@if(!$fromPr)<button type="button" id="po-add-line" class="btn btn-sm btn-app-soft"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มบรรทัด</button>@endif</div><div class="table-responsive"><table class="table align-middle"><thead><tr><th style="min-width:340px">สินค้าและรายละเอียด</th><th style="min-width:140px">หน่วย</th><th style="min-width:120px">จำนวน</th><th id="po-line-amount-heading" style="min-width:155px">ราคารวมรายการ</th><th style="min-width:165px">ราคา/หน่วย (คำนวณ)</th><th style="min-width:160px">Tax Code</th><th class="text-end" style="min-width:120px">ยอดรวม</th>@if(!$fromPr)<th></th>@endif</tr></thead><tbody id="po-lines">@foreach($rows as $i=>$row)<tr class="po-line"><td><select name="lines[{{$i}}][item_id]" class="form-select po-item" @disabled($fromPr)><option value="">ไม่ผูกสินค้า</option>@if(!empty($row['item_id'])) @php($item=\App\Modules\Wms\Models\Item::find($row['item_id'])) @if($item)<option value="{{$item->id}}" selected>{{$item->code}} · {{$item->name}}</option>@endif @endif</select>@if($fromPr)<input type="hidden" name="lines[{{$i}}][item_id]" value="{{$row['item_id']}}"><input type="hidden" name="lines[{{$i}}][purchase_requisition_line_id]" value="{{$row['purchase_requisition_line_id']}}">@endif<input name="lines[{{$i}}][description]" class="form-control mt-2" value="{{$row['description']}}" placeholder="รายละเอียดรายการ" aria-label="รายละเอียดรายการ" required></td><td><select name="lines[{{$i}}][uom_id]" class="form-select po-uom" @disabled($fromPr)><option value="">เลือกหน่วย</option>@if(!empty($row['uom_id'])) @php($uom=\App\Modules\Wms\Models\Uom::find($row['uom_id'])) @if($uom)<option value="{{$uom->id}}" selected>{{$uom->code}} · {{$uom->name}}</option>@endif @endif</select>@if($fromPr)<input type="hidden" name="lines[{{$i}}][uom_id]" value="{{$row['uom_id']}}">@endif</td><td><input name="lines[{{$i}}][quantity]" class="form-control text-end" type="number" step="0.0001" min="0.0001" value="{{$row['quantity']}}" @disabled($fromPr) required></td><td><input name="lines[{{$i}}][line_amount]" class="form-control text-end js-po-line-amount" type="number" step="0.01" min="0" value="{{$row['line_amount']}}" required></td><td><input class="form-control text-end js-po-unit-price" type="text" value="{{$row['unit_price']}}" readonly tabindex="-1"></td><td><select name="lines[{{$i}}][tax_code_id]" class="form-select js-po-tax"><option value="">ไม่มี VAT</option>@foreach($taxCodes as $taxCode)<option value="{{$taxCode->id}}" data-rate="{{$taxCode->rate}}" @selected((string)($row['tax_code_id'] ?? '') === (string)$taxCode->id)>{{$taxCode->code}} · {{$taxCode->name}} ({{$taxCode->rate}}%)</option>@endforeach</select></td><td class="text-end js-po-total">0.00</td>@if(!$fromPr)<td><button class="btn btn-sm btn-app-danger js-remove-po-line" type="button" title="ลบบรรทัด" aria-label="ลบบรรทัด"><i class="bx bx-trash" aria-hidden="true"></i></button></td>@endif</tr>@endforeach</tbody><tfoot><tr><th colspan="6" class="text-end">มูลค่าก่อน VAT</th><th class="text-end" id="po-subtotal">0.00</th>@if(!$fromPr)<th></th>@endif</tr><tr><th colspan="6" class="text-end">ภาษีมูลค่าเพิ่ม</th><th class="text-end" id="po-tax-total">0.00</th>@if(!$fromPr)<th></th>@endif</tr><tr><th colspan="6" class="text-end">รวมทั้งสิ้น</th><th class="text-end" id="po-grand-total">0.00</th>@if(!$fromPr)<th></th>@endif</tr></tfoot></table></div><div class="mt-4"><button class="btn btn-app-primary" type="submit"><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกร่าง PO</button> <a class="btn btn-outline-secondary" href="{{route($moduleRoutePrefix.'.purchase-orders.index')}}">ยกเลิก</a></div></div></div></form></div>
@endsection
@push('scripts')
<script>
$(function () {
    var lines = $('#po-lines');
    var itemUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.item-options') }}';
    var uomUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.uom-options') }}';
    var taxCodes = @json($taxCodeOptions);
    var quantityDecimals = @json($quantityDecimals);
    var quantityStep = quantityDecimals ? '0.'+'0'.repeat(quantityDecimals-1)+'1' : '1';

    function select(element, url, placeholder) {
        element.select2({width:'100%', placeholder:placeholder, ajax:{url:url, dataType:'json', delay:250, data:function(params){return {q:params.term||'', page:params.page||1};}, processResults:function(data){return data;}}});
    }
    function init(row) {
        var quantity = row.find('[name$="[quantity]"]');
        var quantityValue = parseFloat(quantity.val());
        row.find('[name$="[description]"]').prop('required', false);
        quantity.attr({step:quantityStep, min:quantityStep});
        if (Number.isFinite(quantityValue)) quantity.val(quantityValue.toFixed(quantityDecimals));
        if (!row.find('.po-item').prop('disabled')) {
            select(row.find('.po-item'), itemUrl, 'ค้นหาสินค้า');
            row.find('.po-item').on('select2:select', function (event) {
                var item = event.params.data, uom = row.find('.po-uom');
                if (item.uom_id) uom.empty().append(new Option(item.uom_text, item.uom_id, true, true)).trigger('change');
            });
        }
        if (!row.find('.po-uom').prop('disabled')) select(row.find('.po-uom'), uomUrl, 'เลือกหน่วย');
    }
    function fillTax(selectElement) {
        selectElement.append($('<option>', {value:'', text:'ไม่มี VAT'}));
        $.each(taxCodes, function (_, tax) {
            selectElement.append($('<option>', {value:tax.id, text:tax.code+' · '+tax.name+' ('+tax.rate+'%)'}).attr('data-rate', tax.rate));
        });
    }
    function money(number) {
        return Number.isFinite(number) ? number.toFixed(2) : '0.00';
    }
    function totals() {
        var mode = $('#po-tax-calculation').val();
        var vat = mode !== 'NONE';
        var inclusive = mode === 'VAT_INCLUSIVE';
        var baseTotal = 0, taxTotal = 0, grossTotal = 0;
        lines.find('tr').each(function () {
            var row = $(this), quantity = parseFloat(row.find('[name$="[quantity]"]').val()) || 0;
            var amount = parseFloat(row.find('.js-po-line-amount').val()) || 0;
            var unitPrice = quantity > 0 ? amount / quantity : 0;
            var rate = vat ? (parseFloat(row.find('.js-po-tax option:selected').data('rate')) || 0) : 0;
            var base = inclusive && rate ? amount / (1 + rate / 100) : amount;
            var tax = rate ? (inclusive ? amount - base : base * rate / 100) : 0;
            var gross = base + tax;
            baseTotal += base; taxTotal += tax; grossTotal += gross;
            row.find('.js-po-unit-price').val(Number.isFinite(unitPrice) ? unitPrice.toFixed(4) : '0.0000');
            row.find('.js-po-total').text(money(gross));
        });
        $('#po-subtotal').text(money(baseTotal));
        $('#po-tax-total').text(money(taxTotal));
        $('#po-grand-total').text(money(grossTotal));
    }
    function toggleTax() {
        var vat = $('#po-tax-calculation').val() !== 'NONE';
        var defaultTaxCode = $('#po-default-tax-code').val();
        $('#po-default-tax-code,.js-po-tax').prop('disabled', !vat);
        $('#po-line-amount-heading').text($('#po-tax-calculation').val() === 'VAT_INCLUSIVE' ? 'ราคารวม (รวม VAT)' : ($('#po-tax-calculation').val() === 'VAT_EXCLUSIVE' ? 'ราคารวม (ก่อน VAT)' : 'ราคารวมรายการ'));
        if (!vat) {
            $('#po-default-tax-code,.js-po-tax').val('');
        } else if (defaultTaxCode) {
            $('.js-po-tax').filter(function () { return !this.value; }).val(defaultTaxCode);
        }
        totals();
    }

    select($('#po-supplier'), '{{ route($moduleRoutePrefix.'.purchase-documents.supplier-options') }}', 'ค้นหา Supplier');
    lines.find('tr').each(function () { init($(this)); });
    lines.on('input change', '.js-po-line-amount,[name$="[quantity]"],.js-po-tax', totals);
    $('#po-tax-calculation').on('change', toggleTax);
    $('#po-default-tax-code').on('change', function () {
        var taxCode = this.value;
        if (taxCode) $('.js-po-tax').val(taxCode);
        totals();
    });
    $('#po-add-line').on('click', function () {
        var index = lines.children().length;
        var row = $('<tr class="po-line"><td><select name="lines['+index+'][item_id]" class="form-select po-item"><option value="">ไม่ผูกสินค้า</option></select><input name="lines['+index+'][description]" class="form-control mt-2" placeholder="รายละเอียดรายการ" aria-label="รายละเอียดรายการ" required></td><td><select name="lines['+index+'][uom_id]" class="form-select po-uom"><option value="">เลือกหน่วย</option></select></td><td><input name="lines['+index+'][quantity]" class="form-control text-end" type="number" step="0.0001" min="0.0001" value="1" required></td><td><input name="lines['+index+'][line_amount]" class="form-control text-end js-po-line-amount" type="number" step="0.01" min="0" value="0.00" required></td><td><input class="form-control text-end js-po-unit-price" type="text" value="0.0000" readonly tabindex="-1"></td><td><select name="lines['+index+'][tax_code_id]" class="form-select js-po-tax"></select></td><td class="text-end js-po-total">0.00</td><td><button class="btn btn-sm btn-app-danger js-remove-po-line" type="button" title="ลบบรรทัด" aria-label="ลบบรรทัด"><i class="bx bx-trash" aria-hidden="true"></i></button></td></tr>');
        lines.append(row); fillTax(row.find('.js-po-tax')); init(row);
        if ($('#po-default-tax-code').val()) row.find('.js-po-tax').val($('#po-default-tax-code').val());
        toggleTax();
    });
    lines.on('click', '.js-remove-po-line', function () {
        if (lines.children().length > 1) { $(this).closest('tr').remove(); totals(); }
    });
    toggleTax();
    window.erpAjaxForm({form:'#purchase-order-form', redirect:true});
});
</script>
@endpush
@push('scripts')<script>$(function(){var f=$('#purchase-order-form');f.on('submit',function(){f.find('input:disabled').each(function(){var i=$(this),n=i.attr('name');if(n&&n.indexOf('[quantity]')!==-1&&!f.find('input[type="hidden"][name="'+n+'"]').length)$('<input>',{type:'hidden',name:n,value:i.val()}).appendTo(f);});});});</script>@endpush
