<style>
body{font-family:sans-serif;font-size:10.5pt;color:#20252b}h1{margin:0 0 2px}h2{text-align:center;margin:16px 0}table{width:100%;border-collapse:collapse}th,td{border:1px solid #b8c0ca;padding:6px;vertical-align:top}thead{display:table-header-group}.right{text-align:right}.muted{color:#68727d}.total td{font-weight:bold}.history{page-break-inside:avoid}
</style>
@include('Pos::pdf.partials.modern-style')
@if($logo)<img src="{{ $logo }}" style="height:55px">@endif
<h1>{{ $companyName }}</h1><div class="muted">{{ $companyAddress ?: '—' }}</div>
<h2>{{ $sale->document_type === 'HS' ? 'ใบขายสด / ใบกำกับภาษี' : 'ใบส่งสินค้า / ใบกำกับภาษี' }}</h2>
<table class="doc-meta"><tbody><tr><td width="34%">เลขที่<br><b>{{ $sale->document_number }}</b></td><td width="33%">วันที่เอกสาร<br><b>{{ optional($sale->document_date)->format($dateFormat) }}</b></td><td>สถานะ<br><b>{{ $sale->status }}</b></td></tr></tbody></table>
@include('Pos::pdf.partials.document-detail-header', ['document' => $sale])
<br><table><thead><tr><th>#</th><th>สินค้า / รายละเอียด</th><th>หน่วย</th><th>จำนวน</th><th>ราคา/หน่วย</th><th>ส่วนลด</th><th>รวม</th></tr></thead><tbody>
@forelse($sale->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ data_get($line->item_snapshot,'code') }} · {{ data_get($line->item_snapshot,'name') }}</td><td>{{ $line->saleUom?->code ?: '—' }}</td><td class="right">{{ number_format((float) $line->quantity, $decimalPlaces) }}</td><td class="right">{{ number_format((float) $line->unit_price, 2) }}</td><td class="right">{{ number_format((float) $line->discount_amount, 2) }}</td><td class="right">{{ number_format((float) $line->line_total, 2) }}</td></tr>@empty<tr><td colspan="7">ไม่มีรายการสินค้า</td></tr>@endforelse
<tr class="total"><td colspan="6" class="right">รวมสุทธิ</td><td class="right">{{ number_format((float) $sale->total_amount, 2) }}</td></tr></tbody></table>
@if($sale->description)<p>หมายเหตุ: {{ $sale->description }}</p>@endif
<p class="muted">เอกสารต้นทาง: @if($source)ใบสั่งขาย {{ $source->document_number }}@else{{ $sale->source_type }} #{{ $sale->source_id }}@endif</p>
<div class="history"><h3>ประวัติเอกสาร</h3><table><thead><tr><th>วันเวลา</th><th>รายการ</th><th>ผู้ดำเนินการ</th></tr></thead><tbody>@forelse($history as $event)<tr><td>{{ $event->created_at?->format($dateFormat.' H:i') }}</td><td>{{ ['pos.physical-sale.created'=>'สร้างใบขาย'][$event->action] ?? 'ดำเนินการกับเอกสาร' }}</td><td>{{ $event->user?->name ?: 'ระบบ' }}</td></tr>@empty<tr><td colspan="3">ยังไม่มีประวัติ</td></tr>@endforelse</tbody></table></div>
