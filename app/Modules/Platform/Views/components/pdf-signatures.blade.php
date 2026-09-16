@props(['document', 'slots'])
@php
    $roles = collect($slots)->pluck('role')->filter()->unique()->values()->all();
    $snapshots = app(\App\Modules\Platform\Services\DocumentSignatureService::class)->forDocument($document, $roles);
    $showImages = strtoupper((string) ($document->status ?? '')) !== 'DRAFT';
    $count = count($slots);
    $gap = $count === 3 ? 3 : 10;
    $width = $count === 3 ? 31.333 : 45;
@endphp
<table class="pdf-signatures {{ $count === 3 ? 'pdf-signatures-three' : '' }}"><tr>
@foreach($slots as $index => $slot)
    @php($snapshot = $snapshots[$slot['role'] ?? ''] ?? null)
    @if($index > 0)<td width="{{ $gap }}%" class="invoice-sign-gap"></td>@endif
    <td width="{{ $width }}%">
        <div class="invoice-sign-space">
            @if($showImages && filled($snapshot['image'] ?? null))
                <img class="pdf-signature-image" src="{{ $snapshot['image'] }}" alt="ลายเซ็น {{ $snapshot['name'] ?? '' }}">
            @else
                &nbsp;
            @endif
        </div>
        <div>........................................................</div>
        <div>{{ $slot['label'] }}@if(filled($snapshot['name'] ?? null)) · {{ $snapshot['name'] }}@elseif(filled($slot['name'] ?? null)) · {{ $slot['name'] }}@endif</div>
        @if($showImages && filled($snapshot['position'] ?? null))<div class="invoice-sign-position">{{ $snapshot['position'] }}</div>@endif
        <div class="invoice-sign-date">{{ $showImages && ($snapshot['signed_at'] ?? null) ? 'วันที่ '.$snapshot['signed_at']->format('d/m/Y H:i') : ($slot['date'] ?? 'วันที่ .......... / .......... / ..........') }}</div>
    </td>
@endforeach
</tr></table>
