<section class="card border-0 shadow-sm purchasing-process-card">
    <div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">DOCUMENT FLOW · PROCURE TO PAY</p>
                <h2 class="h5 mb-2">เอกสารเชื่อมต่อกันอย่างไร</h2>
                <p class="text-secondary mb-0">เริ่มจากความต้องการซื้อ จนถึงรับสินค้า ตั้งเจ้าหนี้ ชำระเงิน และตรวจสอบบัญชี</p>
            </div>
            <div class="small text-secondary d-flex flex-wrap gap-3" aria-label="คำอธิบายแผนภาพ">
                <span><i class="bx bx-right-arrow-alt me-1" aria-hidden="true"></i>ส่งต่อข้อมูล</span>
                <span><i class="bx bx-git-branch me-1" aria-hidden="true"></i>จุดแตกแขนง</span>
                <span><i class="bx bx-check-shield me-1" aria-hidden="true"></i>จุดควบคุม</span>
            </div>
        </div>

        <div class="purchasing-mermaid-wrap" role="region" aria-label="แผนภาพ Process Workflow Purchasing" tabindex="0">
            <pre class="mermaid purchasing-mermaid">flowchart LR
    PR["01 · ใบขอซื้อ&lt;br/&gt;Purchase Requisition"] --> PO["02 · ใบสั่งซื้อ&lt;br/&gt;Purchase Order"]
    PO --> SUP["03 · Supplier&lt;br/&gt;ส่งสินค้า / ให้บริการ"]
    SUP --> GR["04 · ตรวจรับสินค้า&lt;br/&gt;Goods Receipt"]
    SUP --> SVC["บริการ / ค่าใช้จ่าย&lt;br/&gt;ไม่ผ่าน Goods Receipt"]
    GR --> MATCH{"05 · 3-way match&lt;br/&gt;PO + GR + Invoice"}
    SVC --> MATCH
    MATCH -->|ผ่าน / อนุมัติ variance| PI["06 · ใบซื้อเชื่อ&lt;br/&gt;Credit Purchase"]
    MATCH -->|ไม่ผ่าน| FIX["แก้เอกสารต้นทาง&lt;br/&gt;หรือจัดสรร Receipt ใหม่"]
    FIX -. ตรวจใหม่ .-> MATCH
    PI --> POST["07 · Post&lt;br/&gt;Stock / GL / AP Open Item"]
    POST --> PAY["08 · ชำระเจ้าหนี้&lt;br/&gt;Payment + Allocation"]
    PAY --> CLOSE["09 · Reconcile &amp; Close&lt;br/&gt;ปิดงวด"]
    classDef document fill:#f4f7ff,stroke:#6478c8,color:#202943,stroke-width:1.5px;
    classDef control fill:#fff7e6,stroke:#c98a24,color:#5f4614,stroke-width:2px;
    classDef branch fill:#f6f4fb,stroke:#8173b8,color:#3f3764,stroke-dasharray:4 3;
    classDef posting fill:#eaf8f1,stroke:#2f9e72,color:#205d46,stroke-width:1.5px;
    classDef payment fill:#edf8fb,stroke:#3a9aa6,color:#205c65,stroke-width:1.5px;
    classDef close fill:#f0f2f5,stroke:#68727d,color:#343b45,stroke-width:1.5px;
    classDef focal fill:#fff0e7,stroke:#eb6c36,color:#713516,stroke-width:2.5px;
    class PR,PO,GR,PI document;
    class POST posting;
    class PAY payment;
    class CLOSE close;
    class MATCH,FIX control;
    class SUP,SVC branch;
    class MATCH focal;</pre>
        </div>

        <div class="row g-3 mt-3">
            <div class="col-12 col-lg-6">
                <div class="purchasing-process-branch">
                    <div class="d-flex align-items-center gap-2 mb-2"><i class="bx bx-package" aria-hidden="true"></i><strong>กรณีสินค้าคงคลัง</strong></div>
                    <p class="small text-secondary mb-2">PO → Goods Receipt → Credit Purchase → Inventory/COGS → AP</p>
                    <div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-app-soft" href="{{ route('purchasing.purchase-receipts.index') }}">Goods Receipt</a><a class="btn btn-sm btn-app-soft" href="{{ route('purchasing.landed-costs.index') }}">Landed Cost</a></div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="purchasing-process-branch is-service">
                    <div class="d-flex align-items-center gap-2 mb-2"><i class="bx bx-receipt" aria-hidden="true"></i><strong>กรณีบริการ / ค่าใช้จ่าย</strong></div>
                    <p class="small text-secondary mb-2">PO → Supplier Invoice → Credit Purchase → Expense GL → AP</p>
                    <a class="btn btn-sm btn-app-soft" href="{{ route('purchasing.purchase-documents.index') }}">Credit Purchase</a>
                </div>
            </div>
        </div>

        <div class="alert alert-light border mt-3 mb-0 small"><i class="bx bx-check-shield me-1" aria-hidden="true"></i><strong>จุดควบคุม:</strong> ก่อน Post ใบซื้อเชื่อต้องตรวจ 3-way match (PO + Receipt + Invoice) และหลัง Post ต้องตรวจ Stock/GL/AP ให้สอดคล้องกัน</div>
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
            primaryColor: '#ffffff',
            primaryTextColor: '#2d3142',
            primaryBorderColor: '#2d3142',
            lineColor: '#6b6b6b',
            secondaryColor: '#eef1f4',
            tertiaryColor: '#f7f7f7'
        },
        flowchart: { useMaxWidth: true, htmlLabels: true, curve: 'basis' }
    });

    const diagram = document.querySelector('.purchasing-mermaid');
    const renderPurchasingProcess = async function () {
        if (!diagram || diagram.dataset.rendered === 'true') return;
        diagram.dataset.rendered = 'true';
        try {
            await mermaid.run({ nodes: [diagram] });
        } catch (error) {
            diagram.dataset.rendered = 'false';
            diagram.classList.add('is-fallback');
            console.error('Purchasing process workflow diagram failed to render.', error);
        }
    };

    document.querySelector('[data-bs-target="#purchasing-process-workflow"]')?.addEventListener('shown.bs.tab', renderPurchasingProcess);
    if (document.querySelector('#purchasing-process-workflow')?.classList.contains('show')) renderPurchasingProcess();
</script>
@endpush
