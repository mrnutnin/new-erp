@php
    $isHs = $sale->document_type === 'HS';
    $isFullTaxInvoice = $isHs && $sale->tax_invoice_type === 'FULL';
    $documentTitle = $isHs
        ? ($isFullTaxInvoice ? 'ใบเสร็จรับเงิน / ใบกำกับภาษี' : ($sale->tax_invoice_type === 'ABBREVIATED' ? 'ใบเสร็จรับเงิน / ใบกำกับภาษีอย่างย่อ' : 'ใบขายสด'))
        : 'ใบส่งสินค้า';
    $branchLabel = $companyTaxBranchCode === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$companyTaxBranchCode;
    $taxMethod = $sale->prices_include_vat ? 'ราคารวม VAT / VAT INCLUSIVE' : 'ราคาไม่รวม VAT / VAT EXCLUSIVE';
    $paymentDetailsOverflow = $paymentSummary['deposits']->count() + $sale->tenders->count() > 6;
    $hasAdvanceDeposit = (float) $paymentSummary['deposit_total'] > 0;
@endphp
<htmlpageheader name="invoice-reference"><div style="font-family:notosansthai;font-size:8.5pt;color:#737b83;text-align:right">{{ $companyName }} · {{ $sale->document_number }} · {{ optional($sale->document_date)->format($dateFormat) }}</div></htmlpageheader>
<sethtmlpageheader name="invoice-reference" value="on" show-this-page="1" />
<div class="pdf-tax-invoice">
<table class="pdf-header"><tr>
    @if($logo)<td class="invoice-logo" width="16%"><img src="{{ $logo }}" style="width:24mm;max-height:22mm"></td>@endif
    <td class="pdf-header-company" width="{{ $logo ? '44%' : '60%' }}">
        <h1>{{ $companyName }}</h1>
        <div>{{ $companyAddress ?: '—' }}</div>
        <div class="muted">{{ $branchLabel }}</div>
        <div class="muted">เลขประจำตัวผู้เสียภาษี {{ $companyTaxId ?: '—' }}</div>
    </td>
    <td class="pdf-header-title" width="40%">
        <div class="pdf-copy">{{ $sale->status === 'DRAFT' ? 'ร่าง' : ($sale->status === 'VOID' ? 'ยกเลิกเอกสาร' : 'ต้นฉบับ / ORIGINAL') }}</div>
        <h2>{{ $documentTitle }}</h2>
        <div class="invoice-number">{{ $sale->document_number }}</div>
    </td>
</tr></table>
@if($sale->status === 'DRAFT')<p class="pdf-watermark">ร่าง — ยังไม่ใช่เอกสารขายฉบับสมบูรณ์</p>@endif
@if($sale->status === 'VOID')<p class="pdf-watermark">ยกเลิกเอกสาร</p>@endif

<table class="pdf-party"><tr>
    <td width="60%" class="invoice-buyer">
        <div class="pdf-label">ลูกค้า / CUSTOMER</div>
        <div class="pdf-value">{{ $sale->party_name }}</div>
        <div>{{ $sale->party_address ?: '—' }}</div>
        @if($isFullTaxInvoice)
        <div class="muted">เลขประจำตัวผู้เสียภาษี {{ $sale->party_tax_id }}</div>
        <div class="muted">{{ $sale->party_branch_code === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$sale->party_branch_code }}</div>
        @endif
    </td>
    <td width="40%" class="invoice-details">
        <table class="invoice-meta">
            <tr><td>วันที่เอกสาร / DOCUMENT DATE</td><td class="right">{{ optional($sale->document_date)->format($dateFormat) }}</td></tr>
            @if($source)<tr><td>อ้างอิง / REFERENCE</td><td class="right">{{ $source->document_number }}</td></tr>@endif
            <tr><td>ภาษี / TAX</td><td class="right">{{ $taxMethod }}</td></tr>
        </table>
    </td>
</tr></table>

<table class="pdf-product" autosize="1"><thead><tr>
    <th width="5%">#</th><th width="37%" class="invoice-item-heading">รายการสินค้า / รายละเอียด</th>
    <th width="9%">จำนวน</th><th width="9%">หน่วย</th><th width="14%" class="right">ราคา/หน่วย</th><th width="12%" class="right">ส่วนลด</th><th width="14%" class="right">จำนวนเงิน</th>
