@php($field = $section['field'] ?? '')
@php($align = in_array($section['align'] ?? 'left', ['left', 'center', 'right'], true) ? ($section['align'] ?? 'left') : 'left')
@php($spacing = min(100, max(0, (int) ($section['spacing'] ?? 0))))
@php($size = min(300, max(10, (int) ($section['size'] ?? 80))))
@if(($section['visible'] ?? true) !== false)
<div style="text-align:{{ $align }};margin-top:{{ $spacing }}px;margin-bottom:{{ $spacing }}px">
    @if(!empty($section['label']))<div class="small text-secondary">{{ $section['label'] }}</div>@endif
    @if(($section['type'] ?? '') === 'table' || $field === 'lines')
        <table class="table table-sm border align-middle" style="width:100%;border-collapse:collapse"><thead><tr><th style="border:1px solid #cbd5e1;padding:6px">รายการ</th><th style="border:1px solid #cbd5e1;padding:6px">รายละเอียด</th><th style="border:1px solid #cbd5e1;padding:6px">หน่วย</th><th class="text-end" style="border:1px solid #cbd5e1;padding:6px;text-align:right">จำนวน</th><th class="text-end" style="border:1px solid #cbd5e1;padding:6px;text-align:right">จำนวนเงิน</th></tr></thead><tbody>@foreach($payload['lines'] as $line)<tr><td style="border:1px solid #cbd5e1;padding:6px">{{ $line['item'] ?? '-' }}</td><td style="border:1px solid #cbd5e1;padding:6px">{{ $line['description'] ?? '-' }}</td><td style="border:1px solid #cbd5e1;padding:6px">{{ $line['uom'] ?? '-' }}</td><td class="text-end" style="border:1px solid #cbd5e1;padding:6px;text-align:right">{{ $line['quantity'] ?? '-' }}</td><td class="text-end" style="border:1px solid #cbd5e1;padding:6px;text-align:right">{{ $line['amount'] ?? '-' }}</td></tr>@endforeach</tbody></table>
    @elseif(($section['type'] ?? '') === 'image' || $field === 'company.logo')
        @if(!empty($payload['company']['logo']))<div class="mb-2"><img src="{{ $payload['company']['logo'] }}" alt="โลโก้บริษัท" style="max-height:{{ $size }}px;max-width:100%"></div>@endif
    @elseif($field === 'signatures.prepared_by')<div class="mb-2">ลงชื่อ {{ $payload['signatures']['prepared_by'] ?? 'ผู้จัดทำ' }}</div>
    @elseif($field === 'signatures.approved_by')<div class="mb-2">ลงชื่อ {{ $payload['signatures']['approved_by'] ?? 'ผู้อนุมัติ' }}</div>
    @elseif($field === 'company.name')<div class="mb-2 fw-semibold">{{ $payload['company']['name'] ?? '-' }}</div>
    @elseif($field === 'company.address')<div class="mb-2">{{ $payload['company']['address'] ?? '-' }}</div>
    @elseif($field === 'company.tax_id')<div class="mb-2">เลขผู้เสียภาษี: {{ $payload['company']['tax_id'] ?? '-' }}</div>
    @elseif($field === 'party.name')<div class="mb-2">คู่ค้า: {{ $payload['party']['name'] ?? '-' }}</div>
    @elseif($field === 'party.address')<div class="mb-2">ที่อยู่คู่ค้า: {{ $payload['party']['address'] ?? '-' }}</div>
    @elseif($field === 'document.title')<div class="mb-2">{{ $payload['document']['title'] ?? '-' }}</div>
    @elseif($field === 'document.number')<div class="mb-2">เลขที่: {{ $payload['document']['number'] ?? '-' }}</div>
    @elseif($field === 'document.date')<div class="mb-2">วันที่: {{ $payload['document']['date'] ?? '-' }}</div>
    @elseif($field === 'document.status')<div class="mb-2">สถานะ: {{ $payload['document']['status'] ?? '-' }}</div>
    @elseif($field === 'totals.subtotal')<div class="mb-2 text-end">ยอดก่อนภาษี: {{ $payload['totals']['subtotal'] ?? '-' }}</div>
    @elseif($field === 'totals.vat')<div class="mb-2 text-end">VAT: {{ $payload['totals']['vat'] ?? '-' }}</div>
    @elseif($field === 'totals.grand_total')<div class="text-end border-top pt-3 mt-3" style="text-align:right;border-top:1px solid #cbd5e1;padding-top:12px;margin-top:12px"><span class="me-4">ยอดรวมสุทธิ</span><strong>{{ $payload['totals']['grand_total'] ?? '-' }} บาท</strong></div>
    @endif
</div>
@endif
