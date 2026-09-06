@extends('Pos::layout')
@section('title', 'คู่มือการทำงาน | Sales / POS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">SALES / POS · WORKFLOW CENTER</p>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><h1 class="h3 mb-2">คู่มือการทำงาน POS</h1><p class="text-secondary mb-0">เลือกกลุ่มงาน แล้วทำตามลำดับจากซ้ายไปขวา; ปุ่ม <i class="bx bx-play align-middle" aria-hidden="true"></i> จะพาไปยังเมนูที่เกี่ยวข้อง</p></div>
    </div>
    @php($workflowModes = ['setup' => 'เริ่มใช้งานครั้งแรก', 'daily' => 'งานประจำวัน'])
    @php($hasSetupBlocker = collect($workflows)->where('mode', 'setup')->flatMap(fn ($workflow) => $workflow['steps'])->contains(fn ($step) => in_array($step['status_code'] ?? null, ['NOT_READY', 'CONFIGURATION_WARNING'], true)))
    @php($defaultWorkflowMode = $hasSetupBlocker ? 'setup' : 'daily')
    @php($process = ['key' => 'pos', 'eyebrow' => 'SALES / POS · PROCESS WORKFLOW', 'title' => 'ตั้งแต่เปิดกะจนปิดยอดขาย', 'description' => 'เห็นความสัมพันธ์ของใบขาย การรับชำระเงิน สต็อก และการส่งต่อข้อมูลเข้าบัญชี', 'diagram' => <<<'MERMAID'
flowchart LR
    OPEN["01 · เปิดกะ<br/>Open Shift"] --> SALE["02 · ขายหน้าร้าน<br/>Physical Sale"]
    SALE --> PAY["03 · รับชำระเงิน<br/>Payment"]
    SALE --> STOCK["ตัด Stock<br/>Inventory Movement"]
    PAY --> CLOSE["04 · ปิดกะ<br/>Close Shift"]
    STOCK --> CLOSE
    CLOSE --> RECON{"05 · ตรวจสอบยอด<br/>Reconcile"}
    RECON -->|ผ่าน| POST["06 · ส่งต่อบัญชี<br/>Post to GL"]
    RECON -->|ไม่ผ่าน| FIX["แก้รายการขาย<br/>หรือรับชำระเงิน"]
    FIX -. ตรวจใหม่ .-> RECON
    classDef document fill:#f4f7ff,stroke:#6478c8,color:#202943,stroke-width:1.5px;
    classDef control fill:#fff7e6,stroke:#c98a24,color:#5f4614,stroke-width:2px;
    classDef branch fill:#f6f4fb,stroke:#8173b8,color:#3f3764,stroke-dasharray:4 3;
    classDef posting fill:#eaf8f1,stroke:#2f9e72,color:#205d46,stroke-width:1.5px;
    classDef focal fill:#fff0e7,stroke:#eb6c36,color:#713516,stroke-width:2.5px;
    class OPEN,SALE,PAY,CLOSE document;
    class STOCK branch;
    class RECON,FIX control;
    class RECON focal;
    class POST posting;
MERMAID
, 'notes' => [['title' => 'ฝ่ายขาย', 'icon' => 'bx-cart', 'text' => 'สร้างรายการขายและรับชำระเงินให้ครบก่อนปิดกะ'], ['title' => 'บัญชีและคลัง', 'icon' => 'bx-layer', 'class' => 'is-service', 'text' => 'ตรวจยอดขาย เงินรับ และ Stock ให้ตรงกันก่อน Post']], 'control' => 'ก่อนปิดกะต้องตรวจยอดเงินสด/โอน/บัตร และรายการขายที่ยังค้างให้ครบถ้วน'])
    <ul class="nav nav-pills gap-2 mb-4" role="tablist">
        @foreach($workflowModes as $mode => $label)
            <li class="nav-item"><button class="nav-link {{ $mode === $defaultWorkflowMode ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#pos-workflow-{{ $mode }}" type="button">{{ $label }}</button></li>
        @endforeach
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#process-workflow-pos-tab" type="button"><i class="bx bx-git-branch me-1" aria-hidden="true"></i>Process Workflow</button></li>
    </ul>
    <div class="tab-content">
        @foreach($workflowModes as $mode => $label)
            <div class="tab-pane fade {{ $mode === $defaultWorkflowMode ? 'show active' : '' }}" id="pos-workflow-{{ $mode }}">
                @php($modeWorkflows = collect($workflows)->where('mode', $mode))
                @forelse($modeWorkflows as $workflow)
                    @include('Platform::workflow._workflow-card', ['workflow' => $workflow, 'mode' => $mode])
                @empty
                    <div class="card border-0 shadow-sm"><div class="card-body p-4 text-center text-secondary">ยังไม่มีขั้นตอนในโหมดนี้</div></div>
                @endforelse
            </div>
        @endforeach
        <div class="tab-pane fade" id="process-workflow-pos-tab">
            @include('Platform::workflow._process-diagram', ['process' => $process])
        </div>
    </div>
</div>
@endsection
