@extends('Settings::layout')

@section('title', 'Settings Dashboard | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="page-heading mb-4">
            <p class="eyebrow mb-2">SETTINGS DASHBOARD</p>
            <h1 class="h3 mb-2">{{ $program->name }}</h1>
            <p class="text-secondary mb-0">เลือกหัวข้อที่ต้องการตั้งค่าและดูแลข้อมูลกลางของระบบ</p>
        </div>

        <div class="row g-4">
            @if (auth()->user()->hasPermission('settings.company.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-building fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">ข้อมูลบริษัท</h2>
                            <p class="text-secondary">ข้อมูลบริษัท นโยบายบัญชี ภาษี สต็อก และเลขเอกสาร</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('settings.company.edit') }}">เปิดการตั้งค่า</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('settings.users.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-user fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">ผู้ใช้งาน</h2>
                            <p class="text-secondary">จัดการผู้ใช้ บทบาท โปรแกรม และคลังที่ได้รับอนุญาต</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('settings.users.index') }}">เปิดรายการ</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('settings.branches.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-git-branch fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">สาขา</h2>
                            <p class="text-secondary">ดูแลโครงสร้างสาขาที่ใช้ร่วมกันในทุกโปรแกรม</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('settings.branches.index') }}">เปิดรายการ</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('settings.warehouses.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-package fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">คลัง</h2>
                            <p class="text-secondary">กำหนดคลังและสาขาต้นสังกัดสำหรับการทำรายการ</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('settings.warehouses.index') }}">เปิดรายการ</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('settings.roles.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-shield-quarter fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">บทบาทและสิทธิ์</h2>
                            <p class="text-secondary">กำหนดสิทธิ์ใช้งานตามหน้าที่ของผู้ใช้</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('settings.roles.index') }}">เปิดรายการ</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('settings.audit.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-history fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">ประวัติการเปลี่ยนแปลง</h2>
                            <p class="text-secondary">ตรวจสอบกิจกรรมและข้อมูลที่มีการเปลี่ยนแปลง</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('settings.audit.index') }}">เปิดประวัติ</a>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
