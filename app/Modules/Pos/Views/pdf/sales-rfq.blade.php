@include('Pos::pdf.partials.modern-style')
@if($logo)<img src="{{ $logo }}" style="height:55px">@endif
<h1>{{ $companyName ?: 'บริษัท' }}</h1><div>{{ $companyAddress }}</div><h2 style="text-align:center">ใบขอราคา</h2>
<table class="doc-meta"><tbody><tr><td width="34%">เลขที่<br><b>{{ $salesRfq->document_number }}</b></td><td width="33%">วันที่เอกสาร<br><b>{{ optional($salesRfq->document_date)->format($dateFormat ?: 'd/m/Y') }}</b></td><td>ใช้ได้ถึง<br><b>{{ optional($salesRfq->valid_until)->format($dateFormat ?: 'd/m/Y') }}</b></td></tr></tbody></table>
@include('Pos::pdf.partials.document-detail-header', ['document' => $salesRfq])
<table><thead><tr><th>#</th><th>สินค้า/รายละเอียด</th><th>หน่วย</th><th>จำนวน</th></tr></thead><tbody>@foreach($salesRfq->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ data_get($line->item_snapshot,'code') }} · {{ data_get($line->item_snapshot,'name') }} {{ $line->description }}</td><td>{{ data_get($line->uom_snapshot,'code') }}</td><td class="right">{{ number_format((float)$line->quantity,4) }}</td></tr>@endforeach</tbody></table>
