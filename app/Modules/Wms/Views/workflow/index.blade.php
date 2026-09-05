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
    <section class="row g-3 mb-4" aria-label="WMS workflow summary">
        @foreach ([['ขั้นตอนทั้งหมด', $allSteps->count(), 'ตรวจลำดับงานตั้งแต่ตั้งค่าจนถึงปิดรอบ', 'app-status-info'], ['ต้องแก้ไข', $allSteps->whereIn('status_code', ['NOT_READY', 'CONFIGURATION_WARNING'])->count(), 'Blocker จากการตั้งค่าหรือ preflight', 'app-status-danger'], ['งานค้างตามคลัง', $allSteps->sum(fn ($step) => (int) ($step['pending_count'] ?? 0)), 'เอกสารที่ยังดำเนินการไม่เสร็จ', 'app-status-warning']] as [$label, $value, $hint, $class])
            <div class="col-12 col-md-4"><article class="card border-0 shadow-sm h-100"><div class="card-body p-3"><div class="small text-secondary">{{ $label }}</div><div class="h4 mt-2 mb-1"><span class="badge {{ $class }} fs-6">{{ number_format($value) }}</span></div><div class="small text-secondary">{{ $hint }}</div></div></article></div>
        @endforeach
    </section>
    <ul class="nav nav-pills gap-2 mb-4" role="tablist">
        @foreach($workflowModes as $mode => $label)
            <li class="nav-item"><button class="nav-link {{ $mode === $defaultWorkflowMode ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#wms-workflow-{{ $mode }}" type="button">{{ $label }}</button></li>
        @endforeach
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
    </div>
</div>
@endsection
