@extends('Production::layout')
@section('title', 'Production Dashboard | MintERP')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4 module-dashboard module-dashboard--production">
    <div class="module-dashboard-hero d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <p class="module-dashboard-kicker mb-2"><span></span>PRODUCTION / CONTROL CENTER</p>
            <h1>ศูนย์ควบคุมการผลิต</h1>
            <p class="mb-0">ติดตามแผนผลิตแบบ Made to Order ใบสั่งผลิต และงานค้างของ {{ $branch?->name }} · {{ $warehouse?->name }}</p>
        </div>
        <div class="module-dashboard-actions d-flex flex-wrap gap-2">
            @if(auth()->user()->hasPermission('production.orders.view'))<a class="btn btn-app-soft" href="{{ route('production.planning.index') }}"><i class="bx bx-calendar-event me-1" aria-hidden="true"></i>กระดานวางแผน</a>@endif
            @if(auth()->user()->hasPermission('production.orders.create'))<a class="btn btn-app-primary" href="{{ route('production.orders.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบสั่งผลิต</a>@endif
        </div>
    </div>

    <div class="alert alert-info border-0 d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4" role="status">
        <div><strong>สิ่งที่ควรตรวจวันนี้</strong><div class="small mt-1">ตรวจ WO ที่ขาดวัตถุดิบ ใกล้ครบกำหนด และพร้อมรับผลิตเสร็จ · สิทธิ์ production.* แยกจาก WMS</div></div>
        <div class="d-flex flex-wrap gap-2">
            @if(auth()->user()->hasPermission('production.orders.view'))<a class="btn btn-sm btn-app-soft" href="{{ route('production.demand.index') }}"><i class="bx bx-list-check me-1" aria-hidden="true"></i>Demand Queue</a>@endif
            @if(auth()->user()->hasPermission('production.shop_floor.use'))<a class="btn btn-sm btn-app-soft" href="{{ route('production.shop-floor.index') }}"><i class="bx bx-mobile-alt me-1" aria-hidden="true"></i>เปิดหน้างานผลิต</a>@endif
        </div>
    </div>

    @if($workQueues->isNotEmpty())
        <section class="mb-4" aria-labelledby="production-document-queues-heading">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <h2 id="production-document-queues-heading" class="h5 mb-1">คิวอนุมัติและลงบัญชีเอกสารผลิต</h2>
                    <p class="small text-secondary mb-0">แสดงเฉพาะเอกสารของคลัง {{ $warehouse?->name }} ตามสิทธิ์ WMS ของคุณ</p>
                </div>
                <span class="small text-secondary">กดคิวเพื่อเปิดรายการและทำงานต่อใน Production</span>
            </div>
            <div class="row g-3">
                @foreach($workQueues as $queue)
                    <div class="col-6 col-xl-3">
                        <a class="card h-100 text-decoration-none text-reset" href="{{ $queue['kind'] === 'issue' ? route('production.document-queues.material-issues.index', ['status' => $queue['status']]) : route('production.document-queues.finished-receipts.index', ['status' => $queue['status']]) }}">
                            <div class="card-body">
                                <p class="small text-secondary mb-1">{{ $queue['document'] }}</p>
                                <h3 class="h6 mb-2">{{ $queue['stage'] }}</h3>
                                <span class="badge {{ $queue['count'] > 0 ? 'app-status-warning' : 'app-status-success' }}">{{ number_format($queue['count']) }} รายการ</span>
                                <p class="small text-secondary mt-2 mb-0">{{ $queue['description'] }}</p>
                                <span class="small fw-semibold d-block mt-2">เปิดคิว <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></span>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="row g-3 mb-4 module-dashboard-summary" aria-label="สรุปสถานะการผลิต">
        @foreach([
            ['label' => 'WO ร่าง', 'value' => $metrics['draft'], 'icon' => 'bx-edit', 'class' => 'neutral'],
            ['label' => 'กำลังผลิต', 'value' => $metrics['in_progress'], 'icon' => 'bx-cog', 'class' => 'info'],
            ['label' => 'ใกล้กำหนด 7 วัน', 'value' => $metrics['due_soon'], 'icon' => 'bx-calendar-event', 'class' => 'warning'],
            ['label' => 'เกินกำหนด', 'value' => $metrics['overdue'], 'icon' => 'bx-error-circle', 'class' => 'danger'],
            ['label' => 'ขาดวัตถุดิบ', 'value' => $metrics['shortage'], 'icon' => 'bx-package', 'class' => 'warning'],
            ['label' => 'พร้อมรับผลิตเสร็จ', 'value' => $metrics['ready_to_receive'], 'icon' => 'bx-check-circle', 'class' => 'success'],
        ] as $card)
            <div class="col-6 col-xl-2"><div class="card h-100"><div class="card-body"><div class="d-flex align-items-center gap-2 mb-2"><span class="production-dashboard-icon production-dashboard-icon--{{ $card['class'] }}"><i class="bx {{ $card['icon'] }}" aria-hidden="true"></i></span><div class="small text-secondary">{{ $card['label'] }}</div></div><div class="h3 mb-0">{{ number_format($card['value']) }}</div></div></div></div>
        @endforeach
    </section>

    <div class="row g-3">
        <div class="col-12 col-lg-7"><div class="card h-100"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">เริ่มงานอย่างรวดเร็ว</h2><p class="small text-secondary mb-0">ไปยังงานหลักโดยไม่ต้องค้นหาเมนู</p></div></div><div class="row g-2">
            @if(auth()->user()->hasPermission('production.orders.view'))<div class="col-6 col-md-4"><a class="production-dashboard-shortcut" href="{{ route('production.orders.index') }}"><i class="bx bx-clipboard" aria-hidden="true"></i><span>ใบสั่งผลิต</span></a></div><div class="col-6 col-md-4"><a class="production-dashboard-shortcut" href="{{ route('production.planning.index') }}"><i class="bx bx-calendar-event" aria-hidden="true"></i><span>วางแผนผลิต</span></a></div>@endif
            @if(auth()->user()->hasPermission('production.boms.view'))<div class="col-6 col-md-4"><a class="production-dashboard-shortcut" href="{{ route('production.boms.index') }}"><i class="bx bx-sitemap" aria-hidden="true"></i><span>BOM และ Routing</span></a></div>@endif
            @if(auth()->user()->hasPermission('production.reports.view'))<div class="col-6 col-md-4"><a class="production-dashboard-shortcut" href="{{ route('production.reports.index') }}"><i class="bx bx-bar-chart-alt-2" aria-hidden="true"></i><span>รายงานการผลิต</span></a></div>@endif
            @if(auth()->user()->hasPermission('production.shop_floor.use'))<div class="col-6 col-md-4"><a class="production-dashboard-shortcut" href="{{ route('production.shop-floor.issues') }}"><i class="bx bx-error-circle" aria-hidden="true"></i><span>ปัญหาหน้างาน</span></a></div>@endif
        </div></div></div></div>
        <div class="col-12 col-lg-5"><div class="card h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-1">สถานะการทำงาน</h2><p class="small text-secondary mb-3">ตัวชี้วัดที่ควรติดตามก่อนเริ่มงานถัดไป</p><div class="production-dashboard-status"><a href="{{ route('production.orders.index', ['status' => 'IN_PROGRESS']) }}"><span><i class="bx bx-cog me-2" aria-hidden="true"></i>งานที่กำลังผลิต</span><i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a><a href="{{ route('production.planning.index') }}"><span><i class="bx bx-time-five me-2" aria-hidden="true"></i>งานใกล้ครบกำหนด/เกินกำหนด</span><i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a><a href="{{ route('production.orders.index') }}"><span><i class="bx bx-package me-2" aria-hidden="true"></i>ตรวจวัตถุดิบและการรับผลิต</span><i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a></div></div></div></div>
    </div>
</div>
@endsection
