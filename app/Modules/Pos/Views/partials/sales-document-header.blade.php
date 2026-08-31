@php
    $intake = $sourceIntake ?? null;
    $documentDate = $document->document_date?->format('d/m/Y') ?? '—';
    $validUntil = $document->valid_until?->format('d/m/Y');
    $taxLines = $document->relationLoaded('lines') ? $document->lines : ($intake?->lines ?? collect());
    $taxRates = collect($taxLines)->pluck('tax_rate')->filter(fn ($rate) => (float) $rate > 0)->unique()->sort()->values();
    $hasTax = (float) ($document->tax_amount ?? $intake?->tax_amount ?? 0) > 0 || $taxRates->isNotEmpty();
    $taxMethod = $hasTax
        ? (array_key_exists('price_includes_vat', $document->getAttributes()) && $document->price_includes_vat ? 'ราคารวมภาษี' : 'ราคาไม่รวมภาษี')
        : 'ไม่คิดภาษี';
    if ($taxRates->isNotEmpty()) {
        $taxMethod .= ' · VAT '.$taxRates->map(fn ($rate) => rtrim(rtrim(number_format((float) $rate, 4, '.', ''), '0'), '.').'%')->join(', ');
    }
@endphp
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3 p-lg-4">
        <div class="row g-3">
            <div class="col-md-4"><div class="text-secondary small">ลูกค้า</div><div class="fw-semibold">{{ $document->party_code }} · {{ $document->party_name }}</div></div>
            <div class="col-md-2"><div class="text-secondary small">วันที่เอกสาร</div><div>{{ $documentDate }}</div></div>
            @if ($validUntil)<div class="col-md-2"><div class="text-secondary small">ใช้ได้ถึง</div><div>{{ $validUntil }}</div></div>@endif
            <div class="col-md-2"><div class="text-secondary small">ผู้จัดทำ</div><div>{{ $intake?->preparedBy?->name ?? '—' }}</div></div>
            <div class="col-md-2"><div class="text-secondary small">วันนัดรับ</div><div>{{ $intake?->appointment_date?->format('d/m/Y') ?? '—' }}</div></div>
            <div class="col-md-3"><div class="text-secondary small">วิธีการสั่งซื้อ</div><div>{{ $intake?->order_method ?: '—' }}</div></div>
            <div class="col-md-3"><div class="text-secondary small">วิธีการจัดส่ง</div><div>{{ $intake?->delivery_method ?: '—' }}</div></div>
            <div class="col-md-3"><div class="text-secondary small">วิธีคิดภาษี</div><div>{{ $taxMethod }}</div></div>
            <div class="col-md-6"><div class="text-secondary small">ที่อยู่ออกบิล</div><div class="text-break">{{ $intake?->billing_address ?: $document->party_address ?: '—' }}</div></div>
            <div class="col-md-6"><div class="text-secondary small">ที่อยู่จัดส่ง</div><div class="text-break">{{ $intake?->shipping_address ?: '—' }}</div></div>
        </div>
    </div>
</div>
