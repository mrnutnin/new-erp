@extends('Finance::layout')
@section('title', 'คู่มือการทำงาน | Finance')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">FINANCE · WORKFLOW CENTER</p>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><h1 class="h3 mb-2">คู่มือการทำงาน</h1><p class="text-secondary mb-0">ลำดับงานลูกหนี้ เจ้าหนี้ และรับ–จ่ายเงิน</p></div>
        @if($warehouse)
            <span class="badge text-bg-info"><i class="bx bx-building-house me-1" aria-hidden="true"></i>{{ $warehouse->name }}</span>
        @endif
    </div>
    @php($workflowModes = ['setup' => 'เริ่มใช้งานครั้งแรก', 'daily' => 'งานประจำวัน'])
    @php($hasSetupBlocker = collect($workflows)->where('mode', 'setup')->flatMap(fn ($workflow) => $workflow['steps'])->contains(fn ($step) => ($step['status_code'] ?? null) === 'NOT_READY'))
    @php($defaultWorkflowMode = $hasSetupBlocker ? 'setup' : 'daily')
    <ul class="nav nav-pills gap-2 mb-4" role="tablist">
        @foreach($workflowModes as $mode => $label)
            <li class="nav-item"><button class="nav-link {{ $mode === $defaultWorkflowMode ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#finance-workflow-{{ $mode }}" type="button">{{ $label }}</button></li>
        @endforeach
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
    </div>
</div>
@endsection

