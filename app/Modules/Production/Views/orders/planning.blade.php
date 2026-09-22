@extends('Production::layout')
@section('title', 'กระดานวางแผนผลิต | MintERP')
@section('content')
@php
    $labels = ['DRAFT' => 'ร่าง', 'RELEASED' => 'พร้อมผลิต', 'IN_PROGRESS' => 'กำลังผลิต', 'COMPLETED' => 'เสร็จแล้ว', 'CANCELLED' => 'ยกเลิกเอกสาร'];
    $classes = ['DRAFT' => 'app-status-neutral', 'RELEASED' => 'app-status-info', 'IN_PROGRESS' => 'app-status-warning', 'COMPLETED' => 'app-status-success', 'CANCELLED' => 'app-status-danger'];
    $formatDuration = function ($minutes): string {
        $minutes = max(0, (int) $minutes);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;
        return collect([$days ? $days.' วัน' : null, $hours ? $hours.' ชม.' : null, $mins ? $mins.' นาที' : null])->filter()->join(' ') ?: 'ไม่ถึง 1 นาที';
    };
    $rangeStart = \Carbon\Carbon::parse($filters['date_from'])->startOfDay();
    $rangeEnd = \Carbon\Carbon::parse($filters['date_to'])->endOfDay();
    $rangeMinutes = max(1, $rangeStart->diffInMinutes($rangeEnd));
    $displayDateTime = function ($date, $time = null): string {
        if (!$date) return 'ยังไม่กำหนด';
        return $time ? $date->format('d/m/Y').' '.$time->format('H:i') : $date->format('d/m/Y');
    };
    $metrics = [
        ['label' => 'WO ในช่วงเวลา', 'value' => $orders->count(), 'icon' => 'bx-calendar-event', 'class' => 'app-status-info'],
        ['label' => 'กำลังผลิต', 'value' => $orders->where('status', 'IN_PROGRESS')->count(), 'icon' => 'bx-cog', 'class' => 'app-status-warning'],
        ['label' => 'พร้อมผลิต', 'value' => $orders->where('status', 'RELEASED')->count(), 'icon' => 'bx-play-circle', 'class' => 'app-status-info'],
        ['label' => 'เสร็จแล้ว', 'value' => $orders->where('status', 'COMPLETED')->count(), 'icon' => 'bx-check-circle', 'class' => 'app-status-success'],
    ];
