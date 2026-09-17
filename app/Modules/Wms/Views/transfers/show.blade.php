@extends('Wms::layout')

@php
    $eventLabels = [
        'DISPATCH' => 'ส่งสินค้าออกจากคลังต้นทาง',
        'ACCEPT' => 'รับสินค้าเข้าคลังปลายทาง',
        'REJECT' => 'ปฏิเสธการรับสินค้า',
    ];
    $selectedWarehouseId = (int) request()->attributes->get('selectedWarehouse')->id;
    $backRoute = (int) $transfer->destination_warehouse_id === $selectedWarehouseId
        ? 'wms.transfers.incoming.index'
        : 'wms.transfers.outgoing.index';
    $isSource = (int) $transfer->source_warehouse_id === $selectedWarehouseId;
    $isDestination = (int) $transfer->destination_warehouse_id === $selectedWarehouseId;
@endphp

@section('title', 'รายละเอียดใบโอนสินค้า | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / TRANSFER</p>
            <h1 class="h3 mb-2">{{ $transfer->document_number }}</h1>
            <span class="badge {{ $transferStatusClasses[$transfer->status] ?? 'app-status-neutral' }}">
                {{ $transferStatusLabels[$transfer->status] ?? $transfer->status }}
            </span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="{{ route($backRoute) }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
            @if($transfer->status === 'DRAFT' && $isSource && auth()->user()->hasPermission('wms.transfers.update'))
                <a class="btn btn-app-soft" href="{{ route('wms.transfers.edit', $transfer) }}"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข</a>
            @endif
            @if($transfer->status === 'DRAFT' && $isSource && auth()->user()->hasPermission('wms.transfers.dispatch'))
                <button class="btn btn-app-primary" id="transfer-dispatch" type="button" data-url="{{ route('wms.transfers.dispatch', $transfer) }}"><i class="bx bx-send me-1" aria-hidden="true"></i>ส่งออกจากคลัง</button>
            @endif
            @if(in_array($transfer->status, ['DISPATCHED', 'PARTIALLY_ACCEPTED'], true) && $isDestination && auth()->user()->hasPermission('wms.transfers.complete'))
                <a class="btn btn-app-primary" href="{{ route('wms.transfers.receive', $transfer) }}"><i class="bx bx-check-circle me-1" aria-hidden="true"></i>รับโอนสินค้า</a>
            @endif
            @if($transfer->status === 'REJECTED' && $isSource && auth()->user()->hasPermission('wms.transfers.void'))
                <button class="btn btn-app-danger" id="transfer-void" type="button" data-url="{{ route('wms.transfers.void', $transfer) }}"><i class="bx bx-x-circle me-1" aria-hidden="true"></i>ยกเลิกเอกสาร</button>
            @endif
            @if($transfer->status === 'DRAFT' && $isSource && ! $transfer->events->isNotEmpty() && auth()->user()->hasPermission('wms.transfers.delete'))
                <button class="btn btn-app-danger" id="transfer-delete" type="button" data-url="{{ route('wms.transfers.destroy', $transfer) }}"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบร่าง</button>
            @endif
        </div>
    </div>

    @if($transfer->status === 'DRAFT' && $isSource)
        <div class="alert alert-info border-0 mb-4">ตรวจสอบคลังปลายทางและรายการสินค้าแล้ว กด <strong>ส่งออกจากคลัง</strong> เพื่อเริ่มการโอน</div>
    @elseif(in_array($transfer->status, ['DISPATCHED', 'PARTIALLY_ACCEPTED'], true) && $isSource)
        <div class="alert alert-info border-0 mb-4">ส่งสินค้าออกแล้ว กำลังรอคลังปลายทางรับเข้า</div>
    @elseif(in_array($transfer->status, ['DISPATCHED', 'PARTIALLY_ACCEPTED'], true) && $isDestination)
        <div class="alert alert-info border-0 mb-4">ตรวจสอบจำนวนที่ได้รับ แล้วกด <strong>รับโอนสินค้า</strong> เพื่อบันทึกเข้าคลังปลายทาง</div>
    @elseif($transfer->status === 'REJECTED' && $isSource)
        <div class="alert alert-warning border-0 mb-4">คลังปลายทางปฏิเสธการรับสินค้า ตรวจสอบเหตุผล แล้วใช้ <strong>ยกเลิกเอกสาร</strong> หากต้องปิดรายการนี้</div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <div class="row g-3">
                <div class="col-12 col-md-3"><div class="text-secondary small">วันที่เอกสาร</div><div class="fw-semibold">{{ $transfer->document_date?->format($dateFormat) ?: '-' }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">คลังต้นทาง</div><div class="fw-semibold">{{ collect([$transfer->sourceWarehouse?->code, $transfer->sourceWarehouse?->name])->filter()->implode(' · ') ?: '-' }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">คลังปลายทาง</div><div class="fw-semibold">{{ collect([$transfer->destinationWarehouse?->code, $transfer->destinationWarehouse?->name])->filter()->implode(' · ') ?: '-' }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">ผู้สร้าง</div><div class="fw-semibold">{{ $transfer->creator?->name ?: '-' }}</div></div>
                <div class="col-12"><div class="text-secondary small">หมายเหตุ</div><div class="fw-semibold text-break">{{ $transfer->note ?: '-' }}</div></div>
                @if($transfer->dispatched_at)
                    <div class="col-12 col-md-3"><div class="text-secondary small">วันที่ส่งออก</div><div class="fw-semibold">{{ $transfer->dispatched_at->format($dateFormat.' H:i') }}</div></div>
                @endif
            </div>
            @if($transfer->dispatch_reason || $transfer->reject_reason || $transfer->void_reason)
                <hr>
                <div class="small text-secondary">เหตุผล/หมายเหตุ</div>
                @if($transfer->dispatch_reason)<div><span class="fw-semibold">ส่งออก:</span> {{ $transfer->dispatch_reason }}</div>@endif
                @if($transfer->reject_reason)<div class="text-danger"><span class="fw-semibold">ปฏิเสธ:</span> {{ $transfer->reject_reason }}</div>@endif
                @if($transfer->void_reason)<div class="text-danger"><span class="fw-semibold">ยกเลิก:</span> {{ $transfer->void_reason }}</div>@endif
            @endif
        </div>
    </div>

    <div class="card border-primary-subtle shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div><h2 class="h5 mb-1">สถานะการรับเข้าปลายทาง</h2><p class="text-secondary small mb-0">ติดตามการรับเข้าด้วยเลขที่ Transfer เดียวกัน</p></div>
            </div>
            <div class="row g-3">
                <div class="col-12 col-md-3"><div class="text-secondary small">เลขที่เอกสารรับเข้า</div><div class="fw-semibold">{{ $transfer->document_number }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">คลังปลายทาง</div><div class="fw-semibold">{{ collect([$transfer->destinationWarehouse?->code, $transfer->destinationWarehouse?->name])->filter()->implode(' · ') ?: '-' }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">สถานะรับเข้า</div><span class="badge {{ $transferStatusClasses[$transfer->status] ?? 'app-status-neutral' }}">{{ $receiptStatusLabels[$transfer->status] ?? $transfer->status }}</span></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">วันที่รับเข้าล่าสุด</div><div class="fw-semibold">{{ $transfer->events->where('event_type', 'ACCEPT')->sortByDesc('created_at')->first()?->created_at?->format($dateFormat.' H:i') ?: '-' }}</div></div>
                <div class="col-6 col-md-3"><div class="text-secondary small">วางแผน</div><div class="fw-semibold">{{ $receiptSummary['planned'] }}</div></div>
                <div class="col-6 col-md-3"><div class="text-secondary small text-primary">ส่งออกแล้ว</div><div class="fw-semibold text-primary">{{ $receiptSummary['dispatched'] }}</div></div>
                <div class="col-6 col-md-3"><div class="text-secondary small text-success">รับแล้ว</div><div class="fw-semibold text-success">{{ $receiptSummary['accepted'] }}</div></div>
                <div class="col-6 col-md-3"><div class="text-secondary small text-danger">ปฏิเสธ</div><div class="fw-semibold text-danger">{{ $receiptSummary['rejected'] }}</div></div>
                <div class="col-6 col-md-3"><div class="text-secondary small text-info">คงเหลือรอรับ</div><div class="fw-semibold text-info">{{ $receiptSummary['remaining'] }}</div></div>
            </div>
        </div>
    </div>

    @include('Wms::transfers._photos')

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <h2 class="h5 mb-3">รายการสินค้า</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>#</th><th>สินค้า</th><th>หน่วย Stock</th><th class="text-end">วางแผน</th><th class="text-end text-primary">ส่งออกแล้ว</th><th class="text-end text-success">รับแล้ว</th><th class="text-end text-danger">ปฏิเสธ</th><th class="text-end text-info">คงเหลือรอรับ</th></tr></thead>
                    <tbody>
                    @forelse($lines as $line)
                        <tr>
                            <td>{{ $line['line_number'] }}</td>
                            <td>{{ $line['item_label'] }}</td>
                            <td>{{ $line['uom_label'] }}</td>
                            <td class="text-end">{{ $line['planned_base_quantity'] }}</td>
                            <td class="text-end text-primary">{{ $line['dispatched_base_quantity'] }}</td>
                            <td class="text-end text-success">{{ $line['accepted_base_quantity'] }}</td>
                            <td class="text-end text-danger">{{ $line['rejected_base_quantity'] }}</td>
                            <td class="text-end text-info">{{ $line['remaining_base_quantity'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-secondary py-4">ไม่พบรายการสินค้า</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <h2 class="h5 mb-3">ประวัติเอกสาร</h2>
            <div class="vstack gap-3">
            @forelse($transfer->events->sortByDesc('created_at') as $event)
                <div class="border-bottom pb-3">
                    <div class="d-flex flex-wrap justify-content-between gap-2"><span class="fw-semibold">{{ $eventLabels[$event->event_type] ?? $event->event_type }}</span><span class="text-secondary small">{{ $event->created_at?->format($dateFormat.' H:i') }}</span></div>
                    <div class="small text-secondary">{{ $event->creator?->name ?: 'ระบบ' }} · รายการที่ {{ $event->line?->line_number ?: '-' }} · จำนวน {{ $event->base_quantity }}</div>
                    @if($event->reason)<div class="small mt-1">เหตุผล: {{ $event->reason }}</div>@endif
                </div>
            @empty
                <div class="text-secondary">ยังไม่มีประวัติการเคลื่อนไหว</div>
            @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#transfer-dispatch').on('click', function () {
        var button = $(this);
        Swal.fire({
            title: 'ส่ง Transfer ออกจากคลัง?',
            html: '<label class="form-label text-start d-block">วันที่ส่งออก</label><input id="transfer-dispatch-date" class="form-control" type="date" value="{{ now()->toDateString() }}"><label class="form-label text-start d-block mt-3">เหตุผล</label><textarea id="transfer-dispatch-reason" class="form-control" rows="3" placeholder="ระบุเหตุผลการส่งออก"></textarea>',
            showCancelButton: true,
            confirmButtonText: 'ส่งออก',
            cancelButtonText: 'ยกเลิก',
            focusConfirm: false,
            preConfirm: function () {
                var reason = $('#transfer-dispatch-reason').val().trim();
                if (!reason) { Swal.showValidationMessage('กรุณาระบุเหตุผล'); return false; }
                return {business_date: $('#transfer-dispatch-date').val(), reason: reason};
            }
        }).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.post(button.data('url'), $.extend({_token: '{{ csrf_token() }}'}, result.value))
                .done(function (response) { Swal.fire({icon: 'success', text: response.msg || 'ส่งออกจากคลังแล้ว', timer: 1400, showConfirmButton: false}).then(function () { location.reload(); }); })
                .fail(function (xhr) { Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถส่งออกจากคลังได้'}); })
                .always(function () { button.prop('disabled', false); });
        });
    });
    $('#transfer-void').on('click', function () {
        var button = $(this);
        Swal.fire({icon: 'warning', title: 'ยกเลิกเอกสาร Transfer?', input: 'textarea', inputPlaceholder: 'เหตุผลอย่างน้อย 10 ตัวอักษร', showCancelButton: true, confirmButtonText: 'ยกเลิกเอกสาร', cancelButtonText: 'กลับ', inputValidator: function (value) { return !value || value.trim().length < 10 ? 'กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร' : undefined; }}).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.post(button.data('url'), {_token: '{{ csrf_token() }}', reason: result.value}).done(function (response) { Swal.fire({icon: 'success', text: response.msg || 'ยกเลิกเอกสารแล้ว', timer: 1400, showConfirmButton: false}).then(function () { location.reload(); }); }).fail(function (xhr) { Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถยกเลิกเอกสารได้'}); }).always(function () { button.prop('disabled', false); });
        });
    });
    $('#transfer-delete').on('click', function () {
        var button = $(this);
        Swal.fire({icon: 'warning', title: 'ลบร่างใบโอนสินค้า?', text: 'ลบร่างจะลบเฉพาะเอกสารที่ยังไม่มีการเคลื่อนไหว หากต้องการเก็บประวัติให้ใช้ยกเลิกเอกสาร', showCancelButton: true, confirmButtonText: 'ลบร่าง', cancelButtonText: 'กลับ'}).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.ajax({url: button.data('url'), method: 'DELETE', data: {_token: '{{ csrf_token() }}'}}).done(function (response) { Swal.fire({icon: 'success', text: response.msg || 'ลบร่างใบโอนสินค้าแล้ว', timer: 1400, showConfirmButton: false}).then(function () { location.href = response.redirect; }); }).fail(function (xhr) { Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถลบร่างใบโอนสินค้าได้'}); }).always(function () { button.prop('disabled', false); });
        });
    });
});
</script>
@endpush
