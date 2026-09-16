@php
    $statusLabels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก'];
    $isCreditNote = $document->document_type === 'CREDIT_NOTE';
    $title = $isCreditNote ? 'ใบลดหนี้ซื้อ' : 'ใบตั้งหนี้ซื้อ';
    $branchLabel = $companyTaxBranchCode ? ($companyTaxBranchCode === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$companyTaxBranchCode) : null;
    $supplierBranchLabel = $document->supplier_branch_code ? ($document->supplier_branch_code === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$document->supplier_branch_code) : null;
    $taxLabel = $document->tax_treatment === 'NONE_VAT' ? 'ไม่มีภาษี' : ($document->prices_include_vat ? 'รวมภาษี' : 'ภาษีนอก');
    $discountTotal = $document->lines->reduce(
        fn ($sum, $line) => $sum->plus((string) $line->discount_amount),
        \Brick\Math\BigDecimal::zero(),
    )->toScale(2, \Brick\Math\RoundingMode::HALF_UP)->__toString();
    $creditRemaining = $isCreditNote && $document->originalDocument
        ? \Brick\Math\BigDecimal::of((string) $document->originalDocument->gross_amount)->minus((string) $document->gross_amount)->max(\Brick\Math\BigDecimal::zero())->toScale(2, \Brick\Math\RoundingMode::HALF_UP)->__toString()
        : null;
    $format = fn ($value, $places = 2) => \App\Modules\Wms\Support\WmsDecimal::format($value, $places);
@endphp
<htmlpageheader name="purchase-document-reference"><div style="font-family:notosansthai;font-size:8.5pt;color:#737b83;text-align:right">{{ $companyName }} · {{ $document->document_number }} · {{ optional($document->document_date)->format($dateFormat) }}</div></htmlpageheader>
<sethtmlpageheader name="purchase-document-reference" value="on" show-this-page="1" />
<div class="pdf-tax-invoice pdf-readable">
    <table class="pdf-header"><tr>
        @if($logo)<td class="invoice-logo" width="16%"><img src="{{ $logo }}" style="width:24mm;max-height:22mm"></td>@endif
        <td class="pdf-header-company" width="{{ $logo ? '44%' : '60%' }}"><h1>{{ $companyName ?: 'บริษัท' }}</h1><div>{{ $companyAddress ?: '—' }}</div>@if($branchLabel)<div class="muted">{{ $branchLabel }}</div>@endif @if($companyTaxId)<div class="muted">เลขประจำตัวผู้เสียภาษี {{ $companyTaxId }}</div>@endif</td>
        <td class="pdf-header-title" width="40%"><div class="pdf-copy">สำเนาภายใน / INTERNAL COPY</div><h2>{{ $title }}</h2><div class="invoice-number">{{ $document->document_number }}</div><div class="muted">สถานะ: {{ $statusLabels[$document->status] ?? $document->status }}</div></td>
    </tr></table>
    @if($document->status === 'DRAFT')<p class="pdf-watermark">ร่าง — ยังไม่ผ่านการอนุมัติ</p>@endif
    @if($document->status === 'VOID')<p class="pdf-watermark">ยกเลิกเอกสาร</p>@endif
    <table class="pdf-party"><tr>
        <td width="60%" class="invoice-buyer"><div class="pdf-label">ผู้ขาย / Supplier</div><div class="pdf-value">{{ $document->supplier_code }} · {{ $document->supplier_name }}</div><div>{{ $document->supplier_address ?: '—' }}</div>@if($document->supplier_tax_id)<div class="muted">เลขประจำตัวผู้เสียภาษี {{ $document->supplier_tax_id }}</div>@endif @if($supplierBranchLabel)<div class="muted">{{ $supplierBranchLabel }}</div>@endif</td>
        <td width="40%" class="invoice-details"><table class="invoice-meta"><tr><td>เลขที่เอกสาร</td><td class="right">{{ $document->document_number }}</td></tr><tr><td>วันที่เอกสาร</td><td class="right">{{ optional($document->document_date)->format($dateFormat) }}</td></tr><tr><td>ครบกำหนด</td><td class="right">{{ optional($document->due_date)->format($dateFormat) ?: '—' }}</td></tr><tr><td>การคำนวณภาษี</td><td class="right">{{ $taxLabel }}</td></tr><tr><td>คลัง</td><td class="right">{{ $document->warehouse?->code }} · {{ $document->warehouse?->name }}</td></tr></table></td>
    </tr></table>
    @if($isCreditNote || $referencePos->isNotEmpty() || $referenceGrs->isNotEmpty())
        <table class="pdf-context"><tr>
            <td><div class="pdf-label">อ้างอิงใบตั้งหนี้เดิม</div><div class="pdf-value">{{ $document->originalDocument?->document_number ?: '—' }}</div></td>
            <td><div class="pdf-label">อ้างอิง PO</div><div class="pdf-value">{{ $referencePos->pluck('document_number')->implode(', ') ?: '—' }}</div></td>
            <td><div class="pdf-label">อ้างอิงใบรับสินค้า</div><div class="pdf-value">{{ $referenceGrs->pluck('receipt_number')->implode(', ') ?: '—' }}</div></td>
        </tr></table>
    @endif
    <p class="pdf-note"><b>ข้อควรทราบ:</b> เอกสารนี้เป็นสำเนาภายในสำหรับบันทึกเจ้าหนี้ ไม่ใช่ใบกำกับภาษีที่ออกโดยผู้ขาย</p>
    <table class="pdf-product" autosize="1"><thead><tr><th width="4%">#</th><th width="30%" class="invoice-item-heading">รายการ / บัญชี</th><th width="8%">จำนวน</th><th width="7%">หน่วย</th><th width="12%" class="right">ราคา/หน่วย</th><th width="9%" class="right">ส่วนลด</th><th width="11%" class="right">ฐานภาษี</th><th width="9%" class="right">VAT</th><th width="10%" class="right">รวม</th></tr></thead><tbody>
        @forelse($document->lines as $line)<tr><td class="center">{{ $line->line_number }}</td><td><div>{{ $line->item?->name ?: $line->description }}</div>@if($line->item?->code)<div class="invoice-item-code">{{ $line->item->code }}</div>@endif<div class="muted">{{ $line->account?->code }} · {{ $line->account?->name }}</div>@if($line->description && $line->description !== $line->item?->name)<div class="muted">{{ $line->description }}</div>@endif</td><td class="right">{{ $format($line->quantity, $decimalPlaces) }}</td><td class="center">{{ $line->uom?->code ?: '—' }}</td><td class="right">{{ $format($line->unit_price, 2) }}</td><td class="right">{{ $format($line->discount_amount, 2) }}</td><td class="right">{{ $format($line->tax_base, 2) }}</td><td class="right">{{ $format($line->tax_amount, 2) }}</td><td class="right">{{ $format($line->gross_amount, 2) }}</td></tr>@empty<tr><td colspan="9" class="center">ไม่มีรายการ</td></tr>@endforelse
    </tbody></table>
