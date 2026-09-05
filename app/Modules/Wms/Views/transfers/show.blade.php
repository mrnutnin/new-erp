@extends('Wms::layout')

@php
    $statusLabels = [
        'DRAFT' => 'ร่าง',
        'DISPATCHED' => 'ส่งออกแล้ว',
        'PARTIALLY_ACCEPTED' => 'รับบางส่วน',
        'ACCEPTED' => 'รับครบแล้ว',
        'REJECTED' => 'ปฏิเสธ',
        'VOID' => 'ยกเลิก',
    ];
    $statusClasses = [
        'DRAFT' => 'app-status-neutral',
        'DISPATCHED' => 'app-status-info',
        'PARTIALLY_ACCEPTED' => 'app-status-warning',
        'ACCEPTED' => 'app-status-success',
        'REJECTED' => 'app-status-danger',
        'VOID' => 'app-status-neutral',
    ];
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
            <span class="badge {{ $statusClasses[$transfer->status] ?? 'app-status-neutral' }}">
                {{ $statusLabels[$transfer->status] ?? $transfer->status }}
            </span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="{{ route($backRoute) }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับ</a>
            @if($transfer->status === 'DRAFT' && $isSource && ! $transfer->events->isNotEmpty() && auth()->user()->hasPermission('wms.transfers.delete'))
                <button class="btn btn-outline-danger" id="transfer-delete" type="button" data-url="{{ route('wms.transfers.destroy', $transfer) }}"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ Draft</button>
            @endif
            @if($transfer->status === 'DRAFT' && $isSource && auth()->user()->hasPermission('wms.transfers.dispatch'))
                <button class="btn btn-dark" id="transfer-dispatch" type="button" data-url="{{ route('wms.transfers.dispatch', $transfer) }}"><i class="bx bx-send me-1" aria-hidden="true"></i>ส่งออกจากคลัง</button>
            @endif
            @if(in_array($transfer->status, ['DISPATCHED', 'PARTIALLY_ACCEPTED'], true) && $isDestination && auth()->user()->hasPermission('wms.transfers.complete'))
                <a class="btn btn-dark" href="{{ route('wms.transfers.receive', $transfer) }}"><i class="bx bx-check-circle me-1" aria-hidden="true"></i>รับโอนสินค้า</a>
            @endif
            @if($transfer->status === 'REJECTED' && $isSource && auth()->user()->hasPermission('wms.transfers.void'))
                <button class="btn btn-outline-danger" id="transfer-void" type="button" data-url="{{ route('wms.transfers.void', $transfer) }}"><i class="bx bx-undo me-1" aria-hidden="true"></i>ยกเลิกรายการ</button>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <div class="row g-3">
                <div class="col-12 col-md-3"><div class="text-secondary small">วันที่เอกสาร</div><div class="fw-semibold">{{ $transfer->document_date?->format($dateFormat) ?: '-' }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">คลังต้นทาง</div><div class="fw-semibold">{{ $transfer->sourceWarehouse?->name ?: '-' }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">คลังปลายทาง</div><div class="fw-semibold">{{ $transfer->destinationWarehouse?->name ?: '-' }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">ผู้สร้าง</div><div class="fw-semibold">{{ $transfer->creator?->name ?: '-' }}</div></div>
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

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <h2 class="h5 mb-3">รายการสินค้า</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>#</th><th>สินค้า</th><th>หน่วย Stock</th><th class="text-end">วางแผน</th><th class="text-end text-success">รับแล้ว</th><th class="text-end text-danger">ปฏิเสธ</th><th class="text-end text-info">คงเหลือ</th></tr></thead>
                    <tbody>
                    @forelse($lines as $line)
                        <tr>
                            <td>{{ $line['line_number'] }}</td>
                            <td>{{ $line['item_label'] }}</td>
                            <td>{{ $line['uom_label'] }}</td>
                            <td class="text-end">{{ $line['planned_base_quantity'] }}</td>
                            <td class="text-end text-success">{{ $line['accepted_base_quantity'] }}</td>
                            <td class="text-end text-danger">{{ $line['rejected_base_quantity'] }}</td>
                            <td class="text-end text-info">{{ $line['remaining_base_quantity'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">ไม่พบรายการสินค้า</td></tr>
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
                .done(function (response) { Swal.fire({icon: 'success', text: response.msg || 'ส่ง Transfer แล้ว', timer: 1400, showConfirmButton: false}).then(function () { location.reload(); }); })
                .fail(function (xhr) { Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถส่ง Transfer ได้'}); })
                .always(function () { button.prop('disabled', false); });
        });
    });
    $('#transfer-void').on('click', function () {
        var button = $(this);
        Swal.fire({icon: 'warning', title: 'ยกเลิก Transfer?', input: 'textarea', inputPlaceholder: 'เหตุผลอย่างน้อย 10 ตัวอักษร', showCancelButton: true, confirmButtonText: 'ยกเลิกเอกสาร', cancelButtonText: 'กลับ', confirmButtonColor: '#dc3545', inputValidator: function (value) { return !value || value.trim().length < 10 ? 'กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร' : undefined; }}).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.post(button.data('url'), {_token: '{{ csrf_token() }}', reason: result.value}).done(function (response) { Swal.fire({icon: 'success', text: response.msg || 'ยกเลิกรายการแล้ว', timer: 1400, showConfirmButton: false}).then(function () { location.reload(); }); }).fail(function (xhr) { Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถยกเลิกรายการได้'}); }).always(function () { button.prop('disabled', false); });
        });
    });
    $('#transfer-delete').on('click', function () {
        var button = $(this);
        Swal.fire({icon: 'warning', title: 'ลบร่าง Transfer?', text: 'เอกสาร Draft ที่ยังไม่เคลื่อนไหวจะถูกลบออกจากรายการ', showCancelButton: true, confirmButtonText: 'ลบ Draft', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc3545'}).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.ajax({url: button.data('url'), method: 'DELETE', data: {_token: '{{ csrf_token() }}'}}).done(function (response) { Swal.fire({icon: 'success', text: response.msg || 'ลบร่าง Transfer แล้ว', timer: 1400, showConfirmButton: false}).then(function () { location.href = response.redirect; }); }).fail(function (xhr) { Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถลบร่าง Transfer ได้'}); }).always(function () { button.prop('disabled', false); });
        });
    });
});
</script>
@endpush
