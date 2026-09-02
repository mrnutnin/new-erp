@extends('Asset::layout')

@section('title', 'คู่มือการทำงาน | Asset')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">ASSET · WORKFLOW CENTER</p>
        <div class="mb-4"><h1 class="h3 mb-2">คู่มือการทำงาน</h1><p class="text-secondary mb-0">เริ่มจากการเตรียมข้อมูลหลัก ก่อนสร้างและรับรู้สินทรัพย์</p></div>
        @php($workflowModes = ['setup' => 'เริ่มใช้งานครั้งแรก', 'daily' => 'งานประจำวัน'])
        @php($hasSetupWarning = collect($workflows)->where('mode', 'setup')->flatMap(fn ($workflow) => $workflow['steps'])->contains(fn ($step) => in_array($step['status_code'] ?? null, ['NOT_READY', 'CONFIGURATION_WARNING'], true)))
        @php($defaultWorkflowMode = $hasSetupWarning ? 'setup' : 'daily')
        <ul class="nav nav-pills gap-2 mb-4" role="tablist">
            @foreach ($workflowModes as $mode => $label)
                <li class="nav-item"><button class="nav-link {{ $mode === $defaultWorkflowMode ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#asset-workflow-{{ $mode }}" type="button">{{ $label }}</button></li>
            @endforeach
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
        </div>
    </div>
@endsection
