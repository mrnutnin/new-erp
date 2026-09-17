@foreach($items as $opportunity)
@php
    $customer=$opportunity->party?->name?:$opportunity->contact_name?:'ยังไม่ผูกลูกค้า';
    $stale=!in_array($opportunity->stage,['WON','LOST'],true)&&$opportunity->updated_at->lt(now()->subDays(14));
@endphp
<article class="crm-kanban-card" data-opportunity-id="{{ $opportunity->id }}" data-stage="{{ $opportunity->stage }}" data-title="{{ $opportunity->title }}" data-value="{{ $opportunity->expected_value }}" @if($canUpdate&&!in_array($opportunity->stage,['WON','LOST'],true)) draggable="true" @endif>
    <div class="d-flex justify-content-between align-items-start gap-2"><a class="fw-semibold text-body text-decoration-none text-break" href="{{ route('crm.opportunities.show',$opportunity) }}">{{ $opportunity->title }}</a>@if($stale)<span class="badge app-status-warning">ไม่มีความเคลื่อนไหว</span>@endif</div>
    <div class="small text-secondary text-break mt-1"><i class="bx bx-building me-1" aria-hidden="true"></i>{{ $customer }}</div>
    <div class="crm-kanban-value mt-3">{{ number_format((float)$opportunity->expected_value,2) }} บาท <span>{{ $opportunity->probability }}%</span></div>
    <div class="progress mt-1" style="height:5px" role="progressbar" aria-label="ความน่าจะเป็น {{ $opportunity->probability }} เปอร์เซ็นต์" aria-valuenow="{{ $opportunity->probability }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:{{ $opportunity->probability }}%"></div></div>
    <div class="crm-kanban-meta mt-3"><span><i class="bx bx-user" aria-hidden="true"></i>{{ $opportunity->owner?->name?:'-' }}</span><span><i class="bx bx-calendar" aria-hidden="true"></i>{{ $opportunity->expected_close_date?->format('d/m/Y')?:'-' }}</span><span class="{{ $opportunity->next_action_at?->isPast()?'text-danger':'' }}"><i class="bx bx-bell" aria-hidden="true"></i>{{ $opportunity->next_action_at?->format('d/m/Y H:i')?:'ยังไม่มีงานถัดไป' }}</span></div>
    @if($canUpdate&&!in_array($opportunity->stage,['WON','LOST'],true))<button class="btn btn-sm btn-app-soft w-100 mt-3 js-change-stage" type="button"><i class="bx bx-transfer-alt me-1" aria-hidden="true"></i>เปลี่ยนขั้นตอน</button>@endif
</article>
@endforeach