</tr></thead><tbody>
@forelse($sale->lines as $line)
<tr>
    <td class="center">{{ $line->line_number }}</td>
    <td><div>{{ data_get($line->item_snapshot, 'name') }}</div><div class="invoice-item-code">{{ data_get($line->item_snapshot, 'code') }}</div></td>
    <td class="right">{{ number_format((float) $line->quantity, $decimalPlaces) }}</td>
    <td class="center">{{ $line->saleUom?->code ?: '—' }}</td>
    <td class="right">{{ number_format((float) $line->unit_price, 2) }}</td>
    <td class="right">{{ number_format((float) $line->discount_amount, 2) }}</td>
    <td class="right">{{ number_format((float) $line->line_total, 2) }}</td>
</tr>
@empty<tr><td colspan="7">ไม่มีรายการสินค้า</td></tr>@endforelse
</tbody></table>
@if($paymentDetailsOverflow)
    @include('Pos::pdf.partials.physical-sale-payments')
@endif
</div>
<!-- invoice-closing -->
<div class="pdf-tax-invoice">
<table class="pdf-footer"><tr>
    <td width="55%" class="pdf-footer-payment">
        @if($paymentDetailsOverflow)
            <div class="pdf-footer-heading">สรุปการชำระเงิน</div>
            <p class="muted">รายละเอียดเงินมัดจำและการรับชำระแสดงก่อนส่วนสรุปยอด</p>
        @else
            @include('Pos::pdf.partials.physical-sale-payments')
        @endif
    </td>
    <td width="45%" class="pdf-footer-total">
        <table class="pdf-total-summary">
            <tr><td>ยอดรวมก่อนส่วนลด{{ $sale->prices_include_vat ? ' (รวม VAT)' : '' }}</td><td class="right">{{ number_format((float) $sale->subtotal, 2) }}</td></tr>
            <tr><td>ส่วนลด</td><td class="right">{{ number_format((float) $sale->discount_amount, 2) }}</td></tr>
            <tr><td>มูลค่าสินค้าหรือบริการ<br><span class="muted">ก่อนภาษีมูลค่าเพิ่ม</span></td><td class="right">{{ number_format((float) $sale->tax_base, 2) }}</td></tr>
            <tr><td>ภาษีมูลค่าเพิ่ม</td><td class="right">{{ number_format((float) $sale->tax_amount, 2) }}</td></tr>
            <tr class="total"><td>รวมทั้งสิ้น</td><td class="right">{{ number_format((float) $sale->total_amount, 2) }}</td></tr>
            @if($isHs && $sale->status === 'POSTED')
            @if($hasAdvanceDeposit)
            <tr><td>หักเงินรับมัดจำ</td><td class="right">{{ number_format((float) $paymentSummary['deposit_total'], 2) }}</td></tr>
            @endif
            @if((float) $sale->withholding_amount > 0)<tr><td>หัก ณ ที่จ่าย</td><td class="right">{{ number_format((float) $sale->withholding_amount, 2) }}</td></tr>@endif
            @if($hasAdvanceDeposit)
            <tr class="invoice-net"><td>ยอดชำระหลังหัก</td><td class="right">{{ number_format((float) $paymentSummary['net'], 2) }}</td></tr>
            <tr><td>รับชำระแล้ว</td><td class="right">{{ number_format((float) $paymentSummary['received'], 2) }}</td></tr>
            <tr><td>{{ str_starts_with($paymentSummary['remaining'], '-') ? 'รับเกิน' : 'คงเหลือชำระ' }}</td><td class="right">{{ number_format(abs((float) $paymentSummary['remaining']), 2) }}</td></tr>
            @endif
            @endif
        </table>
    </td>
</tr></table>
<p class="pdf-note"><b>หมายเหตุ:</b> {{ $sale->description ?: '—' }}</p>
@if($sale->status === 'POSTED')
<table class="pdf-signatures"><tr>
    <td width="45%"><div class="invoice-sign-space">&nbsp;</div><div>........................................................</div><div>ผู้ซื้อ / ผู้รับสินค้า</div><div class="invoice-sign-date">วันที่ .......... / .......... / ..........</div></td>
    <td width="10%" class="invoice-sign-gap"></td>
    <td width="45%"><div class="invoice-sign-space">&nbsp;</div><div>........................................................</div><div>ผู้รับเงิน / ผู้มีอำนาจลงนาม</div><div class="invoice-sign-date">วันที่ .......... / .......... / ..........</div></td>
</tr></table>
@endif
<p class="pdf-control">{{ $sale->document_number }} · {{ $isFullTaxInvoice ? 'ใบกำกับภาษีเต็มรูป' : $documentTitle }}</p>
</div>
