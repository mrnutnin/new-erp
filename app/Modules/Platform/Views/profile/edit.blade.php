@extends('layouts.app')

@section('title', 'ข้อมูลส่วนตัว | New ERP')

@section('content')
    <div class="container py-4">
        <div class="page-heading mb-4">
            <p class="eyebrow mb-2">MY PROFILE</p>
            <h1 class="h3 mb-2">ข้อมูลส่วนตัว</h1>
            <p class="text-secondary mb-0">แก้ไขชื่อ อีเมล และรหัสผ่านสำหรับเข้าสู่ระบบ</p>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <form id="profile-form" action="{{ route('profile.update') }}" method="post" novalidate>
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="name">ชื่อผู้ใช้งาน</label>
                            <input class="form-control" id="name" name="name" value="{{ old('name', auth()->user()->name) }}" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control" type="email" id="email" name="email" value="{{ old('email', auth()->user()->email) }}">
                            <div class="invalid-feedback" data-error-for="email"></div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <p class="fw-semibold mb-1">เปลี่ยนรหัสผ่าน</p>
                    <p class="small text-secondary mb-3">เว้นว่างไว้หากไม่ต้องการเปลี่ยน</p>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="current_password">รหัสผ่านปัจจุบัน</label>
                            <input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password">
                            <div class="invalid-feedback" data-error-for="current_password"></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="password">รหัสผ่านใหม่</label>
                            <input class="form-control" type="password" id="password" name="password" autocomplete="new-password">
                            <div class="invalid-feedback" data-error-for="password"></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="password_confirmation">ยืนยันรหัสผ่านใหม่</label>
                            <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button class="btn btn-dark" type="submit" data-busy-text="กำลังบันทึก...">
                            <i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกข้อมูลส่วนตัว
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            window.erpAjaxForm({ form: '#profile-form', reload: false });
        });
    </script>
@endpush
