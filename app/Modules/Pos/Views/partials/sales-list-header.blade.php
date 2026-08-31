<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <p class="eyebrow mb-2">{{ $eyebrow }}</p>
        <h1 class="h3 mb-1">{{ $title }}</h1>
        <p class="text-secondary mb-0">{{ $description }}</p>
    </div>
    @if (! empty($actionUrl))
        <a class="btn {{ $actionClass ?? 'btn-primary' }} d-inline-flex align-items-center text-nowrap px-3" href="{{ $actionUrl }}"><i class="bx {{ $actionIcon ?? 'bx-plus' }} me-1" aria-hidden="true"></i>{{ $actionLabel }}</a>
    @endif
</div>
