@extends('layouts.app')

@section('title', 'ข้อมูลส่วนตัว | MintERP')

@section('content')
    <div class="container py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 page-heading mb-4">
            <div>
                <p class="eyebrow mb-2">MY PROFILE</p>
                <h1 class="h3 mb-2">ข้อมูลส่วนตัว</h1>
                <p class="text-secondary mb-0">แก้ไขข้อมูลส่วนตัว และตรวจสอบสิทธิ์การใช้งานของคุณ</p>
            </div>
            <a class="btn btn-outline-dark" href="{{ route('programs.index') }}">
                <i class="bx bx-grid-alt me-1" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม
            </a>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
                    <div>
                        <h2 class="h5 mb-1">ข้อมูลบัญชีผู้ใช้งาน</h2>
                        <p class="small text-secondary mb-0">ข้อมูลประจำบัญชีและสาขาที่สังกัด หากไม่ถูกต้องให้ติดต่อผู้ดูแลระบบ</p>
                    </div>
                    <span class="badge {{ $user->is_active ? 'app-status-success' : 'app-status-neutral' }}">
                        {{ $user->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                    </span>
                </div>

                <dl class="row g-3 mb-0">
                    <div class="col-12 col-md-6 col-xl-4">
                        <dt class="small fw-normal text-secondary mb-1">ชื่อผู้ใช้งาน</dt>
                        <dd class="fw-semibold mb-0">{{ $user->name ?: '—' }}</dd>
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <dt class="small fw-normal text-secondary mb-1">รหัสพนักงาน</dt>
                        <dd class="fw-semibold mb-0">{{ $user->employee_code ?: '—' }}</dd>
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <dt class="small fw-normal text-secondary mb-1">ตำแหน่ง</dt>
                        <dd class="fw-semibold mb-0">{{ $user->position ?: '—' }}</dd>
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <dt class="small fw-normal text-secondary mb-1">Username</dt>
                        <dd class="fw-semibold mb-0">{{ $user->username ?: '—' }}</dd>
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <dt class="small fw-normal text-secondary mb-1">สาขาที่สังกัด</dt>
                        <dd class="fw-semibold mb-0">
                            {{ $user->primaryBranch ? $user->primaryBranch->code.' · '.$user->primaryBranch->name : '— ไม่ระบุ —' }}
                        </dd>
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <dt class="small fw-normal text-secondary mb-1">อีเมล</dt>
                        <dd class="fw-semibold text-break mb-0">{{ $user->email ?: '—' }}</dd>
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <dt class="small fw-normal text-secondary mb-1">สถานะบัญชี</dt>
                        <dd class="fw-semibold mb-0">{{ $user->is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4 p-md-5">
                <h2 class="h5 mb-1">ข้อมูลส่วนตัว</h2>
                <p class="small text-secondary mb-4">แก้ไขชื่อ อีเมล และรูปโปรไฟล์ของคุณ</p>
                <form id="profile-form" action="{{ route('profile.update') }}" method="post" enctype="multipart/form-data" novalidate>
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-12">
                            <div class="row justify-content-center">
                                <div class="col-12 col-md-8 col-lg-6">
                                    <x-platform::file-uploader
                                        name="profile_image"
                                        label="รูปโปรไฟล์"
                                        accept="image/jpeg,image/png,image/webp"
                                        max-file-size="5MB"
                                        help="JPG, PNG หรือ WEBP ขนาดไม่เกิน 5 MB"
                                        :image-preview="true"
                                        :preview-url="$user->profile_image_path ? route('profile.image') : null"
                                        preview-alt="รูปโปรไฟล์ปัจจุบัน"
                                    />
                                    @if ($user->profile_image_path)
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" id="remove_profile_image" name="remove_profile_image" type="checkbox" value="1">
                                            <label class="form-check-label text-danger" for="remove_profile_image">ลบรูปโปรไฟล์ปัจจุบัน</label>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
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
                        <x-platform::user-signature-fields
                            :has-signature="filled($user->signature_path)"
                            :preview-url="$user->signature_path ? route('profile.signature') : null"
                            preview-alt="ลายเซ็นปัจจุบันของคุณ"
                        />
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button class="btn btn-app-primary" type="submit" data-busy-text="กำลังบันทึก...">
                            <i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกข้อมูลส่วนตัว
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4 p-md-5">
                <h2 class="h5 mb-1">เปลี่ยนรหัสผ่าน</h2>
                <p class="small text-secondary mb-4">ยืนยันรหัสผ่านปัจจุบันก่อนกำหนดรหัสผ่านใหม่</p>
                <form id="profile-password-form" action="{{ route('profile.password.update') }}" method="post" novalidate>
                    @csrf
                    @method('PUT')

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
                        <button class="btn btn-app-primary" type="submit" data-busy-text="กำลังเปลี่ยนรหัสผ่าน...">
                            <i class="bx bx-lock-alt me-1" aria-hidden="true"></i>เปลี่ยนรหัสผ่าน
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4 p-md-5">
                <h2 class="h5 mb-1">สิทธิ์การใช้งาน</h2>
                <p class="small text-secondary mb-4">สิทธิ์เหล่านี้กำหนดขอบเขตข้อมูลและเมนูที่คุณเข้าใช้งานได้</p>
                <div class="row g-4">
                    <div class="col-12 col-lg-4">
                        <h3 class="h6">โปรแกรมที่เข้าใช้ได้</h3>
                        @forelse ($user->programs as $program)
                            <span class="badge text-bg-light border me-1 mb-1">{{ $program->name }}</span>
                        @empty
                            <span class="text-secondary">ไม่มีสิทธิ์</span>
                        @endforelse
                    </div>
                    <div class="col-12 col-lg-4">
                        <h3 class="h6">สาขาที่เข้าใช้ได้</h3>
                        @forelse ($user->branches as $branch)
                            <span class="badge text-bg-light border me-1 mb-1">{{ $branch->code }} · {{ $branch->name }}</span>
                        @empty
                            <span class="text-secondary">ใช้สิทธิ์สาขาจากคลังที่กำหนด</span>
                        @endforelse
                    </div>
                    <div class="col-12 col-lg-4">
                        <h3 class="h6">คลังที่เข้าใช้ได้</h3>
                        @forelse ($user->warehouses as $warehouse)
                            <span class="badge text-bg-light border me-1 mb-1">{{ $warehouse->code }} · {{ $warehouse->name }}</span>
                        @empty
                            <span class="text-secondary">ไม่มีสิทธิ์</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        @include('Platform::partials.permission-summary', ['effectivePermissions' => $effectivePermissions])

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                    <h2 class="h5 mb-0">ประวัติการใช้งาน (Audit Log)</h2>
                    <span class="small text-secondary">โหลดข้อมูลแบบแบ่งหน้า</span>
                </div>
                <p class="small text-secondary mb-4">รายการที่คุณเคยดำเนินการในระบบ</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="profile-audit-table" data-url="{{ route('profile.audit-data') }}">
                        <thead>
                            <tr><th>วันเวลา</th><th>การกระทำ</th><th>เอกสารอ้างอิง</th><th>รายละเอียด</th></tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            window.erpAjaxForm({ form: '#profile-form', reload: true });
            window.erpAjaxForm({ form: '#profile-password-form', reload: true });
            var $table = $('#profile-audit-table');
            var text = $.fn.dataTable.render.text();
            $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                ajax: $table.data('url'),
                pageLength: 10,
                order: [[0, 'desc']],
                columns: [
                    { data: 'created_at_label', name: 'created_at', render: text.display },
                    { data: 'action', name: 'action', render: text.display },
                    { data: 'subject_label', name: 'subject_type', render: text.display },
                    { data: 'reason_label', name: 'new_values', orderable: false, searchable: false, render: text.display }
                ]
            }));
        });
    </script>
@endpush
