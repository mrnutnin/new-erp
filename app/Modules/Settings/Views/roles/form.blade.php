@extends('Settings::layout')

@section('title', ($role->exists ? 'แก้ไข' : 'เพิ่ม').'บทบาท | MintERP')

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
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                <div>
                                    <h2 class="h5 mb-1">สิทธิ์ของบทบาท</h2>
                                    <p class="text-secondary small mb-0">เลือกโมดูล แล้วเลือกเฉพาะงานที่บทบาทนี้ต้องรับผิดชอบ</p>
                                </div>
                                <span class="badge app-badge-soft">{{ $permissionModules->sum('count') }} สิทธิ์</span>
                            </div>

                            @if ($permissionModules->isNotEmpty())
                                <div class="overflow-auto mb-4">
                                    <ul class="nav nav-pills flex-nowrap gap-2" role="tablist" aria-label="โมดูลสิทธิ์">
                                        @foreach ($permissionModules as $module => $moduleData)
                                            @php($moduleId = 'permission-module-'.$module)
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link text-nowrap @if ($loop->first) active @endif" id="{{ $moduleId }}-tab" data-bs-toggle="tab" data-bs-target="#{{ $moduleId }}" type="button" role="tab" aria-controls="{{ $moduleId }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                                                    {{ $moduleData['label'] }}
                                                    <span class="badge text-bg-light ms-1">{{ $moduleData['count'] }}</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>

                                <div class="tab-content">
                                    @foreach ($permissionModules as $module => $moduleData)
                                        @php($moduleId = 'permission-module-'.$module)
                                        <section class="tab-pane fade @if ($loop->first) show active @endif" id="{{ $moduleId }}" role="tabpanel" aria-labelledby="{{ $moduleId }}-tab" tabindex="0">
                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                                <div>
                                                    <h3 class="h6 mb-1">โมดูล{{ $moduleData['label'] }}</h3>
                                                    <p class="small text-secondary mb-0">แบ่งสิทธิ์ตามหน้าจอและงาน เพื่อให้ตรวจสอบก่อนบันทึกได้ง่าย</p>
                                                </div>
                                                <span class="small text-secondary">{{ $moduleData['count'] }} สิทธิ์</span>
                                            </div>
                                            <div class="row g-3">
                                                @foreach ($moduleData['groups'] as $group)
                                                    @php($groupId = 'permission-group-'.md5($module.'.'.$group['code']))
                                                    <div class="col-12 col-xl-6">
                                                        <fieldset class="border rounded-3 p-3 h-100">
                                                            <legend class="float-none w-100 px-2 mb-2 d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                                <span>
                                                                    <span class="d-block fs-6 fw-semibold">{{ $group['label'] }}</span>
                                                                    <span class="d-block small fw-normal text-secondary">รหัสกลุ่ม: {{ $module }}.{{ $group['code'] }}</span>
                                                                </span>
                                                                <label class="small fw-normal text-secondary mb-0 text-nowrap">
                                                                    <input class="form-check-input me-1 js-check-all" type="checkbox" data-group="{{ $groupId }}" @disabled($role->code === 'admin')>
                                                                    เลือกทั้งหมด
                                                                </label>
                                                            </legend>
                                                            @foreach ($group['permissions'] as $permission)
                                                                <div class="form-check mb-2">
                                                                    <input class="form-check-input js-permission" type="checkbox" data-group="{{ $groupId }}" id="permission_{{ $permission->id }}" name="permission_ids[]" value="{{ $permission->id }}" @checked($role->code === 'admin' || in_array($permission->id, old('permission_ids', $selectedPermissions))) @disabled($role->code === 'admin')>
                                                                    <label class="form-check-label" for="permission_{{ $permission->id }}">
                                                                        {{ $permission->name }}
                                                                    </label>
                                                                </div>
                                                            @endforeach
                                                            @if ($role->code === 'admin')
                                                                <div class="form-text">บทบาทผู้ดูแลระบบได้รับทุกสิทธิ์เสมอ</div>
                                                            @endif
                                                        </fieldset>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </section>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-center text-secondary py-4">ยังไม่มีสิทธิ์ในระบบ</div>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="permission_ids"></div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a class="btn btn-app-soft" href="{{ route('settings.roles.index') }}">
                                    <i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ
                                </a>
                                <button class="btn btn-app-primary" type="submit" data-busy-text="กำลังบันทึก...">
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
            function syncCheckAll(group) {
                var $permissions = $('.js-permission[data-group="' + group + '"]');
                var checked = $permissions.filter(':checked').length;
                var $toggle = $('.js-check-all[data-group="' + group + '"]');
                $toggle.prop('checked', checked === $permissions.length);
                $toggle.prop('indeterminate', checked > 0 && checked < $permissions.length);
            }

            $('.js-check-all').each(function () {
                syncCheckAll($(this).data('group'));
            }).on('change', function () {
                $('.js-permission[data-group="' + $(this).data('group') + '"]').prop('checked', this.checked);
                $(this).prop('indeterminate', false);
            });
            $('.js-permission').on('change', function () {
                syncCheckAll($(this).data('group'));
            });

            window.erpAjaxForm({
                form: '#role-form',
                redirect: @json(! $role->exists),
                reload: false
            });
        });
    </script>
@endpush
