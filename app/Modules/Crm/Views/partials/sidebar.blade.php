@php
    $analyticsMenuActive = request()->routeIs('crm.forecast.*') || request()->routeIs('crm.customers.duplicates*');
    $teamMenuActive = request()->routeIs('crm.team-work.*') || request()->routeIs('crm.teams.*') || request()->routeIs('crm.ownership-transfers.*');
@endphp

<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}"><i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม</a>
    @if(auth()->user()->hasPermission('crm.dashboard.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('crm.index') ? 'active' : '' }}" href="{{ route('crm.index') }}"><i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard</a>
        <a class="list-group-item list-group-item-action {{ request()->routeIs('crm.workflow.*') ? 'active' : '' }}" href="{{ route('crm.workflow.index') }}"><i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือการทำงาน</a>
    @endif
</div>

@if(auth()->user()->hasPermission('crm.opportunities.view'))
    <p class="eyebrow px-3 mb-2">งานประจำวัน</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('crm.my-work.*') ? 'active' : '' }}" href="{{ route('crm.my-work.index') }}"><i class="bx bx-task me-2" aria-hidden="true"></i>งานของฉัน</a>
        @if(auth()->user()->hasPermission('crm.calendar.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('crm.calendar.*') ? 'active' : '' }}" href="{{ route('crm.calendar.index') }}"><i class="bx bx-calendar me-2" aria-hidden="true"></i>ปฏิทินงาน</a>@endif
        <a class="list-group-item list-group-item-action {{ request()->routeIs('crm.customers.*')&&!request()->routeIs('crm.customers.duplicates*') ? 'active' : '' }}" href="{{ route('crm.customers.index') }}"><i class="bx bx-user-circle me-2" aria-hidden="true"></i>ลูกค้า 360°</a>
        <a class="list-group-item list-group-item-action {{ request()->routeIs('crm.opportunities.*')&&!request()->routeIs('crm.opportunities.kanban*') ? 'active' : '' }}" href="{{ route('crm.opportunities.index') }}"><i class="bx bx-target-lock me-2" aria-hidden="true"></i>โอกาสการขาย</a>
        <a class="list-group-item list-group-item-action {{ request()->routeIs('crm.opportunities.kanban*') ? 'active' : '' }}" href="{{ route('crm.opportunities.kanban') }}"><i class="bx bx-columns me-2" aria-hidden="true"></i>Kanban Pipeline</a>
        <a class="list-group-item list-group-item-action d-flex align-items-center {{ request()->routeIs('crm.notifications.*') ? 'active' : '' }}" href="{{ route('crm.notifications.index') }}"><i class="bx bx-bell me-2" aria-hidden="true"></i>การแจ้งเตือน @if($unreadCrmNotifications=auth()->user()->unreadNotifications()->count())<span class="badge app-status-danger ms-auto">{{ $unreadCrmNotifications }}</span>@endif</a>
    </div>
@endif

@if(auth()->user()->hasPermission('crm.forecast.view') || auth()->user()->hasPermission('crm.customers.update'))
    <p class="eyebrow px-3 mb-2">ข้อมูลประกอบการตัดสินใจ</p>
    <div class="list-group mb-4">
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $analyticsMenuActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#crm-analytics-menu" aria-expanded="{{ $analyticsMenuActive ? 'true' : 'false' }}" aria-controls="crm-analytics-menu"><span><i class="bx bx-line-chart me-2" aria-hidden="true"></i>วิเคราะห์และติดตาม</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="crm-analytics-menu" class="collapse {{ $analyticsMenuActive ? 'show' : '' }}">
            @if(auth()->user()->hasPermission('crm.forecast.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal ps-5 {{ request()->routeIs('crm.forecast.*') ? 'active' : '' }}" href="{{ route('crm.forecast.index') }}">Sales Forecast</a>@endif
            @if(auth()->user()->hasPermission('crm.customers.update'))<a class="list-group-item list-group-item-action border-0 small fw-normal ps-5 {{ request()->routeIs('crm.customers.duplicates*') ? 'active' : '' }}" href="{{ route('crm.customers.duplicates') }}">ตรวจสอบลูกค้าซ้ำ</a>@endif
        </div>
    </div>
@endif

@if(auth()->user()->hasPermission('crm.team-work.view') || auth()->user()->hasPermission('crm.teams.manage') || auth()->user()->hasPermission('crm.customers.transfer-owner'))
    <p class="eyebrow px-3 mb-2">การบริหารทีม</p>
    <div class="list-group mb-4">
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $teamMenuActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#crm-team-menu" aria-expanded="{{ $teamMenuActive ? 'true' : 'false' }}" aria-controls="crm-team-menu"><span><i class="bx bx-group me-2" aria-hidden="true"></i>ทีมขายและการมอบหมาย</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="crm-team-menu" class="collapse {{ $teamMenuActive ? 'show' : '' }}">
            @if(auth()->user()->hasPermission('crm.team-work.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal ps-5 {{ request()->routeIs('crm.team-work.*') ? 'active' : '' }}" href="{{ route('crm.team-work.index') }}">งานของทีม</a>@endif
            @if(auth()->user()->hasPermission('crm.teams.manage'))<a class="list-group-item list-group-item-action border-0 small fw-normal ps-5 {{ request()->routeIs('crm.teams.*') ? 'active' : '' }}" href="{{ route('crm.teams.index') }}">จัดการทีมขาย</a>@endif
            @if(auth()->user()->hasPermission('crm.customers.transfer-owner'))<a class="list-group-item list-group-item-action border-0 small fw-normal ps-5 {{ request()->routeIs('crm.ownership-transfers.*') ? 'active' : '' }}" href="{{ route('crm.ownership-transfers.index') }}">โอนเจ้าของลูกค้าและงาน</a>@endif
        </div>
    </div>
@endif
