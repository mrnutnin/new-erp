<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}"><i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม</a>
    @if (auth()->user()->hasPermission('production.dashboard.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('production.index') ? 'active' : '' }}" href="{{ route('production.index') }}"><i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard การผลิต</a>
    @endif
</div>

@php($shopFloorActive = request()->routeIs('production.shop-floor.*'))
@if (auth()->user()->hasPermission('production.shop_floor.use'))
    <p class="eyebrow px-3 mb-2">หน้างานผลิต</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('production.shop-floor.index', 'production.shop-floor.show') ? 'active' : '' }}" href="{{ route('production.shop-floor.index') }}"><i class="bx bx-mobile-alt me-2" aria-hidden="true"></i>หน้างานผลิต</a>
        <a class="list-group-item list-group-item-action {{ request()->routeIs('production.shop-floor.issues') ? 'active' : '' }}" href="{{ route('production.shop-floor.issues') }}"><i class="bx bx-error-circle me-2" aria-hidden="true"></i>ปัญหาหน้างานผลิต</a>
    </div>
@endif

@if (auth()->user()->hasPermission('production.orders.view'))
    <p class="eyebrow px-3 mb-2">วางแผนและดำเนินการผลิต</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ request()->routeIs('production.demand.*') ? 'active' : '' }}" href="{{ route('production.demand.index') }}"><span><i class="bx bx-cart me-2" aria-hidden="true"></i>คำสั่งขายรอผลิต</span>@if($productionDemandCount > 0)<span class="badge app-status-danger ms-auto" title="คำสั่งขายรอผลิต {{ $productionDemandCount }} รายการ">{{ $productionDemandCount > 99 ? '99+' : $productionDemandCount }}</span>@endif</a>
        <a class="list-group-item list-group-item-action {{ request()->routeIs('production.planning.*') ? 'active' : '' }}" href="{{ route('production.planning.index') }}"><i class="bx bx-calendar-event me-2" aria-hidden="true"></i>กระดานวางแผนผลิต</a>
        <a class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ request()->routeIs('production.orders.*') ? 'active' : '' }}" href="{{ route('production.orders.index', ['status' => 'DRAFT']) }}"><span><i class="bx bx-clipboard me-2" aria-hidden="true"></i>ใบสั่งผลิต</span>@if($productionDraftCount > 0)<span class="badge app-status-danger ms-auto" title="ใบสั่งผลิต {{ $productionDraftCount }} รายการ">{{ $productionDraftCount > 99 ? '99+' : $productionDraftCount }}</span>@endif</a>
    </div>
@endif

@if (auth()->user()->hasPermission('production.boms.view'))
    <p class="eyebrow px-3 mb-2">ข้อมูลหลักการผลิต</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('production.boms.*') ? 'active' : '' }}" href="{{ route('production.boms.index') }}"><i class="bx bx-sitemap me-2" aria-hidden="true"></i>BOM และ Routing</a>
    </div>
@endif

@if (auth()->user()->hasPermission('production.reports.view'))
    @php($reportsActive = request()->routeIs('production.reports.*'))
    <p class="eyebrow px-3 mb-2">รายงานการผลิต</p>
    <div class="list-group mb-4">
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $reportsActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#production-reports-menu" aria-expanded="{{ $reportsActive ? 'true' : 'false' }}" aria-controls="production-reports-menu"><span><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>รายงาน</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="production-reports-menu" class="collapse {{ $reportsActive ? 'show' : '' }}">
            <a class="list-group-item list-group-item-action ps-5 {{ request()->routeIs('production.reports.index', 'production.reports.data') ? 'active' : '' }}" href="{{ route('production.reports.index') }}">รายงานการผลิต</a>
            <a class="list-group-item list-group-item-action ps-5 {{ request()->routeIs('production.reports.cost*') ? 'active' : '' }}" href="{{ route('production.reports.cost') }}">ต้นทุนการผลิต</a>
            <a class="list-group-item list-group-item-action ps-5 {{ request()->routeIs('production.reports.material-movements*') ? 'active' : '' }}" href="{{ route('production.reports.material-movements') }}">Material Movement</a>
        </div>
    </div>
@endif
