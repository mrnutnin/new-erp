@extends('Settings::layout')

@section('title', ($role->exists ? 'แก้ไข' : 'เพิ่ม').'บทบาท | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="page-heading mb-4">
            <p class="eyebrow mb-2">SETTINGS / ROLES</p>
            <h1 class="h3 mb-2">{{ $role->exists ? 'แก้ไขบทบาท' : 'เพิ่มบทบาท' }}</h1>
            <p class="text-secondary mb-0">กำหนดบทบาทและสิทธิ์ที่ผู้ใช้งานจะได้รับ</p>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <form id="role-form" action="{{ $role->exists ? route('settings.roles.update', $role) : route('settings.roles.store') }}" method="post" novalidate>
                    @csrf
                    @if ($role->exists)
                        @method('PUT')
                    @endif

                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body p-4 p-md-5">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="code">รหัสบทบาท</label>
                                    <input class="form-control" id="code" name="code" value="{{ old('code', $role->code) }}" required @readonly($role->code === 'admin')>
                                    <div class="form-text">ใช้ตัวพิมพ์เล็ก ตัวเลข จุด ขีดกลาง หรือขีดล่าง</div>
                                    <div class="invalid-feedback" data-error-for="code"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="name">ชื่อบทบาท</label>
                                    <input class="form-control" id="name" name="name" value="{{ old('name', $role->name) }}" required>
                                    <div class="invalid-feedback" data-error-for="name"></div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="description">คำอธิบาย</label>
                                    <input class="form-control" id="description" name="description" value="{{ old('description', $role->description) }}">
                                    <div class="invalid-feedback" data-error-for="description"></div>
                                </div>
                                <div class="col-12">
                                    @if ($role->code === 'admin')
                                        <input type="hidden" name="is_active" value="1">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="is_active" checked disabled>
                                            <label class="form-check-label" for="is_active">เปิดใช้งาน</label>
                                        </div>
                                        <div class="form-text">บทบาทผู้ดูแลระบบต้องเปิดใช้งานเสมอ</div>
                                    @else
                                        <input type="hidden" name="is_active" value="0">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $role->is_active))>
                                            <label class="form-check-label" for="is_active">เปิดใช้งาน</label>
                                            <div class="invalid-feedback" data-error-for="is_active"></div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-4 p-md-5">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h2 class="h5 mb-1">สิทธิ์ของบทบาท</h2>
                                    <p class="text-secondary small mb-0">เลือกเฉพาะสิทธิ์ที่จำเป็นต่อหน้าที่</p>
                                </div>
                                <span class="badge text-bg-light">{{ $permissionGroups->flatten()->count() }} สิทธิ์</span>
                            </div>

                            <div class="row g-3">
                                @forelse ($permissionGroups as $prefix => $permissions)
                                    <div class="col-12 col-md-6">
                                        <fieldset class="border rounded-3 p-3 h-100">
                                            <legend class="float-none w-auto px-2 fs-6 fw-semibold mb-2">{{ $prefix }}</legend>
                                            @foreach ($permissions as $permission)
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" id="permission_{{ $permission->id }}" name="permission_ids[]" value="{{ $permission->id }}" @checked($role->code === 'admin' || in_array($permission->id, old('permission_ids', $selectedPermissions))) @disabled($role->code === 'admin')>
                                                    <label class="form-check-label" for="permission_{{ $permission->id }}">
                                                        {{ $permission->name }}
                                                        <span class="d-block small text-secondary">{{ $permission->code }}</span>
                                                    </label>
                                                </div>
                                            @endforeach
                                            @if ($role->code === 'admin')
                                                <div class="form-text">บทบาทผู้ดูแลระบบได้รับทุกสิทธิ์เสมอ</div>
                                            @endif
                                        </fieldset>
                                    </div>
                                @empty
                                    <div class="col-12 text-center text-secondary py-4">ยังไม่มีสิทธิ์ในระบบ</div>
                                @endforelse
                            </div>
                            <div class="invalid-feedback d-block" data-error-for="permission_ids"></div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a class="btn btn-outline-dark" href="{{ route('settings.roles.index') }}">
                                    <i class="bx bx-arrow-back me-1" aria-hidden="true"></i>ยกเลิก
                                </a>
                                <button class="btn btn-dark" type="submit" data-busy-text="กำลังบันทึก...">
                                    <i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกบทบาท
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            window.erpAjaxForm({
                form: '#role-form',
                redirect: @json(! $role->exists),
                reload: false
            });
        });
    </script>
@endpush
