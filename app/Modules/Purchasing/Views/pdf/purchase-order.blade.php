@php
    $statusLabels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'VOID' => 'ยกเลิก'];
    $branchLabel = $companyTaxBranchCode ? ($companyTaxBranchCode === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$companyTaxBranchCode) : null;
    $supplierBranchLabel = $order->supplier?->branch_code ? ($order->supplier->branch_code === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$order->supplier->branch_code) : null;
    $hasVat = $order->tax_treatment === 'VAT_IN';
    $taxLabel = $hasVat ? ($order->prices_include_vat ? 'รวมภาษี' : 'ภาษีนอก') : 'ไม่มีภาษี';
    $money = fn ($value) => number_format((float) $value, 2);
@endphp
<htmlpageheader name="purchase-order-reference"><div style="font-family:notosansthai;font-size:8.5pt;color:#737b83;text-align:right">{{ $companyName }} · {{ $order->document_number }} · {{ optional($order->document_date)->format($dateFormat) }}</div></htmlpageheader>
<sethtmlpageheader name="purchase-order-reference" value="on" show-this-page="1" />
<div class="pdf-tax-invoice">
    <table class="pdf-header"><tr>
        @if($logo)<td class="invoice-logo" width="16%"><img src="{{ $logo }}" style="width:24mm;max-height:22mm"></td>@endif
        <td class="pdf-header-company" width="{{ $logo ? '44%' : '60%' }}"><h1>{{ $companyName ?: 'บริษัท' }}</h1><div>{{ $companyAddress ?: '—' }}</div>@if($branchLabel)<div class="muted">{{ $branchLabel }}</div>@endif @if($companyTaxId)<div class="muted">เลขประจำตัวผู้เสียภาษี {{ $companyTaxId }}</div>@endif</td>
        <td class="pdf-header-title" width="40%"><div class="pdf-copy">เอกสารทางการค้า</div><h2>ใบสั่งซื้อ / PURCHASE ORDER</h2><div class="invoice-number">{{ $order->document_number }}</div><div class="muted">สถานะ: {{ $statusLabels[$order->status] ?? $order->status }}</div></td>
    </tr></table>
    @if($order->status === 'DRAFT')<p class="pdf-watermark">ร่าง — ยังไม่ใช่เอกสารที่อนุมัติแล้ว</p>@endif
    @if($order->status === 'VOID')<p class="pdf-watermark">ยกเลิกเอกสาร</p>@endif
    <table class="pdf-party"><tr>
        <td width="60%" class="invoice-buyer"><div class="pdf-label">ผู้ขาย / Supplier</div><div class="pdf-value">{{ $order->supplier_code }} · {{ $order->supplier_name }}</div><div>{{ $order->supplier?->address ?: '—' }}</div>@if($order->supplier?->tax_id)<div class="muted">เลขประจำตัวผู้เสียภาษี {{ $order->supplier->tax_id }}</div>@endif @if($supplierBranchLabel)<div class="muted">{{ $supplierBranchLabel }}</div>@endif @if($order->supplier?->contact_name || $order->supplier?->phone)<div class="muted">ติดต่อ {{ $order->supplier?->contact_name ?: '—' }}{{ $order->supplier?->phone ? ' · '.$order->supplier->phone : '' }}</div>@endif</td>
        <td width="40%" class="invoice-details"><table class="invoice-meta"><tr><td>เลขที่</td><td class="right">{{ $order->document_number }}</td></tr><tr><td>วันที่เอกสาร</td><td class="right">{{ optional($order->document_date)->format($dateFormat) }}</td></tr><tr><td>กำหนดรับสินค้า</td><td class="right">{{ optional($order->expected_date)->format($dateFormat) ?: '—' }}</td></tr><tr><td>เงื่อนไขชำระ</td><td class="right">{{ $order->paymentTerm ? $order->paymentTerm->code.' · '.$order->paymentTerm->name : '—' }}</td></tr><tr><td>การคำนวณภาษี</td><td class="right">{{ $taxLabel }}</td></tr>@if($order->purchaseRequisition)<tr><td>อ้างอิง PR</td><td class="right">{{ $order->purchaseRequisition->document_number }}</td></tr>@endif</table></td>
    </tr></table>
    <table class="pdf-product" autosize="1"><thead><tr><th width="5%">#</th><th width="35%" class="invoice-item-heading">รายการสินค้า / รายละเอียด</th><th width="10%">จำนวน</th><th width="9%">หน่วย</th><th width="14%" class="right">ราคา/หน่วย</th><th width="11%" class="right">VAT</th><th width="16%" class="right">รวม</th></tr></thead><tbody>
        @forelse($order->lines as $line)<tr><td class="center">{{ $line->line_number }}</td><td><div>{{ $line->item?->name ?: $line->description }}</div>@if($line->item?->code)<div class="invoice-item-code">{{ $line->item->code }}</div>@endif @if($line->description && $line->description !== $line->item?->name)<div class="muted">{{ $line->description }}</div>@endif</td><td class="right">{{ number_format((float) $line->quantity, $decimalPlaces) }}</td><td class="center">{{ $line->uom?->code ?: '—' }}</td><td class="right">{{ $money($line->unit_price) }}</td><td class="right">{{ $hasVat ? $money($line->tax_amount) : '—' }}</td><td class="right">{{ $money($line->gross_amount ?: $line->line_total) }}</td></tr>@empty<tr><td colspan="7" class="center">ไม่มีรายการสินค้า</td></tr>@endforelse
    </tbody></table>
</div>
<!-- invoice-closing -->
<div class="pdf-tax-invoice">
    <table class="pdf-footer"><tr><td width="55%" class="pdf-footer-payment"><div class="pdf-footer-heading">เงื่อนไขการสั่งซื้อ</div><p class="muted">เอกสารนี้เป็นใบสั่งซื้อ ไม่ใช่ใบกำกับภาษีหรือหลักฐานการชำระเงิน</p>@if($order->description)<p class="pdf-note"><b>หมายเหตุ:</b> {{ $order->description }}</p>@endif</td><td width="45%" class="pdf-footer-total"><table class="pdf-total-summary"><tr><td>มูลค่าก่อน VAT</td><td class="right">{{ $money($order->subtotal) }}</td></tr>@if($hasVat)<tr><td>ภาษีมูลค่าเพิ่ม</td><td class="right">{{ $money($order->tax_amount) }}</td></tr>@endif<tr class="total"><td>รวมทั้งสิ้น</td><td class="right">{{ $money($order->total_amount) }}</td></tr></table></td></tr></table>
    <table class="pdf-signatures"><tr><td width="45%"><div class="invoice-sign-space">&nbsp;</div><div>........................................................</div><div>ผู้จัดทำ{{ $order->createdBy?->name ? ' · '.$order->createdBy->name : '' }}</div><div class="invoice-sign-date">วันที่ .......... / .......... / ..........</div></td><td width="10%" class="invoice-sign-gap"></td><td width="45%"><div class="invoice-sign-space">&nbsp;</div><div>........................................................</div><div>ผู้อนุมัติ{{ $order->approvedBy?->name ? ' · '.$order->approvedBy->name : '' }}</div><div class="invoice-sign-date">{{ $order->approved_at ? 'อนุมัติ '.$order->approved_at->format('d/m/Y H:i') : 'วันที่ .......... / .......... / ..........' }}</div></td></tr></table>
    <p class="pdf-control">{{ $order->document_number }} · ใบสั่งซื้อ / PURCHASE ORDER</p>
</div>
