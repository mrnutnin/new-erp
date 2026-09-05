@php($sections = collect($definition['sections'] ?? []))
@php($headerFields = ['company.logo', 'company.name', 'company.address', 'company.tax_id'])
@php($headerSections = $sections->filter(fn ($section) => in_array($section['field'] ?? '', $headerFields, true)))
@php($documentHeaderFields = ['document.title', 'document.number', 'document.date'])
@php($documentHeaderSections = $sections->filter(fn ($section) => in_array($section['field'] ?? '', $documentHeaderFields, true))->sortBy(fn ($section) => array_search($section['field'] ?? '', $documentHeaderFields, true)))
@php($signatureFields = ['signatures.prepared_by', 'signatures.approved_by'])
@php($signatureSections = $sections->filter(fn ($section) => in_array($section['field'] ?? '', $signatureFields, true)))
@php($bodySections = $sections->reject(fn ($section) => in_array($section['field'] ?? '', array_merge($headerFields, $documentHeaderFields, $signatureFields), true)))
@php($hasLogo = $headerSections->contains('field', 'company.logo'))
@php($hasName = $headerSections->contains('field', 'company.name'))
@php($hasTitle = $sections->contains('field', 'document.title'))
@php($hasNumber = $sections->contains('field', 'document.number'))
@php($hasDate = $sections->contains('field', 'document.date'))
@php($hasPreparedSignature = $sections->contains('field', 'signatures.prepared_by'))
@php($hasApprovedSignature = $sections->contains('field', 'signatures.approved_by'))
<style>
    @page { size: A4; margin: 12mm; }
    .document-render, .document-render * { box-sizing: border-box; }
    .document-render { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #172033; }
    .document-render .text-end { text-align: right; }
    .document-render .border { border: 1px solid #cbd5e1; }
    .document-render .border-bottom { border-bottom: 1px solid #cbd5e1; }
    .document-render .border-top { border-top: 1px solid #cbd5e1; }
    .document-render .rounded { border-radius: 4px; }
    .document-render table { width: 100%; border-collapse: collapse; }
    .document-render thead { display: table-header-group; }
    .document-render tr { page-break-inside: avoid; }
    .document-render th, .document-render td { border: 1px solid #cbd5e1; padding: 6px; }
    .document-render th { font-weight: bold; }
    .document-render .small { font-size: 9pt; }
    .document-render .fw-bold, .document-render .fw-semibold { font-weight: bold; }
</style>
<div class="document-render bg-white border rounded p-4" style="min-height:520px;font-family:Arial,sans-serif">
    <table style="width:100%;table-layout:fixed;border-collapse:collapse;border:0;border-bottom:1px solid #cbd5e1;margin-bottom:16px"><tr>
        <td style="width:65%;border:0;vertical-align:top;padding:0 0 12px 0">
            @if(!$hasLogo && !empty($payload['company']['logo']))<img src="{{ $payload['company']['logo'] }}" alt="โลโก้บริษัท" style="max-height:55px;max-width:180px;display:block;margin-bottom:6px">@endif
            @if(!$hasName)<div class="fw-bold fs-5">{{ $payload['company']['name'] ?? '-' }}</div>@endif
            @foreach($headerSections as $section)
                @include('Platform::document-templates.section', ['section' => $section, 'payload' => $payload])
            @endforeach
            @if(!$headerSections->contains('field', 'company.address') && !$headerSections->contains('field', 'company.tax_id'))<div class="small text-secondary">{{ $payload['company']['address'] ?? '-' }} · เลขผู้เสียภาษี {{ $payload['company']['tax_id'] ?? '-' }}</div>@endif
        </td>
        <td style="width:35%;border:0;vertical-align:top;text-align:right;padding:0 0 12px 0">
            @if(!$hasTitle)<div class="fw-semibold">{{ $payload['document']['title'] ?? '-' }}</div>@endif
            @foreach($documentHeaderSections as $section)
                @include('Platform::document-templates.section', ['section' => $section, 'payload' => $payload])
            @endforeach
            @if(!$hasNumber)<div class="small">{{ $payload['document']['number'] ?? '-' }}</div>@endif
            @if(!$hasDate)<div class="small text-secondary">{{ $payload['document']['date'] ?? '-' }}</div>@endif
        </td>
    </tr></table>
    <div class="mb-4"><div class="small text-secondary">Supplier / Customer</div><div class="fw-semibold">{{ $payload['party']['name'] ?? '-' }}</div><div class="small">{{ $payload['party']['address'] ?? '' }}</div></div>
    @foreach($bodySections as $section)
        @include('Platform::document-templates.section', ['section' => $section, 'payload' => $payload])
    @endforeach
    <table style="width:100%;table-layout:fixed;border-collapse:collapse;border:0;margin-top:48px;padding-top:16px;text-align:center"><tr>
        <td style="width:50%;border:0;vertical-align:bottom;text-align:left">
            @if($hasPreparedSignature)
                @include('Platform::document-templates.section', ['section' => $signatureSections->firstWhere('field', 'signatures.prepared_by'), 'payload' => $payload])
            @else
                ลงชื่อ {{ $payload['signatures']['prepared_by'] ?? 'ผู้จัดทำ' }}
            @endif
        </td>
        <td style="width:50%;border:0;vertical-align:bottom;text-align:right">
            @if($hasApprovedSignature)
                @include('Platform::document-templates.section', ['section' => $signatureSections->firstWhere('field', 'signatures.approved_by'), 'payload' => $payload])
            @else
                ลงชื่อ {{ $payload['signatures']['approved_by'] ?? 'ผู้อนุมัติ' }}
            @endif
        </td>
    </tr></table>
</div>
