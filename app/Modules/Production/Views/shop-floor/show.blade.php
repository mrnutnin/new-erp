@extends('Production::layout')
@section('title', 'งานผลิต '.$order->document_number.' | MintERP')
@section('body-class', 'app-page sf-tablet-page')
@section('content')
@php
    $statusLabel = $order->held_at ? 'พักงานผลิต' : (['DRAFT'=>'ร่าง', 'RELEASED'=>'พร้อมผลิต', 'IN_PROGRESS'=>'กำลังผลิต', 'COMPLETED'=>'เสร็จแล้ว', 'CANCELLED'=>'ยกเลิกเอกสาร'][$order->status] ?? $order->status);
    $statusClass = $order->held_at ? 'danger' : (['RELEASED'=>'info', 'IN_PROGRESS'=>'warning', 'COMPLETED'=>'success', 'CANCELLED'=>'danger'][$order->status] ?? 'neutral');
    $canReportIssue = in_array($order->status, \App\Modules\Production\Models\ProductionOrderIssue::REPORTABLE_ORDER_STATUSES, true) && auth()->user()->hasPermission('production.shop_floor.use');
    $progress = match (true) {
        $order->status === 'COMPLETED', $receiptDocument?->status === 'POSTED' => 100,
        $receiptDocument?->status === 'APPROVED' => 85, $receiptDocument !== null => 75,
        $order->started_at !== null => 60, $issue?->status === 'POSTED' => 45,
        $issue?->status === 'APPROVED' => 30, $issue !== null => 15, default => 0,
    };
    $readinessRows = collect($materialReadiness['rows'] ?? [])->keyBy('line_number');
    $documentLabels = ['DRAFT'=>'ร่าง', 'APPROVED'=>'อนุมัติแล้ว', 'POSTED'=>'ลง Stock แล้ว', 'VOID'=>'ยกเลิกเอกสาร', 'REVERSED'=>'ยกเลิกเอกสาร'];
    $documentClasses = ['DRAFT'=>'neutral', 'APPROVED'=>'info', 'POSTED'=>'success', 'VOID'=>'danger', 'REVERSED'=>'danger'];
