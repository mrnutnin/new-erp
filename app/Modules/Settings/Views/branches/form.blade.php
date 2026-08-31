@extends('Settings::layout')

@section('title', ($branch->exists ? 'แก้ไข' : 'เพิ่ม').'สาขา | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <p class="eyebrow mb-2">SETTINGS / BRANCHES</p>
            <h1 class="h3 mb-2">{{ $branch->exists ? 'แก้ไขสาขา' : 'เพิ่มสาขา' }}</h1>
            <p class="text-secondary mb-0">กำหนดรหัส ชื่อ และสถานะของสาขา</p>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <form id="branch-form" action="{{ $branch->exists ? route('settings.branches.update', $branch) : route('settings.branches.store') }}" method="post" novalidate>
                            @csrf
                            @if ($branch->exists) @method('PUT') @endif

                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="code">รหัสสาขา</label>
                                    <input class="form-control" id="code" name="code" value="{{ old('code', $branch->code) }}" maxlength="50" required>
                                    <div class="invalid-feedback" data-error-for="code"></div>
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label" for="name">ชื่อสาขา</label>
                                    <input class="form-control" id="name" name="name" value="{{ old('name', $branch->name) }}" required>
                                    <div class="invalid-feedback" data-error-for="name"></div>
                                </div>
                                <div class="col-12">
                                    <input type="hidden" name="is_active" value="0">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $branch->is_active))>
                                        <label class="form-check-label" for="is_active">เปิดใช้งาน</label>
                                        <div class="invalid-feedback" data-error-for="is_active"></div>
                                    </div>
                                    <div class="form-text">ต้องปิดคลังทั้งหมดในสาขาก่อนจึงจะปิดสาขาได้</div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a class="btn btn-outline-dark" href="{{ route('settings.branches.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>ยกเลิก</a>
                                <button class="btn btn-dark" type="submit" data-busy-text="กำลังบันทึก..."><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกสาขา</button>
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
                form: '#branch-form',
                redirect: @json(! $branch->exists),
                reload: false
            });
        });
    </script>
@endpush
