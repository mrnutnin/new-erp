@php
    $statusLabels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'VOID' => 'ยกเลิก'];
    $branchLabel = $companyTaxBranchCode ? ($companyTaxBranchCode === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$companyTaxBranchCode) : null;
    $supplierBranchLabel = $receipt->supplier?->branch_code ? ($receipt->supplier->branch_code === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$receipt->supplier->branch_code) : null;
    $totalCost = $receipt->lines->reduce(
        fn ($sum, $line) => $sum->plus((string) $line->total_cost),
        \Brick\Math\BigDecimal::zero(),
    )->toScale(2, \Brick\Math\RoundingMode::HALF_UP)->__toString();
@endphp
<htmlpageheader name="goods-receipt-reference"><div style="font-family:notosansthai;font-size:8.5pt;color:#737b83;text-align:right">{{ $companyName }} · {{ $receipt->receipt_number }} · {{ optional($receipt->business_date)->format($dateFormat) }}</div></htmlpageheader>
<sethtmlpageheader name="goods-receipt-reference" value="on" show-this-page="1" />
<div class="pdf-tax-invoice">
    <table class="pdf-header"><tr>
        @if($logo)<td class="invoice-logo" width="16%"><img src="{{ $logo }}" style="width:24mm;max-height:22mm"></td>@endif
        <td class="pdf-header-company" width="{{ $logo ? '44%' : '60%' }}"><h1>{{ $companyName ?: 'บริษัท' }}</h1><div>{{ $companyAddress ?: '—' }}</div>@if($branchLabel)<div class="muted">{{ $branchLabel }}</div>@endif @if($companyTaxId)<div class="muted">เลขประจำตัวผู้เสียภาษี {{ $companyTaxId }}</div>@endif</td>
        <td class="pdf-header-title" width="40%"><div class="pdf-copy">เอกสารรับสินค้า</div><h2>ใบรับสินค้า / GOODS RECEIPT</h2><div class="invoice-number">{{ $receipt->receipt_number }}</div><div class="muted">สถานะ: {{ $statusLabels[$receipt->status] ?? $receipt->status }}</div></td>
    </tr></table>
    @if($receipt->status === 'DRAFT')<p class="pdf-watermark">ร่าง — ยังไม่กระทบสินค้าคงคลัง</p>@endif
    @if($receipt->status === 'VOID')<p class="pdf-watermark">ยกเลิกเอกสาร</p>@endif
    <table class="pdf-party"><tr>
        <td width="60%" class="invoice-buyer"><div class="pdf-label">ผู้ขาย / Supplier</div><div class="pdf-value">{{ $receipt->supplier?->code }} · {{ $receipt->supplier?->name ?: '—' }}</div><div>{{ $receipt->supplier?->address ?: '—' }}</div>@if($receipt->supplier?->tax_id)<div class="muted">เลขประจำตัวผู้เสียภาษี {{ $receipt->supplier->tax_id }}</div>@endif @if($supplierBranchLabel)<div class="muted">{{ $supplierBranchLabel }}</div>@endif</td>
        <td width="40%" class="invoice-details"><table class="invoice-meta"><tr><td>เลขที่รับสินค้า</td><td class="right">{{ $receipt->receipt_number }}</td></tr><tr><td>วันที่รับสินค้า</td><td class="right">{{ optional($receipt->business_date)->format($dateFormat) }}</td></tr><tr><td>อ้างอิง PO</td><td class="right">{{ $receipt->purchaseOrder?->document_number ?: '—' }}</td></tr><tr><td>คลังรับสินค้า</td><td class="right">{{ $receipt->warehouse?->code }} · {{ $receipt->warehouse?->name }}</td></tr></table></td>
    </tr></table>
    <table class="pdf-product" autosize="1"><thead><tr><th width="5%">#</th><th width="33%" class="invoice-item-heading">สินค้า / รายละเอียด</th><th width="11%">จำนวนรับ</th><th width="9%">หน่วยซื้อ</th><th width="11%">จำนวนสต็อก</th><th width="9%">หน่วยสต็อก</th><th width="11%" class="right">ต้นทุน/หน่วย</th><th width="11%" class="right">ต้นทุนรวม</th></tr></thead><tbody>
        @forelse($receipt->lines as $line)<tr><td class="center">{{ $loop->iteration }}</td><td><div>{{ $line->item?->name ?: '—' }}</div>@if($line->item?->code)<div class="invoice-item-code">{{ $line->item->code }}</div>@endif</td><td class="right">{{ \App\Modules\Wms\Support\WmsDecimal::format($line->purchase_quantity, $decimalPlaces) }}</td><td class="center">{{ $line->purchaseUom?->code ?: '—' }}</td><td class="right">{{ \App\Modules\Wms\Support\WmsDecimal::format($line->stock_quantity, $decimalPlaces) }}</td><td class="center">{{ $line->stockUom?->code ?: '—' }}</td><td class="right">{{ \App\Modules\Wms\Support\WmsDecimal::format($line->stock_unit_cost, 2) }}</td><td class="right">{{ \App\Modules\Wms\Support\WmsDecimal::format($line->total_cost, 2) }}</td></tr>@empty<tr><td colspan="8" class="center">ไม่มีรายการรับสินค้า</td></tr>@endforelse
    </tbody></table>
</div>
<!-- invoice-closing -->
<div class="pdf-tax-invoice">
    <table class="pdf-footer"><tr><td width="55%" class="pdf-footer-payment"><div class="pdf-footer-heading">ข้อมูลการรับสินค้า</div><p class="muted">กรุณาตรวจสอบจำนวน หน่วยนับ และสภาพสินค้าก่อนลงนามรับ</p>@if($receipt->description)<p class="pdf-note"><b>หมายเหตุ:</b> {{ $receipt->description }}</p>@endif @if($receipt->void_reason)<p class="pdf-note"><b>เหตุผลยกเลิก:</b> {{ $receipt->void_reason }}</p>@endif</td><td width="45%" class="pdf-footer-total"><table class="pdf-total-summary"><tr class="total"><td>ต้นทุนรวม</td><td class="right">{{ \App\Modules\Wms\Support\WmsDecimal::format($totalCost, 2) }}</td></tr></table></td></tr></table>
    <x-platform::pdf-signatures :document="$receipt" :slots="[
        ['role' => 'prepared', 'label' => 'ผู้รับสินค้า', 'name' => $receipt->createdBy?->name],
        ['role' => 'approved', 'label' => 'ผู้อนุมัติ', 'name' => $receipt->approvedBy?->name],
    ]" />
    <p class="pdf-control">{{ $receipt->receipt_number }} · ใบรับสินค้า / GOODS RECEIPT</p>
</div>
