@forelse($activities as $activity)
    @php
        $type = ['CALL'=>['โทรศัพท์','bx-phone','crm-activity-call'],'MEETING'=>['นัดหมาย','bx-calendar-event','crm-activity-meeting'],'TASK'=>['งานติดตาม','bx-task','crm-activity-task'],'NOTE'=>['บันทึก','bx-note','crm-activity-note']][$activity->type] ?? [$activity->type,'bx-circle',''];
        $overdue = ! $activity->completed_at && $activity->due_at?->isPast();
        $semantic = $activity->completed_at ? 'success' : ($overdue ? 'danger' : 'info');
        $statusLabel = $activity->completed_at ? 'เสร็จแล้ว' : ($overdue ? 'เกินกำหนด' : 'รอดำเนินการ');
    @endphp
    <article class="crm-activity-card {{ $type[2] }} {{ $activity->completed_at ? 'is-completed' : '' }} {{ $overdue ? 'is-overdue' : '' }}">
        <div class="crm-activity-marker"><i class="bx {{ $type[1] }}" aria-hidden="true"></i></div>
        <div class="crm-activity-content">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start gap-2">
                <div class="min-w-0"><div class="d-flex flex-wrap align-items-center gap-2"><strong class="text-break">{{ $activity->subject }}</strong><span class="badge app-status-{{ $semantic }}">{{ $statusLabel }}</span><span class="small text-secondary">{{ $type[0] }}</span></div><div class="small text-secondary mt-1"><i class="bx bx-user me-1" aria-hidden="true"></i>{{ $activity->assignee?->name ?: '-' }} @if($activity->due_at)<span class="mx-1">·</span><i class="bx bx-time-five me-1" aria-hidden="true"></i>{{ $activity->due_at->format('d/m/Y H:i') }}@endif</div></div>
                @if(!$activity->completed_at&&auth()->user()->hasPermission('crm.activities.complete'))<button class="btn btn-sm crm-activity-action-{{ $semantic }} js-complete-activity flex-shrink-0" type="button" data-url="{{ route('crm.opportunities.activities.complete',[$opportunity,$activity]) }}"><i class="bx bx-check me-1" aria-hidden="true"></i>ทำเสร็จ</button>@endif
            </div>
            @if($activity->details)<div class="crm-activity-details text-break mt-2">{{ $activity->details }}</div>@endif
            <div class="crm-activity-audit mt-2">บันทึกโดย {{ $activity->creator?->name ?: '-' }} · {{ $activity->created_at->format('d/m/Y H:i') }}@if($activity->completed_at) · เสร็จเมื่อ {{ $activity->completed_at->format('d/m/Y H:i') }}@endif</div>
        </div>
    </article>
@empty
    <div class="text-center text-secondary py-5"><i class="bx bx-calendar-x fs-1" aria-hidden="true"></i><p class="mb-0 mt-2">ไม่พบกิจกรรมตามตัวกรอง</p></div>
@endforelse
