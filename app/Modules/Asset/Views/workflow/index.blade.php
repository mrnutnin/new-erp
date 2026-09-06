@extends('Asset::layout')

@section('title', 'คู่มือการทำงาน | Asset')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">ASSET · WORKFLOW CENTER</p>
        <div class="mb-4"><h1 class="h3 mb-2">คู่มือการทำงาน</h1><p class="text-secondary mb-0">เริ่มจากการเตรียมข้อมูลหลัก ก่อนสร้างและรับรู้สินทรัพย์</p></div>
        @php($workflowModes = ['setup' => 'เริ่มใช้งานครั้งแรก', 'daily' => 'งานประจำวัน'])
        @php($hasSetupWarning = collect($workflows)->where('mode', 'setup')->flatMap(fn ($workflow) => $workflow['steps'])->contains(fn ($step) => in_array($step['status_code'] ?? null, ['NOT_READY', 'CONFIGURATION_WARNING'], true)))
        @php($defaultWorkflowMode = $hasSetupWarning ? 'setup' : 'daily')
        @php($process = ['key' => 'asset', 'eyebrow' => 'ASSET · PROCESS WORKFLOW', 'title' => 'วงจรชีวิตสินทรัพย์', 'description' => 'เห็นเส้นทางตั้งแต่จัดหา รับรู้ โอน ใช้งาน คิดค่าเสื่อม จนถึงจำหน่ายสินทรัพย์', 'diagram' => <<<'MERMAID'
flowchart LR
    REQUEST["01 · ขอจัดหา<br/>Asset Request"] --> ACQUIRE["02 · จัดหา / รับมอบ<br/>Acquire"]
    ACQUIRE --> CAPITAL["03 · รับรู้สินทรัพย์<br/>Capitalize"]
    CAPITAL --> USE["04 · ใช้งาน / โอนย้าย<br/>In Service"]
    USE --> DEPR["05 · คิดค่าเสื่อม<br/>Depreciation"]
    DEPR --> REVIEW{"06 · ตรวจสอบ<br/>Asset Register"}
    REVIEW -->|พร้อม| REPORT["07 · รายงานและบัญชี<br/>GL / Disclosure"]
    REVIEW -->|ไม่พร้อม| FIX["แก้ข้อมูล<br/>หรือ mapping"]
    FIX -. ตรวจใหม่ .-> REVIEW
    DISPOSE["จำหน่าย / ตัดออก"] -. ปิดวงจร .-> REVIEW
    classDef document fill:#f4f7ff,stroke:#6478c8,color:#202943,stroke-width:1.5px;
    classDef control fill:#fff7e6,stroke:#c98a24,color:#5f4614,stroke-width:2px;
    classDef branch fill:#f6f4fb,stroke:#8173b8,color:#3f3764,stroke-dasharray:4 3;
    classDef posting fill:#eaf8f1,stroke:#2f9e72,color:#205d46,stroke-width:1.5px;
    classDef focal fill:#fff0e7,stroke:#eb6c36,color:#713516,stroke-width:2.5px;
    class REQUEST,ACQUIRE,CAPITAL,USE,DEPR document;
    class DISPOSE branch;
    class REVIEW,FIX control;
    class REVIEW focal;
    class REPORT posting;
MERMAID
, 'notes' => [['title' => 'Asset Register', 'icon' => 'bx-building-house', 'text' => 'ข้อมูลสินทรัพย์ต้องมีผู้รับผิดชอบ สถานที่ และวันที่เริ่มใช้งาน'], ['title' => 'Accounting', 'icon' => 'bx-calculator', 'class' => 'is-service', 'text' => 'ตรวจบัญชีสินทรัพย์ ค่าเสื่อม และผลต่างก่อนปิดงวด']], 'control' => 'ก่อน Post หรือปิดงวดต้องตรวจ mapping, อายุการใช้งาน, ค่าเสื่อมสะสม และสถานะสินทรัพย์'])
        <ul class="nav nav-pills gap-2 mb-4" role="tablist">
            @foreach ($workflowModes as $mode => $label)
                <li class="nav-item"><button class="nav-link {{ $mode === $defaultWorkflowMode ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#asset-workflow-{{ $mode }}" type="button">{{ $label }}</button></li>
            @endforeach
            <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#process-workflow-asset-tab" type="button"><i class="bx bx-git-branch me-1" aria-hidden="true"></i>Process Workflow</button></li>
        </ul>
        <div class="tab-content">
            @foreach ($workflowModes as $mode => $label)
                <div class="tab-pane fade {{ $mode === $defaultWorkflowMode ? 'show active' : '' }}" id="asset-workflow-{{ $mode }}">
                    @forelse (collect($workflows)->where('mode', $mode) as $workflow)
                        @include('Platform::workflow._workflow-card', ['workflow' => $workflow, 'mode' => $mode])
                    @empty
                        <div class="card border-0 shadow-sm"><div class="card-body p-4 text-center text-secondary">ยังไม่มีขั้นตอนในโหมดนี้</div></div>
                    @endforelse
                </div>
            @endforeach
            <div class="tab-pane fade" id="process-workflow-asset-tab">
                @include('Platform::workflow._process-diagram', ['process' => $process])
            </div>
        </div>
    </div>
@endsection
