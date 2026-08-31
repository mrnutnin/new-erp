@extends('Settings::layout')

@section('title', ($warehouse->exists ? 'แก้ไข' : 'เพิ่ม').'คลัง | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <p class="eyebrow mb-2">SETTINGS / WAREHOUSES</p>
            <h1 class="h3 mb-2">{{ $warehouse->exists ? 'แก้ไขคลัง' : 'เพิ่มคลัง' }}</h1>
            <p class="text-secondary mb-0">กำหนดสาขา รหัส ชื่อ และสถานะของคลัง</p>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <form id="warehouse-form" action="{{ $warehouse->exists ? route('settings.warehouses.update', $warehouse) : route('settings.warehouses.store') }}" method="post" novalidate>
                            @csrf
                            @if ($warehouse->exists) @method('PUT') @endif

                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="branch_id">สาขา</label>
                                    <select class="form-select js-select2" id="branch_id" name="branch_id" required>
                                        <option value="">เลือกสาขา</option>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" @selected((string) old('branch_id', $warehouse->branch_id) === (string) $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="invalid-feedback d-block" data-error-for="branch_id"></div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="code">รหัสคลัง</label>
                                    <input class="form-control" id="code" name="code" value="{{ old('code', $warehouse->code) }}" maxlength="50" required>
                                    <div class="invalid-feedback" data-error-for="code"></div>
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label" for="name">ชื่อคลัง</label>
                                    <input class="form-control" id="name" name="name" value="{{ old('name', $warehouse->name) }}" required>
                                    <div class="invalid-feedback" data-error-for="name"></div>
                                </div>
                                <div class="col-12">
                                    <input type="hidden" name="is_active" value="0">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $warehouse->is_active))>
                                        <label class="form-check-label" for="is_active">เปิดใช้งาน</label>
                                        <div class="invalid-feedback" data-error-for="is_active"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a class="btn btn-outline-dark" href="{{ route('settings.warehouses.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>ยกเลิก</a>
                                <button class="btn btn-dark" type="submit" data-busy-text="กำลังบันทึก..."><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกคลัง</button>
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
                form: '#warehouse-form',
                redirect: @json(! $warehouse->exists),
                reload: false
            });
        });
    </script>
@endpush
