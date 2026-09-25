@extends('Production::layout')
@section('title', 'กระดานวางแผนผลิต | MintERP')
@section('content')
@php
    $labels = ['DRAFT' => 'ร่าง', 'RELEASED' => 'พร้อมผลิต', 'IN_PROGRESS' => 'กำลังผลิต', 'COMPLETED' => 'เสร็จแล้ว'];
    $classes = ['DRAFT' => 'app-status-neutral', 'RELEASED' => 'app-status-info', 'IN_PROGRESS' => 'app-status-warning', 'COMPLETED' => 'app-status-success'];
    $day = \Carbon\CarbonImmutable::parse($filters['date_from']);
    $otherFilters = request()->except('date_from', 'date_to');
    $hasAdvancedFilters = collect($filters)->except(['date_from', 'date_to'])->filter()->isNotEmpty();
@endphp
<div class="container-fluid planning-day-page">
    <header class="planning-day-header">
        <div><p class="eyebrow mb-1">PRODUCTION / PLANNING BOARD</p><h1>กระดานวางแผนผลิต</h1><p class="text-secondary mb-0">ดูแผนรายวันเป็นช่วงชั่วโมง · เวลาทับกันคือข้อมูลเพื่อวางแผน ไม่ใช่การจองกำลังผลิต</p></div>
        <div class="d-flex flex-wrap gap-2"><a class="btn btn-app-soft" href="{{ route('production.orders.index') }}"><i class="bx bx-clipboard me-1" aria-hidden="true"></i>ใบสั่งผลิต</a>@if(auth()->user()->hasPermission('production.orders.create'))<a class="btn btn-app-primary" href="{{ route('production.orders.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบสั่งผลิต</a>@endif</div>
    </header>

    <div class="planning-day-toolbar">
        <div class="planning-day-picker" aria-label="เลือกวันแผนผลิต">
            <a class="btn btn-app-soft" href="{{ route('production.planning.index', [...$otherFilters, 'date_from' => $day->subDay()->toDateString()]) }}" title="วันก่อนหน้า" aria-label="วันก่อนหน้า"><i class="bx bx-chevron-left" aria-hidden="true"></i></a>
            <form method="GET" id="planning-day-form">@foreach($otherFilters as $key => $value)@if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach<label for="planning-date-from" class="visually-hidden">วันที่วางแผน</label><input id="planning-date-from" class="form-control" type="date" name="date_from" value="{{ $day->toDateString() }}" aria-label="วันที่วางแผน"><button type="submit" class="btn btn-app-primary"><i class="bx bx-search me-1" aria-hidden="true"></i>ดูแผน</button></form>
            <a class="btn btn-app-soft" href="{{ route('production.planning.index', [...$otherFilters, 'date_from' => $day->addDay()->toDateString()]) }}" title="วันถัดไป" aria-label="วันถัดไป"><i class="bx bx-chevron-right" aria-hidden="true"></i></a>
            <a class="btn btn-app-soft" href="{{ route('production.planning.index', [...$otherFilters, 'date_from' => today()->toDateString()]) }}">วันนี้</a>
        </div>
        <div class="planning-day-stats" aria-label="สรุปงานในวัน {{ $day->format('d/m/Y') }}">
            <span><strong>{{ count($timeline['timed']) }}</strong> มีเวลา</span><span><strong>{{ count($timeline['unscheduled']) }}</strong> ยังไม่ระบุเวลา</span><span class="{{ $timeline['overlapCount'] ? 'text-danger' : '' }}"><strong>{{ $timeline['overlapCount'] }}</strong> งานเวลาทับกัน</span>
        </div>
    </div>

    <details class="planning-day-filters" @if($hasAdvancedFilters) open @endif>
        <summary><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>ตัวกรองเพิ่มเติม @if($hasAdvancedFilters)<span class="badge app-status-info">กำลังกรอง</span>@endif</summary>
        <div class="planning-day-filter-body">
            <div class="d-flex justify-content-end"><a class="btn btn-sm btn-app-soft" href="{{ route('production.planning.index', ['date_from' => $day->toDateString()]) }}"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</a></div>
            <form method="GET" class="row g-2 align-items-end"><input type="hidden" name="date_from" value="{{ $day->toDateString() }}">
                <div class="col-6 col-md-2"><label for="planning-status" class="form-label">สถานะ</label><select id="planning-status" name="status" class="form-select"><option value="">ทั้งหมด</option>@foreach($labels as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-6 col-md-3"><label for="planning-responsible" class="form-label">ผู้รับผิดชอบ</label><select id="planning-responsible" name="responsible_user_id" class="form-select" data-url="{{ route('production.planning.options', 'users') }}"><option value="">ทั้งหมด</option>@if($selectedResponsible)<option value="{{ $selectedResponsible->id }}" selected>{{ $selectedResponsible->name }}</option>@endif</select></div>
                <div class="col-6 col-md-3"><label for="planning-product" class="form-label">สินค้า</label><select id="planning-product" name="finished_item_id" class="form-select" data-url="{{ route('production.planning.options', 'products') }}"><option value="">ทั้งหมด</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)($filters['finished_item_id'] ?? '') === (string)$product->id)>{{ $product->code }} · {{ $product->name }}</option>@endforeach</select></div>
                <div class="col-6 col-md-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="planning-overdue" name="overdue" value="1" @checked($filters['overdue'] ?? false)><label class="form-check-label" for="planning-overdue">เกินกำหนด</label></div><div class="form-check"><input class="form-check-input" type="checkbox" id="planning-shortage" name="shortage" value="1" @checked($filters['shortage'] ?? false)><label class="form-check-label" for="planning-shortage">วัตถุดิบขาด</label></div></div>
                <div class="col-12 col-md-auto"><button class="btn btn-app-primary" type="submit"><i class="bx bx-search me-1" aria-hidden="true"></i>ค้นหา</button></div>
            </form>
        </div>
    </details>

    <section class="planning-day-board" aria-label="แผนผลิตวันที่ {{ $day->format('d/m/Y') }}">
        <div class="planning-day-caption"><div><h2>วัน{{ $day->locale('th')->translatedFormat('l j F Y') }}</h2><p>เส้นแนวตั้งทุก 1 ชั่วโมง · ปัดซ้าย/ขวาเพื่อดูทั้งวัน · แตะงานเพื่อดูรายละเอียด</p></div><div class="d-flex flex-wrap gap-2 small" aria-label="คำอธิบายสถานะ"><span class="badge app-status-info">พร้อมผลิต</span><span class="badge app-status-warning">กำลังผลิต</span><span class="badge app-status-success">เสร็จแล้ว</span><span class="badge app-status-danger">เวลาทับกัน</span></div></div>
        <div class="planning-day-scroll" tabindex="0" role="region" aria-label="ตารางแผนผลิต เลื่อนแนวนอนเพื่อดูชั่วโมงอื่น และเลื่อนแนวตั้งเพื่อดูงานเพิ่มเติม">
            <div class="planning-day-inner">
                <div class="planning-day-grid-head"><div class="planning-day-label">ใบสั่งผลิต / เวลา</div><div class="planning-day-hours" aria-hidden="true">@foreach(range(0, 23) as $hour)<span>{{ $hour % 2 === 0 ? sprintf('%02d:00', $hour) : '' }}</span>@endforeach</div></div>
                @forelse($timeline['timed'] as $row)
                    @php
                        $order = $row['order'];
                        $start = $order->planned_start_at->format('d/m H:i');
                        $finish = $order->planned_finish_at->format('d/m H:i');
                        $duration = $order->planned_start_at->diffInMinutes($order->planned_finish_at);
                        $actualStart = $order->started_at ?: $order->released_at;
                        $actualEnd = $order->completed_at ?: ($order->status === 'IN_PROGRESS' ? now() : null);
                    @endphp
                    <div class="planning-day-row {{ $row['overlaps'] ? 'has-overlap' : '' }}">
                        <div class="planning-day-label"><a class="fw-semibold" href="{{ route('production.orders.show', $order) }}">{{ $order->document_number }}</a><span class="badge {{ $classes[$order->status] ?? 'app-status-neutral' }}">{{ $labels[$order->status] ?? $order->status }}</span><span class="planning-day-item" title="{{ $order->finishedItem?->code }} · {{ $order->finishedItem?->name }}">{{ $order->finishedItem?->code }} · {{ $order->finishedItem?->name }}</span><span class="planning-day-time">{{ $start }} – {{ $finish }} @if($row['overlaps'])<strong class="text-danger">· เวลาทับกัน</strong>@endif</span><details class="planning-day-more"><summary>ข้อมูลเพิ่ม</summary><div>แผนใช้เวลา {{ round($duration / 60, 1) }} ชม. @if($actualStart && $actualEnd) · ใช้จริง {{ round($actualStart->diffInMinutes($actualEnd) / 60, 1) }} ชม.@endif<br>{{ number_format((float)$order->planned_quantity, 2) }} {{ $order->uom?->code }} · {{ $order->responsibleUser?->name ?: 'ยังไม่ระบุผู้รับผิดชอบ' }} · {{ $order->salesOrder ? $order->salesOrder->document_number.' · '.$order->salesOrder->party_name : 'Make to Stock' }}</div></details></div>
                        <div class="planning-timeline-track"><a class="planning-timeline-bar {{ $classes[$order->status] ?? 'app-status-neutral' }}" href="{{ route('production.orders.show', $order) }}" style="left:{{ $row['left'] }}%;width:{{ $row['width'] }}%" title="{{ $order->document_number }} · {{ $start }} – {{ $finish }}" aria-label="{{ $order->document_number }} เริ่ม {{ $start }} จบ {{ $finish }}{{ $row['overlaps'] ? ' เวลาทับกับงานอื่น' : '' }}"></a></div>
                    </div>
                @empty<div class="planning-day-empty">ไม่มีงานที่กำหนดช่วงเวลาในวันนี้</div>@endforelse
            </div>
        </div>
        @if(count($timeline['unscheduled']))
            <details class="planning-day-unscheduled"><summary>ยังไม่ระบุเวลาเริ่ม–จบ {{ count($timeline['unscheduled']) }} งาน (ไม่แสดงเป็นแท่งเวลา)</summary><div class="planning-day-unscheduled-list">@foreach($timeline['unscheduled'] as $order)<a href="{{ route('production.orders.show', $order) }}">{{ $order->document_number }} · {{ $order->finishedItem?->name ?: '-' }} <small>{{ $order->planned_start_date?->format('d/m/Y') ?: 'ยังไม่กำหนดวัน' }} – {{ $order->planned_finish_date?->format('d/m/Y') ?: 'ไม่มีกำหนดจบ' }}</small></a>@endforeach</div></details>
        @endif
        @if($orders->count() === 200)<p class="small text-secondary p-2 mb-0">แสดงสูงสุด 200 งาน โปรดใช้ตัวกรองเพื่อลดจำนวนรายการ</p>@endif
    </section>
</div>
@endsection
@push('scripts')
<script>
$(function(){['#planning-responsible','#planning-product'].forEach(function(selector){var el=$(selector);window.erpInitSelect2(el,{placeholder:'ค้นหา'+(selector==='#planning-product'?'สินค้า':'ผู้รับผิดชอบ'),allowClear:true,ajax:{url:el.data('url'),delay:250,data:function(p){return{q:p.term||'',page:p.page||1};},processResults:function(data){return data;}}});});});
</script>
@endpush
