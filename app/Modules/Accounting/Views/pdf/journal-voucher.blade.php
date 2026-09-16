@php
    $statusLabels = ['DRAFT' => 'ร่าง', 'VALIDATED' => 'รออนุมัติ', 'POSTED' => 'ลงบัญชีแล้ว', 'REVERSED' => 'กลับรายการแล้ว'];
    $branchLabel = $companyTaxBranchCode ? ($companyTaxBranchCode === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$companyTaxBranchCode) : null;
    $money = fn ($value) => \App\Modules\Wms\Support\WmsDecimal::format($value, $decimalPlaces);
@endphp
<htmlpageheader name="journal-voucher-reference"><div style="font-family:notosansthai;font-size:8.5pt;color:#737b83;text-align:right">{{ $companyName }} · {{ $journalEntry->entry_number }} · {{ $journalEntry->entry_date?->format($dateFormat) }}</div></htmlpageheader>
<sethtmlpageheader name="journal-voucher-reference" value="on" show-this-page="1" />
<div class="pdf-tax-invoice">
    <table class="pdf-header"><tr>
        @if($logo)<td class="invoice-logo" width="16%"><img src="{{ $logo }}" style="width:24mm;max-height:22mm"></td>@endif
        <td class="pdf-header-company" width="{{ $logo ? '44%' : '60%' }}"><h1>{{ $companyName }}</h1><div>{{ $companyAddress ?: '—' }}</div>@if($branchLabel)<div class="muted">{{ $branchLabel }}</div>@endif @if($companyTaxId)<div class="muted">เลขประจำตัวผู้เสียภาษี {{ $companyTaxId }}</div>@endif</td>
        <td class="pdf-header-title" width="40%"><div class="pdf-copy">เอกสารภายใน</div><h2>ใบสำคัญการลงบัญชี</h2><div class="small muted">JOURNAL VOUCHER</div><div class="invoice-number">{{ $journalEntry->entry_number }}</div><div class="muted">สถานะ: {{ $statusLabels[$journalEntry->status] ?? $journalEntry->status }}</div></td>
    </tr></table>
    @if($journalEntry->status === 'DRAFT')<p class="pdf-watermark">ร่าง — ยังไม่ได้ส่งอนุมัติ</p>@endif
    @if($journalEntry->status === 'REVERSED')<p class="pdf-watermark">กลับรายการแล้ว</p>@endif
    <table class="pdf-party"><tr>
        <td width="58%"><div class="pdf-label">คำอธิบายรายการ</div><div class="pdf-value">{{ $journalEntry->description ?: '—' }}</div><div class="muted">สาขา / คลัง: {{ $journalEntry->branch?->code }} · {{ $journalEntry->branch?->name }} / {{ $journalEntry->warehouse?->code }} · {{ $journalEntry->warehouse?->name }}</div><div class="muted">อ้างอิง: {{ $journalEntry->source_reference ?: '—' }}</div></td>
        <td width="42%"><table class="invoice-meta"><tr><td>วันที่ลงบัญชี</td><td class="right">{{ $journalEntry->entry_date?->format($dateFormat) }}</td></tr><tr><td>วันที่เอกสาร</td><td class="right">{{ $journalEntry->document_date?->format($dateFormat) ?: '—' }}</td></tr><tr><td>สมุดบัญชี</td><td class="right">{{ $journalEntry->book?->code }} · {{ $journalEntry->book?->name }}</td></tr><tr><td>งวดบัญชี</td><td class="right">{{ $journalEntry->period?->fiscalYear?->name }} / {{ $journalEntry->period?->period_number }}</td></tr><tr><td>สกุลเงิน</td><td class="right">{{ $journalEntry->currency_code }} · {{ $journalEntry->exchange_rate }}</td></tr></table></td>
    </tr></table>
    <table class="pdf-product" autosize="1"><thead><tr><th width="5%">#</th><th width="28%">บัญชี</th><th width="31%">คำอธิบาย</th><th width="12%">ภาษี</th><th width="12%">เดบิต</th><th width="12%">เครดิต</th></tr></thead><tbody>
        @forelse($journalEntry->lines as $line)<tr><td class="center">{{ $line->line_number }}</td><td><div>{{ $line->account?->code }} · {{ $line->account?->name }}</div>@if($line->subledger_type && $line->subledger_id)<div class="muted small">{{ $line->subledger_type }} · {{ $line->subledger_id }}</div>@endif</td><td>{{ $line->description ?: '—' }}</td><td class="center">{{ $line->taxCode?->code ?: '—' }}</td><td class="right">{{ $money($line->debit) }}</td><td class="right">{{ $money($line->credit) }}</td></tr>@empty<tr><td colspan="6" class="center">ไม่มีรายการบัญชี</td></tr>@endforelse
    </tbody><tfoot><tr class="total"><td colspan="4" class="right">รวม</td><td class="right">{{ $money($debitTotal) }}</td><td class="right">{{ $money($creditTotal) }}</td></tr></tfoot></table>
</div>
<!-- invoice-closing -->
<div class="pdf-tax-invoice">
    <table class="pdf-footer"><tr><td width="62%" class="pdf-footer-payment"><div class="pdf-footer-heading">ข้อมูลอ้างอิงและการควบคุม</div><p class="pdf-note"><b>Source:</b> {{ $journalEntry->source_type ?: 'MANUAL' }}{{ $journalEntry->source_event ? ' · '.$journalEntry->source_event : '' }}</p>@if($journalEntry->reversalOf)<p class="pdf-note"><b>กลับจาก Journal:</b> {{ $journalEntry->reversalOf->entry_number }}</p>@elseif($journalEntry->reversal)<p class="pdf-note"><b>Journal กลับรายการ:</b> {{ $journalEntry->reversal->entry_number }}</p>@endif</td><td width="38%" class="pdf-footer-total"><table class="pdf-total-summary"><tr><td>รวมเดบิต</td><td class="right">{{ $money($debitTotal) }}</td></tr><tr><td>รวมเครดิต</td><td class="right">{{ $money($creditTotal) }}</td></tr><tr class="total"><td>ผลต่าง</td><td class="right">{{ $money($difference) }}</td></tr></table></td></tr></table>
    <x-platform::pdf-signatures :document="$journalEntry" :slots="[
        ['role' => 'prepared', 'label' => 'ผู้จัดทำ', 'name' => $journalEntry->createdBy?->name],
        ['role' => 'validated', 'label' => 'ผู้ตรวจสอบ', 'name' => $journalEntry->validatedBy?->name],
        ['role' => 'posted', 'label' => 'ผู้อนุมัติ', 'name' => $journalEntry->postedBy?->name],
    ]" />
    <p class="pdf-control">{{ $journalEntry->entry_number }} · ใบสำคัญการลงบัญชี · เอกสารภายใน</p>
</div>
