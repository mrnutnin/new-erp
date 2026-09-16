@if($paymentSummary['deposits']->isNotEmpty())
<table class="invoice-payments" autosize="1"><thead><tr><th>เงินรับมัดจำที่นำมาใช้</th><th class="right">จำนวนเงิน</th></tr></thead><tbody>
@foreach($paymentSummary['deposits'] as $application)
<tr><td>{{ $application->advanceDeposit?->document_number ?: 'ใบรับเงินมัดจำ' }}<br><span class="muted">วันที่ใช้ {{ $application->application_date?->format($dateFormat) ?: '—' }}</span></td><td class="right">{{ number_format((float) $application->amount, 2) }}</td></tr>
@endforeach
</tbody></table>
@endif
@if($isHs && $sale->status === 'POSTED')
<table class="invoice-payments" autosize="1"><thead><tr><th>ช่องทางการชำระเงิน</th><th class="right">รับเงินจริง</th></tr></thead><tbody>
@forelse($sale->tenders as $tender)
<tr><td>{{ $tender->bankAccount?->name ?: '—' }}<br><span class="muted">{{ $sale->posting_date?->format($dateFormat) ?: '—' }}@if($tender->reference) · {{ $tender->reference }}@endif</span></td><td class="right">{{ number_format((float) $tender->amount, 2) }}</td></tr>
@empty<tr><td colspan="2">ไม่มีรายการรับชำระเพิ่มเติม</td></tr>@endforelse
</tbody></table>
@endif
