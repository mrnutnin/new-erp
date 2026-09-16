@php
    $statusLabels = ['DRAFT' => 'ร่าง', 'SUBMITTED' => 'รออนุมัติ', 'APPROVED' => 'อนุมัติแล้ว', 'REJECTED' => 'ตีกลับ', 'VOID' => 'ยกเลิก'];
    $branchLabel = $companyTaxBranchCode ? ($companyTaxBranchCode === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$companyTaxBranchCode) : null;
@endphp
<htmlpageheader name="purchase-requisition-reference"><div style="font-family:notosansthai;font-size:8.5pt;color:#737b83;text-align:right">{{ $companyName }} · {{ $requisition->document_number }} · {{ optional($requisition->document_date)->format($dateFormat) }}</div></htmlpageheader>
<sethtmlpageheader name="purchase-requisition-reference" value="on" show-this-page="1" />
<div class="pdf-tax-invoice">
    <table class="pdf-header"><tr>
        @if($logo)<td class="invoice-logo" width="16%"><img src="{{ $logo }}" style="width:24mm;max-height:22mm"></td>@endif
        <td class="pdf-header-company" width="{{ $logo ? '44%' : '60%' }}"><h1>{{ $companyName ?: 'บริษัท' }}</h1><div>{{ $companyAddress ?: '—' }}</div>@if($branchLabel)<div class="muted">{{ $branchLabel }}</div>@endif @if($companyTaxId)<div class="muted">เลขประจำตัวผู้เสียภาษี {{ $companyTaxId }}</div>@endif</td>
        <td class="pdf-header-title" width="40%"><div class="pdf-copy">เอกสารภายใน</div><h2>ใบขอซื้อ</h2><div class="invoice-number">{{ $requisition->document_number }}</div><div class="muted">สถานะ: {{ $statusLabels[$requisition->status] ?? $requisition->status }}</div></td>
    </tr></table>
    @if($requisition->status === 'DRAFT')<p class="pdf-watermark">ร่าง — ยังไม่ได้ส่งอนุมัติ</p>@endif
    @if($requisition->status === 'REJECTED')<p class="pdf-watermark">ตีกลับ — กรุณาแก้ไขก่อนส่งใหม่</p>@endif
    @if($requisition->status === 'VOID')<p class="pdf-watermark">ยกเลิกเอกสาร</p>@endif
    <table class="pdf-party"><tr>
        <td width="60%" class="invoice-buyer"><div class="pdf-label">วัตถุประสงค์การขอซื้อ</div><div class="pdf-value">{{ $requisition->description ?: 'ขออนุมัติจัดซื้อสินค้าตามรายการ' }}</div>@if($requisition->supplier)<div class="muted">ผู้ขายที่เสนอ: {{ $requisition->supplier->code }} · {{ $requisition->supplier->name }}</div>@else<div class="muted">ยังไม่ระบุผู้ขาย</div>@endif</td>
        <td width="40%" class="invoice-details"><table class="invoice-meta"><tr><td>วันที่เอกสาร</td><td class="right">{{ optional($requisition->document_date)->format($dateFormat) }}</td></tr><tr><td>คลังที่ขอซื้อ</td><td class="right">{{ $requisition->warehouse?->code }} · {{ $requisition->warehouse?->name }}</td></tr>@if($requisition->purchaseOrder)<tr><td>PO ที่สร้างแล้ว</td><td class="right">{{ $requisition->purchaseOrder->document_number }}</td></tr>@endif</table></td>
    </tr></table>
    <table class="pdf-product" autosize="1"><thead><tr><th width="5%">#</th><th width="45%" class="invoice-item-heading">สินค้า / รายละเอียด</th><th width="15%">จำนวนที่ขอ</th><th width="12%">หน่วย</th><th width="23%">หมายเหตุรายการ</th></tr></thead><tbody>
        @forelse($requisition->lines as $line)<tr><td class="center">{{ $line->line_number }}</td><td><div>{{ $line->item?->name ?: '—' }}</div>@if($line->item?->code)<div class="invoice-item-code">{{ $line->item->code }}</div>@endif</td><td class="right">{{ \App\Modules\Wms\Support\WmsDecimal::format($line->quantity, $decimalPlaces) }}</td><td class="center">{{ $line->uom?->code ?: '—' }}</td><td>{{ $line->description ?: '—' }}</td></tr>@empty<tr><td colspan="5" class="center">ไม่มีรายการสินค้า</td></tr>@endforelse
    </tbody></table>
</div>
<!-- invoice-closing -->
<div class="pdf-tax-invoice">
    <table class="pdf-footer"><tr><td width="62%" class="pdf-footer-payment"><div class="pdf-footer-heading">หมายเหตุและผลการพิจารณา</div><p class="pdf-note"><b>หมายเหตุ:</b> {{ $requisition->description ?: '—' }}</p>@if($requisition->rejection_reason)<p class="pdf-note"><b>เหตุผลตีกลับ:</b> {{ $requisition->rejection_reason }}</p>@endif @if($requisition->void_reason)<p class="pdf-note"><b>เหตุผลยกเลิก:</b> {{ $requisition->void_reason }}</p>@endif</td><td width="38%" class="pdf-footer-total"><table class="pdf-total-summary"><tr><td>จำนวนรายการ</td><td class="right">{{ $requisition->lines->count() }}</td></tr><tr class="total"><td>สถานะ</td><td class="right">{{ $statusLabels[$requisition->status] ?? $requisition->status }}</td></tr></table></td></tr></table>
    <table class="pdf-signatures"><tr><td width="31%"><div class="invoice-sign-space">&nbsp;</div><div>....................................</div><div>ผู้ขอซื้อ{{ $requisition->createdBy?->name ? ' · '.$requisition->createdBy->name : '' }}</div><div class="invoice-sign-date">วันที่ .......... / .......... / ..........</div></td><td width="3%"></td><td width="31%"><div class="invoice-sign-space">&nbsp;</div><div>....................................</div><div>ผู้ส่งอนุมัติ{{ $requisition->submittedBy?->name ? ' · '.$requisition->submittedBy->name : '' }}</div><div class="invoice-sign-date">{{ $requisition->submitted_at ? 'ส่ง '.$requisition->submitted_at->format('d/m/Y H:i') : 'วันที่ .......... / .......... / ..........' }}</div></td><td width="3%"></td><td width="32%"><div class="invoice-sign-space">&nbsp;</div><div>....................................</div><div>ผู้อนุมัติ{{ $requisition->approvedBy?->name ? ' · '.$requisition->approvedBy->name : '' }}</div><div class="invoice-sign-date">{{ $requisition->approved_at ? 'อนุมัติ '.$requisition->approved_at->format('d/m/Y H:i') : 'วันที่ .......... / .......... / ..........' }}</div></td></tr></table>
    <p class="pdf-control">{{ $requisition->document_number }} · ใบขอซื้อ · เอกสารภายใน</p>
</div>
