@extends('layouts.app')

@section('title', 'เข้าสู่ระบบ | New ERP')
@section('body-class', 'auth-page')

@section('content')
    <div class="auth-shell container">
        <div class="auth-card card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <div class="mb-4">
                    <p class="eyebrow mb-2">NEW ERP</p>
                    <h1 class="h3 mb-2">เข้าสู่ระบบ</h1>
                    <p class="text-secondary mb-0">กรอกบัญชีผู้ใช้เพื่อเริ่มทำงาน</p>
                </div>

                <form id="login-form" action="{{ route('login.store') }}" method="post" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="username">ชื่อผู้ใช้</label>
                        <input class="form-control" id="username" name="username" type="text" autocomplete="username" autofocus required>
                        <div class="invalid-feedback" data-error-for="username"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">รหัสผ่าน</label>
                        <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                        <div class="invalid-feedback" data-error-for="password"></div>
                    </div>
                    <div class="form-check mb-4">
                        <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                        <label class="form-check-label" for="remember">จดจำการเข้าสู่ระบบ</label>
                    </div>
                    <button class="btn btn-dark w-100" type="submit" data-busy-text="กำลังเข้าสู่ระบบ...">เข้าสู่ระบบ</button>
                </form>
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
