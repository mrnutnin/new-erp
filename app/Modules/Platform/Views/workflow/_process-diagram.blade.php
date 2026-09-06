@php($process = $process ?? [])
@php($diagramId = 'process-workflow-'.($process['key'] ?? 'module'))

<section class="card border-0 shadow-sm module-process-card" aria-labelledby="{{ $diagramId }}-title">
    <div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">{{ $process['eyebrow'] ?? 'PROCESS WORKFLOW' }}</p>
                <h2 class="h5 mb-2" id="{{ $diagramId }}-title">{{ $process['title'] ?? 'เอกสารเชื่อมต่อกันอย่างไร' }}</h2>
                <p class="text-secondary mb-0">{{ $process['description'] ?? '' }}</p>
            </div>
            <div class="small text-secondary d-flex flex-wrap gap-3" aria-label="คำอธิบายแผนภาพ">
                <span><i class="bx bx-right-arrow-alt me-1" aria-hidden="true"></i>ส่งต่อข้อมูล</span>
                <span><i class="bx bx-git-branch me-1" aria-hidden="true"></i>จุดแตกแขนง</span>
                <span><i class="bx bx-check-shield me-1" aria-hidden="true"></i>จุดควบคุม</span>
            </div>
        </div>

        <div class="module-process-diagram" role="region" aria-label="แผนภาพ {{ $process['title'] ?? 'Process Workflow' }}" tabindex="0">
            <pre class="mermaid module-process-mermaid" id="{{ $diagramId }}">{!! $process['diagram'] !!}</pre>
        </div>

        @if(!empty($process['notes']))
            <div class="row g-3 mt-3">
                @foreach($process['notes'] as $note)
                    <div class="col-12 col-lg-{{ count($process['notes']) > 1 ? '6' : '12' }}">
                        <div class="module-process-note {{ ($note['class'] ?? '') }}">
                            <div class="d-flex align-items-center gap-2 mb-2"><i class="bx {{ $note['icon'] ?? 'bx-info-circle' }}" aria-hidden="true"></i><strong>{{ $note['title'] }}</strong></div>
                            <p class="small text-secondary mb-0">{{ $note['text'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if(!empty($process['control']))
            <div class="alert alert-light border mt-3 mb-0 small"><i class="bx bx-check-shield me-1" aria-hidden="true"></i><strong>จุดควบคุม:</strong> {{ $process['control'] }}</div>
        @endif
    </div>
</section>

@push('scripts')
<script type="module">
    import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';

    mermaid.initialize({
        startOnLoad: false,
        securityLevel: 'strict',
        theme: 'base',
        themeVariables: {
            fontFamily: 'Noto Sans Thai, Apple SD Gothic Neo, sans-serif',
            primaryTextColor: '#202943',
            lineColor: '#6b7280',
            tertiaryColor: '#f6f4fb'
        },
        flowchart: { useMaxWidth: true, htmlLabels: true, curve: 'basis' }
    });

    const diagram = document.getElementById(@json($diagramId));
    const renderModuleProcess = async function () {
        if (!diagram || diagram.dataset.rendered === 'true') return;
        diagram.dataset.rendered = 'true';
        try {
            await mermaid.run({ nodes: [diagram] });
        } catch (error) {
            diagram.dataset.rendered = 'false';
            diagram.classList.add('is-fallback');
            console.error('Module process workflow diagram failed to render.', error);
        }
    };

    document.querySelector('[data-bs-target="#{{ $diagramId }}-tab"]')?.addEventListener('shown.bs.tab', renderModuleProcess);
    if (document.querySelector('#{{ $diagramId }}-tab')?.classList.contains('show')) renderModuleProcess();
</script>
@endpush