@endphp
<div class="container-fluid shop-floor-board sf-cockpit">
    <header class="sf-detail-header">
        <div class="sf-detail-heading">
            <a class="btn btn-outline-secondary" href="{{ route('production.shop-floor.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
            <div class="sf-document-title"><h1>{{ $order->document_number }}</h1><span class="badge app-status-{{ $statusClass }}">{{ $statusLabel }}</span></div>
        </div>
        <div class="sf-header-tools">
            @if($order->status === 'IN_PROGRESS' && $order->started_at && ! $order->held_at && auth()->user()->hasPermission('production.orders.hold'))<button class="btn btn-app-soft js-shop-hold" type="button" data-url="{{ route('production.shop-floor.hold', $order) }}"><i class="bx bx-pause-circle me-1" aria-hidden="true"></i>พักงานผลิต</button>@endif
            @if($canReportIssue)<button class="btn btn-app-warning" type="button" data-bs-toggle="modal" data-bs-target="#production-issue-modal"><i class="bx bx-error-circle me-1" aria-hidden="true"></i>แจ้งปัญหา</button>@endif
        </div>
    </header>

    <section class="sf-detail-strip" aria-label="สรุปงานผลิต">
        <div class="sf-product-summary"><div class="sf-detail-cover">@if($order->finishedItem?->cover_image_path)<img src="{{ route('production.shop-floor.item-image', [$order, $order->finishedItem]) }}" alt="ภาพสินค้า {{ $order->finishedItem->name }}">@else<i class="bx bx-package" aria-hidden="true"></i>@endif</div><div><strong>{{ $order->finishedItem?->name ?: 'ไม่ระบุสินค้า' }}</strong><small>{{ $order->finishedItem?->code }}</small></div></div>
        @if($order->salesOrder)<div><small>เลขที่ SO</small><strong>{{ $order->salesOrder->document_number }}</strong><small>ลูกค้า: {{ $order->salesOrder->party_name ?: 'ไม่ระบุ' }}</small></div>@else<div><small>แหล่งงาน</small><strong>Make to Stock</strong></div>@endif
        <div><small>เป้าหมาย</small><strong>{{ number_format((float)$order->planned_quantity, 2) }} {{ $order->uom?->code }}</strong></div>
        <div><small>กำหนดเสร็จ</small><strong>{{ $order->planned_finish_at?->format('d/m/Y H:i') ?: ($order->planned_finish_date?->format('d/m/Y') ?: 'ไม่ระบุ') }}</strong></div>
        <div><small>เวลาผลิต (รวมพัก)</small><strong class="js-production-elapsed" data-started-at="{{ $order->started_at?->toIso8601String() }}" data-ended-at="{{ $order->completed_at?->toIso8601String() }}">{{ $order->started_at ? 'กำลังคำนวณ...' : 'ยังไม่เริ่ม' }}</strong></div>
        <div class="sf-strip-progress"><small>ความคืบหน้าตามเอกสาร {{ $progress }}% (ไม่ใช่ร้อยละของจำนวนผลิต)</small><div class="progress" role="progressbar" aria-label="ความคืบหน้าตามเอกสาร" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:{{ $progress }}%"></div></div></div>
    </section>
    <div id="shop-floor-network-status" class="alert alert-warning d-none" role="status" aria-live="polite">เครือข่ายขัดข้อง กรุณาตรวจสอบการเชื่อมต่อก่อนทำรายการ</div>
    <div id="shop-floor-feedback" class="alert alert-danger d-none" role="status" aria-live="polite"></div>
    @if($order->customer_specification)<div class="alert alert-warning" role="note"><strong>ข้อกำหนดจากลูกค้า:</strong> {{ $order->customer_specification }}</div>@endif
    @include('Production::shop-floor._next-action')

    <div class="sf-detail-grid">
        <div class="sf-tabs" role="tablist" aria-label="ข้อมูลและการทำงาน">
            <button class="nav-link active" id="sf-tab-materials" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#materials" aria-controls="materials" aria-selected="true"><i class="bx bx-box" aria-hidden="true"></i>วัตถุดิบ <span>{{ $order->materials_count }}</span></button>
            <button class="nav-link" id="sf-tab-routing" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#routing" aria-controls="routing" aria-selected="false"><i class="bx bx-git-branch" aria-hidden="true"></i>Routing <span>{{ $order->completed_operations_count }}/{{ $order->operations_count }}</span></button>
            <button class="nav-link" id="sf-tab-issues" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#issues" aria-controls="issues" aria-selected="false"><i class="bx bx-error-circle" aria-hidden="true"></i>ปัญหา <span class="{{ $openIssuesCount ? 'text-danger' : '' }}">{{ $openIssuesCount }}</span></button>
            <button class="nav-link" id="sf-tab-info" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#info" aria-controls="info" aria-selected="false"><i class="bx bx-info-circle" aria-hidden="true"></i>ข้อมูล / งานอื่น</button>
        </div>
        <div class="tab-content sf-tab-content">
            <section class="tab-pane active" id="materials" role="tabpanel" aria-labelledby="sf-tab-materials" tabindex="0">
                <div class="sf-panel-heading"><h2>วัตถุดิบที่ต้องใช้</h2><span>ตรวจสอบก่อนเริ่มงาน · {{ $materials->total() }} รายการ</span></div>
                <div class="sf-panel-scroll">
                    @forelse($materials as $line)
                        @php($readiness = $readinessRows->get($line->line_number))
                        <div class="sf-material"><div class="sf-material-image">@if($line->item?->cover_image_path)<img src="{{ route('production.shop-floor.item-image', [$order, $line->item]) }}" alt="ภาพวัตถุดิบ {{ $line->item->name }}" loading="lazy">@else<i class="bx bx-package" aria-hidden="true"></i>@endif</div><div class="sf-material-name"><strong>{{ $line->item?->code }}</strong><span>{{ $line->item?->name }}</span>@if($readiness && (float)$readiness['shortage_quantity'] > 0)<span class="text-danger">ขาด {{ \App\Modules\Wms\Support\WmsDecimal::format($readiness['shortage_quantity']) }} {{ $line->uom?->code }} · พร้อมใช้ {{ \App\Modules\Wms\Support\WmsDecimal::format($readiness['available_quantity']) }}</span>@endif</div><div class="sf-material-qty"><small>ต้องใช้</small><strong>{{ \App\Modules\Wms\Support\WmsDecimal::format($line->required_quantity) }}</strong><span>{{ $line->uom?->code }}</span></div></div>
                    @empty<div class="sf-empty"><i class="bx bx-box" aria-hidden="true"></i><p>ยังไม่มีรายการวัตถุดิบ</p></div>@endforelse
                </div>
                @include('Production::shop-floor._pager', ['paginator' => $materials, 'label' => 'วัตถุดิบ'])
            </section>

            <section class="tab-pane" id="routing" role="tabpanel" aria-labelledby="sf-tab-routing" tabindex="0">
                <div class="sf-panel-heading"><h2>Routing (ไม่บังคับ)</h2>@if(in_array($order->status, ['DRAFT','RELEASED'], true) && auth()->user()->hasPermission('production.routing.create'))<button class="btn btn-app-soft js-operation-add" type="button" data-url="{{ route('production.shop-floor.operation.store', $order) }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มขั้นตอน</button>@endif</div>
                <div class="sf-panel-scroll">
                    <p class="sf-panel-intro">ไม่จำเป็นต้องมี Routing เพื่อเบิก เริ่ม หรือรับผลิต @if($order->bomRevision) · อ้างอิง BOM {{ $order->bomRevision->bom?->code }} Rev {{ $order->bomRevision->revision_number }} @endif · การแก้ไข BOM ภายหลังไม่เปลี่ยน WO นี้</p>
                    <ol class="sf-steps">
                        @forelse($operations as $operation)
                            <li class="sf-step"><span class="sf-step-number">{{ $operation->sequence }}</span><div class="sf-step-body"><strong>{{ $operation->name }}</strong><div class="sf-step-meta">{{ $operation->planned_minutes ? $operation->planned_minutes.' นาที' : 'ไม่กำหนดเวลา' }} · <span class="badge app-status-{{ $operation->status === 'COMPLETED' ? 'success' : ($operation->status === 'IN_PROGRESS' ? 'warning' : 'neutral') }}">{{ ['PENDING'=>'รอดำเนินการ','IN_PROGRESS'=>'กำลังทำ','COMPLETED'=>'เสร็จแล้ว','SKIPPED'=>'ข้าม'][$operation->status] ?? $operation->status }}</span></div>@if($operation->notes)<p class="sf-step-note">{{ $operation->notes }}</p>@endif</div><div class="sf-step-actions">
                                @if($operation->status === 'PENDING' && auth()->user()->hasPermission('production.routing.update'))<button class="btn btn-app-soft js-operation-edit" type="button" data-url="{{ route('production.shop-floor.operation.update', [$order, $operation]) }}" data-name="{{ $operation->name }}" data-minutes="{{ $operation->planned_minutes }}" title="แก้ไขขั้นตอน" aria-label="แก้ไขขั้นตอน"><i class="bx bx-edit" aria-hidden="true"></i></button>@endif
                                @if(auth()->user()->hasPermission('production.operations.execute') && $order->status === 'IN_PROGRESS' && $order->started_at && ! $order->held_at && $operation->status === 'PENDING')<button class="btn btn-app-primary js-shop-action" type="button" data-url="{{ route('production.shop-floor.operation.start', [$order, $operation]) }}"><i class="bx bx-play me-1" aria-hidden="true"></i>เริ่มขั้นตอน</button>@elseif(auth()->user()->hasPermission('production.operations.execute') && $order->status === 'IN_PROGRESS' && ! $order->held_at && $operation->status === 'IN_PROGRESS')<button class="btn btn-app-primary js-shop-action" type="button" data-url="{{ route('production.shop-floor.operation.complete', [$order, $operation]) }}"><i class="bx bx-check me-1" aria-hidden="true"></i>จบขั้นตอน</button>@endif
                                @if($operation->status === 'PENDING' && auth()->user()->hasPermission('production.routing.delete'))<button class="btn btn-app-danger js-operation-delete" type="button" data-url="{{ route('production.shop-floor.operation.delete', [$order, $operation]) }}" title="ลบขั้นตอน" aria-label="ลบขั้นตอน"><i class="bx bx-trash" aria-hidden="true"></i></button>@endif
                            </div></li>
                        @empty<li class="sf-empty">WO นี้ไม่มี Routing · สามารถเบิกวัตถุดิบ เริ่มผลิต และรับผลิตได้ตามปกติ</li>@endforelse
                    </ol>
                </div>
                @include('Production::shop-floor._pager', ['paginator' => $operations, 'label' => 'Routing'])
            </section>

            <section class="tab-pane" id="issues" role="tabpanel" aria-labelledby="sf-tab-issues" tabindex="0">
                <div class="sf-panel-heading"><h2>ปัญหาการผลิต</h2><span>รอตรวจสอบ {{ $openIssuesCount }} · ทั้งหมด {{ $productionIssues->total() }}</span></div>
                <div class="sf-panel-scroll">
                    @forelse($productionIssues as $productionIssue)<article class="sf-issue"><div class="d-flex flex-wrap gap-2 mb-2"><span class="badge app-status-{{ $productionIssue->status === 'OPEN' ? 'danger' : 'success' }}">{{ $productionIssue->status === 'OPEN' ? 'รอตรวจสอบ' : 'แก้ไขแล้ว' }}</span><span class="badge app-status-{{ $productionIssue->severity === 'HIGH' ? 'danger' : ($productionIssue->severity === 'MEDIUM' ? 'warning' : 'info') }}">ความรุนแรง {{ ['LOW'=>'ต่ำ','MEDIUM'=>'กลาง','HIGH'=>'สูง'][$productionIssue->severity] ?? $productionIssue->severity }}</span></div><p>{{ $productionIssue->description }}</p><small>แจ้ง {{ $productionIssue->reported_at?->format('d/m/Y H:i') }} · {{ $productionIssue->reporter?->name ?: '-' }} @if($productionIssue->resolved_at) · แก้ไข {{ $productionIssue->resolved_at->format('d/m/Y H:i') }} โดย {{ $productionIssue->resolver?->name ?: '-' }} @endif</small>@if($productionIssue->resolution_method)<p class="mt-2 mb-0">วิธีแก้ไข: {{ $productionIssue->resolution_method }}</p>@endif @if($productionIssue->status === 'OPEN')<div class="mt-2"><button class="btn btn-app-soft js-shop-resolve-issue" type="button" data-url="{{ route('production.shop-floor.issues.resolve', $productionIssue) }}"><i class="bx bx-check me-1" aria-hidden="true"></i>ปิดปัญหา</button></div>@endif</article>@empty<div class="sf-empty"><i class="bx bx-check-shield" aria-hidden="true"></i><h3>ยังไม่มีการแจ้งปัญหา</h3><p>พบปัญหาระหว่างเตรียมงานหรือผลิต? ใช้ปุ่ม “แจ้งปัญหา” ด้านบน</p></div>@endforelse
                </div>
                @include('Production::shop-floor._pager', ['paginator' => $productionIssues, 'label' => 'ปัญหา'])
            </section>

            <section class="tab-pane" id="info" role="tabpanel" aria-labelledby="sf-tab-info" tabindex="0">
                <div class="sf-panel-heading"><h2>ข้อมูลเอกสารและงานเพิ่มเติม</h2><span>ไม่ต้องทำส่วนนี้ทุกครั้ง</span></div>
                <div class="sf-panel-scroll sf-info-grid">
                    <div><h3>ข้อมูลแผนผลิต</h3><dl class="sf-metadata"><div><dt>คลัง</dt><dd>{{ request()->attributes->get('selectedWarehouse')?->name ?: '-' }}</dd></div><div><dt>เริ่มตามแผน</dt><dd>{{ $order->planned_start_at?->format('d/m/Y H:i') ?: ($order->planned_start_date?->format('d/m/Y') ?: 'ไม่ระบุ') }}</dd></div><div><dt>ลูกค้าต้องการส่ง</dt><dd>{{ $order->required_delivery_date?->format('d/m/Y') ?: 'ไม่ระบุ' }}</dd></div><div><dt>ส่งตามแผน</dt><dd>{{ $order->required_delivery_at?->format('d/m/Y H:i') ?: 'ไม่ระบุ' }}</dd></div><div><dt>เริ่มจริง</dt><dd>{{ $order->started_at?->format('d/m/Y H:i') ?: 'ยังไม่เริ่ม' }}</dd></div></dl>
                        @if($order->notes)<h3>หมายเหตุสำหรับงานผลิต</h3><p class="sf-note">{{ $order->notes }}</p>@endif
                        <h3>สถานะเอกสารที่เกี่ยวข้อง</h3><dl class="sf-metadata">@foreach(['ใบเบิกวัตถุดิบ'=>$issue, 'ใบรับผลิต'=>$receiptDocument, 'ใบรับคืน'=>$returnDocument, 'ใบรับเศษผลิต'=>$scrapDocument] as $label=>$document)<div><dt>{{ $label }}</dt><dd>@if($document)@php($documentUrl = match ($label) {'ใบเบิกวัตถุดิบ' => auth()->user()->hasPermission('wms.issues.view') ? route('wms.production.material-issues.show', $document) : null, 'ใบรับผลิต' => auth()->user()->hasPermission('wms.inventory-adjustments.view') ? route('wms.production.finished-receipts.show', $document) : null, 'ใบรับคืน' => auth()->user()->hasPermission('wms.issue-returns.view') ? route('wms.production.issue-returns.show', $document) : null, 'ใบรับเศษผลิต' => auth()->user()->hasPermission('wms.inventory-adjustments.view') ? route('wms.inventory-adjustments.documents.show', $document) : null, default => null})@if($documentUrl)<a href="{{ $documentUrl }}" target="_blank" rel="noopener noreferrer">{{ $document->document_number }}</a>@else{{ $document->document_number ?: '—' }}@endif <span class="badge app-status-{{ $documentClasses[$document->status] ?? 'neutral' }}">{{ $documentLabels[$document->status] ?? $document->status }}</span>@else<span class="badge app-status-neutral">ยังไม่มี</span>@endif</dd></div>@endforeach</dl>
                    </div>
                    <div>
                        @if($order->status === 'IN_PROGRESS' && $order->started_at && ! $order->held_at)
                            <h3>คืนวัตถุดิบ / ของเสีย</h3><p class="sf-panel-intro">ทำเฉพาะเมื่อมีวัตถุดิบเหลือหรือของเสีย</p>
                            @if($returnDocument?->status === 'DRAFT')<button class="btn btn-app-soft js-shop-action" type="button" data-url="{{ route('production.orders.material-returns.approve', [$order, $returnDocument]) }}">อนุมัติใบรับคืน</button>@elseif($returnDocument?->status === 'APPROVED')<button class="btn btn-app-soft js-shop-action" type="button" data-url="{{ route('production.orders.material-returns.post', [$order, $returnDocument]) }}">ลง Stock ใบรับคืน</button>@else<form method="POST" action="{{ route('production.orders.material-return', $order) }}" class="js-shop-create">@csrf<label for="shop-floor-return-reason" class="form-label">เหตุผลการรับคืนวัตถุดิบ</label><textarea class="form-control mb-2" id="shop-floor-return-reason" name="reason" minlength="10" maxlength="500" required>คืนวัตถุดิบจาก WO {{ $order->document_number }}</textarea><button class="btn btn-app-soft" type="submit"><i class="bx bx-undo me-1" aria-hidden="true"></i>สร้างร่างรับคืนวัตถุดิบ</button></form>@endif
                            <button class="btn btn-app-soft mt-3 js-shop-scrap" type="button" data-url="{{ route('production.orders.non-recoverable-scrap', $order) }}" data-uom-id="{{ $order->uom_id }}"><i class="bx bx-recycle me-1" aria-hidden="true"></i>บันทึกร่าง Scrap</button>
                        @endif
                        @if($order->status === 'RELEASED' && auth()->user()->hasPermission('production.orders.cancel'))<div class="sf-danger-zone"><h3>ยกเลิกใบสั่งผลิต</h3><p>ยกเลิกการดำเนินงาน โดยเก็บเอกสารและประวัติไว้</p><button class="btn btn-app-danger js-shop-action" type="button" data-url="{{ route('production.orders.cancel', $order) }}"><i class="bx bx-x-circle me-1" aria-hidden="true"></i>ยกเลิกเอกสาร</button></div>@endif
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
@if($canReportIssue)
<div class="modal fade sf-touch-modal" id="production-issue-modal" tabindex="-1" aria-labelledby="production-issue-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="production-issue-form" method="POST" action="{{ route('production.shop-floor.issues.report', $order) }}">@csrf<div class="modal-header"><h2 class="modal-title fs-5" id="production-issue-title">แจ้งปัญหา · {{ $order->document_number }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div><div class="modal-body"><p>แจ้ง Supervisor เพื่อตรวจสอบ · การแจ้งปัญหาไม่เปลี่ยนสถานะหรือพักงานอัตโนมัติ</p><label class="form-label" for="production-issue-severity">ความรุนแรง *</label><div id="production-issue-error" class="alert alert-danger d-none" role="alert"></div><select class="form-select" id="production-issue-severity" name="severity" aria-describedby="production-issue-severity-error" required><option value="HIGH">สูง — ต้องตรวจสอบเร่งด่วน</option><option value="MEDIUM" selected>กลาง</option><option value="LOW">ต่ำ</option></select><div class="invalid-feedback" id="production-issue-severity-error"></div><label class="form-label mt-3" for="production-issue-description">รายละเอียดปัญหา *</label><textarea class="form-control" id="production-issue-description" name="description" aria-describedby="production-issue-description-error" rows="4" minlength="10" maxlength="2000" required placeholder="ระบุปัญหาอย่างน้อย 10 ตัวอักษร"></textarea><div class="invalid-feedback" id="production-issue-description-error"></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button><button class="btn btn-app-primary" type="submit">แจ้งปัญหา</button></div></form></div></div>
@endif
@endsection
@push('scripts')
<script src="{{ asset('js/production-shop-floor.js') }}?v={{ filemtime(public_path('js/production-shop-floor.js')) }}"></script>
@endpush
