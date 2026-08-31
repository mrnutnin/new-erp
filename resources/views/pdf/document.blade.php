@php($profile = $profile ?? 'a4')
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 16mm 12mm; }
        body { font-family: sans-serif; font-size: 10pt; color: #20242a; }
        .document-header { border-bottom: 1px solid #aeb5bd; padding-bottom: 5mm; margin-bottom: 5mm; }
        .company-name, .document-title { font-size: 15pt; font-weight: bold; }
        .meta { color: #56616d; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 0.2mm solid #d9dee4; padding: 2mm 1.5mm; vertical-align: top; }
        th { background: #f1f4f6; text-align: left; }
        .number { text-align: right; white-space: nowrap; }
        .total { font-weight: bold; border-top: 0.4mm solid #20242a; }
        .reference { margin-top: 3mm; }
        @media print { .screen-only { display: none; } }
        @if($profile === 'dot_matrix')
        body { font-family: monospace; font-size: 8pt; }
        th, td { padding: 1mm; border-color: #333; }
        th { background: transparent; }
        .document-header { border-bottom: 0.2mm solid #333; }
        @endif
    </style>
</head>
<body>
    <header class="document-header">
        <div class="company-name">{{ $company['name'] ?? config('app.name') }}</div>
        @if(!empty($company['tax_id']))<div class="meta">เลขประจำตัวผู้เสียภาษี {{ $company['tax_id'] }}</div>@endif
        @if(!empty($company['address']))<div class="meta">{{ $company['address'] }}</div>@endif
        <div class="document-title">{{ $document['title'] ?? 'เอกสาร' }}</div>
        <div class="meta">เลขที่ {{ $document['number'] ?? '-' }} · วันที่ {{ $document['date'] ?? '-' }} · สถานะ {{ $document['status'] ?? '-' }}</div>
    </header>
    @if(!empty($references))<div class="reference meta">อ้างอิง: {{ collect($references)->map(fn($reference) => ($reference['label'] ?? '').' '.$reference['number'])->implode(' · ') }}</div>@endif
    @if(!empty($lines))<table><thead><tr><th>#</th><th>รายการ</th><th>หน่วย</th><th class="number">จำนวน</th><th class="number">ราคา/หน่วย</th><th class="number">รวม</th></tr></thead><tbody>@foreach($lines as $line)<tr><td>{{ $line['line_number'] ?? $loop->iteration }}</td><td>{{ $line['description'] ?? $line['item'] ?? '-' }}</td><td>{{ $line['uom'] ?? '-' }}</td><td class="number">{{ $line['quantity'] ?? '-' }}</td><td class="number">{{ $line['unit_price'] ?? '-' }}</td><td class="number">{{ $line['amount'] ?? '-' }}</td></tr>@endforeach</tbody></table>@endif
    @if(!empty($totals))<table><tbody>@foreach($totals as $label => $value)<tr class="{{ $label === 'grand_total' ? 'total' : '' }}"><th colspan="5">{{ $label }}</th><td class="number">{{ $value }}</td></tr>@endforeach</tbody></table>@endif
</body>
</html>
