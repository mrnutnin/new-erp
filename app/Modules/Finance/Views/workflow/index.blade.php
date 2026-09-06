@extends('Finance::layout')
@section('title', 'คู่มือการทำงาน | Finance')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">FINANCE · WORKFLOW CENTER</p>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><h1 class="h3 mb-2">คู่มือการทำงาน</h1><p class="text-secondary mb-0">ลำดับงานตั้งค่าการเงิน รับ–จ่าย เงินสดย่อย เงินทดรอง และโอนเงินภายใน</p></div>
        @if($warehouse)
            <span class="badge text-bg-info"><i class="bx bx-building-house me-1" aria-hidden="true"></i>{{ $warehouse->name }}</span>
        @endif
    </div>
    @php($workflowModes = ['setup' => 'เริ่มใช้งานครั้งแรก', 'daily' => 'งานประจำวัน'])
    @php($hasSetupBlocker = collect($workflows)->where('mode', 'setup')->flatMap(fn ($workflow) => $workflow['steps'])->contains(fn ($step) => in_array($step['status_code'] ?? null, ['NOT_READY', 'CONFIGURATION_WARNING'], true)))
    @php($defaultWorkflowMode = $hasSetupBlocker ? 'setup' : 'daily')
    @php($process = ['key' => 'finance', 'eyebrow' => 'FINANCE · PROCESS WORKFLOW', 'title' => 'เอกสารการเงินตั้งแต่คำขอจนถึงกระทบยอด', 'description' => 'เห็นเส้นทางของรายการรับ–จ่าย เงินสดย่อย เงินทดรอง และการโอนเงินภายใน', 'diagram' => <<<'MERMAID'
flowchart LR
    REQUEST["01 · สร้างคำขอ<br/>Request / Voucher"] --> SUBMIT["02 · ส่งอนุมัติ<br/>Submit"]
    SUBMIT --> APPROVE{"03 · ตรวจสอบและอนุมัติ<br/>Approval"}
    APPROVE -->|อนุมัติ| POST["04 · ลงบัญชี<br/>Post"]
    APPROVE -->|ไม่อนุมัติ| RETURN["แก้ไขและส่งใหม่<br/>Return"]
    RETURN -. ส่งตรวจใหม่ .-> APPROVE
    POST --> SETTLE["05 · ชำระ / เคลียร์<br/>Settlement / Clearing"]
    SETTLE --> RECON{"06 · กระทบยอด<br/>Reconcile"}
    RECON -->|ผ่าน| CLOSE["07 · ปิดงวด<br/>Close"]
    RECON -->|ไม่ผ่าน| FIX["แก้รายการค้าง<br/>และตรวจใหม่"]
    FIX -. ตรวจใหม่ .-> RECON
    classDef document fill:#f4f7ff,stroke:#6478c8,color:#202943,stroke-width:1.5px;
    classDef control fill:#fff7e6,stroke:#c98a24,color:#5f4614,stroke-width:2px;
    classDef branch fill:#f6f4fb,stroke:#8173b8,color:#3f3764,stroke-dasharray:4 3;
    classDef posting fill:#eaf8f1,stroke:#2f9e72,color:#205d46,stroke-width:1.5px;
    classDef payment fill:#edf8fb,stroke:#3a9aa6,color:#205c65,stroke-width:1.5px;
    classDef focal fill:#fff0e7,stroke:#eb6c36,color:#713516,stroke-width:2.5px;
    class REQUEST,SUBMIT,POST document;
    class RETURN,FIX branch;
    class APPROVE,RECON control;
    class APPROVE focal;
    class SETTLE payment;
    class CLOSE posting;
MERMAID
, 'notes' => [['title' => 'รับ–จ่าย', 'icon' => 'bx-transfer', 'text' => 'เอกสารต้องผ่าน Submit และ Approval ก่อน Post เสมอ'], ['title' => 'ควบคุมเงิน', 'icon' => 'bx-shield-quarter', 'class' => 'is-service', 'text' => 'เคลียร์ยอดและแนบหลักฐานให้ครบก่อนกระทบยอด']], 'control' => 'ห้ามแก้ไขหรือลบรายการที่ Post แล้ว ให้ใช้การยกเลิกหรือรายการกลับรายการตาม audit trail'])
    <ul class="nav nav-pills gap-2 mb-4" role="tablist">
        @foreach($workflowModes as $mode => $label)
            <li class="nav-item"><button class="nav-link {{ $mode === $defaultWorkflowMode ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#finance-workflow-{{ $mode }}" type="button">{{ $label }}</button></li>
        @endforeach
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#process-workflow-finance-tab" type="button"><i class="bx bx-git-branch me-1" aria-hidden="true"></i>Process Workflow</button></li>
    </ul>
    <div class="tab-content">
        @foreach($workflowModes as $mode => $label)
            <div class="tab-pane fade {{ $mode === $defaultWorkflowMode ? 'show active' : '' }}" id="finance-workflow-{{ $mode }}">
                @php($modeWorkflows = collect($workflows)->where('mode', $mode))
                @forelse($modeWorkflows as $workflow)
                    @include('Platform::workflow._workflow-card', ['workflow' => $workflow, 'mode' => $mode])
                @empty
                    <div class="card border-0 shadow-sm"><div class="card-body p-4 text-center text-secondary">ยังไม่มีขั้นตอนในโหมดนี้</div></div>
                @endforelse
            </div>
        @endforeach
        <div class="tab-pane fade" id="process-workflow-finance-tab">
            @include('Platform::workflow._process-diagram', ['process' => $process])
        </div>
    </div>
</div>
@endsection
