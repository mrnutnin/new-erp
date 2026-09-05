@if(!empty($workflow['decision_cards']))
    <p class="eyebrow mb-2">จุดควบคุมก่อนดำเนินการ</p>
    <div class="row g-3 mb-4">
        @foreach($workflow['decision_cards'] as $decision)
            @continue(($decision['mode'] ?? 'daily') !== $mode)
            <div class="col-md-6">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <h3 class="h6 mb-0">{{ $decision['title'] }}</h3>
                            <span class="badge {{ $decision['status_badge_class'] ?? 'app-status-warning' }}">
                                {{ $decision['status'] ?? 'ยังไม่พร้อม' }}
                            </span>
                        </div>
                        <p class="small text-secondary mb-2">{{ $decision['description'] }}</p>
                        @if(!empty($decision['block_reason']))<p class="small text-warning-emphasis mb-2">
                            <i class="bx bx-info-circle me-1" aria-hidden="true"></i>
                            {{ $decision['block_reason'] }}
                        </p>@endif
                        @if(!empty($decision['url']))
                            <a class="btn btn-sm btn-outline-secondary" href="{{ $decision['url'] }}">เปิดหน้าตรวจสอบ</a>
                        @elseif(!empty($decision['recovery_url']) && (!isset($decision['recovery_permission']) || auth()->user()->hasPermission($decision['recovery_permission'])))
                            <a class="btn btn-sm btn-outline-secondary" href="{{ $decision['recovery_url'] }}">{{ $decision['recovery_label'] ?? 'เปิดหน้าตั้งค่า' }}</a>
                        @endif
                        <details class="small mt-2">
                            <summary class="text-secondary">ทำผิดหรือย้อนกลับอย่างไร</summary>
                            <p class="text-secondary mb-0 mt-1">{{ $decision['recovery_hint'] }}</p>
                        </details>
                    </div>
                </article>
            </div>
        @endforeach
    </div>
@endif

<section class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
            <div>
                <h2 class="h5 mb-2">{{ $workflow['title'] }}</h2>
                <p class="text-secondary mb-0">{{ $workflow['description'] }}</p>
            </div>
            <span class="text-secondary small">
                <i class="bx bx-time-five me-1" aria-hidden="true"></i>{{ $workflow['duration'] }}
            </span>
        </div>

        <div class="workflow-canvas">
            <div class="workflow-lane">
                @foreach($workflow['steps'] as $step)
                    <div class="workflow-step">
                        <div class="workflow-node">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="workflow-step-number">{{ $loop->iteration }}</span>
                                <div class="flex-grow-1 ms-2">
                                    <h3 class="h6 mb-1">{{ $step['label'] }}</h3>
                                    <p class="text-secondary small mb-0">{{ $step['effect'] }}</p>
                                    @if(!empty($step['limitation_note']))
                                        <p class="small text-warning-emphasis mb-0 mt-1">
                                            <i class="bx bx-info-circle me-1" aria-hidden="true"></i>{{ $step['limitation_note'] }}
                                        </p>
                                    @endif
                                </div>
                                <span class="badge {{ $step['status_badge_class'] ?? 'app-status-warning' }}">{{ $step['status'] }}</span>
                                @if(!empty($step['recovery_url']) && (!isset($step['recovery_permission']) || auth()->user()->hasPermission($step['recovery_permission'])))
                                    <a class="workflow-recovery-action" href="{{ $step['recovery_url'] }}">{{ $step['recovery_label'] ?? 'เปิดหน้าตั้งค่า' }}</a>
                                @endif
                                @if($step['url'])
                                    <a class="workflow-node-action ms-2" href="{{ $step['url'] }}" title="{{ $step['next_action'] ?? 'เริ่มทำงาน' }}" aria-label="{{ $step['next_action'] ?? 'เริ่มทำงาน' }} {{ $step['label'] }}">
                                        <i class="bx bx-play" aria-hidden="true"></i>
                                    </a>
                                @endif
                            </div>

                            @if(!empty($step['block_reason']) && (!$step['url'] || !empty($step['configuration_warning'])))
                                <p class="small text-warning-emphasis mb-0 mt-2">
                                    <i class="bx bx-info-circle me-1" aria-hidden="true"></i>{{ $step['block_reason'] }}
                                </p>
                            @endif
                            @if(!empty($step['recovery_hint']))
                                <details class="small mt-2">
                                    <summary class="text-secondary">ทำผิดหรือย้อนกลับอย่างไร</summary>
                                    <p class="text-secondary mb-0 mt-1">{{ $step['recovery_hint'] }}</p>
                                </details>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
