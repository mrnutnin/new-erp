@php
    $statusLabels = ['DRAFT' => 'ร่าง', 'SUBMITTED' => 'รออนุมัติ', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'REJECTED' => 'ตีกลับ', 'VOID' => 'ยกเลิก'];
    $branchLabel = $companyTaxBranchCode ? ($companyTaxBranchCode === '00000' ? 'สำนักงานใหญ่' : 'สาขา '.$companyTaxBranchCode) : $branchName;
    $money = fn ($value) => \App\Modules\Wms\Support\WmsDecimal::format($value, 2);
@endphp
<htmlpageheader name="purchasing-report-reference"><div style="font-family:notosansthai;font-size:8.5pt;color:#737b83;text-align:right">{{ $companyName }} · รายงานปฏิบัติการจัดซื้อ · {{ $dateFrom->format($dateFormat) }}–{{ $dateTo->format($dateFormat) }}</div></htmlpageheader>
<sethtmlpageheader name="purchasing-report-reference" value="on" show-this-page="1" />
<div class="pdf-tax-invoice pdf-readable">
    <table class="pdf-header"><tr>
        @if($logo)<td class="invoice-logo" width="16%"><img src="{{ $logo }}" style="width:24mm;max-height:22mm"></td>@endif
        <td class="pdf-header-company" width="{{ $logo ? '44%' : '60%' }}"><h1>{{ $companyName ?: 'บริษัท' }}</h1><div>{{ $companyAddress ?: '—' }}</div>@if($branchLabel)<div class="muted">{{ $branchLabel }}</div>@endif @if($companyTaxId)<div class="muted">เลขประจำตัวผู้เสียภาษี {{ $companyTaxId }}</div>@endif</td>
        <td class="pdf-header-title" width="40%"><div class="pdf-copy">เอกสารภายใน</div><h2>รายงานปฏิบัติการจัดซื้อ</h2><div class="muted">ข้อมูล ณ เวลาที่สร้างรายงาน</div></td>
    </tr></table>
    <table class="pdf-party"><tr>
        <td width="55%" class="invoice-buyer"><div class="pdf-label">ช่วงวันที่เอกสาร</div><div class="pdf-value">{{ $dateFrom->format($dateFormat) }} – {{ $dateTo->format($dateFormat) }}</div><div class="muted">คลัง: {{ $warehouses->map(fn ($warehouse) => $warehouse->code.' · '.$warehouse->name)->implode(', ') ?: 'ไม่มีคลังที่ได้รับสิทธิ์' }}</div></td>
        <td width="45%" class="invoice-details"><table class="invoice-meta"><tr><td>วันที่สร้างรายงาน</td><td class="right">{{ $generatedAt->format('d/m/Y H:i') }}</td></tr><tr><td>สร้างโดย</td><td class="right">{{ $generatedBy }}</td></tr><tr><td>จำนวนประเภทเอกสาร</td><td class="right">{{ $sections->count() }}</td></tr></table></td>
    </tr></table>

    @foreach($sections as $section)
        <h3>{{ $section['title'] }}</h3>
        <table class="pdf-product" autosize="1"><thead><tr><th width="50%" class="invoice-item-heading">สถานะ</th><th width="20%" class="right">จำนวนเอกสาร</th><th width="30%" class="right">มูลค่ารวม (บาท)</th></tr></thead><tbody>
            @forelse($section['rows'] as $row)<tr><td>{{ $statusLabels[$row->status] ?? $row->status }}</td><td class="right">{{ number_format((int) $row->document_count) }}</td><td class="right">{{ isset($row->total_amount) ? $money($row->total_amount) : '—' }}</td></tr>@empty<tr><td colspan="3" class="center">ไม่พบเอกสารในช่วงวันที่นี้</td></tr>@endforelse
            @if($section['rows']->isNotEmpty())<tr class="total"><td>รวม</td><td class="right">{{ number_format((int) $section['rows']->sum('document_count')) }}</td><td class="right">{{ isset($section['rows']->first()->total_amount) ? $money($section['rows']->reduce(fn ($sum, $row) => $sum->plus((string) $row->total_amount), \Brick\Math\BigDecimal::zero())) : '—' }}</td></tr>@endif
        </tbody></table>
    @endforeach
</div>
<!-- invoice-closing -->
<div class="pdf-tax-invoice pdf-readable">
    <p class="pdf-note">รายงานนี้เป็นสรุปข้อมูลภายในตามสิทธิ์คลังและช่วงวันที่ที่เลือก ไม่ใช่ใบกำกับภาษีหรือหลักฐานการชำระเงิน</p>
    <p class="pdf-control">รายงานปฏิบัติการจัดซื้อ · สร้างเมื่อ {{ $generatedAt->format('d/m/Y H:i') }} · {{ $generatedBy }}</p>
</div>
