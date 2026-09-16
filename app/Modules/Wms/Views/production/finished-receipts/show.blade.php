@extends('Wms::layout')
@php($productionMode = true)
@php($wmsDecimal = \App\Modules\Wms\Support\WmsDecimal::class)
@php($statusLabels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock และบัญชีแล้ว', 'VOID' => 'ยกเลิกเอกสาร', 'REVERSED' => 'ยกเลิกเอกสารแล้ว'])
@php($statusClasses = ['DRAFT' => 'neutral', 'APPROVED' => 'info', 'POSTED' => 'success', 'VOID' => 'danger', 'REVERSED' => 'warning'])
@php($directionLabels = ['GAIN' => 'เพิ่มสินค้า', 'LOSS' => 'ลดสินค้า']) @php($events = ['wms.inventory_adjustment.created' => 'สร้างร่างเอกสาร', 'wms.inventory_adjustment.updated' => 'แก้ไขร่างเอกสาร', 'wms.inventory_adjustment.approved' => 'อนุมัติเอกสาร', 'wms.inventory_adjustment.posted' => 'ลง Stock และบัญชี', 'wms.inventory_adjustment.deleted' => 'ลบร่างเอกสาร', 'wms.inventory_adjustment.reversed' => 'ยกเลิกเอกสาร', 'wms.inventory_adjustment.document_reversed' => 'ยกเลิกเอกสาร'])
@php(
    $displayValue = static function ($value): string {
        if (is_array($value)) {
            return implode(', ', array_map(static fn($part): string => is_scalar($part) ? (string) $part : json_encode($part, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $value));
        }
        return $value === null ? '' : (string) $value;
    }
)
@php(
    $normalizeText = static function ($model, array $attributes) use ($displayValue): void {
        if (!$model) {
            return;
        }
        foreach ($attributes as $attribute) {
            if (is_array($model->getAttribute($attribute))) {
                $model->setAttribute($attribute, $displayValue($model->getAttribute($attribute)));
            }
        }
    }
)
@php($normalizeText($document, ['document_number', 'reason'])); @php($normalizeText($sourceIssue ?? null, ['document_number', 'reason'])); @php(
    $document->lines->each(function ($line) use ($normalizeText): void {
        $normalizeText($line, ['direction', 'quantity', 'value', 'reason']);
        $normalizeText($line->item, ['code', 'name']);
        $normalizeText($line->uom, ['code']);
        $normalizeText($line->movement, ['source_reference']);
        $normalizeText($line->allocation, ['method', 'value']);
        $normalizeText($line->allocation?->journalEntry, ['entry_number']);
    })
); @php(
    $history->each(function ($event) use ($normalizeText): void {
        $normalizeText($event->user, ['name']);
    })
)
@section('title', ($productionMode ?? false ? 'รายละเอียดรับสินค้าผลิตเสร็จ' : 'รายละเอียด Adjustment') . ' | WMS')
@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">WMS /
                    {{ $productionMode ?? false ? 'PRODUCTION RECEIPT' : 'INVENTORY ADJUSTMENT' }}</p>
                <h1 class="h3 mb-2">{{ $displayValue($document->document_number) }}</h1>
                <p class="text-secondary mb-0">
                    {{ $productionMode ?? false ? 'รับสินค้าผลิตเสร็จ' : 'ปรับปรุงสินค้าคงเหลือ' }} ·
                    {{ $displayValue($document->warehouse?->code) }} · {{ $document->document_date?->format($dateFormat) }}
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary"
                    href="{{ $productionMode ?? false ? route('wms.production.finished-receipts.index') : route('wms.production.finished-receipts.index') }}"
                    title="กลับหน้ารายการ" aria-label="กลับหน้ารายการ"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
                @if ($document->status === 'DRAFT' && auth()->user()->hasPermission('wms.inventory-adjustments.update'))
                    <a class="btn btn-app-soft"
                        href="{{ $productionMode ?? false ? route('wms.production.finished-receipts.edit', $document) : route('wms.production.finished-receipts.edit', $document) }}"
                        title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข</a>
                    @endif @if ($document->status === 'DRAFT' && auth()->user()->hasPermission('wms.inventory-adjustments.approve'))
                        <button class="btn btn-app-primary js-doc-action" data-action="approve" title="อนุมัติ"
                            aria-label="อนุมัติ"><i class="bx bx-check me-1" aria-hidden="true"></i>อนุมัติ</button>
                        @endif @if ($document->status === 'DRAFT' && auth()->user()->hasPermission('wms.inventory-adjustments.delete'))
                            <button class="btn btn-app-danger js-doc-delete" type="button"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบร่าง</button>
                        @endif @if (
                            $document->status === 'APPROVED' &&
                                config(
                                    $productionMode ?? false
                                        ? 'erp.inventory.manual_production_receipt_posting_enabled'
                                        : 'erp.inventory.adjustment_posting_enabled',
                                    false) &&
                                auth()->user()->hasPermission('wms.inventory-adjustments.post'))
                            <button class="btn btn-app-primary js-doc-action" data-action="post" title="ลง Stock และบัญชี"
                                aria-label="ลง Stock และบัญชี" @disabled(!($postReadiness['ready'] ?? true))><i class="bx bx-send me-1"
                                aria-hidden="true"></i>ลง Stock และบัญชี</button>
                            @endif @if (
                                $document->status === 'POSTED' &&
                                    $document->reversal_status !== 'REVERSED' &&
                                    auth()->user()->hasPermission('wms.inventory-adjustments.reverse'))
                                <button class="btn btn-app-danger js-doc-reverse" title="ยกเลิกเอกสาร"
                                    aria-label="ยกเลิกเอกสาร"><i class="bx bx-undo me-1"
                                        aria-hidden="true"></i>ยกเลิกเอกสาร</button>
                            @endif
            </div>
        </div>
        <div class="alert alert-info border-0 mb-4"><strong>ขั้นตอนถัดไป:</strong> {{ $document->status === 'DRAFT' ? 'ตรวจสอบข้อมูล แล้วแก้ไขหรือกด “อนุมัติ”' : ($document->status === 'APPROVED' ? (($postReadiness['ready'] ?? false) ? 'กด “ลง Stock และบัญชี” เพื่อบันทึกผลกระทบทั้งหมด' : 'แก้ไข blocker ที่แสดงด้านล่างก่อนลง Stock และบัญชี') : ($document->status === 'POSTED' ? 'เอกสารเสร็จสมบูรณ์ หากพบข้อผิดพลาดให้ยกเลิกเอกสารเพื่อสร้างรายการย้อนกลับ' : 'เอกสารนี้ไม่มีขั้นตอนที่ต้องดำเนินการต่อ')) }}</div>
        @if ($document->status === 'APPROVED' && !($postReadiness['ready'] ?? true))
            <div class="alert alert-warning border-0"><strong>ยังลงบัญชีไม่ได้</strong>
                <ul class="mb-0 mt-1">
                    @foreach ($postReadiness['blockers'] as $blocker)
                        <li>{{ is_array($blocker) ? $blocker['message'] ?? 'ไม่ทราบสาเหตุ' : $blocker }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if (($productionMode ?? false) && ($sourceIssue ?? null))
            <div id="production-source-document" class="card border-primary-subtle shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                        <div>
                            <p class="eyebrow mb-2">SOURCE DOCUMENT</p>
                            <h2 class="h5 mb-1">เอกสารเบิกวัตถุดิบต้นทาง {{ $sourceIssue->document_number }}</h2>
                            <p class="text-secondary mb-0">วันที่ {{ $sourceIssue->document_date?->format($dateFormat) }} ·
                                {{ $sourceIssue->reason ?: 'ไม่ระบุเหตุผล' }}</p>
                        </div>
                        <div class="text-lg-end">
                            <div class="small text-secondary">ต้นทุนวัตถุดิบรวม</div>
                            <div class="fs-4 fw-semibold text-primary">{{ $wmsDecimal::format($sourceIssueCostTotal) }}
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>สินค้า</th>
                                    <th>หน่วย</th>
                                    <th class="text-end">จำนวนเบิก</th>
                                    <th class="text-end">ต้นทุนรวม</th>
                                    <th>สถานะต้นทุน</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sourceIssue->lines as $sourceLine)
                                    <tr>
                                        <td>{{ $sourceLine->item?->code }} · {{ $sourceLine->item?->name }}</td>
                                        <td>{{ $sourceLine->uom?->code ?: '-' }}</td>
                                        <td class="text-end">{{ $wmsDecimal::format($sourceLine->quantity) }}</td>
                                        <td class="text-end">
                                            {{ $wmsDecimal::format($sourceLine->allocation ? abs((float) $sourceLine->allocation->value) : null) }}
                                        </td>
                                        <td>{{ $sourceLine->allocation?->cost_status === 'FINAL' ? 'Final' : 'Pending / Unlinked' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @elseif($productionMode ?? false)
            <div class="alert alert-secondary border-0 mb-4">ไม่พบเอกสารเบิกวัตถุดิบต้นทางที่เชื่อมโยงกับใบรับผลิตนี้</div>
        @endif
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap gap-2 mb-3"><span
                        class="badge app-status-{{ $statusClasses[$document->status] ?? 'neutral' }}">{{ $statusLabels[$document->status] ?? $document->status }}</span><span
                        class="badge app-status-neutral">{{ $document->lines->count() }} รายการ</span></div>
                <div class="row g-3">
                    <div class="col-md-3"><small
                            class="text-secondary d-block">วันที่เอกสาร</small><strong>{{ $document->document_date?->format($dateFormat) }}</strong>
                    </div>
                    <div class="col-md-3"><small
                            class="text-secondary d-block">คลังสินค้า</small><strong>{{ $document->warehouse?->code }} ·
                            {{ $document->warehouse?->name }}</strong></div>
                    <div class="col-md-6"><small
                            class="text-secondary d-block">เหตุผล</small><strong>{{ $document->reason }}</strong></div>
                </div>
            </div>
        </div>
        @if ($productionMode ?? false)
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="text-secondary small">จำนวนรับผลิตรวม</div>
                            <div class="fs-3 fw-semibold">{{ $wmsDecimal::format($receiptSummary['quantity']) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="text-secondary small">มูลค่ารับผลิตรวม</div>
                            <div class="fs-3 fw-semibold">{{ $wmsDecimal::format($receiptSummary['value']) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="text-secondary small">ต้นทุนเฉลี่ยต่อหน่วย</div>
                            <div class="fs-3 fw-semibold text-primary">
                                {{ $wmsDecimal::format($receiptSummary['average_unit_cost']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
        <div id="production-receipt-lines" class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">รายการสินค้า</h2>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>สินค้า</th>
                                <th>ทิศทาง</th>
                                <th>หน่วย</th>
                                <th class="text-end">จำนวน</th>
                                <th class="text-end">มูลค่า</th>
                                @if ($productionMode ?? false)
                                    <th class="text-end">ต้นทุนต่อหน่วย</th>
                                @endif
                                <th>
                                    สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($document->lines as $line)
                                <tr>
                                    <td>{{ $line->line_number }}</td>
                                    <td>{{ $line->item?->code }} · {{ $line->item?->name }}</td>
                                    <td class="{{ $line->direction === 'GAIN' ? 'text-success' : 'text-danger' }}">
                                        {{ $directionLabels[$line->direction] ?? $line->direction }}</td>
                                    <td>{{ $line->uom?->code }}</td>
                                    <td class="text-end">{{ $wmsDecimal::format($line->quantity) }}</td>
                                    <td class="text-end">{{ $wmsDecimal::format($line->value) }}</td>
                                    @if ($productionMode ?? false)
                                        <td class="text-end">{{ $wmsDecimal::format($lineUnitCosts[$line->id] ?? '0') }}
                                        </td>
                                    @endif
                                    <td>
                                        <span
                                            class="badge app-status-{{ $statusClasses[$line->status] ?? 'neutral' }}">{{ $statusLabels[$line->status] ?? $line->status }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">Stock Movement / Cost Allocation / Journal</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>สินค้า</th>
                                <th>Movement</th>
                                <th>Cost Allocation</th>
                                <th>Journal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($document->lines as $line)
                                <tr>
                                    <td>{{ $line->item?->code }}</td>
                                    <td>{{ $line->movement?->source_reference ?? 'ยังไม่มี' }}</td>
                                    <td>{{ $line->allocation?->method ?? 'ยังไม่มี' }} @if ($line->allocation)
                                            · {{ $wmsDecimal::format($line->allocation->value) }}
                                        @endif
                                    </td>
                                    <td>{{ $line->allocation?->journalEntry?->entry_number ?? 'ยังไม่มี' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">ประวัติเอกสาร</h2>
                @forelse($history as $event)
                    <div class="d-flex gap-3 border-bottom py-2">
                        <div class="small text-secondary text-nowrap">
                            {{ $event->created_at?->format($dateFormat . ' H:i') }}</div>
                        <div><strong>{{ $events[$event->action] ?? $event->action }}</strong>
                            <div class="small text-secondary">{{ $event->user?->name ?? 'ระบบ' }}</div>
                            @if ($event->reason)
                                <div class="small mt-1"><span class="text-secondary">เหตุผล:</span> {{ $event->reason }}
                                </div>
                            @endif
                        </div>
                </div>@empty<p class="text-secondary mb-0">ยังไม่มีประวัติ</p>
                @endforelse
            </div>
        </div>
    </div>
    @if (($productionMode ?? false) && $document->status !== 'POSTED' && $productionReadiness)
        <div class="alert alert-info border-0 mt-4"><strong>Production Mapping Preflight</strong>
            <div class="small mt-1">ตรวจ WIP, Finished Goods และ Production Variance ก่อนเปิด Post</div>
            <ul class="mb-0 mt-2">
                @forelse($productionReadiness['blockers'] as $blocker)
                <li>{{ $blocker['message'] ?? 'Mapping ยังไม่พร้อม' }}</li>@empty<li class="text-success">Mapping
                        พร้อมตาม contract</li>
                @endforelse
            </ul><a class="btn btn-sm btn-outline-primary mt-2"
                href="{{ route('accounting.account-mappings.index', ['event_code' => 'production.finished_receipt']) }}">ตรวจ
                Account Mapping</a>
        </div>
    @endif
@endsection
@push('scripts')
    <script>
        $(function() {
            const source = $('#production-source-document');
            const lines = $('#production-receipt-lines');
            if (source.length && lines.length) source.insertBefore(lines);
        });
    </script>
@endpush
@push('scripts')
    <script>
        $(function() {
            $('.js-doc-delete').on('click', function() {
                Swal.fire({
                    icon: 'warning',
                    title: 'ลบร่างเอกสาร?',
                    text: 'รายการนี้จะถูกลบและไม่สามารถกู้คืนได้',
                    showCancelButton: true,
                    confirmButtonText: 'ลบร่าง',
                    cancelButtonText: 'กลับ'
                }).then(x => {
                    if (!x.isConfirmed) return;
                    $.ajax({
                        url: '{{ route('wms.production.finished-receipts.destroy', $document) }}',
                        method: 'DELETE',
                        data: {
                            _token: $('meta[name=csrf-token]').attr('content')
                        }
                    }).done(r => {
                        Swal.fire({
                            icon: 'success',
                            text: r.msg,
                            timer: 1200,
                            showConfirmButton: false
                        }).then(() => window.location.href = r.redirect)
                    }).fail(e => Swal.fire({
                        icon: 'error',
                        text: e.responseJSON?.message || 'ลบเอกสารไม่สำเร็จ'
                    }));
                });
            });
        });
    </script>
@endpush
@push('scripts')
    <script>
        $(function() {
            $('.js-doc-action').on('click', function() {
                const b = $(this),
                    a = b.data('action'),
                    u = a === 'approve' ?
                    '{{ route('wms.production.finished-receipts.approve', $document) }}' :
                    '{{ route('wms.production.finished-receipts.post', $document) }}';
                Swal.fire({
                    icon: 'warning',
                    title: a === 'approve' ? 'อนุมัติใบรับผลิต?' : 'ลง Stock และบัญชีใบรับผลิต?',
                    showCancelButton: true,
                    confirmButtonText: 'ยืนยัน',
                    cancelButtonText: 'กลับ'
                }).then(x => {
                    if (!x.isConfirmed) return;
                    $.post(u, {
                        _token: $('meta[name=csrf-token]').attr('content')
                    }).done(r => {
                        Swal.fire({
                            icon: 'success',
                            text: r.msg,
                            timer: 1200,
                            showConfirmButton: false
                        }).then(() => location.reload())
                    }).fail(x => Swal.fire({
                        icon: 'error',
                        text: x.responseJSON?.message || 'ดำเนินการไม่สำเร็จ'
                    }));
                });
            });
            $('.js-doc-reverse').on('click', function() {
                Swal.fire({
                    icon: 'warning',
                    title: 'ยกเลิกเอกสารรับผลิต?',
                    input: 'textarea',
                    inputPlaceholder: 'เหตุผลอย่างน้อย 10 ตัวอักษร',
                    showCancelButton: true,
                    confirmButtonText: 'ยืนยัน',
                    cancelButtonText: 'กลับ',
                    preConfirm: v => {
                        if (!v || v.trim().length < 10) {
                            Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร');
                            return false;
                        }
                        return v;
                    }
                }).then(x => {
                    if (!x.isConfirmed) return;
                    $.post('{{ route('wms.production.finished-receipts.reverse', $document) }}', {
                        _token: $('meta[name=csrf-token]').attr('content'),
                        reversal_date: '{{ now()->format('Y-m-d') }}',
                        reason: x.value
                    }).done(r => {
                        Swal.fire({
                            icon: 'success',
                            text: r.msg,
                            timer: 1200,
                            showConfirmButton: false
                        }).then(() => location.reload())
                    }).fail(e => Swal.fire({
                        icon: 'error',
                        text: e.responseJSON?.message || 'ยกเลิกเอกสารไม่สำเร็จ'
                    }));
                });
            });
        });
    </script>
@endpush
