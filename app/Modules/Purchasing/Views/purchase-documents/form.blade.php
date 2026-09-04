@extends($moduleRoutePrefix === 'purchasing' ? 'Purchasing::layout' : 'Wms::layout')

@section('title', ($document->exists ? 'แก้ไข' : 'สร้าง').'เอกสารซื้อ | Purchasing')

@section('content')
    @php
        $moduleRoutePrefix = $moduleRoutePrefix ?? 'wms';
        $rows = old('lines', $document->exists ? $document->lines->map(fn ($line) => [...$line->only(['description','item_id','uom_id','purchase_order_line_id','account_id','tax_code_id','quantity','unit_price','discount_amount']), 'receipt_allocations' => $line->receiptAllocations->map->only(['goods_receipt_line_id','allocated_quantity'])->all()])->all() : [['description'=>'','item_id'=>'','uom_id'=>'','purchase_order_line_id'=>'','account_id'=>'','tax_code_id'=>'','quantity'=>'1.0000','unit_price'=>'0.0000','discount_amount'=>'0.00','receipt_allocations'=>[]]]);
        $type = old('document_type', $document->document_type);
        $purchaseMode = old('purchase_mode', collect($rows)->contains(fn (array $row) => ! empty($row['receipt_allocations']) || $lineItems->get((int) ($row['item_id'] ?? 0))?->item_type === 'GOODS') ? 'INVENTORY' : 'EXPENSE');
    @endphp
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">PURCHASING / DOCUMENTS</p>
        <h1 class="h3 mb-2">{{ $document->exists ? 'แก้ไข' : 'สร้าง' }}{{ $type === 'CREDIT_NOTE' ? 'ใบลดหนี้ซื้อ / คืนสินค้า' : 'เอกสารซื้อ' }}</h1>
        @if($type === 'CREDIT_NOTE')
            <div class="alert alert-info border-0 py-2 mb-4"><i class="bx bx-info-circle me-1" aria-hidden="true"></i>เลือกใบตั้งหนี้ซื้อที่ลงบัญชีแล้ว จากนั้นตรวจรายการคืนสินค้าและจำนวน ระบบจะไม่ให้คืนเกินยอดของเอกสารต้นทาง</div>
        @endif
        <form id="purchase-document-form" class="purchase-mode-{{ strtolower($purchaseMode) }}" method="{{ $document->exists ? 'PUT' : 'POST' }}" action="{{ $document->exists ? route($moduleRoutePrefix.'.purchase-documents.update', $document) : route($moduleRoutePrefix.'.purchase-documents.store') }}">
            @csrf
            <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="row g-3">
                <div class="col-md-3"><label class="form-label" for="purchase-document-type">ประเภท</label>
                    @if($document->exists || ($documentTypeLocked ?? false))<input type="hidden" name="document_type" value="{{ $document->document_type }}"><input class="form-control" value="{{ $document->document_type === 'INVOICE' ? 'ใบตั้งหนี้ซื้อ' : 'ใบลดหนี้ซื้อ' }}" disabled>
                    @else<select class="form-select" id="purchase-document-type" name="document_type"><option value="INVOICE" @selected($type==='INVOICE')>ใบตั้งหนี้</option><option value="CREDIT_NOTE" @selected($type==='CREDIT_NOTE')>ใบลดหนี้</option></select>@endif
                    <div class="invalid-feedback" data-error-for="document_type"></div></div>
                <div class="col-md-3 js-credit-mode-wrap"><label class="form-label" for="purchase-credit-mode">รูปแบบใบลดหนี้</label><select class="form-select" id="purchase-credit-mode" name="credit_note_mode"><option value="NON_RETURN" @selected(old('credit_note_mode', $document->credit_note_mode ?? 'NON_RETURN')==='NON_RETURN')>ลดหนี้โดยไม่คืนสินค้า</option><option value="RETURN" @selected(old('credit_note_mode', $document->credit_note_mode ?? 'NON_RETURN')==='RETURN')>ลดหนี้จากการคืนสินค้า</option></select><div class="form-text">แบบไม่คืนสินค้าไม่มีผลต่อ Stock/Cost</div><div class="invalid-feedback" data-error-for="credit_note_mode"></div></div>
                <div class="col-md-3"><label class="form-label" for="purchase-mode">ประเภทการซื้อ</label><select class="form-select" id="purchase-mode" name="purchase_mode"><option value="INVENTORY" @selected($purchaseMode==='INVENTORY')>สินค้า / วัตถุดิบ</option><option value="EXPENSE" @selected($purchaseMode==='EXPENSE')>ค่าใช้จ่ายทั่วไป</option></select><div class="form-text">เลือกก่อนเริ่มเพิ่มรายการ</div><div class="invalid-feedback" data-error-for="purchase_mode"></div></div>
                <div class="col-md-3"><label class="form-label" for="purchase-document-date">วันที่เอกสาร</label><input class="form-control" id="purchase-document-date" type="date" name="document_date" value="{{ old('document_date', $document->document_date?->format('Y-m-d') ?? today()->toDateString()) }}" required><div class="invalid-feedback" data-error-for="document_date"></div></div>
                <div class="col-md-6"><label class="form-label" for="purchase-supplier">Supplier</label><select class="form-select" id="purchase-supplier" name="supplier_id" data-url="{{ route($moduleRoutePrefix.'.purchase-documents.supplier-options') }}" required>@if($selectedSupplier)<option value="{{ $selectedSupplier->id }}" selected>{{ $selectedSupplier->code }} · {{ $selectedSupplier->name }}</option>@endif</select><div class="form-text">เลือก Supplier ก่อนเลือก VAT และ Goods Receipt</div><div class="invalid-feedback" data-error-for="supplier_id"></div></div>
                <div class="col-md-6"><label class="form-label" for="purchase-payment-term">เงื่อนไขชำระเงิน</label><select class="form-select" id="purchase-payment-term" name="payment_term_id"><option value="">ไม่กำหนด</option>@foreach($paymentTerms as $term)<option value="{{ $term->id }}" @selected((string)old('payment_term_id',$document->payment_term_id)===(string)$term->id)>{{ $term->code }} · {{ $term->name }}</option>@endforeach</select><div class="invalid-feedback" data-error-for="payment_term_id"></div></div>
                <div class="col-md-3"><label class="form-label" for="purchase-tax-treatment">ภาษี</label><select class="form-select" id="purchase-tax-treatment" name="tax_treatment"><option value="NONE_VAT" @selected(old('tax_treatment',$document->tax_treatment)==='NONE_VAT')>NONE VAT</option><option value="VAT_IN" @selected(old('tax_treatment',$document->tax_treatment)==='VAT_IN')>VAT IN</option></select><div class="invalid-feedback" data-error-for="tax_treatment"></div></div>
                <div class="col-md-3 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="purchase-prices-include-vat" name="prices_include_vat" value="1" @checked(old('prices_include_vat',$document->prices_include_vat))><label class="form-check-label" for="purchase-prices-include-vat">ราคามี VAT แล้ว</label></div></div>
                <div class="col-md-4"><label class="form-label" for="purchase-wht-code">ภาษีหัก ณ ที่จ่าย</label><select class="form-select" id="purchase-wht-code" name="withholding_tax_code_id" @disabled($type==='CREDIT_NOTE')>@if($withholdingTaxCode)<option value="{{ $withholdingTaxCode->id }}" selected>{{ $withholdingTaxCode->code }} · {{ $withholdingTaxCode->name }} ({{ $withholdingTaxCode->rate }}%)</option>@endif</select><div class="invalid-feedback" data-error-for="withholding_tax_code_id"></div></div>
                <div class="col-md-2"><label class="form-label">ฐานหัก ณ ที่จ่าย</label><input class="form-control" type="number" step="0.01" min="0" name="withholding_base" value="{{ old('withholding_base',$document->withholding_base ?? '0.00') }}" @disabled($type==='CREDIT_NOTE')><div class="invalid-feedback" data-error-for="withholding_base"></div></div>
                <div class="col-md-6 js-original-wrap"><label class="form-label" for="purchase-original">ใบตั้งหนี้ซื้อที่อ้างอิง</label><select class="form-select" id="purchase-original" name="original_document_id" data-url="{{ route($moduleRoutePrefix.'.purchase-documents.original-options') }}">@if($selectedOriginal)<option value="{{ $selectedOriginal->id }}" selected>{{ $selectedOriginal->document_number }} · {{ $selectedOriginal->supplier_code ?? '' }}</option>@endif</select><div class="form-text">ต้องเป็นเอกสารของ Supplier และคลังเดียวกันที่มีสถานะลงบัญชีแล้ว</div><div class="invalid-feedback" data-error-for="original_document_id"></div></div>
                <div class="col-12"><label class="form-label" for="purchase-description">คำอธิบาย</label><textarea class="form-control" id="purchase-description" name="description" rows="2">{{ old('description',$document->description) }}</textarea></div>
            </div></div></div>

            <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3"><div><h2 class="h5 mb-1">รายการตั้งหนี้</h2><span class="badge {{ old('tax_treatment',$document->tax_treatment)==='VAT_IN' ? 'app-status-info' : 'app-status-neutral' }}" id="tax-label">{{ old('tax_treatment',$document->tax_treatment)==='VAT_IN' ? 'VAT IN' : 'NONE VAT' }}</span><span class="small text-secondary d-block mt-1" id="purchase-mode-hint"></span></div><div class="d-flex flex-wrap gap-2 align-items-center"><div class="purchase-gr-picker-wrap"><select id="purchase-gr-picker" class="form-select form-select-sm" data-url="{{ route($moduleRoutePrefix.'.purchase-documents.goods-receipt-options') }}" aria-label="เพิ่มรายการจาก Goods Receipt"><option value="">เพิ่มรายการจาก Goods Receipt</option></select></div><button class="btn btn-sm btn-app-soft js-expense-only" id="add-purchase-line" type="button"><i class="bx bx-plus me-1"></i><span>เพิ่มรายการบริการ</span></button></div></div>
                <div class="table-responsive"><table class="table align-middle purchase-lines-compact"><thead><tr><th>สินค้า/รายละเอียด</th><th>จำนวน/หน่วย</th><th>PO / GR</th><th>บัญชี/ภาษี</th><th>ราคา/ส่วนลด</th><th class="text-end">รวม</th><th class="text-end">จัดการ</th></tr></thead><tbody id="purchase-lines">
                    @foreach($rows as $index=>$row)@php($selectedItem=$lineItems->get((int)($row['item_id'] ?? 0))) @php($selectedUom=$lineUoms->get((int)($row['uom_id'] ?? 0))) @php($selectedAccount=$lineAccounts->get((int)($row['account_id'] ?? 0))) @php($selectedTax=$lineTaxCodes->get((int)($row['tax_code_id'] ?? 0)))<tr class="purchase-line"><td><input class="form-control" name="lines[{{ $index }}][description]" value="{{ $row['description'] ?? '' }}" required><select class="form-select js-purchase-item mt-1" name="lines[{{ $index }}][item_id]" data-url="{{ route($moduleRoutePrefix.'.purchase-documents.item-options') }}"><option value="">บริการ/ไม่ผูกสินค้า</option>@if($selectedItem)<option value="{{ $selectedItem->id }}" selected>{{ $selectedItem->code }} · {{ $selectedItem->name }}</option>@endif</select></td><td><input class="form-control text-end js-quantity" type="number" min="0.0001" step="0.0001" name="lines[{{ $index }}][quantity]" value="{{ $row['quantity'] ?? '1.0000' }}" required><select class="form-select js-purchase-uom mt-1" name="lines[{{ $index }}][uom_id]" data-url="{{ route($moduleRoutePrefix.'.purchase-documents.uom-options') }}"><option value="">เลือกหน่วย</option>@if($selectedUom)<option value="{{ $selectedUom->id }}" selected>{{ $selectedUom->code }} · {{ $selectedUom->name }}</option>@endif</select></td><td class="js-purchase-linkage-slot"></td><td><select class="form-select js-purchase-account" name="lines[{{ $index }}][account_id]" data-url="{{ route($moduleRoutePrefix.'.purchase-documents.account-options') }}" required>@if($selectedAccount)<option value="{{ $selectedAccount->id }}" selected>{{ $selectedAccount->code }} · {{ $selectedAccount->name }}</option>@endif</select><select class="form-select js-purchase-tax mt-1" name="lines[{{ $index }}][tax_code_id]" data-url="{{ route($moduleRoutePrefix.'.purchase-documents.tax-code-options') }}"><option value="">NONE</option>@if($selectedTax)<option value="{{ $selectedTax->id }}" selected>{{ $selectedTax->code }} · {{ $selectedTax->name }} ({{ $selectedTax->rate }}%)</option>@endif</select></td><td><input class="form-control text-end js-unit-price" type="number" min="0" step="0.0001" name="lines[{{ $index }}][unit_price]" value="{{ $row['unit_price'] ?? '0.0000' }}" required><input class="form-control text-end js-discount mt-1" type="number" min="0" step="0.01" name="lines[{{ $index }}][discount_amount]" value="{{ $row['discount_amount'] ?? '0.00' }}" required></td><td class="text-end js-line-total">0.00</td><td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-danger js-remove-line" title="ลบบรรทัด" aria-label="ลบบรรทัด" type="button"><i class="bx bx-trash" aria-hidden="true"></i></button></td></tr>@endforeach
                </tbody></table></div>
                <div class="d-flex justify-content-end"><div class="text-end"><div class="small text-secondary">ยอดรวม NONE VAT</div><div class="h4 mb-0" id="purchase-total">0.00</div></div></div>
                <div class="invalid-feedback d-block" data-error-for="lines"></div>
                <div class="mt-4"><button class="btn btn-dark" type="submit"><i class="bx bx-save me-1"></i>บันทึกร่าง</button><a class="btn btn-outline-secondary" href="{{ route($moduleRoutePrefix.'.purchase-documents.index') }}">ยกเลิก</a></div>
            </div></div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $lines = $('#purchase-lines');
            var accountUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.account-options') }}';
            var itemUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.item-options') }}';
            var uomUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.uom-options') }}';
            var taxUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.tax-code-options') }}';
            var whtUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.withholding-tax-code-options') }}';
            var poLineUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.purchase-order-line-options') }}';
            var receiptLineUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.goods-receipt-line-options') }}';
            var grOptionsUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.goods-receipt-options') }}';
            var grLinesUrl = '{{ route($moduleRoutePrefix.'.purchase-documents.goods-receipt-lines') }}';
            var linkageRows = @json($rows);
            var purchaseMode = @json($purchaseMode);
            var decimalPlaces = @json((int) app(\App\Modules\Settings\Services\GlobalSettings::class)->value('tax_decimal_places'));
            function formatConfiguredDecimal(value) { var number = Number(value || 0); return Number.isFinite(number) ? number.toFixed(decimalPlaces) : ''; }
            var selectedGoodsReceiptIds = {};
            @foreach(($selectedGoodsReceiptIds ?? collect()) as $goodsReceiptId)
                selectedGoodsReceiptIds[String(@json($goodsReceiptId))] = true;
            @endforeach
            function initLinkage($row, index, values) {
                if ($row.find('.js-purchase-linkage').length) return;
                if (!(values.item_id || $row.find('.js-purchase-item').val())) return;
                var poId = values.purchase_order_line_id || '';
                var allocation = (values.receipt_allocations || [])[0] || {};
                var grId = allocation.goods_receipt_line_id || '';
                var $cell = $('<div class="js-purchase-linkage"><div class="js-purchase-source-text small text-secondary">เลือกรายการจาก Goods Receipt ด้านบน</div><select class="form-select form-select-sm js-purchase-po-line" name="lines['+index+'][purchase_order_line_id]" data-url="'+poLineUrl+'"></select><div class="small text-secondary mt-1">Goods Receipt ที่นำมาตั้งหนี้</div><div class="js-receipt-allocation-list"><div class="js-receipt-allocation-row"><select class="form-select form-select-sm js-purchase-receipt-line mt-1" name="lines['+index+'][receipt_allocations][0][goods_receipt_line_id]" data-url="'+receiptLineUrl+'"></select><input type="number" class="form-control form-control-sm text-end js-purchase-receipt-qty mt-1" name="lines['+index+'][receipt_allocations][0][allocated_quantity]" value="'+(allocation.allocated_quantity || '')+'" min="0.0001" step="0.0001" placeholder="จำนวนที่ตั้งหนี้"></div></div><button type="button" class="btn btn-sm btn-app-soft mt-1 js-add-receipt-allocation"><i class="bx bx-plus me-1"></i>เพิ่ม GR</button></div>');
                $row.find('.js-purchase-linkage-slot').append($cell);
                var $po=$cell.find('.js-purchase-po-line'), $gr=$cell.find('.js-purchase-receipt-line');
                if (poId) $po.append(new Option('PO line #'+poId, poId, true, true));
                if (grId) $gr.append(new Option('Receipt line #'+grId, grId, true, true));
                $po.select2({width:'180px', placeholder:'เลือก PO line', allowClear:true, ajax:{url:poLineUrl,dataType:'json',delay:250,data:function(p){return {q:p.term||'',page:p.page||1,supplier_id:$('#purchase-supplier').val(),item_id:$row.find('.js-purchase-item').val()||''};},processResults:function(d){return d;},cache:true}});
                $gr.select2({width:'180px', placeholder:'เลือก Receipt line', allowClear:true, ajax:{url:receiptLineUrl,dataType:'json',delay:250,data:function(p){return {q:p.term||'',page:p.page||1,purchase_order_line_id:$po.val(),supplier_id:$('#purchase-supplier').val()};},processResults:function(d){return d;},cache:true}});
                $po.on('change',function(){ $gr.val(null).trigger('change'); if (!$(this).val()) $row.find('.js-purchase-receipt-qty').val(''); });
                $gr.on('select2:select',function(e){ $row.find('.js-purchase-receipt-qty').val(e.params.data.received_quantity || ''); });
                if (!poId) $gr.prop('disabled',true);
                $po.on('change',function(){ $gr.prop('disabled',!$(this).val()); });
                mergeInventoryFields($row);
            }
            function refreshInventorySummary($row) { var $link=$row.find('.js-purchase-linkage'); if (!$link.length) return; var po=$link.find('.js-purchase-po-line option:selected').text() || 'ยังไม่เลือก PO'; var gr=$link.find('.js-purchase-receipt-line option:selected').text() || 'ยังไม่เลือก GR'; var account=$row.find('.js-purchase-account option:selected').text() || 'รอบัญชีจาก Item Master'; var tax=$row.find('.js-purchase-tax option:selected').text() || 'ไม่มี Tax Code'; var text=po+' · '+gr+' · '+account+' · '+tax; var $summary=$link.find('.js-inventory-account-tax'); if (!$summary.length) { $summary=$('<div class="js-inventory-account-tax small text-secondary mt-2"></div>'); $link.append($summary); } $summary.text(text); }
            function mergeInventoryFields($row) { var $accountCell=$row.children('td').eq(3), $link=$row.find('.js-purchase-linkage'); if (purchaseMode !== 'INVENTORY') { if ($accountCell.data('moved')) { $accountCell.append($link.children('.js-purchase-account,.js-purchase-tax,.select2-container')); $accountCell.data('moved',false); } $accountCell.show(); return; } $accountCell.hide(); if ($link.length && !$accountCell.data('moved')) { $link.append($accountCell.children()); $accountCell.data('moved',true); } refreshInventorySummary($row); }
            function initAccount($select) { $select.select2({ width: '100%', placeholder: 'ค้นหาบัญชี', ajax: { url: accountUrl, dataType: 'json', delay: 250, data: function (params) { return { q: params.term || '', page: params.page || 1 }; }, processResults: function (data) { return data; }, cache: true } }); }
            function initItem($select) { $select.select2({ width: '100%', placeholder: purchaseMode === 'EXPENSE' ? 'ค้นหารายการบริการ' : 'ค้นหาสินค้า/วัตถุดิบ', allowClear: true, ajax: { url: itemUrl, dataType: 'json', delay: 250, data: function (params) { return { q: params.term || '', page: params.page || 1, item_type: purchaseMode === 'EXPENSE' ? 'SERVICE' : 'GOODS' }; }, processResults: function (data) { return data; }, cache: true } }); }
            function initUom($select) { $select.select2({ width: '100%', placeholder: 'เลือกหน่วย', allowClear: true, ajax: { url: uomUrl, dataType: 'json', delay: 250, data: function (params) { return { q: params.term || '', page: params.page || 1, item_id: $select.closest('.purchase-line').find('.js-purchase-item').val() || '' }; }, processResults: function (data) { return data; }, cache: true } }); }
            function initTax($select) { $select.select2({ width: '100%', placeholder: 'ค้นหา Tax Code', allowClear: true, ajax: { url: taxUrl, dataType: 'json', delay: 250, data: function (params) { return { q: params.term || '', page: params.page || 1 }; }, processResults: function (data) { return data; }, cache: true } }); }
            function fillGoodsReceiptRow($row, line, receiptId) {
                var index = $row.index();
                var $item = $row.find('.js-purchase-item'), $uom = $row.find('.js-purchase-uom');
                if (receiptId) $row.attr('data-goods-receipt-id', receiptId);
                $item.append(new Option(line.item_text || 'สินค้า', line.item_id, true, true)).trigger('change');
                $uom.append(new Option(line.uom_text || 'หน่วย', line.uom_id, true, true)).trigger('change');
                $row.find('[name$="[description]"]').val(line.description || 'สินค้า');
                $row.find('.js-quantity').val(formatConfiguredDecimal(line.quantity || '1'));
                $row.find('.js-unit-price').val(formatConfiguredDecimal(line.unit_price || '0'));
                if (line.account_id) { var $account=$row.find('.js-purchase-account'); $account.append(new Option(line.account_text || 'บัญชีสินค้าจาก Item Master', line.account_id, true, true)).trigger('change'); }
                setTimeout(function () {
                    var $cell = $row.find('.js-purchase-linkage'), $po = $cell.find('.js-purchase-po-line'), $gr = $cell.find('.js-purchase-receipt-line').first();
                    if (!$cell.length) return;
                    $po.append(new Option('PO line #'+line.purchase_order_line_id, line.purchase_order_line_id, true, true)).trigger('change');
                    $gr.append(new Option('GR line #'+line.receipt_line_id, line.receipt_line_id, true, true)).trigger('change');
                    $cell.find('.js-purchase-receipt-qty').first().val(formatConfiguredDecimal(line.quantity || ''));
                    $cell.find('.js-purchase-source-text').text('PO line #'+line.purchase_order_line_id+' · GR line #'+line.receipt_line_id);
                    refreshInventorySummary($row);
                    total();
                }, 0);
            }
            function reindex() { $lines.find('.purchase-line').each(function (index) { $(this).find('[name]').each(function () { this.name = this.name.replace(/lines\[\d+\]/, 'lines[' + index + ']'); }); }); }
            function total() { var sum = 0; $lines.find('.purchase-line').each(function () { var $row=$(this); var before=Math.round((parseFloat($row.find('.js-quantity').val())||0)*(parseFloat($row.find('.js-unit-price').val())||0)*100)/100; var value=Math.max(0,before-(parseFloat($row.find('.js-discount').val())||0)); $row.find('.js-line-total').text(value.toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2})); sum+=value; }); $('#purchase-total').text(sum.toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2})); }
            function togglePurchaseMode() { var inventory=purchaseMode==='INVENTORY', credit=$('[name="document_type"]').val()==='CREDIT_NOTE', nonReturn=credit && $('#purchase-credit-mode').val()==='NON_RETURN'; $('#purchase-document-form').toggleClass('purchase-mode-inventory',inventory).toggleClass('purchase-mode-expense',!inventory); $('.purchase-gr-picker-wrap').toggle(inventory && !nonReturn); $('.js-expense-only').toggle(!inventory); $('#purchase-gr-picker').prop('disabled', !inventory || credit || nonReturn || !$('#purchase-supplier').val()); $('#add-purchase-line span').text(inventory?'เพิ่มรายการสินค้า':'เพิ่มรายการบริการ'); $('#purchase-mode-hint').text(nonReturn?'Credit Note แบบไม่คืนสินค้า: ไม่ต้องเลือก Goods Receipt และไม่มีผลต่อ Stock/Cost':(inventory?'เลือก Goods Receipt เพื่อดึงสินค้า จำนวน และราคาจาก PO':'เลือกได้เฉพาะรายการประเภทบริการ และไม่ต้องอ้างอิง PO/GR')); $lines.find('.purchase-line').each(function(){ mergeInventoryFields($(this)); }); $lines.find('.js-purchase-item').each(function(){ var $select=$(this); if ($select.hasClass('select2-hidden-accessible')) $select.select2('destroy'); initItem($select); }); }
            function toggleType() { var credit=$('[name="document_type"]').val()==='CREDIT_NOTE'; $('.js-original-wrap,.js-credit-mode-wrap').toggle(credit); $('#purchase-original').prop('required',credit); $('#purchase-credit-mode').prop('disabled',!credit); $('#purchase-payment-term').prop('required',!credit); togglePurchaseMode(); if(!credit) $('#purchase-original').val(null).trigger('change'); }
            $('.js-purchase-account').each(function(){ initAccount($(this)); });
            $('.js-purchase-item').each(function(){ initItem($(this)); });
            $('.js-purchase-uom').each(function(){ initUom($(this)); });
            $('.js-purchase-tax').each(function(){ initTax($(this)); });
            $lines.find('.purchase-line').each(function(index){
                initLinkage($(this), index, linkageRows[index] || {});
                var values = linkageRows[index] || {}, allocations = values.receipt_allocations || [], $cell = $(this).find('.js-purchase-linkage');
                allocations.slice(1).forEach(function (allocation, allocationIndex) {
                    var lineIndex = index, rowIndex = allocationIndex + 1;
                    var $row = $('<div class="js-receipt-allocation-row mt-1"><select class="form-select form-select-sm js-purchase-receipt-line" name="lines['+lineIndex+'][receipt_allocations]['+rowIndex+'][goods_receipt_line_id]" data-url="'+receiptLineUrl+'"></select><input type="number" class="form-control form-control-sm text-end js-purchase-receipt-qty mt-1" name="lines['+lineIndex+'][receipt_allocations]['+rowIndex+'][allocated_quantity]" value="'+(allocation.allocated_quantity || '')+'" min="0.0001" step="0.0001" placeholder="จำนวนที่ตั้งหนี้"></div>');
                    $cell.find('.js-receipt-allocation-list').append($row);
                    var $select = $row.find('.js-purchase-receipt-line');
                    if (allocation.goods_receipt_line_id) $select.append(new Option('Receipt line #'+allocation.goods_receipt_line_id, allocation.goods_receipt_line_id, true, true));
                    $select.select2({width:'180px', placeholder:'เลือก Receipt line', allowClear:true, ajax:{url:receiptLineUrl,dataType:'json',delay:250,data:function(p){return {q:p.term||'',page:p.page||1,purchase_order_line_id:$cell.find('.js-purchase-po-line').val(),supplier_id:$('#purchase-supplier').val()};},processResults:function(d){return d;},cache:true}});
                });
            });
            $lines.on('click', '.js-add-receipt-allocation', function () {
                var $cell = $(this).closest('.js-purchase-linkage'), $po = $cell.find('.js-purchase-po-line'), lineMatch = ($cell.find('.js-purchase-receipt-line').first().attr('name') || '').match(/lines\[(\d+)\]/), lineIndex = lineMatch ? lineMatch[1] : 0, rowIndex = $cell.find('.js-purchase-receipt-line').length;
                var $row = $('<div class="js-receipt-allocation-row mt-1"><select class="form-select form-select-sm js-purchase-receipt-line" name="lines['+lineIndex+'][receipt_allocations]['+rowIndex+'][goods_receipt_line_id]"></select><input type="number" class="form-control form-control-sm text-end js-purchase-receipt-qty mt-1" name="lines['+lineIndex+'][receipt_allocations]['+rowIndex+'][allocated_quantity]" min="0.0001" step="0.0001" placeholder="จำนวนที่ตั้งหนี้"></div>');
                $cell.find('.js-receipt-allocation-list').append($row);
                $row.find('.js-purchase-receipt-line').select2({width:'180px', placeholder:'เลือก Receipt line', allowClear:true, ajax:{url:receiptLineUrl,dataType:'json',delay:250,data:function(p){return {q:p.term||'',page:p.page||1,purchase_order_line_id:$po.val(),supplier_id:$('#purchase-supplier').val()};},processResults:function(d){return d;},cache:true}});
            });
            $lines.on('select2:select', '.js-purchase-receipt-line', function (event) {
                var $select = $(this), selectedId = String(event.params.data.id || ''), duplicate = $lines.find('.js-purchase-receipt-line').filter(function () {
                    return this !== $select[0] && String($(this).val() || '') === selectedId;
                }).length > 0;
                if (duplicate) {
                    $select.val(null).trigger('change');
                    $select.closest('.js-receipt-allocation-row').find('.js-purchase-receipt-qty').val('');
                    Swal.fire({icon:'warning', title:'เลือก Goods Receipt ซ้ำไม่ได้', text:'กรุณาเลือก GR line อื่น หรือใช้ปริมาณที่เหลือของรายการเดิม'});
                }
            });
            $lines.on('change', '.js-purchase-item', function () { var $row = $(this).closest('.purchase-line'); initLinkage($row, $row.index(), { item_id: $(this).val() }); });
            $('#purchase-supplier').select2({ width:'100%', placeholder:'ค้นหา Supplier', ajax:{url:$('#purchase-supplier').data('url'),dataType:'json',delay:250,data:function(params){return{q:params.term||'',page:params.page||1};},processResults:function(data){return data;},cache:true} });
            $('#purchase-gr-picker').select2({ width:'100%', placeholder:'เพิ่มรายการจาก Goods Receipt', allowClear:true, ajax:{url:grOptionsUrl,dataType:'json',delay:250,data:function(params){return{q:params.term||'',page:params.page||1,supplier_id:$('#purchase-supplier').val()};},processResults:function(data){ data.results=(data.results||[]).filter(function(receipt){ return !selectedGoodsReceiptIds[String(receipt.id)]; }); return data;},cache:true} });
            $('#purchase-gr-picker').on('select2:select', function (event) {
                var receipt = event.params.data, supplierId = $('#purchase-supplier').val();
                if (!supplierId) return;
                if (selectedGoodsReceiptIds[String(receipt.id)]) {
                    $('#purchase-gr-picker').val(null).trigger('change');
                    Swal.fire({icon:'warning', title:'เลือก Goods Receipt ซ้ำไม่ได้', text:'เอกสาร Goods Receipt นี้ถูกนำมาใช้ในใบตั้งหนี้แล้ว'});
                    return;
                }
                selectedGoodsReceiptIds[String(receipt.id)] = true;
                $.getJSON(grLinesUrl, { goods_receipt_id: receipt.id, supplier_id: supplierId }).done(function (payload) {
                    (payload.lines || []).forEach(function (line, offset) {
                        var $target = $lines.find('.purchase-line').filter(function () { return !$(this).find('.js-purchase-item').val() && !$(this).find('[name$="[description]"]').val(); }).first();
                        if (!$target.length) { $('#add-purchase-line').trigger('click'); $target = $lines.find('.purchase-line').last(); }
                        fillGoodsReceiptRow($target, line, receipt.id);
                    });
                    $('#purchase-gr-picker').val(null).trigger('change');
                }).fail(function () { delete selectedGoodsReceiptIds[String(receipt.id)]; Swal.fire({ icon:'error', title:'โหลด Goods Receipt ไม่สำเร็จ', text:'กรุณาตรวจสอบ Supplier และสิทธิ์การเข้าถึงข้อมูล' }); });
            });
            $('#purchase-wht-code').select2({ width:'100%', placeholder:'ไม่หัก ณ ที่จ่าย', allowClear:true, ajax:{url:whtUrl,dataType:'json',delay:250,data:function(params){return{q:params.term||'',page:params.page||1};},processResults:function(data){return data;},cache:true} });
            $('#purchase-original').select2({ width:'100%', placeholder:'ค้นหาใบตั้งหนี้ที่ Post แล้ว', ajax:{url:$('#purchase-original').data('url'),dataType:'json',delay:250,data:function(params){return{q:params.term||'',page:params.page||1,supplier_id:$('#purchase-supplier').val()};},processResults:function(data){return data;},cache:true} });
            $('#purchase-supplier').on('change',function(){ selectedGoodsReceiptIds={}; $('#purchase-original').val(null).trigger('change'); $('#purchase-gr-picker').val(null).trigger('change').prop('disabled', purchaseMode!=='INVENTORY' || !$(this).val()); }); $('#purchase-mode').on('change',function(){ var next=$(this).val(), hasData=$lines.find('.js-purchase-item').filter(function(){ return $(this).val() || $(this).closest('.purchase-line').find('.js-purchase-linkage').length; }).length; if(hasData && !window.confirm('การเปลี่ยนประเภทการซื้อจะต้องเลือกข้อมูลรายการใหม่ ต้องการเปลี่ยนหรือไม่?')){ $(this).val(purchaseMode); return; } purchaseMode=next; selectedGoodsReceiptIds={}; $lines.find('.purchase-line').each(function(){ mergeInventoryFields($(this)); }); $lines.find('.js-purchase-linkage').remove(); $lines.find('.js-purchase-item,.js-purchase-uom').val(null).trigger('change'); togglePurchaseMode(); }); $('#purchase-gr-picker').prop('disabled', purchaseMode!=='INVENTORY' || !$('#purchase-supplier').val()); $('[name="document_type"]').on('change',toggleType); $('#purchase-tax-treatment').on('change',function(){ var vat=$(this).val()==='VAT_IN'; $('#tax-label').text(vat?'VAT IN':'NONE VAT'); $lines.find('.js-purchase-tax').prop('disabled',!vat); if(!vat) $lines.find('.js-purchase-tax').val(null).trigger('change'); });
            $('#add-purchase-line').on('click',function(){ var index=$lines.children().length; var $row=$('<tr class="purchase-line"><td><input class="form-control" name="lines['+index+'][description]" required><select class="form-select js-purchase-item mt-1" name="lines['+index+'][item_id]"><option value="">บริการ/ไม่ผูกสินค้า</option></select></td><td><input class="form-control text-end js-quantity" type="number" min="0.0001" step="0.0001" name="lines['+index+'][quantity]" value="1.0000" required><select class="form-select js-purchase-uom mt-1" name="lines['+index+'][uom_id]"><option value="">เลือกหน่วย</option></select></td><td class="js-purchase-linkage-slot"></td><td><select class="form-select js-purchase-account" name="lines['+index+'][account_id]" required></select><select class="form-select js-purchase-tax mt-1" name="lines['+index+'][tax_code_id]"><option value="">NONE</option></select></td><td><input class="form-control text-end js-unit-price" type="number" min="0" step="0.0001" name="lines['+index+'][unit_price]" value="0.0000" required><input class="form-control text-end js-discount mt-1" type="number" min="0" step="0.01" name="lines['+index+'][discount_amount]" value="0.00" required></td><td class="text-end js-line-total">0.00</td><td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-danger js-remove-line" title="ลบบรรทัด" aria-label="ลบบรรทัด" type="button"><i class="bx bx-trash" aria-hidden="true"></i></button></td></tr>'); $lines.append($row); initItem($row.find('.js-purchase-item')); initUom($row.find('.js-purchase-uom')); initAccount($row.find('.js-purchase-account')); initTax($row.find('.js-purchase-tax')); $row.find('.js-purchase-tax').prop('disabled',$('#purchase-tax-treatment').val()!=='VAT_IN'); });
            $lines.on('click','.js-remove-line',function(){ if($lines.children().length>1){ $(this).closest('tr').remove(); reindex(); total(); } }); $lines.on('input','.js-quantity,.js-unit-price,.js-discount',total);
            $('#purchase-credit-mode').on('change', togglePurchaseMode); toggleType(); $('#purchase-tax-treatment').trigger('change'); total(); window.erpAjaxForm({ form:'#purchase-document-form', redirect:true });
        });
    </script>
@endpush
