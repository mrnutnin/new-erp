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
    @php($hasSetupBlocker = collect($workflows)->where('mode', 'setup')->flatMap(fn ($workflow) => $workflow['steps'])->contains(fn ($step) => in_array($step['status_code'] ?? null, ['NOT_READY', 'CONFIGURATION_WARNING'], true)))
    @php($defaultWorkflowMode = $hasSetupBlocker ? 'setup' : 'daily')
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
