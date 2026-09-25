@extends('Production::layout')
@section('title', 'คู่มือการทำงาน | Production')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">PRODUCTION · WORKFLOW CENTER</p>
    <header class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-2">คู่มือการทำงาน Production</h1>
            <p class="text-secondary mb-0">ลำดับตั้งแต่เตรียม BOM รับคำขอสั่งผลิต ดำเนินงานหน้างาน จนถึงรับสินค้าสำเร็จรูป</p>
        </div>
    </header>
    @php($workflowModes = ['setup' => 'เริ่มใช้งานครั้งแรก', 'daily' => 'งานประจำวัน'])
    @php($setupSteps = collect($workflows)->where('mode', 'setup')->flatMap(fn ($workflow) => $workflow['steps']))
    @php($defaultMode = $setupSteps->contains(fn ($step) => in_array($step['status_code'] ?? null, ['NOT_READY', 'CONFIGURATION_WARNING'], true)) ? 'setup' : 'daily')
    <ul class="nav nav-pills gap-2 mb-4" role="tablist" aria-label="ประเภท workflow">
        @foreach($workflowModes as $mode => $label)
            <li class="nav-item"><button class="nav-link {{ $mode === $defaultMode ? 'active' : '' }}" id="production-workflow-{{ $mode }}-tab" data-bs-toggle="pill" data-bs-target="#production-workflow-{{ $mode }}" type="button" role="tab" aria-controls="production-workflow-{{ $mode }}" aria-selected="{{ $mode === $defaultMode ? 'true' : 'false' }}">{{ $label }}</button></li>
        @endforeach
    </ul>
    <div class="tab-content">
        @foreach($workflowModes as $mode => $label)
            <div class="tab-pane fade {{ $mode === $defaultMode ? 'show active' : '' }}" id="production-workflow-{{ $mode }}" role="tabpanel" aria-labelledby="production-workflow-{{ $mode }}-tab" tabindex="0">
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
