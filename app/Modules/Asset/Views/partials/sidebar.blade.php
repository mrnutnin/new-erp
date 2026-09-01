<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}"><i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม</a>
    @if (auth()->user()->hasPermission('asset.dashboard.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.index') ? 'active' : '' }}" href="{{ route('asset.index') }}"><i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard</a>
    @endif
    @if (auth()->user()->hasPermission('asset.workflow.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.workflow.*') ? 'active' : '' }}" href="{{ route('asset.workflow.index') }}"><i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือการทำงาน</a>
    @endif
</div>

@if (auth()->user()->hasPermission('asset.register.view') || auth()->user()->hasPermission('asset.capitalizations.view') || auth()->user()->hasPermission('asset.depreciation.view') || auth()->user()->hasPermission('asset.depreciation.calculate') || auth()->user()->hasPermission('asset.transfers.view') || auth()->user()->hasPermission('asset.counts.view') || auth()->user()->hasPermission('asset.impairments.view') || auth()->user()->hasPermission('asset.disposals.view'))
    <p class="eyebrow px-3 mb-2">สินทรัพย์</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('asset.register.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.assets.*') ? 'active' : '' }}" href="{{ route('asset.assets.index') }}"><i class="bx bx-building-house me-2" aria-hidden="true"></i>ทะเบียนสินทรัพย์</a>
        @endif
        @if (auth()->user()->hasPermission('asset.capitalizations.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.capitalizations.*') ? 'active' : '' }}" href="{{ route('asset.capitalizations.index') }}"><i class="bx bx-receipt me-2" aria-hidden="true"></i>ใบรับรู้สินทรัพย์</a>
        @endif
        @if (auth()->user()->hasPermission('asset.depreciation.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.depreciations.*') ? 'active' : '' }}" href="{{ route('asset.depreciations.index') }}"><i class="bx bx-calculator me-2" aria-hidden="true"></i>ค่าเสื่อมราคา</a>
        @endif
        @if (auth()->user()->hasPermission('asset.depreciation.calculate'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.depreciation-policies.*') ? 'active' : '' }}" href="{{ route('asset.depreciation-policies.index') }}"><i class="bx bx-git-branch me-2" aria-hidden="true"></i>เปลี่ยนนโยบายค่าเสื่อม</a>
        @endif
        @if (auth()->user()->hasPermission('asset.transfers.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.transfers.*') ? 'active' : '' }}" href="{{ route('asset.transfers.index') }}"><i class="bx bx-transfer me-2" aria-hidden="true"></i>โอน/ย้ายสินทรัพย์</a>
        @endif
        @if (auth()->user()->hasPermission('asset.counts.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.counts.*') ? 'active' : '' }}" href="{{ route('asset.counts.index') }}"><i class="bx bx-clipboard me-2" aria-hidden="true"></i>ตรวจนับสินทรัพย์</a>
        @endif
        @if (auth()->user()->hasPermission('asset.impairments.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.impairments.*') ? 'active' : '' }}" href="{{ route('asset.impairments.index') }}"><i class="bx bx-error-circle me-2" aria-hidden="true"></i>ด้อยค่าสินทรัพย์</a>
        @endif
        @if (auth()->user()->hasPermission('asset.disposals.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.disposals.*') ? 'active' : '' }}" href="{{ route('asset.disposals.index') }}"><i class="bx bx-archive-out me-2" aria-hidden="true"></i>จำหน่ายสินทรัพย์</a>
        @endif
    </div>
@endif

@if (auth()->user()->hasPermission('asset.maintenance.view'))
    <p class="eyebrow px-3 mb-2">แจ้งซ่อม</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.maintenance.index', 'asset.maintenance.create', 'asset.maintenance.show', 'asset.maintenance.assign', 'asset.maintenance.start', 'asset.maintenance.waiting-parts', 'asset.maintenance.complete', 'asset.maintenance.cancel', 'asset.maintenance.attachments.*') ? 'active' : '' }}" href="{{ route('asset.maintenance.index') }}"><i class="bx bx-wrench me-2" aria-hidden="true"></i>แจ้งซ่อมและบำรุงรักษา</a>
        <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.maintenance.schedules.*') ? 'active' : '' }}" href="{{ route('asset.maintenance.schedules.index') }}"><i class="bx bx-calendar-check me-2" aria-hidden="true"></i>แผนการบำรุงรักษา</a>
    </div>
@endif

@if (auth()->user()->hasPermission('asset.reports.view'))
    <p class="eyebrow px-3 mb-2">รายงาน</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.reports.depreciation.*') ? 'active' : '' }}" href="{{ route('asset.reports.depreciation.index') }}"><i class="bx bx-line-chart me-2" aria-hidden="true"></i>รายงานค่าเสื่อม</a>
        <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.reports.maintenance.*') ? 'active' : '' }}" href="{{ route('asset.reports.maintenance.index') }}"><i class="bx bx-wrench me-2" aria-hidden="true"></i>รายงานงานซ่อมบำรุง</a>
        <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.reports.reconciliation.*') ? 'active' : '' }}" href="{{ route('asset.reports.reconciliation.index') }}"><i class="bx bx-check-shield me-2" aria-hidden="true"></i>กระทบยอดทะเบียนกับ GL</a>
    </div>
@endif

@if (auth()->user()->hasPermission('asset.categories.view') || auth()->user()->hasPermission('asset.locations.view'))
    <p class="eyebrow px-3 mb-2">ข้อมูลหลัก</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('asset.categories.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.categories.*') ? 'active' : '' }}" href="{{ route('asset.categories.index') }}"><i class="bx bx-category me-2" aria-hidden="true"></i>หมวดสินทรัพย์</a>
        @endif
        @if (auth()->user()->hasPermission('asset.locations.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('asset.locations.*') ? 'active' : '' }}" href="{{ route('asset.locations.index') }}"><i class="bx bx-map-pin me-2" aria-hidden="true"></i>สถานที่สินทรัพย์</a>
        @endif
    </div>
@endif
