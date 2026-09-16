@php
    $statusLabels = ['DRAFT' => 'ร่าง', 'SUBMITTED' => 'รออนุมัติ', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก'];
    $basisLabels = ['VALUE' => 'ตามมูลค่า', 'QUANTITY' => 'ตามจำนวน', 'WEIGHT' => 'ตามน้ำหนัก'];
    $branchLabel = $companyTaxBranchCode ? ($companyTaxBranchCode === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$companyTaxBranchCode) : null;
    $money = fn ($value) => \App\Modules\Wms\Support\WmsDecimal::format($value, 2);
    $number = fn ($value) => \App\Modules\Wms\Support\WmsDecimal::format($value, $decimalPlaces);
@endphp
<htmlpageheader name="landed-cost-reference"><div style="font-family:notosansthai;font-size:8.5pt;color:#737b83;text-align:right">{{ $companyName }} · {{ $document->document_number }} · {{ optional($document->business_date)->format($dateFormat) }}</div></htmlpageheader>
<sethtmlpageheader name="landed-cost-reference" value="on" show-this-page="1" />
<div class="pdf-tax-invoice">
    <table class="pdf-header"><tr>
        @if($logo)<td class="invoice-logo" width="16%"><img src="{{ $logo }}" style="width:24mm;max-height:22mm"></td>@endif
        <td class="pdf-header-company" width="{{ $logo ? '44%' : '60%' }}"><h1>{{ $companyName ?: 'บริษัท' }}</h1><div>{{ $companyAddress ?: '—' }}</div>@if($branchLabel)<div class="muted">{{ $branchLabel }}</div>@endif @if($companyTaxId)<div class="muted">เลขประจำตัวผู้เสียภาษี {{ $companyTaxId }}</div>@endif</td>
        <td class="pdf-header-title" width="40%"><div class="pdf-copy">เอกสารภายใน</div><h2>ใบสรุปต้นทุนแฝง</h2><div class="invoice-number">{{ $document->document_number }}</div><div class="muted">สถานะ: {{ $statusLabels[$document->status] ?? $document->status }}</div></td>
    </tr></table>
    @if($document->status === 'DRAFT')<p class="pdf-watermark">ร่าง — ยังไม่ได้ส่งอนุมัติ</p>@endif
    @if($document->status === 'VOID')<p class="pdf-watermark">ยกเลิกเอกสาร</p>@endif
    <table class="pdf-party"><tr>
        <td width="60%" class="invoice-buyer"><div class="pdf-label">ขอบเขตการปันส่วน</div><div class="pdf-value">คลัง {{ $document->warehouse?->code }} · {{ $document->warehouse?->name }}</div><div class="muted">อ้างอิง Goods Receipt {{ $document->receipts->count() }} เอกสาร · {{ $targets->count() }} รายการสินค้า</div></td>
        <td width="40%" class="invoice-details"><table class="invoice-meta"><tr><td>วันที่เอกสาร</td><td class="right">{{ optional($document->business_date)->format($dateFormat) }}</td></tr><tr><td>วิธีปันส่วน</td><td class="right">{{ $basisLabels[$document->allocation_basis] ?? $document->allocation_basis }}</td></tr><tr><td>สกุลเงิน</td><td class="right">{{ $document->currency_code }}</td></tr></table></td>
    </tr></table>
    <h3>ค่าใช้จ่ายที่นำมาปันส่วน</h3>
    <table class="pdf-product" autosize="1"><thead><tr><th width="5%">#</th><th width="28%" class="invoice-item-heading">บัญชีค่าใช้จ่าย</th><th width="18%">แหล่งที่มา</th><th width="31%" class="invoice-item-heading">รายละเอียด</th><th width="18%" class="right">จำนวนเงิน</th></tr></thead><tbody>
        @forelse($document->lines as $line)<tr><td class="center">{{ $loop->iteration }}</td><td>{{ $line->account?->code }}<div class="invoice-item-code">{{ $line->account?->name }}</div></td><td class="center">{{ $line->expense_source_type }}{{ $line->expense_source_id ? ' #'.$line->expense_source_id : '' }}</td><td>{{ $line->description ?: '—' }}</td><td class="right">{{ $money($line->amount) }}</td></tr>@empty<tr><td colspan="5" class="center">ไม่มีรายการค่าใช้จ่าย</td></tr>@endforelse
    </tbody></table>
    <h3>ผลการปันส่วนตามรายการรับสินค้า</h3>
    <table class="pdf-product" autosize="1"><thead><tr><th width="5%">#</th><th width="15%">Goods Receipt</th><th width="23%" class="invoice-item-heading">สินค้า</th><th width="10%">จำนวน</th><th width="9%">สัดส่วน</th><th width="12%" class="right">มูลค่าก่อน</th><th width="12%" class="right">ต้นทุนเพิ่ม</th><th width="14%" class="right">มูลค่าหลัง</th></tr></thead><tbody>
        @forelse($targets as $target)<tr><td class="center">{{ $loop->iteration }}</td><td class="center">{{ $target['receipt_number'] ?: '—' }}</td><td>{{ $target['item']?->name ?: '—' }}@if($target['item']?->code)<div class="invoice-item-code">{{ $target['item']->code }}</div>@endif</td><td class="right">{{ $number($target['quantity']) }} {{ $target['uom']?->code }}</td><td class="right">{{ $number(\Brick\Math\BigDecimal::of((string) $target['ratio'])->multipliedBy(100)) }}%</td><td class="right">{{ $money($target['before']) }}</td><td class="right">{{ $money($target['added']) }}</td><td class="right">{{ $money($target['after']) }}</td></tr>@empty<tr><td colspan="8" class="center">ไม่มีผลการปันส่วน</td></tr>@endforelse
    </tbody></table>
</div>
<!-- invoice-closing -->
<div class="pdf-tax-invoice">
    <table class="pdf-footer"><tr><td width="55%" class="pdf-footer-payment"><div class="pdf-footer-heading">เอกสารรับสินค้าที่อ้างอิง</div>@forelse($document->receipts as $receipt)<p class="invoice-tender">{{ $receipt->goodsReceipt?->receipt_number ?: '—' }} · ฐานจัดสรร {{ $number($receipt->selected_value) }} · ต้นทุนเพิ่ม {{ $money($receipt->allocated_amount) }}</p>@empty<p class="muted">ไม่มีเอกสารรับสินค้าที่อ้างอิง</p>@endforelse<p class="pdf-note">เอกสารนี้เป็นรายงานการปันส่วนต้นทุนภายใน ไม่ใช่ใบกำกับภาษีหรือหลักฐานการชำระเงิน</p></td><td width="45%" class="pdf-footer-total"><table class="pdf-total-summary"><tr><td>มูลค่าก่อนปันส่วน</td><td class="right">{{ $money($targets->reduce(fn ($sum, $target) => $sum->plus($target['before']), \Brick\Math\BigDecimal::zero())) }}</td></tr><tr><td>ต้นทุนที่ปันส่วน</td><td class="right">{{ $money($document->total_amount) }}</td></tr><tr class="total"><td>มูลค่าหลังปันส่วน</td><td class="right">{{ $money($targets->reduce(fn ($sum, $target) => $sum->plus($target['after']), \Brick\Math\BigDecimal::zero())) }}</td></tr></table></td></tr></table>
    <table class="pdf-signatures"><tr><td width="45%"><div class="invoice-sign-space">&nbsp;</div><div>........................................................</div><div>ผู้จัดทำ{{ $document->createdBy?->name ? ' · '.$document->createdBy->name : '' }}</div><div class="invoice-sign-date">วันที่ .......... / .......... / ..........</div></td><td width="10%" class="invoice-sign-gap"></td><td width="45%"><div class="invoice-sign-space">&nbsp;</div><div>........................................................</div><div>{{ $document->status === 'POSTED' ? 'ผู้ลงบัญชี' : 'ผู้ตรวจสอบ/อนุมัติ' }}{{ $document->postedBy?->name ? ' · '.$document->postedBy->name : '' }}</div><div class="invoice-sign-date">{{ $document->posted_at ? 'ลงบัญชี '.$document->posted_at->format('d/m/Y H:i') : 'วันที่ .......... / .......... / ..........' }}</div></td></tr></table>
    <p class="pdf-control">{{ $document->document_number }} · ใบสรุปต้นทุนแฝง · เอกสารภายใน</p>
</div>
