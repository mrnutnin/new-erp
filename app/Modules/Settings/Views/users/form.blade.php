@extends('Settings::layout')

@section('title', ($user->exists ? 'แก้ไข' : 'เพิ่ม').'ผู้ใช้งาน | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="page-heading mb-4">
            <p class="eyebrow mb-2">SETTINGS / USERS</p>
            <h1 class="h3 mb-2">{{ $user->exists ? 'แก้ไขผู้ใช้งาน' : 'เพิ่มผู้ใช้งาน' }}</h1>
            <p class="text-secondary mb-0">กำหนดข้อมูลเข้าสู่ระบบและขอบเขตการใช้งาน</p>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <form id="user-form" action="{{ $user->exists ? route('settings.users.update', $user) : route('settings.users.store') }}" method="post" novalidate>
                            @csrf
                            @if ($user->exists)
                                @method('PUT')
                            @endif

                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="name">ชื่อผู้ใช้งาน</label>
                                    <input class="form-control" id="name" name="name" value="{{ old('name', $user->name) }}" required>
                                    <div class="invalid-feedback" data-error-for="name"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="username">Username</label>
                                    <input class="form-control" id="username" name="username" value="{{ old('username', $user->username) }}" required autocomplete="off">
                                    <div class="invalid-feedback" data-error-for="username"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="employee_code">รหัสพนักงาน</label>
                                    <input class="form-control" id="employee_code" name="employee_code" value="{{ old('employee_code', $user->employee_code) }}" autocomplete="off">
                                    <div class="invalid-feedback" data-error-for="employee_code"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="primary_branch_id">สาขาหลักที่สังกัด</label>
                                    <select class="form-select js-select2" id="primary_branch_id" name="primary_branch_id">
                                        <option value="">— ไม่ระบุ —</option>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" @selected((string) old('primary_branch_id', $user->primary_branch_id) === (string) $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="invalid-feedback" data-error-for="primary_branch_id"></div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="email">Email (ถ้ามี)</label>
                                    <input class="form-control" type="email" id="email" name="email" value="{{ old('email', $user->email) }}">
                                    <div class="invalid-feedback" data-error-for="email"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="password">รหัสผ่าน {{ $user->exists ? '(เว้นว่างหากไม่เปลี่ยน)' : '' }}</label>
                                    <input class="form-control" type="password" id="password" name="password" {{ $user->exists ? '' : 'required' }} autocomplete="new-password">
                                    <div class="invalid-feedback" data-error-for="password"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="password_confirmation">ยืนยันรหัสผ่าน</label>
                                    <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">
                                </div>
                                <div class="col-12">
                                    <input type="hidden" name="is_active" value="0">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $user->is_active))>
                                        <label class="form-check-label" for="is_active">เปิดใช้งาน</label>
                                        <div class="invalid-feedback" data-error-for="is_active"></div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="role_ids">บทบาทและสิทธิ์</label>
                                    <select class="form-select js-select2" id="role_ids" name="role_ids[]" multiple>
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->id }}" @selected(in_array($role->id, old('role_ids', $selectedRoles)))>{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="invalid-feedback d-block" data-error-for="role_ids"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="program_ids">โปรแกรมที่เข้าใช้ได้</label>
                                    <select class="form-select js-select2" id="program_ids" name="program_ids[]" multiple>
                                        @foreach ($programs as $program)
                                            <option value="{{ $program->id }}" @selected(in_array($program->id, old('program_ids', $selectedPrograms)))>{{ $program->name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="invalid-feedback d-block" data-error-for="program_ids"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="branch_ids">สาขาที่มีสิทธิ์เข้าใช้งาน</label>
                                    <select class="form-select js-select2" id="branch_ids" name="branch_ids[]" multiple>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" @selected(in_array($branch->id, old('branch_ids', $selectedBranches)))>{{ $branch->code }} — {{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">หากไม่เลือก ระบบจะใช้สาขาจากคลังที่กำหนดไว้</div>
                                    <div class="invalid-feedback d-block" data-error-for="branch_ids"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="warehouse_ids">คลังที่เข้าใช้ได้</label>
                                    <select class="form-select js-select2" id="warehouse_ids" name="warehouse_ids[]" multiple>
                                        @foreach ($warehouses as $warehouse)
                                            <option value="{{ $warehouse->id }}" @selected(in_array($warehouse->id, old('warehouse_ids', $selectedWarehouses)))>{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="invalid-feedback d-block" data-error-for="warehouse_ids"></div>
                                </div>
                            </div>

                            @include('Platform::partials.permission-summary', ['effectivePermissions' => $effectivePermissions])

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a class="btn btn-outline-dark" href="{{ route('settings.users.index') }}">
                                    <i class="bx bx-arrow-back me-1" aria-hidden="true"></i>ยกเลิก
                                </a>
                                <button class="btn btn-dark" type="submit" data-busy-text="กำลังบันทึก...">
                                    <i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกผู้ใช้งาน
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            window.erpAjaxForm({
                form: '#user-form',
                redirect: @json(! $user->exists),
                reload: false
            });
        });
    </script>
@endpush