</div>
<!-- invoice-closing -->
<div class="pdf-tax-invoice pdf-readable">
    <table class="pdf-footer"><tr><td width="55%" class="pdf-footer-payment"><div class="pdf-footer-heading">รายละเอียดเอกสาร</div>@if($document->paymentTerm)<p class="muted">เงื่อนไขชำระ: {{ $document->paymentTerm->code }} · {{ $document->paymentTerm->name }}</p>@endif @if($document->description)<p class="pdf-note"><b>{{ $isCreditNote ? 'เหตุผลลดหนี้' : 'หมายเหตุ' }}:</b> {{ $document->description }}</p>@endif @if($document->approval_reason)<p class="pdf-note"><b>เหตุผลอนุมัติ:</b> {{ $document->approval_reason }}</p>@endif @if($document->void_reason)<p class="pdf-note"><b>เหตุผลยกเลิก:</b> {{ $document->void_reason }}</p>@endif</td><td width="45%" class="pdf-footer-total"><table class="pdf-total-summary"><tr><td>มูลค่าก่อนส่วนลด</td><td class="right">{{ $format(\Brick\Math\BigDecimal::of((string) $document->subtotal)->plus($discountTotal)->__toString(), 2) }}</td></tr>@if(\Brick\Math\BigDecimal::of($discountTotal)->isPositive())<tr><td>ส่วนลด</td><td class="right">{{ $format($discountTotal, 2) }}</td></tr>@endif<tr><td>ฐานภาษี</td><td class="right">{{ $format($document->subtotal, 2) }}</td></tr><tr><td>ภาษีมูลค่าเพิ่ม</td><td class="right">{{ $format($document->tax_amount, 2) }}</td></tr>@if(\Brick\Math\BigDecimal::of((string) $document->rounding_amount)->isZero() === false)<tr><td>ปัดเศษ</td><td class="right">{{ $format($document->rounding_amount, 2) }}</td></tr>@endif<tr class="total"><td>{{ $isCreditNote ? 'มูลค่าลดหนี้' : 'ยอดตั้งหนี้' }}</td><td class="right">{{ $format($document->gross_amount, 2) }}</td></tr>@if(\Brick\Math\BigDecimal::of((string) $document->withholding_amount)->isPositive())<tr><td>ข้อมูล WHT</td><td class="right">{{ $format($document->withholding_amount, 2) }}</td></tr>@endif @if($creditRemaining !== null)<tr><td>มูลค่าเอกสารเดิม</td><td class="right">{{ $format($document->originalDocument->gross_amount, 2) }}</td></tr><tr class="total"><td>คงเหลือหลังลดหนี้</td><td class="right">{{ $format($creditRemaining, 2) }}</td></tr>@endif</table></td></tr></table>
    <x-platform::pdf-signatures :document="$document" :slots="[
        ['role' => 'prepared', 'label' => 'ผู้จัดทำ', 'name' => $document->createdBy?->name],
        ['role' => 'approved', 'label' => 'ผู้อนุมัติ', 'name' => $document->approvedBy?->name],
        ['role' => 'posted', 'label' => 'ผู้ลงบัญชี', 'name' => $document->postedBy?->name],
    ]" />
    <p class="pdf-control">{{ $document->document_number }} · {{ $title }} · สำเนาภายใน</p>
</div>
