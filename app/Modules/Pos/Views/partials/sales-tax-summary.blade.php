@php
    $taxSource = $sourceIntake ?? null;
    $grandTotal = $document->total_amount ?? $document->grand_total ?? 0;
@endphp
<div class="d-flex justify-content-end mt-4">
    <section class="col-sm-8 col-md-6 col-lg-5 col-xl-4" aria-label="สรุปยอด">
        <div class="bg-body-tertiary rounded-3 p-3 p-md-4">
            <dl class="mb-0">
                <div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-secondary">ยอดก่อนส่วนลด</dt><dd class="fw-semibold">{{ number_format((float) $document->subtotal, 2) }}</dd></div>
                <div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-secondary">ส่วนลด</dt><dd class="fw-semibold">{{ number_format((float) $document->discount_amount, 2) }}</dd></div>
                <div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-secondary">ฐานภาษี</dt><dd class="fw-semibold">{{ number_format((float) ($taxSource?->tax_base ?? 0), 2) }}</dd></div>
                <div class="d-flex justify-content-between"><dt class="fw-normal text-secondary">ภาษี</dt><dd class="fw-semibold">{{ number_format((float) ($taxSource?->tax_amount ?? 0), 2) }}</dd></div>
            </dl>
            <div class="border-top mt-3 pt-3 d-flex justify-content-between align-items-center"><span class="fs-5">Grand Total</span><strong class="fs-3">{{ number_format((float) $grandTotal, 2) }}</strong></div>
        </div>
    </section>
</div>
