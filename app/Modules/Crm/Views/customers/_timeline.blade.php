@forelse($events as $event)
@php
    $meta = match($event->event_type) {
        'ACTIVITY' => ['กิจกรรม', 'bx-task', 'is-activity'],
        'STAGE' => ['Opportunity', 'bx-trending-up', 'is-stage'],
        default => ['เอกสารขาย', 'bx-file', 'is-document'],
    };
    $statusClass = in_array($event->status, ['WON','COMPLETED','POSTED','APPROVED','ACCEPTED','CONFIRMED','FULFILLED'], true) ? 'app-status-success'
        : (in_array($event->status, ['LOST','VOID','CANCELLED','REJECTED'], true) ? 'app-status-danger' : 'app-status-info');
    $statusLabels = collect(\App\Modules\Crm\Models\Opportunity::STAGES)->mapWithKeys(fn ($value, $key) => [$key => $value['label']])->merge([
        'CALL' => 'โทรศัพท์', 'MEETING' => 'นัดหมาย', 'TASK' => 'งานติดตาม', 'NOTE' => 'บันทึก', 'COMPLETED' => 'เสร็จแล้ว',
        'DRAFT' => 'ร่าง', 'WAIT' => 'รอพิจารณา', 'APPROVED' => 'อนุมัติแล้ว', 'SENT' => 'ส่งแล้ว', 'ACCEPTED' => 'ตอบรับแล้ว',
        'CONFIRMED' => 'ยืนยันแล้ว', 'FULFILLED' => 'ดำเนินการแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก', 'CANCELLED' => 'ยกเลิก', 'REJECTED' => 'ปฏิเสธ',
    ]);
    $detail = str_replace($statusLabels->keys()->all(), $statusLabels->values()->all(), $event->detail);
@endphp
<article class="crm-timeline-event {{ $meta[2] }}">
    <div class="crm-timeline-icon"><i class="bx {{ $meta[1] }}" aria-hidden="true"></i></div>
    <div class="min-w-0 flex-grow-1"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2"><div><span class="small text-secondary">{{ $meta[0] }}</span><h3 class="h6 mb-1 text-break">{{ $event->title }}</h3></div><time class="small text-secondary text-nowrap" datetime="{{ $event->event_at }}">{{ \Carbon\Carbon::parse($event->event_at)->format('d/m/Y H:i') }}</time></div><p class="small mb-1 text-break">{{ $detail }}</p><div class="d-flex flex-wrap align-items-center gap-2 small text-secondary">@if($event->reference)<span><i class="bx bx-link" aria-hidden="true"></i> {{ $event->reference }}</span>@endif @if($event->actor)<span><i class="bx bx-user" aria-hidden="true"></i> {{ $event->actor }}</span>@endif @if($event->status)<span class="badge {{ $statusClass }}">{{ $statusLabels[$event->status] ?? $event->status }}</span>@endif</div></div>
</article>
@empty
<div class="text-center text-secondary py-5"><i class="bx bx-history fs-1" aria-hidden="true"></i><h3 class="h6 mt-2">ยังไม่มีเหตุการณ์</h3><p class="small mb-0">ลองเปลี่ยนคำค้นหาหรือตัวกรอง</p></div>
@endforelse
