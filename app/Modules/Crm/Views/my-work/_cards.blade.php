@forelse($items as $item)
    @php
        $activity = $type === 'ACTIVITY' ? $item : null;
        $opportunity = $activity?->opportunity ?: $item;
        $phone = $opportunity->phone ?: $opportunity->party?->phone;
        $customer = $opportunity->party?->name ?: $opportunity->contact_name ?: 'ยังไม่ผูกลูกค้า';
        $dueAt = $activity?->due_at;
        $overdue = $activity && ! $activity->completed_at && $dueAt?->isPast();
        $typeLabel = $activity ? (['CALL'=>'โทรศัพท์','MEETING'=>'นัดหมาย','TASK'=>'งานติดตาม','NOTE'=>'บันทึก'][$activity->type] ?? $activity->type) : 'ยังไม่มีงานถัดไป';
    @endphp
    <div class="col-12 col-lg-6">
        <article class="card border-0 shadow-sm h-100 crm-work-card {{ $overdue ? 'is-overdue' : '' }}">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div class="min-w-0"><a class="crm-card-title text-body text-decoration-none d-block text-break" href="{{ route('crm.opportunities.show', $opportunity) }}">{{ $activity?->subject ?: $opportunity->title }}</a><div class="crm-card-customer text-break mt-1"><i class="bx bx-briefcase me-1" aria-hidden="true"></i>{{ $opportunity->title }} · {{ $customer }}</div></div>
                    <span class="badge {{ $activity?->completed_at ? 'app-status-success' : ($overdue ? 'app-status-danger' : ($dueAt ? 'app-status-info' : 'app-status-warning')) }} flex-shrink-0">{{ $activity?->completed_at ? 'เสร็จแล้ว' : $typeLabel }}</span>
                </div>

                <div class="crm-work-time mb-3"><i class="bx {{ $activity?->completed_at ? 'bx-check-circle' : 'bx-bell-ring' }}" aria-hidden="true"></i><span><small>{{ $activity?->completed_at ? 'ดำเนินการเสร็จเมื่อ' : ($overdue ? 'ควรดำเนินการทันที' : 'กำหนดติดตาม') }}</small><strong>{{ ($activity?->completed_at ?: $dueAt)?->format('d/m/Y H:i') ?: 'ยังไม่ได้กำหนดวันเวลา' }}</strong></span></div>
                @if($activity?->details)<p class="small text-secondary text-break mb-3">{{ $activity->details }}</p>@endif
                @if($teamView ?? false)<div class="crm-work-owner mb-3"><i class="bx bx-user-check" aria-hidden="true"></i><span><small>ผู้รับผิดชอบ</small><strong>{{ $activity?->assignee?->name ?: $opportunity->owner?->name ?: '-' }}</strong></span></div>@endif
                <div class="crm-work-meta mb-3"><span><i class="bx bx-line-chart me-1" aria-hidden="true"></i>{{ number_format((float)$opportunity->expected_value, 2) }} บาท</span><span><i class="bx bx-target-lock me-1" aria-hidden="true"></i>{{ $opportunity->probability }}%</span>@if($opportunity->salesIntake)<span><i class="bx bx-link-alt me-1" aria-hidden="true"></i>{{ $opportunity->salesIntake->document_number }}</span>@endif</div>

                <div class="crm-work-actions mt-auto">
                    @if($phone)<a class="btn btn-sm crm-btn-call" href="tel:{{ $phone }}"><i class="bx bx-phone me-1" aria-hidden="true"></i>โทร</a>@endif
                    <a class="btn btn-sm crm-btn-note" href="{{ route('crm.opportunities.show', $opportunity) }}#activity-form"><i class="bx bx-message-square-edit me-1" aria-hidden="true"></i>บันทึกผล</a>
                    @if($activity && ! $activity->completed_at && auth()->user()->hasPermission('crm.activities.complete'))<button class="btn btn-sm btn-app-primary js-complete-work" type="button" data-url="{{ route('crm.opportunities.activities.complete', [$opportunity, $activity]) }}"><i class="bx bx-check me-1" aria-hidden="true"></i>ทำเสร็จ</button>@elseif(!$activity && auth()->user()->hasPermission('crm.opportunities.update'))<a class="btn btn-sm btn-app-soft" href="{{ route('crm.opportunities.edit', $opportunity) }}"><i class="bx bx-calendar-edit me-1" aria-hidden="true"></i>กำหนดงาน</a>@endif
                </div>
            </div>
        </article>
    </div>
@empty
    <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-center py-5"><i class="bx bx-party fs-1 text-success" aria-hidden="true"></i><h2 class="h5 mt-3">ไม่มีงานในกลุ่มนี้</h2><p class="text-secondary mb-0">เลือกกลุ่มอื่นเพื่อตรวจสอบงานต่อไป</p></div></div></div>
@endforelse
