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
@endphp

@section('title', 'รายละเอียดใบโอนสินค้า | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / TRANSFER</p>
            <h1 class="h3 mb-2">{{ $transfer->document_number }}</h1>
            <span class="badge {{ $statusClasses[$transfer->status] ?? 'app-status-neutral' }}">
                {{ $statusLabels[$transfer->status] ?? $transfer->status }}
            </span>
        </div>
        <a class="btn btn-app-soft" href="{{ route($backRoute) }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับรายการ</a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <div class="row g-3">
                <div class="col-12 col-md-3"><div class="text-secondary small">วันที่เอกสาร</div><div class="fw-semibold">{{ $transfer->document_date?->format($dateFormat) ?: '-' }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">คลังต้นทาง</div><div class="fw-semibold">{{ $transfer->sourceWarehouse?->name ?: '-' }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">คลังปลายทาง</div><div class="fw-semibold">{{ $transfer->destinationWarehouse?->name ?: '-' }}</div></div>
                <div class="col-12 col-md-3"><div class="text-secondary small">ผู้สร้าง</div><div class="fw-semibold">{{ $transfer->creator?->name ?: '-' }}</div></div>
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
