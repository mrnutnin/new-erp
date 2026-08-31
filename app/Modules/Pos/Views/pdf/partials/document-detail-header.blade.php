@php
    $intake = $sourceIntake ?? null;
    $taxLines = $document->relationLoaded('lines') ? $document->lines : collect();
    $rates = collect($taxLines)->pluck('tax_rate')->filter(fn ($rate) => (float) $rate > 0)->unique()->sort()->values();
    $hasTax = (float) ($document->tax_amount ?? $intake?->tax_amount ?? 0) > 0 || $rates->isNotEmpty();
    $taxMethod = $hasTax ? (($intake?->prices_include_vat ?? false) ? 'รวมภาษี' : 'ภาษีนอก') : 'ไม่มีภาษี';
    if ($rates->isNotEmpty()) $taxMethod .= ' · VAT '.$rates->map(fn ($rate) => rtrim(rtrim(number_format((float) $rate, 4, '.', ''), '0'), '.').'%')->join(', ');
@endphp
<table class="doc-meta"><tbody>
<tr><td width="34%">ลูกค้า<br><b>{{ $document->party_code }} · {{ $document->party_name }}</b></td><td width="22%">ผู้จัดทำ<br><b>{{ $intake?->preparedBy?->name ?: '—' }}</b></td><td>วันนัดรับ<br><b>{{ $intake?->appointment_date?->format($dateFormat) ?: '—' }}</b></td></tr>
<tr><td>วิธีการสั่งซื้อ<br><b>{{ $intake?->order_method ?: '—' }}</b></td><td>วิธีการจัดส่ง<br><b>{{ $intake?->delivery_method ?: '—' }}</b></td><td>วิธีคิดภาษี<br><b>{{ $taxMethod }}</b></td></tr>
<tr><td colspan="3">ที่อยู่ออกบิล: <b>{{ $intake?->billing_address ?: $document->party_address ?: '—' }}</b><br>ที่อยู่จัดส่ง: <b>{{ $intake?->shipping_address ?: '—' }}</b>@if(!empty($sourceLabel))<br>เอกสารต้นทาง: <b>{{ $sourceLabel }}</b>@endif</td></tr>
</tbody></table>
