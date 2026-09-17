@extends('Wms::layout')
@php
    $productionMode = $productionMode ?? ($document->issue_type === 'PRODUCTION');
@endphp
@section('title', ($productionMode ? 'ใบเบิกวัตถุดิบผลิต ' : 'ใบเบิกสินค้า ').$document->document_number.' | WMS')
@php
    $statusLabels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิก'];
    $statusClasses = ['DRAFT' => 'app-status-neutral', 'APPROVED' => 'app-status-info', 'POSTED' => 'app-status-success', 'VOID' => 'app-status-danger'];
@endphp
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / {{ $productionMode ? 'PRODUCTION MATERIAL ISSUE' : 'ISSUE' }}</p>
            <h1 class="h3 mb-2">{{ $document->document_number }}</h1>
            <span class="badge {{ $statusClasses[$document->status] ?? 'app-status-neutral' }}">{{ $statusLabels[$document->status] ?? $document->status }}</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="{{ $productionMode ? route('wms.production.material-issues.index') : route('wms.issues.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
            @if($document->status === 'DRAFT' && auth()->user()->hasPermission('wms.issues.update'))
                <a class="btn btn-app-soft" href="{{ $productionMode ? route('wms.production.material-issues.edit', $document) : route('wms.issues.edit', $document) }}"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข</a>
            @endif
            @if($document->status === 'DRAFT' && auth()->user()->hasPermission('wms.issues.approve'))
                <button class="btn btn-app-primary js-act" data-action="approve"><i class="bx bx-check me-1" aria-hidden="true"></i>อนุมัติ</button>
            @endif
            @if($document->status === 'APPROVED' && auth()->user()->hasPermission('wms.issues.post'))
                <button class="btn btn-app-primary js-act" data-action="post"><i class="bx bx-send me-1" aria-hidden="true"></i>ลง Stock</button>
            @endif
            @if(in_array($document->status, ['DRAFT', 'APPROVED'], true) && auth()->user()->hasPermission('wms.issues.approve'))
                <button class="btn btn-app-danger js-cancel" type="button" data-url="{{ $productionMode ? route('wms.production.material-issues.cancel', $document) : route('wms.issues.cancel', $document) }}"><i class="bx bx-x-circle me-1" aria-hidden="true"></i>ยกเลิกเอกสาร</button>
            @endif
            @if($document->status === 'POSTED')
                @if($productionMode && auth()->user()->hasPermission('wms.inventory-adjustments.create'))
                    <a class="btn btn-app-primary" href="{{ route('wms.production.finished-receipts.create', ['source_issue_id' => $document->id]) }}"><i class="bx bx-package me-1" aria-hidden="true"></i>สร้างใบรับผลิตเสร็จ</a>
                @endif
                @if(auth()->user()->hasPermission('wms.issue-returns.create'))
                    <a class="btn btn-app-primary" href="{{ route('wms.issue-returns.create', ['issue_document_id' => $document->id]) }}"><i class="bx bx-undo me-1" aria-hidden="true"></i>สร้างใบรับคืน</a>
                @endif
            @endif
            @if($document->status === 'DRAFT' && auth()->user()->hasPermission('wms.issues.delete'))
                <button class="btn btn-app-danger js-delete" type="button" data-url="{{ $productionMode ? route('wms.production.material-issues.destroy', $document) : route('wms.issues.destroy', $document) }}"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบร่าง</button>
            @endif
        </div>
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><div class="row g-3"><div class="col-12 col-md-3"><small class="text-secondary">วันที่เอกสาร</small><div class="fw-semibold">{{ $document->document_date?->format($dateFormat) ?: '-' }}</div></div><div class="col-12 col-md-3"><small class="text-secondary">ประเภทการเบิก</small><div class="fw-semibold">{{ ['GENERAL' => 'เบิกทั่วไป', 'PRODUCTION' => 'เบิกเข้าผลิต', 'PROJECT' => 'เบิกโครงการ'][$document->issue_type] ?? $document->issue_type }}</div></div><div class="col-12 col-md-6"><small class="text-secondary">เหตุผล</small><div class="fw-semibold">{{ $document->reason ?: '-' }}</div></div></div></div></div>
    @include('Wms::partials.document-photos', ['photoTitle' => 'รูปหลักฐานการเบิกสินค้า', 'photoStoreRoute' => 'wms.issues.photos.store', 'photoPreviewRoute' => 'wms.issues.photos.preview', 'photoUploadPermission' => 'wms.issues.create'])
    @if($document->issueReturns->isNotEmpty())<div class="card border-primary-subtle shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">เอกสารรับคืนจากการเบิก</h2><span class="badge app-status-info">{{ $document->issueReturns->count() }} ใบ</span></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>เลขที่เอกสาร</th><th>วันที่</th><th>สถานะ</th><th class="text-end">รายการ</th><th class="text-end">จำนวนรวม</th><th>จัดการ</th></tr></thead><tbody>@foreach($document->issueReturns as $return)<tr><td>{{ $return->document_number }}</td><td>{{ $return->document_date?->format($dateFormat) ?: '-' }}</td><td><span class="badge {{ ['DRAFT' => 'app-status-neutral', 'APPROVED' => 'app-status-info', 'POSTED' => 'app-status-success', 'VOID' => 'app-status-danger'][$return->status] ?? 'app-status-neutral' }}">{{ ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิก'][$return->status] ?? $return->status }}</span></td><td class="text-end">{{ $return->lines->count() }}</td><td class="text-end">{{ \App\Modules\Wms\Support\WmsDecimal::format($return->lines->sum('quantity')) }}</td><td><a class="btn btn-sm btn-app-soft" href="{{ route('wms.issue-returns.show', $return) }}"><i class="bx bx-file-find me-1" aria-hidden="true"></i>ดูรายละเอียด</a></td></tr>@endforeach</tbody></table></div></div></div>@endif
    @if($productionMode && $document->finishedReceipts->isNotEmpty())<div class="card border-primary-subtle shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">เอกสารรับผลิตเสร็จ</h2><span class="badge app-status-info">{{ $document->finishedReceipts->count() }} ใบ</span></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>เลขที่เอกสาร</th><th>วันที่</th><th>สถานะ</th><th class="text-end">รายการ</th><th class="text-end">มูลค่ารวม</th><th>จัดการ</th></tr></thead><tbody>@foreach($document->finishedReceipts as $receipt)<tr><td>{{ $receipt->document_number }}</td><td>{{ $receipt->document_date?->format($dateFormat) ?: '-' }}</td><td><span class="badge {{ ['DRAFT' => 'app-status-neutral', 'APPROVED' => 'app-status-info', 'POSTED' => 'app-status-success', 'VOID' => 'app-status-danger', 'REVERSED' => 'app-status-warning'][$receipt->status] ?? 'app-status-neutral' }}">{{ ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก', 'REVERSED' => 'กลับรายการแล้ว'][$receipt->status] ?? $receipt->status }}</span></td><td class="text-end">{{ $receipt->lines->count() }}</td><td class="text-end">{{ \App\Modules\Wms\Support\WmsDecimal::format($receipt->lines->sum('value')) }}</td><td><a class="btn btn-sm btn-app-soft" href="{{ route('wms.production.finished-receipts.show', $receipt) }}"><i class="bx bx-file-find me-1" aria-hidden="true"></i>ดูรายละเอียด</a></td></tr>@endforeach</tbody></table></div></div></div>@elseif($productionMode)<div class="alert alert-secondary border-0 mb-4">ยังไม่มีเอกสารรับผลิตเสร็จที่เชื่อมโยงกับใบเบิกนี้</div>@endif
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-1">รายการเบิก</h2><p class="text-secondary small mb-3">คงเหลือพร้อมใช้ ณ ปัจจุบัน ก่อนอนุมัติเอกสาร</p><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>#</th><th>สินค้า</th><th>หน่วย</th><th class="text-end">คงเหลือพร้อมใช้</th><th class="text-end">จำนวนเบิก</th><th>Movement</th><th class="text-end">ต้นทุน</th></tr></thead><tbody>@forelse($document->lines as $line)@php($available = (string) ($stockBalances[$line->item_id.':'.$line->uom_id]?->available ?? '0'))<tr><td>{{ $line->line_number }}</td><td>{{ $line->item?->code }} · {{ $line->item?->name }}</td><td>{{ $line->uom?->code }}</td><td class="text-end {{ \Brick\Math\BigDecimal::of($available)->isLessThan($line->quantity) ? 'text-danger fw-semibold' : 'text-success' }}">{{ \App\Modules\Wms\Support\WmsDecimal::format($available) }}</td><td class="text-end">{{ \App\Modules\Wms\Support\WmsDecimal::format($line->quantity) }}</td><td>{{ $line->movement?->id ? '#'.$line->movement->id : '-' }}</td><td class="text-end">{{ $line->allocation ? \App\Modules\Wms\Support\WmsDecimal::format($line->allocation->value) : '-' }}</td></tr>@empty<tr><td colspan="7" class="text-center text-secondary py-4">ไม่พบรายการเบิก</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">ประวัติเอกสาร</h2>@forelse($history as $h)<div class="border-bottom py-2"><strong>{{ $h->action }}</strong><small class="text-secondary ms-2">{{ $h->created_at?->format($dateFormat.' H:i') }} · {{ $h->user?->name ?? '-' }}</small></div>@empty<div class="text-secondary">ยังไม่มีประวัติ</div>@endforelse</div></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    $('.js-act').on('click', function () {
        const button = $(this), action = button.data('action');
        Swal.fire({icon: 'warning', title: action === 'approve' ? 'อนุมัติใบเบิก?' : 'ลง Stock ใบเบิก?', showCancelButton: true, confirmButtonText: action === 'approve' ? 'อนุมัติ' : 'ลง Stock', cancelButtonText: 'กลับ'}).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.post('{{ route('wms.issues.show', $document) }}/' + action, {_token: $('meta[name=csrf-token]').attr('content')}).done(function () { location.reload(); }).fail(function (xhr) { Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ดำเนินการไม่สำเร็จ'}); }).always(function () { button.prop('disabled', false); });
        });
    });
    $('.js-delete').on('click', function () {
        const button = $(this);
        Swal.fire({icon: 'warning', title: 'ลบร่างใบเบิก?', text: 'ลบร่างจะลบเฉพาะเอกสารที่ยังไม่มีการเคลื่อนไหว', showCancelButton: true, confirmButtonText: 'ลบร่าง', cancelButtonText: 'กลับ'}).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.ajax({url: button.data('url'), method: 'DELETE', data: {_token: $('meta[name=csrf-token]').attr('content')}}).done(function (response) { Swal.fire({icon: 'success', text: response.msg, timer: 1200, showConfirmButton: false}).then(function () { location.href = response.redirect; }); }).fail(function (xhr) { button.prop('disabled', false); Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ลบร่างใบเบิกไม่สำเร็จ'}); });
        });
    });
    $('.js-cancel').on('click', function () {
        const button = $(this);
        Swal.fire({
            icon: 'warning',
            title: 'ยกเลิกเอกสาร?',
            input: 'textarea',
            inputLabel: 'เหตุผลการยกเลิก',
            inputPlaceholder: 'ระบุเหตุผลอย่างน้อย 5 ตัวอักษร',
            showCancelButton: true,
            confirmButtonText: 'ยกเลิกเอกสาร',
            cancelButtonText: 'กลับ',
            inputValidator: function (value) {
                return !value || value.trim().length < 5 ? 'กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร' : undefined;
            }
        }).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.post(button.data('url'), {
                _token: $('meta[name=csrf-token]').attr('content'),
                reason: result.value
            }).done(function (response) {
                Swal.fire({icon: 'success', text: response.msg, timer: 1200, showConfirmButton: false}).then(function () {
                    location.href = response.redirect;
                });
            }).fail(function (xhr) {
                button.prop('disabled', false);
                Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ยกเลิกเอกสารไม่สำเร็จ'});
            });
        });
    });
});
</script>
@endpush
