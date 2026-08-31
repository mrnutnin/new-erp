@php
    $statusClass = [
        'DRAFT' => 'app-badge-soft', 'WAIT' => 'app-badge-warning', 'SENT' => 'app-badge-info',
        'COMPLETED' => 'app-badge-success', 'APPROVED' => 'app-badge-success', 'ACCEPTED' => 'app-badge-success',
        'CONFIRMED' => 'app-badge-success', 'FULFILLED' => 'app-badge-success', 'POSTED' => 'app-badge-success',
        'REJECTED' => 'text-bg-danger', 'CANCELLED' => 'text-bg-danger', 'VOID' => 'text-bg-danger',
    ][$status] ?? 'app-badge-soft';
@endphp
<span class="badge rounded-pill px-3 py-2 {{ $statusClass }}">{{ $label }}</span>
