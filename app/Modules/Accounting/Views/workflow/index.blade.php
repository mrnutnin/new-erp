@extends('Accounting::layout')
@section('title', 'คู่มือการทำงาน | Accounting')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">ACCOUNTING · WORKFLOW CENTER</p>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-2">คู่มือการทำงาน</h1>
            <p class="text-secondary mb-0">ลำดับงานบัญชีและรายงานทางการเงิน</p>
        </div>
        @if($warehouse)
            <span class="badge text-bg-info"><i class="bx bx-building-house me-1" aria-hidden="true"></i>{{ $warehouse->name }}</span>
        @endif
    </div>
    @php($workflowModes = ['setup' => 'เริ่มใช้งานครั้งแรก', 'daily' => 'งานประจำวัน'])
    @php($hasSetupBlocker = collect($workflows)->where('mode', 'setup')->flatMap(fn ($workflow) => $workflow['steps'])->contains(fn ($step) => ($step['status_code'] ?? null) === 'NOT_READY'))
    @php($defaultWorkflowMode = $hasSetupBlocker ? 'setup' : 'daily')
    @php($process = ['key' => 'accounting', 'eyebrow' => 'ACCOUNTING · PROCESS WORKFLOW', 'title' => 'จากเอกสารต้นทางสู่รายงานทางการเงิน', 'description' => 'เห็นเส้นทางการสร้าง Journal, ตรวจสอบยอด, Post และปิดงวดเพื่อออกรายงาน', 'diagram' => <<<'MERMAID'
flowchart LR
    SOURCE["01 · เอกสารต้นทาง<br/>Finance / Sales / WMS"] --> JOURNAL["02 · สร้าง Journal<br/>Journal Entry"]
    JOURNAL --> CHECK{"03 · ตรวจสอบ Journal<br/>Debit = Credit"}
    CHECK -->|ผ่าน| POST["04 · Post Ledger<br/>ลงบัญชีแยกประเภท"]
    CHECK -->|ไม่ผ่าน| FIX["แก้ Mapping<br/>หรือเอกสารต้นทาง"]
    FIX -. ตรวจใหม่ .-> CHECK
    POST --> RECON["05 · Reconciliation<br/>Bank / AR / AP / Inventory"]
    RECON -->|ผ่าน| CLOSE["06 · ปิดงวด<br/>Period Close"]
    RECON -->|ไม่ผ่าน| RESOLVE["Resolve ผลต่าง<br/>และรายการค้าง"]
    RESOLVE -. ตรวจใหม่ .-> RECON
    CLOSE --> REPORT["07 · รายงานการเงิน<br/>Financial Reports"]
    classDef document fill:#f4f7ff,stroke:#6478c8,color:#202943,stroke-width:1.5px;
    classDef control fill:#fff7e6,stroke:#c98a24,color:#5f4614,stroke-width:2px;
    classDef branch fill:#f6f4fb,stroke:#8173b8,color:#3f3764,stroke-dasharray:4 3;
    classDef posting fill:#eaf8f1,stroke:#2f9e72,color:#205d46,stroke-width:1.5px;
    classDef close fill:#f0f2f5,stroke:#68727d,color:#343b45,stroke-width:1.5px;
    classDef focal fill:#fff0e7,stroke:#eb6c36,color:#713516,stroke-width:2.5px;
    class SOURCE,JOURNAL,POST document;
    class FIX,RESOLVE branch;
    class CHECK,RECON control;
    class CHECK focal;
    class CLOSE close;
    class REPORT posting;
MERMAID
, 'notes' => [['title' => 'Control Account', 'icon' => 'bx-check-shield', 'text' => 'ตรวจยอดคงค้างและผลต่างกับเอกสารต้นทางก่อนปิดงวด'], ['title' => 'Audit Trail', 'icon' => 'bx-history', 'class' => 'is-service', 'text' => 'แก้ไขผ่านรายการแก้ไขหรือกลับรายการ ไม่ทำลาย Journal เดิม']], 'control' => 'ต้องตรวจ Debit/Credit, mapping, reconciliation และรายการค้างให้ครบก่อนปิดงวด'])
    <ul class="nav nav-pills gap-2 mb-4" role="tablist">
        @foreach($workflowModes as $mode => $label)
            <li class="nav-item"><button class="nav-link {{ $mode === $defaultWorkflowMode ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#accounting-workflow-{{ $mode }}" type="button">{{ $label }}</button></li>
        @endforeach
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#process-workflow-accounting-tab" type="button"><i class="bx bx-git-branch me-1" aria-hidden="true"></i>Process Workflow</button></li>
    </ul>
    <div class="tab-content">
        @foreach($workflowModes as $mode => $label)
            <div class="tab-pane fade {{ $mode === $defaultWorkflowMode ? 'show active' : '' }}" id="accounting-workflow-{{ $mode }}">
                @php($modeWorkflows = collect($workflows)->where('mode', $mode))
                @forelse($modeWorkflows as $workflow)
                    @include('Platform::workflow._workflow-card', ['workflow' => $workflow, 'mode' => $mode])
                @empty
                    <div class="card border-0 shadow-sm"><div class="card-body p-4 text-center text-secondary">ยังไม่มีขั้นตอนในโหมดนี้</div></div>
                @endforelse
            </div>
        @endforeach
        <div class="tab-pane fade" id="process-workflow-accounting-tab">
            @include('Platform::workflow._process-diagram', ['process' => $process])
        </div>
    </div>
</div>
@endsection
