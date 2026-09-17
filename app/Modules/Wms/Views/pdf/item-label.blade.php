@php
    $nameLength = mb_strlen($item->name);
    $codeLength = mb_strlen($item->code);
    $qrSize = match ($labelSize) {
        '100x50' => $symbol === 'QR' ? 0.9 : 0.7,
        '60x40', 'a4_3x7' => $symbol === 'QR' ? 0.65 : 0.52,
        default => $symbol === 'QR' ? 0.42 : 0.36,
    };
    $barcodeSize = match ($labelSize) {
        '100x50' => 0.8,
        '60x40', 'a4_3x7' => 0.62,
        default => 0.42,
    };
    $displayCode = implode("\u{200B}", mb_str_split($item->code, 10));
    $codeClass = trim(($codeLength > 18 ? 'sticker-code-long ' : '').($codeLength > 32 ? 'sticker-code-very-long' : ''));
@endphp
<div class="pdf-sticker {{ $large ? 'pdf-sticker-large' : '' }}">
    <div class="sticker-company">{{ $companyName }}</div>
    <div class="sticker-name {{ $nameLength > 32 ? 'sticker-name-long' : '' }}">{{ $item->name }}</div>
    <div class="sticker-meta">{{ str($item->category?->name ?? 'สินค้า')->limit(32) }} · {{ $item->baseUom?->code ?: $item->base_uom }}</div>
    @if($symbol === 'BOTH')
        <table class="sticker-symbol"><tr>
            <td class="sticker-barcode-cell"><barcode code="{{ $item->code }}" type="C128B" size="{{ $barcodeSize }}" height="0.58" /><div class="sticker-code {{ $codeClass }}">{{ $displayCode }}</div></td>
            <td class="sticker-qr-cell"><barcode code="{{ $item->code }}" type="QR" size="{{ $qrSize }}" error="M" disableborder="1" /></td>
        </tr></table>
    @elseif($symbol === 'QR')
        <div class="sticker-symbol"><barcode code="{{ $item->code }}" type="QR" size="{{ $qrSize }}" error="M" disableborder="1" /></div>
    @else
        <div class="sticker-symbol"><barcode code="{{ $item->code }}" type="C128B" size="{{ $barcodeSize }}" height="0.62" /></div>
    @endif
    @if($symbol !== 'BOTH')<div class="sticker-code {{ $codeClass }}">{{ $displayCode }}</div>@endif
</div>
