@forelse($opportunities as $opportunity)
    @php
        $stage = $stages[$opportunity->stage] ?? ['label' => $opportunity->stage, 'class' => 'app-status-neutral'];
        $customerLabel = $opportunity->party
            ? $opportunity->party->code.' · '.$opportunity->party->name
            : ($opportunity->contact_name ?: null);
        $showCustomer = $customerLabel && mb_strtolower(trim($customerLabel)) !== mb_strtolower(trim($opportunity->title));
    @endphp
    <div class="col-12 col-lg-6">
        <article class="card border-0 shadow-sm h-100 crm-opportunity-card crm-stage-{{ strtolower($opportunity->stage) }}">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div class="min-w-0">
                        <a class="crm-card-title text-body text-decoration-none d-block text-break" href="{{ route('crm.opportunities.show', $opportunity) }}">{{ $opportunity->title }}</a>
                        @if($showCustomer)<div class="crm-card-customer text-secondary text-break mt-1"><i class="bx bx-user me-1" aria-hidden="true"></i>{{ $customerLabel }}</div>@endif
                    </div>
                    <span class="badge {{ $stage['class'] }} flex-shrink-0">{{ $stage['label'] }}</span>
                </div>

                <div class="crm-card-commercial my-3">
                    <div class="crm-card-amount"><div class="crm-card-label">มูลค่าคาดการณ์</div><div class="crm-card-value">{{ number_format((float)$opportunity->expected_value, 2) }} <span>บาท</span></div></div>
                    <div class="text-end crm-card-chance"><div class="crm-card-label">โอกาสสำเร็จ</div><div class="crm-card-probability">{{ $opportunity->probability }}%</div></div>
                </div>
                <progress class="crm-probability-progress mb-3" value="{{ $opportunity->probability }}" max="100" aria-label="โอกาสสำเร็จ {{ $opportunity->probability }} เปอร์เซ็นต์">{{ $opportunity->probability }}%</progress>

                <div class="crm-card-meta mb-3">
                    <div class="min-w-0 crm-meta-owner"><i class="bx bx-user-check" aria-hidden="true"></i><span><small>ผู้รับผิดชอบ</small><strong class="text-truncate" title="{{ $opportunity->owner?->name ?: '-' }}">{{ $opportunity->owner?->name ?: '-' }}</strong></span></div>
                    <div class="crm-meta-close"><i class="bx bx-calendar-check" aria-hidden="true"></i><span><small>วันที่คาดปิด</small><strong>{{ $opportunity->expected_close_date?->format('d/m/Y') ?: '-' }}</strong></span></div>
                </div>

                <div class="crm-card-next-action {{ $opportunity->next_action_at?->isPast() ? 'is-overdue' : '' }} mb-3">
                    <i class="bx bx-bell" aria-hidden="true"></i><span><small>{{ $opportunity->next_action_at?->isPast() ? 'งานเกินกำหนด' : 'งานถัดไป' }}</small><strong>{{ $opportunity->next_action_at?->format('d/m/Y H:i') ?: 'ยังไม่ได้กำหนด' }}</strong></span>
                </div>

                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-auto crm-card-footer">
                    <div>@if($opportunity->salesIntake)<span class="badge app-status-success"><i class="bx bx-link-alt me-1" aria-hidden="true"></i>{{ $opportunity->salesIntake->document_number }}</span>@endif</div>
                    <div class="d-flex gap-2 crm-card-actions"><a class="btn btn-sm btn-app-soft" href="{{ route('crm.opportunities.show', $opportunity) }}"><i class="bx bx-file-find me-1" aria-hidden="true"></i>ดูรายละเอียด</a>@if(auth()->user()->hasPermission('crm.opportunities.update')&&!in_array($opportunity->stage,['WON','LOST'],true))<a class="btn btn-sm btn-app-soft" href="{{ route('crm.opportunities.edit', $opportunity) }}"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข</a>@endif</div>
                </div>
            </div>
        </article>
    </div>
@empty
    <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-center py-5"><i class="bx bx-target-lock fs-1 text-secondary" aria-hidden="true"></i><h2 class="h5 mt-3">ไม่พบโอกาสการขาย</h2><p class="text-secondary mb-0">ลองเปลี่ยนคำค้นหาหรือล้างตัวกรอง</p></div></div></div>
@endforelse