@endphp
<div class="container-fluid px-3 px-lg-4 py-4 module-dashboard module-dashboard--production planning-board">
    <div class="module-dashboard-hero d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div><p class="module-dashboard-kicker mb-2"><span></span>PRODUCTION / PLANNING BOARD</p><h1>กระดานวางแผนผลิต</h1><p class="mb-0">มองภาพรวมใบสั่งผลิตตามวันและเวลา แล้วกดดูรายละเอียดเฉพาะงานที่ต้องติดตาม</p></div>
        <div class="module-dashboard-actions d-flex flex-wrap gap-2"><a class="btn btn-app-soft" href="{{ route('production.orders.index') }}"><i class="bx bx-clipboard me-1" aria-hidden="true"></i>ใบสั่งผลิต</a><a class="btn btn-app-primary" href="{{ route('production.orders.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบสั่งผลิต</a></div>
    </div>

    <section class="row g-3 mb-4 module-dashboard-summary" aria-label="สรุปแผนผลิต">
        @foreach($metrics as $metric)
            <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="d-flex align-items-center gap-2 mb-2"><span class="production-dashboard-icon production-dashboard-icon--{{ str_replace('app-status-', '', $metric['class']) }}"><i class="bx {{ $metric['icon'] }}" aria-hidden="true"></i></span><div class="small text-secondary">{{ $metric['label'] }}</div></div><div class="h3 mb-0">{{ number_format($metric['value']) }}</div></div></div></div>
        @endforeach
    </section>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>ตัวกรองแผนผลิต</strong>
            <a class="btn btn-sm btn-app-soft" href="{{ route('production.planning.index') }}"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</a>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-md-3"><label class="form-label" for="planning-date-from">วันที่เริ่ม</label><input id="planning-date-from" class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"></div>
                <div class="col-12 col-md-3"><label class="form-label" for="planning-date-to">วันที่สิ้นสุด</label><input id="planning-date-to" class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></div>
                <div class="col-12 col-md-2"><label class="form-label" for="planning-status">สถานะ</label><select id="planning-status" class="form-select" name="status"><option value="">ทั้งหมด</option>@foreach($labels as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-12 col-md-3"><label class="form-label" for="planning-responsible">ผู้รับผิดชอบ</label><select id="planning-responsible" class="form-select" name="responsible_user_id" data-url="{{ route('production.planning.options', 'users') }}"><option value="">ทั้งหมด</option>@if($selectedResponsible)<option value="{{ $selectedResponsible->id }}" selected>{{ $selectedResponsible->name }}</option>@endif</select></div>
                <div class="col-12 col-md-3"><label class="form-label" for="planning-product">สินค้า</label><select id="planning-product" class="form-select" name="finished_item_id" data-url="{{ route('production.planning.options', 'products') }}"><option value="">ทั้งหมด</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)($filters['finished_item_id'] ?? '') === (string)$product->id)>{{ $product->code }} · {{ $product->name }}</option>@endforeach</select></div>
                <div class="col-12 col-md-2 d-flex align-items-center"><div class="form-check mt-3"><input class="form-check-input" id="planning-overdue" type="checkbox" name="overdue" value="1" @checked($filters['overdue'] ?? false)><label class="form-check-label" for="planning-overdue">เฉพาะเกินกำหนด</label></div></div><div class="col-12 col-md-2 d-flex align-items-center"><div class="form-check mt-3"><input class="form-check-input" id="planning-shortage" type="checkbox" name="shortage" value="1" @checked($filters['shortage'] ?? false)><label class="form-check-label" for="planning-shortage">เฉพาะวัตถุดิบขาด</label></div></div>
                <div class="col-12 col-md-1"><button class="btn btn-app-primary w-100" type="submit" title="ค้นหา" aria-label="ค้นหา"><i class="bx bx-search" aria-hidden="true"></i></button></div>
            </form>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2"><div><h2 class="h5 mb-1">Timeline แผนผลิตตามวันและเวลา</h2><span class="text-secondary small">{{ $filters['date_from'] }} – {{ $filters['date_to'] }} · {{ $orders->count() }} WO</span></div><div class="d-flex flex-wrap gap-2 small"><span><i class="bx bxs-circle text-info" aria-hidden="true"></i> พร้อมผลิต</span><span><i class="bx bxs-circle text-warning" aria-hidden="true"></i> กำลังผลิต</span><span><i class="bx bxs-circle text-success" aria-hidden="true"></i> เสร็จแล้ว</span></div></div>
    <div class="card border-0 shadow-sm planning-board-card">
        <div class="planning-board-head"><div class="planning-board-label">ใบสั่งผลิต</div><div class="planning-board-scale"><span>{{ $filters['date_from'] }}</span><span>วันนี้</span><span>{{ $filters['date_to'] }}</span></div></div>
        <div class="card-body p-0">
            @forelse($orders as $order)
                @php
                    $start = $displayDateTime($order->planned_start_date, $order->planned_start_at);
                    $finish = $order->planned_finish_date ? $displayDateTime($order->planned_finish_date, $order->planned_finish_at) : 'ไม่มีกำหนดจบ';
                    $overdue = $order->planned_finish_date && $order->planned_finish_date->isPast() && !in_array($order->status, ['COMPLETED', 'CANCELLED'], true);
                    $plannedMinutes = $order->planned_start_at && $order->planned_finish_at ? $order->planned_start_at->diffInMinutes($order->planned_finish_at) : ($order->planned_start_date && $order->planned_finish_date ? $order->planned_start_date->startOfDay()->diffInMinutes($order->planned_finish_date->endOfDay()) : null);
                    $actualStart = $order->started_at ?: $order->released_at;
                    $actualEnd = $order->completed_at ?: ($order->status === 'IN_PROGRESS' ? now() : null);
                    $actualMinutes = $actualStart && $actualEnd ? $actualStart->diffInMinutes($actualEnd) : null;
                    $barStart = $order->planned_start_at ?: ($order->planned_start_date?->startOfDay());
                    $barEnd = $order->planned_finish_at ?: ($order->planned_finish_date?->endOfDay());
                    $left = $barStart ? max(0, min(100, $rangeStart->diffInMinutes($barStart, false) / $rangeMinutes * 100)) : 0;
                    $width = $barStart && $barEnd ? max(1, min(100 - $left, $barStart->diffInMinutes($barEnd) / $rangeMinutes * 100)) : 0;
                @endphp
                <div class="planning-board-row border-bottom">
                    <div class="planning-board-label"><div class="d-flex align-items-center gap-2"><a class="fw-semibold text-decoration-none" href="{{ route('production.orders.show', $order) }}">{{ $order->document_number }}</a><span class="badge {{ $classes[$order->status] ?? 'app-status-neutral' }}">{{ $labels[$order->status] ?? $order->status }}</span></div><div class="small text-secondary text-truncate" title="{{ $order->finishedItem?->code }} · {{ $order->finishedItem?->name }}">{{ $order->finishedItem?->code }} · {{ $order->finishedItem?->name }}</div><div class="small text-secondary">{{ number_format((float)$order->planned_quantity, 2) }} {{ $order->uom?->code }} · จบ {{ $finish }}</div><details class="mt-1"><summary class="small text-primary">ดูเพิ่มเติม</summary><div class="small text-secondary mt-2">เริ่ม {{ $start }} · จบ {{ $finish }} · {{ $order->salesOrder ? $order->salesOrder->document_number.' · '.$order->salesOrder->party_name : 'Make to Stock' }}<br>ผู้รับผิดชอบ {{ $order->responsibleUser?->name ?: 'ยังไม่ระบุ' }}<br>แผนใช้เวลา {{ $plannedMinutes !== null ? $formatDuration($plannedMinutes) : 'ไม่ระบุ' }} @if($actualMinutes !== null) · ใช้จริง {{ $formatDuration($actualMinutes) }}@endif</div></details>@if($overdue)<span class="badge app-status-danger mt-1">เกินกำหนด</span>@endif @if(isset($order->planning_readiness) && ! $order->planning_readiness['ready'])<span class="badge app-status-danger mt-1">วัตถุดิบไม่พอ</span>@endif</div>
                    <div class="planning-board-track"><div class="planning-timeline" role="img" aria-label="ช่วงเวลาแผนผลิต {{ $order->document_number }}"><div class="planning-timeline-track">@if($width)<a class="planning-timeline-bar {{ $classes[$order->status] ?? 'app-status-neutral' }}" href="{{ route('production.orders.show', $order) }}" style="left:{{ $left }}%;width:{{ $width }}%" title="{{ $order->document_number }} {{ $start }} - {{ $finish }}"></a>@else<span class="small text-secondary">ยังไม่กำหนดช่วงเวลา</span>@endif</div></div></div>
                </div>
            @empty
                <div class="p-5 text-center text-secondary">ไม่พบ WO ในช่วงเวลาหรือเงื่อนไขที่เลือก</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script>
$(function(){['#planning-responsible','#planning-product'].forEach(function(selector){var el=$(selector);window.erpInitSelect2(el,{placeholder:'ค้นหา'+(selector==='#planning-product'?'สินค้า':'ผู้รับผิดชอบ'),allowClear:true,ajax:{url:el.data('url'),delay:250,data:function(p){return{q:p.term||'',page:p.page||1};},processResults:function(data){return data;}}});});});
</script>
@endpush
