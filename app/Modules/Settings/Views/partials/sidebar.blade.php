<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}">
        <i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม
    </a>
    <a class="list-group-item list-group-item-action {{ request()->routeIs('settings.index') ? 'active' : '' }}"
       href="{{ route('settings.index') }}">
        <i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard
    </a>
    <a class="list-group-item list-group-item-action {{ request()->routeIs('settings.workflow.*') ? 'active' : '' }}" href="{{ route('settings.workflow.index') }}"><i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือการทำงาน</a>
</div>

@if (auth()->user()->hasPermission('settings.company.view') || auth()->user()->hasPermission('settings.branches.view') || auth()->user()->hasPermission('settings.warehouses.view'))
    <p class="eyebrow px-3 mb-2">องค์กรและสถานที่</p>
    <div class="list-group mb-4">
    @if (auth()->user()->hasPermission('settings.company.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('settings.company.*') ? 'active' : '' }}"
           href="{{ route('settings.company.edit') }}">
            <i class="bx bx-building me-2" aria-hidden="true"></i>ข้อมูลบริษัท
        </a>
    @endif
    @if (auth()->user()->hasPermission('settings.branches.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('settings.branches.*') ? 'active' : '' }}"
           href="{{ route('settings.branches.index') }}">
            <i class="bx bx-git-branch me-2" aria-hidden="true"></i>สาขา
        </a>
    @endif
    @if (auth()->user()->hasPermission('settings.warehouses.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('settings.warehouses.*') ? 'active' : '' }}"
           href="{{ route('settings.warehouses.index') }}">
            <i class="bx bx-package me-2" aria-hidden="true"></i>คลัง
        </a>
    @endif
    </div>
@endif

@if (auth()->user()->hasPermission('finance.document-sequences.view'))
    <p class="eyebrow px-3 mb-2">การตั้งค่าระบบ</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('settings.document-sequences.*') ? 'active' : '' }}"
           href="{{ route('settings.document-sequences.index') }}">
            <i class="bx bx-barcode me-2" aria-hidden="true"></i>รหัสและรูปแบบเอกสาร
        </a>
    </div>
@endif

@if (auth()->user()->hasPermission('settings.users.view') || auth()->user()->hasPermission('settings.roles.view'))
    <p class="eyebrow px-3 mb-2">ผู้ใช้และสิทธิ์</p>
    <div class="list-group mb-4">
    @if (auth()->user()->hasPermission('settings.users.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('settings.users.*') ? 'active' : '' }}"
           href="{{ route('settings.users.index') }}">
            <i class="bx bx-user me-2" aria-hidden="true"></i>ผู้ใช้งานและสิทธิ์เข้าถึง
        </a>
    @endif
    @if (auth()->user()->hasPermission('settings.roles.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('settings.roles.*') ? 'active' : '' }}"
           href="{{ route('settings.roles.index') }}">
            <i class="bx bx-shield-quarter me-2" aria-hidden="true"></i>บทบาทและสิทธิ์
        </a>
    @endif
    </div>
@endif

@if (auth()->user()->hasPermission('settings.audit.view'))
    <p class="eyebrow px-3 mb-2">ตรวจสอบระบบ</p>
    <div class="list-group">
    @if (auth()->user()->hasPermission('settings.audit.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('settings.audit.*') ? 'active' : '' }}"
           href="{{ route('settings.audit.index') }}">
            <i class="bx bx-history me-2" aria-hidden="true"></i>ประวัติการเปลี่ยนแปลง
        </a>
    @endif
    </div>
@endif
