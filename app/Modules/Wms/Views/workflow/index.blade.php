@extends('Wms::layout')
@php($isInventory = ($program?->code ?? null) === 'wms')
@section('title', 'คู่มือการทำงาน | '.($isInventory ? 'WMS' : 'Purchasing'))
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">WMS · WORKFLOW CENTER</p>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><h1 class="h3 mb-2">คู่มือการทำงาน WMS</h1><p class="text-secondary mb-0">เลือกกลุ่มงาน แล้วทำตามลำดับจากต้นทางไปปลายทาง; ปุ่ม <i class="bx bx-play align-middle" aria-hidden="true"></i> จะพาไปยังเมนูที่เกี่ยวข้อง</p></div>
    </div>
    @php($workflowModes = ['setup' => 'เริ่มใช้งานครั้งแรก', 'daily' => 'งานประจำวัน'])
    @php($setupItems = collect($workflows)->where('mode', 'setup')->flatMap(fn ($workflow) => collect($workflow['steps'])->merge($workflow['decision_cards'] ?? [])))
    @php($hasSetupBlocker = $setupItems->contains(fn ($step) => in_array($step['status_code'] ?? null, ['NOT_READY', 'CONFIGURATION_WARNING'], true)))
    @php($defaultWorkflowMode = $hasSetupBlocker ? 'setup' : 'daily')
    @php($allSteps = collect($workflows)->flatMap(fn ($workflow) => $workflow['steps']))
    @php($process = ['key' => 'wms', 'eyebrow' => 'WMS · PROCESS WORKFLOW', 'title' => 'การเคลื่อนไหวสินค้าและการควบคุมต้นทุน', 'description' => 'เห็นเส้นทางตั้งแต่รับสินค้า โอน เบิก ตรวจนับ จนถึง Stock Valuation และการกระทบยอด', 'diagram' => <<<'MERMAID'
flowchart LR
    SOURCE["01 · เอกสารต้นทาง<br/>Purchase / Transfer / Issue"] --> MOVE["02 · Stock Movement<br/>รับเข้า · โอน · เบิก"]
    MOVE --> LEDGER["03 · Stock Ledger<br/>ยอดคงเหลือ"]
    LEDGER --> COST["04 · Valuation / RECOST<br/>ต้นทุนสินค้า"]
    COST --> RECON{"05 · Reconciliation<br/>Stock / Allocation / GL"}
    RECON -->|ผ่าน| POST["06 · Inventory Post<br/>ส่งต่อ Accounting"]
    RECON -->|ไม่ผ่าน| FIX["แก้เอกสาร<br/>หรือจัดสรรรายการค้าง"]
    FIX -. ตรวจใหม่ .-> RECON
    COUNT["ตรวจนับ / ปรับปรุง"] -. ปรับยอด .-> LEDGER
    classDef document fill:#f4f7ff,stroke:#6478c8,color:#202943,stroke-width:1.5px;
    classDef control fill:#fff7e6,stroke:#c98a24,color:#5f4614,stroke-width:2px;
    classDef branch fill:#f6f4fb,stroke:#8173b8,color:#3f3764,stroke-dasharray:4 3;
    classDef posting fill:#eaf8f1,stroke:#2f9e72,color:#205d46,stroke-width:1.5px;
    classDef focal fill:#fff0e7,stroke:#eb6c36,color:#713516,stroke-width:2.5px;
    class SOURCE,MOVE,LEDGER,COST document;
    class COUNT branch;
    class RECON,FIX control;
    class RECON focal;
    class POST posting;
MERMAID
, 'notes' => [['title' => 'Stock Control', 'icon' => 'bx-package', 'text' => 'ตรวจยอดคงเหลือและรายการเคลื่อนไหวตามคลัง/สาขา'], ['title' => 'Cost Control', 'icon' => 'bx-line-chart', 'class' => 'is-service', 'text' => 'ห้าม Post เมื่อยังมี Pending Cost หรือรายการที่ link ไม่ครบ']], 'control' => 'ก่อนส่งต่อ Accounting ต้องผ่าน allocation, balance, unlinked และ valuation preflight'])
    <section class="row g-3 mb-4" aria-label="WMS workflow summary">
        @foreach ([['ขั้นตอนทั้งหมด', $allSteps->count(), 'ตรวจลำดับงานตั้งแต่ตั้งค่าจนถึงปิดรอบ', 'app-status-info'], ['ต้องแก้ไข', $allSteps->whereIn('status_code', ['NOT_READY', 'CONFIGURATION_WARNING'])->count(), 'Blocker จากการตั้งค่าหรือ preflight', 'app-status-danger'], ['งานค้างตามคลัง', $allSteps->sum(fn ($step) => (int) ($step['pending_count'] ?? 0)), 'เอกสารที่ยังดำเนินการไม่เสร็จ', 'app-status-warning']] as [$label, $value, $hint, $class])
            <div class="col-12 col-md-4"><article class="card border-0 shadow-sm h-100"><div class="card-body p-3"><div class="small text-secondary">{{ $label }}</div><div class="h4 mt-2 mb-1"><span class="badge {{ $class }} fs-6">{{ number_format($value) }}</span></div><div class="small text-secondary">{{ $hint }}</div></div></article></div>
        @endforeach
    </section>
    <ul class="nav nav-pills gap-2 mb-4" role="tablist">
        @foreach($workflowModes as $mode => $label)
            <li class="nav-item"><button class="nav-link {{ $mode === $defaultWorkflowMode ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#wms-workflow-{{ $mode }}" type="button">{{ $label }}</button></li>
        @endforeach
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#process-workflow-wms-tab" type="button"><i class="bx bx-git-branch me-1" aria-hidden="true"></i>Process Workflow</button></li>
    </ul>
    <div class="tab-content">
        @foreach($workflowModes as $mode => $label)
            <div class="tab-pane fade {{ $mode === $defaultWorkflowMode ? 'show active' : '' }}" id="wms-workflow-{{ $mode }}">
                @php($modeWorkflows = collect($workflows)->where('mode', $mode))
                @forelse($modeWorkflows as $workflow)
                    @include('Platform::workflow._workflow-card', ['workflow' => $workflow, 'mode' => $mode])
                @empty
                    <div class="card border-0 shadow-sm"><div class="card-body p-4 text-center text-secondary">ยังไม่มีขั้นตอนในโหมดนี้</div></div>
                @endforelse
            </div>
        @endforeach
        <div class="tab-pane fade" id="process-workflow-wms-tab">
            @include('Platform::workflow._process-diagram', ['process' => $process])
        </div>
    </div>
</div>
@endsection
