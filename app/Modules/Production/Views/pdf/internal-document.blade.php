<htmlpageheader name="production-document-reference">
    <div style="font-family:notosansthai,sans-serif;font-size:8.5pt;color:#737b83;text-align:right">{{ $companyName }} · {{ $documentNumber }} · {{ $documentDate }}</div>
</htmlpageheader>
<sethtmlpageheader name="production-document-reference" value="on" show-this-page="1" />

<div class="pdf-internal-document{{ $logo ? ' with-logo' : '' }}">
    <table class="pdf-header"><tr>
        @if($logo)<td class="invoice-logo"><img src="{{ $logo }}" alt="{{ $companyName }}" style="width:24mm;max-height:22mm"></td>@endif
        <td class="pdf-header-company">
            <h1>{{ $companyName }}</h1>
            <div>{{ $companyAddress ?: '—' }}</div>
            @if($branchLabel)<div class="muted">{{ $branchLabel }}</div>@endif
        </td>
        <td class="pdf-header-title">
            <div class="pdf-copy">เอกสารภายใน / INTERNAL</div>
            <h2>{{ $documentTitle }}</h2>
            <div class="invoice-number">{{ $documentNumber }}</div>
            <div class="muted">สถานะ: {{ $statusLabel }}</div>
        </td>
    </tr></table>

    @if($watermark)<p class="pdf-watermark">{{ $watermark }}</p>@endif

    <table class="pdf-party"><tr>
        <td width="34%"><div class="pdf-label">วันที่เอกสาร</div><div class="pdf-value">{{ $documentDate }}</div></td>
        <td width="38%"><div class="pdf-label">คลังสินค้า</div><div class="pdf-value">{{ $warehouse ?: '—' }}</div></td>
        <td width="28%"><div class="pdf-label">ประเภทเอกสาร</div><div class="pdf-value">INTERNAL</div></td>
    </tr></table>

    @if($metadata)
        <table class="pdf-meta">
            @foreach(array_chunk($metadata, 2) as $row)
                <tr>
                    @foreach($row as $item)<td><span class="pdf-label">{{ $item['label'] }}</span><br><span class="pdf-value">{{ $item['value'] }}</span></td>@endforeach
                    @if(count($row) === 1)<td></td>@endif
                </tr>
            @endforeach
        </table>
    @endif

    @if($references)
        <h3>เอกสารอ้างอิง</h3>
        <table class="pdf-meta"><thead><tr><th>ประเภทเอกสาร</th><th>เลขที่</th></tr></thead><tbody>
            @foreach($references as $reference)<tr><td>{{ $reference['label'] }}</td><td>{{ $reference['number'] }}</td></tr>@endforeach
        </tbody></table>
    @endif

    <h3>{{ $lineHeading }}</h3>
    <table class="pdf-product" autosize="1">
        <thead><tr><th width="6%">#</th><th width="45%">สินค้า / รายละเอียด</th><th width="10%">หน่วย</th><th width="{{ $showValue ? '19%' : '39%' }}" class="right">จำนวน</th>@if($showValue)<th width="20%" class="right">มูลค่า</th>@endif</tr></thead>
        <tbody>
            @forelse($lines as $line)
                <tr>
                    <td class="center">{{ $line['line_number'] }}</td>
                    <td><div>{{ $line['description'] }}</div><div class="small text-secondary">{{ $line['code'] }}</div></td>
                    <td class="center">{{ $line['uom'] }}</td>
                    <td class="right">{{ $line['quantity'] }}</td>
                    @if($showValue)<td class="right">{{ $line['value'] }}</td>@endif
                </tr>
            @empty
                <tr><td colspan="{{ $showValue ? 5 : 4 }}" class="center">ไม่มีรายการ</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($showValue)
        <table class="pdf-total-summary"><tr class="total"><td>มูลค่ารวม</td><td class="right">{{ $totalValue }}</td></tr></table>
    @endif

    @if($notes)<p class="pdf-note"><b>หมายเหตุ:</b> {{ $notes }}</p>@endif
    <p class="pdf-control">{{ $documentNumber }} · {{ $documentTitle }} · เอกสารภายในสำหรับการปฏิบัติงาน</p>
</div>
