@extends('layouts.app')

@section('title', 'เข้าสู่ระบบ | MintERP')
@section('body-class', 'auth-page')

@section('content')
    <div class="auth-shell container">
        <div class="auth-card card border-0">
            <div class="row g-0">
                <div class="auth-intro col-lg-6 p-4 p-md-5" aria-hidden="true">
                    <div class="auth-brand-mark">
                        {{-- <i class="bx bx-grid-alt"></i> --}}
                        <img class="" src="{{ asset('images/mint-icon.png') }}" width="36" height="36" alt="MintERP">
                    </div>
                    <p class="eyebrow mt-4 mb-2">MintERP</p>
                    <h2 class="display-6 fw-semibold mb-3">จัดการธุรกิจ<br>ในที่เดียว</h2>
                    <p class="text-secondary mb-0">เชื่อมโยงงานขาย คลังสินค้า การเงิน และบัญชี ให้ทีมทำงานด้วยข้อมูลชุดเดียวกัน</p>
                    <div class="auth-intro-status mt-auto pt-5">
                        <span class="auth-status-dot"></span>
                        <span>ระบบพร้อมให้บริการ</span>
                    </div>
                </div>
                <div class="col-lg-6 p-4 p-md-5">
                    <div class="mb-4">
                        <img class="auth-vendor-logo" src="{{ asset('images/mint-erp-logo.png') }}" width="566" height="194" alt="MintERP">
                        <p class="eyebrow mb-2">ยินดีต้อนรับกลับ</p>
                        <h1 class="h2 mb-2">เข้าสู่ระบบ</h1>
                        <p class="text-secondary mb-0">กรอกบัญชีผู้ใช้เพื่อเข้าสู่พื้นที่ทำงาน</p>
                    </div>

                    <form id="login-form" action="{{ route('login.store') }}" method="post" novalidate>
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="username">ชื่อผู้ใช้</label>
                            <div class="auth-input-wrap">
                                <i class="bx bx-user" aria-hidden="true"></i>
                                <input class="form-control" id="username" name="username" type="text" autocomplete="username" placeholder="กรอกชื่อผู้ใช้" autofocus required>
                            </div>
                            <div class="invalid-feedback" data-error-for="username"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">รหัสผ่าน</label>
                            <div class="auth-input-wrap">
                                <i class="bx bx-lock-alt" aria-hidden="true"></i>
                                <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" placeholder="กรอกรหัสผ่าน" required>
                            </div>
                            <div class="invalid-feedback" data-error-for="password"></div>
                        </div>
                        <div class="form-check mb-4">
                            <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                            <label class="form-check-label" for="remember">จดจำการเข้าสู่ระบบ</label>
                        </div>
                        <button class="btn btn-app-primary w-100 auth-submit" type="submit" data-busy-text="กำลังเข้าสู่ระบบ...">
                            เข้าสู่ระบบ <i class="bx bx-right-arrow-alt ms-1" aria-hidden="true"></i>
                        </button>
                    </form>
                    <p class="auth-security-note text-secondary text-center mb-0 mt-4"><i class="bx bx-shield-quarter me-1" aria-hidden="true"></i>การเข้าถึงระบบเป็นไปตามสิทธิ์ของผู้ใช้งาน</p>
                    <p class="auth-copyright text-center mb-0 mt-2"><img class="auth-copyright-logo" src="{{ asset('images/alexiasoft-logo.png') }}" width="566" height="194" alt="" aria-hidden="true"> © {{ now()->year }} AlexiaSoft Company Limited</p>
                </div>
            </div>
        </div>
    </div>
@endsection


@push('scripts')
    <script>
        $(function () {
            window.erpAjaxForm({
                form: '#login-form',
                redirect: true,
                alert: false
            });
        });
    </script>
@endpush
