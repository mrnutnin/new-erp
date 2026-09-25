@extends('Production::layout')
@section('title', 'หน้างานผลิต | MintERP')
@section('body-class', 'app-page sf-tablet-page')
@section('content')
<div class="container-fluid shop-floor-board sf-workboard">
    <header class="sf-board-header">
        <div><p class="sf-kicker">SHOP FLOOR</p><h1>หน้างานผลิต</h1><p class="sf-subtitle">เลือกงานเพื่อเบิกวัตถุดิบ เริ่มผลิต และรับผลิต</p></div>
        <div class="sf-header-tools"><span class="badge app-status-info"><i class="bx bx-buildings me-1" aria-hidden="true"></i>{{ request()->attributes->get('selectedWarehouse')?->name ?: 'คลังปัจจุบัน' }}</span><button class="btn btn-app-soft d-none" type="button" data-production-install><i class="bx bx-download me-1" aria-hidden="true"></i>ติดตั้งบนหน้าจอหลัก</button></div>
    </header>
    <div id="shop-floor-network-status" class="alert alert-warning d-none" role="status" aria-live="polite">เครือข่ายขัดข้อง กรุณาตรวจสอบการเชื่อมต่อก่อนทำรายการ</div>

    <section class="sf-board-controls" aria-label="ค้นหางานผลิต">
        <div class="sf-controls-heading"><label for="shop-floor-search" class="form-label mb-0"><i class="bx bx-scan me-1" aria-hidden="true"></i>ค้นหา หรือสแกน QR/Barcode WO</label><a class="btn btn-sm btn-app-soft" href="{{ route('production.shop-floor.index') }}"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</a></div>
        <form method="GET" class="sf-search-form">
            @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
            <input id="shop-floor-search" class="form-control" name="q" value="{{ $search }}" placeholder="เลข WO / สินค้า / ลูกค้า" inputmode="search" autocomplete="off" maxlength="100">
            <button class="btn btn-app-primary" type="submit"><i class="bx bx-search me-1" aria-hidden="true"></i>ค้นหา</button>
        </form>
        <nav class="sf-filters" aria-label="กรองสถานะงาน">
            @foreach(['' => 'ทั้งหมด', 'IN_PROGRESS' => 'กำลังผลิต', 'RELEASED' => 'พร้อมผลิต'] as $value => $label)
                <a class="sf-filter {{ ($status ?: '') === $value ? 'is-active' : '' }}" @if(($status ?: '') === $value) aria-current="page" @endif href="{{ route('production.shop-floor.index', ['q' => $search, 'status' => $value]) }}">{{ $label }}</a>
            @endforeach
            <span class="sf-result-count">{{ $orders->total() }} งาน · กำลังผลิตก่อน / กำหนดเสร็จก่อน</span>
        </nav>
    </section>

    <div class="sf-queue" aria-label="คิวงานผลิต">
        @forelse($orders as $order)
            @php
                $issueId = $order->events->firstWhere('event_type', 'material_issue_created')?->source_id;
                $issueStatus = $issueId ? $issueStatuses->get((int) $issueId) : null;
                $receipt = $issueId ? $receiptDocuments->get((int) $issueId)?->first() : null;
                $next = $order->held_at ? 'พักงาน · ตรวจสอบก่อนทำต่อ' : ($issueStatus !== 'POSTED' ? match ($issueStatus) { 'DRAFT' => 'รออนุมัติใบเบิก', 'APPROVED' => 'รอลง Stock ใบเบิก', default => $order->started_at ? 'ตรวจสอบใบเบิกวัตถุดิบ' : 'เตรียมใบเบิกวัตถุดิบ' } : (! $order->started_at ? 'ยืนยันเริ่มงานผลิต' : match ($receipt?->status) { 'DRAFT' => 'รออนุมัติใบรับผลิต', 'APPROVED' => 'รอลง Stock ใบรับผลิต', default => 'รับผลิตเมื่อผลิตเสร็จ' }));
                $progress = match (true) {
                    $receipt?->status === 'POSTED' => 100, $receipt?->status === 'APPROVED' => 85,
                    $receipt !== null => 75, $order->started_at !== null => 60,
                    $issueStatus === 'POSTED' => 45, $issueStatus === 'APPROVED' => 30, $issueStatus !== null => 15, default => 0,
                };
            @endphp
            <article class="sf-job {{ $order->held_at ? 'is-held' : ($order->status === 'IN_PROGRESS' ? 'is-running' : 'is-ready') }}">
                <div class="sf-job-heading"><h2>{{ $order->document_number }}</h2><span class="badge {{ $order->held_at ? 'app-status-danger' : ($order->status === 'IN_PROGRESS' ? 'app-status-warning' : 'app-status-info') }}">{{ $order->held_at ? 'พักงาน' : ($order->status === 'IN_PROGRESS' ? 'กำลังผลิต' : 'พร้อมผลิต') }}</span></div>
                <div class="sf-job-product"><div class="sf-job-image sf-detail-cover">@if($order->finishedItem?->cover_image_path)<img src="{{ route('production.shop-floor.item-image', [$order, $order->finishedItem]) }}" alt="ภาพสินค้า {{ $order->finishedItem->name }}" loading="lazy" decoding="async">@else<i class="bx bx-package" aria-hidden="true"></i>@endif</div><div><strong>{{ $order->finishedItem?->name ?: 'ไม่ระบุสินค้า' }}</strong><span>{{ $order->finishedItem?->code }}</span>@if($order->salesOrder)<span>SO: {{ $order->salesOrder->document_number }}</span><span>ลูกค้า: {{ $order->salesOrder->party_name ?: 'ไม่ระบุ' }}</span>@else<span>Make to Stock · ไม่ผูกใบสั่งขาย</span>@endif</div></div>
                <div class="sf-job-figures"><span><small>เป้าหมาย</small><strong>{{ number_format((float)$order->planned_quantity, 2) }} {{ $order->uom?->code }}</strong></span><span><small>กำหนดเสร็จ</small><strong>{{ $order->planned_finish_date?->format('d/m/Y') ?: 'ไม่ระบุ' }}</strong></span></div>
                <div class="sf-job-next"><i class="bx bx-right-arrow-alt" aria-hidden="true"></i>{{ $next }}</div>
                <div class="sf-job-footer"><div class="sf-job-progress"><small>ตามเอกสาร {{ $progress }}%</small><div class="progress" role="progressbar" aria-label="ความคืบหน้า {{ $order->document_number }}" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:{{ $progress }}%"></div></div></div><a class="btn btn-app-soft btn-lg" href="{{ route('production.shop-floor.show', $order) }}" aria-label="เปิดงาน {{ $order->document_number }}">เปิดงาน<i class="bx bx-right-arrow-alt ms-1" aria-hidden="true"></i></a></div>
            </article>
        @empty
            <div class="sf-empty"><i class="bx bx-search-alt" aria-hidden="true"></i><h2>ไม่พบงานในคิวนี้</h2><p>ลองเปลี่ยนคำค้นหาหรือล้างตัวกรอง</p><a class="btn btn-app-soft" href="{{ route('production.shop-floor.index') }}">ล้างตัวกรอง</a></div>
        @endforelse
    </div>
    @include('Production::shop-floor._pager', ['paginator' => $orders, 'label' => 'คิวงาน'])
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/production-shop-floor.js') }}?v={{ filemtime(public_path('js/production-shop-floor.js')) }}"></script>
@endpush
