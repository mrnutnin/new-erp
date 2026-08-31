<style>
body{font-family:sans-serif;font-size:10.5pt;color:#20252b}h1{margin:0 0 2px}h2{text-align:center;margin:16px 0}table{width:100%;border-collapse:collapse}th,td{border:1px solid #b8c0ca;padding:6px;vertical-align:top}thead{display:table-header-group}.right{text-align:right}.muted{color:#68727d}.total td{font-weight:bold}
</style>
@include('Pos::pdf.partials.modern-style')
@if($logo)<img src="{{ $logo }}" style="height:55px">@endif
<h1>{{ $companyName ?: 'บริษัท' }}</h1><div class="muted">{{ $companyAddress }}</div>
<h2>ใบเสนอราคา</h2>
<table class="doc-meta"><tbody><tr><td width="34%">เลขที่<br><b>{{ $quotation->document_number }}</b></td><td width="33%">วันที่เอกสาร<br><b>{{ optional($quotation->document_date)->format($dateFormat) }}</b></td><td>ใช้ได้ถึง<br><b>{{ optional($quotation->valid_until)->format($dateFormat) }}</b></td></tr></tbody></table>
@include('Pos::pdf.partials.document-detail-header', ['document' => $quotation])
<br><table><thead><tr><th>#</th><th>สินค้า / รายละเอียด</th><th>หน่วย</th><th>จำนวน</th><th>ราคา/หน่วย</th><th>ส่วนลด</th><th>รวม</th></tr></thead><tbody>
@foreach($quotation->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ data_get($line->item_snapshot,'code') }} · {{ data_get($line->item_snapshot,'name') }}<br>{{ $line->description }}</td><td>{{ data_get($line->uom_snapshot,'code') }}</td><td class="right">{{ number_format((float) $line->quantity, $decimalPlaces) }}</td><td class="right">{{ number_format((float) $line->unit_price, 2) }}</td><td class="right">{{ number_format((float) $line->discount_amount, 2) }}</td><td class="right">{{ number_format((float) $line->line_total, 2) }}</td></tr>@endforeach
<tr class="total"><td colspan="6" class="right">รวมสุทธิ</td><td class="right">{{ number_format((float) $quotation->total_amount, 2) }}</td></tr></tbody></table>
@if($quotation->description)<p>หมายเหตุ: {{ $quotation->description }}</p>@endif
