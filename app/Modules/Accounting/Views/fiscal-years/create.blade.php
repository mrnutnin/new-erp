@extends('Accounting::layout')

@section('title', 'สร้างปีบัญชี | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <p class="eyebrow mb-2">ACCOUNTING / FISCAL PERIODS</p>
            <h1 class="h3 mb-2">สร้างปีบัญชี</h1>
            <p class="text-secondary mb-0">ระบบจะสร้าง 12 งวดรายเดือนต่อเนื่องจากวันเริ่มปีบัญชี</p>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <form id="fiscal-year-form" action="{{ route('accounting.fiscal-years.store') }}" method="post" novalidate>
                            @csrf
                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="code">รหัสปีบัญชี</label>
                                    <input class="form-control text-uppercase" id="code" name="code" value="{{ old('code') }}" maxlength="20" placeholder="FY2026" required>
                                    <div class="invalid-feedback" data-error-for="code"></div>
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label" for="name">ชื่อปีบัญชี</label>
                                    <input class="form-control" id="name" name="name" value="{{ old('name') }}" placeholder="ปีบัญชี 2569" required>
                                    <div class="invalid-feedback" data-error-for="name"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="start_date">วันเริ่มปีบัญชี</label>
                                    <input class="form-control" id="start_date" name="start_date" type="date" value="{{ old('start_date') }}" required>
                                    <div class="invalid-feedback" data-error-for="start_date"></div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a class="btn btn-outline-dark" href="{{ route('accounting.fiscal-years.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>ยกเลิก</a>
                                <button class="btn btn-dark" type="submit" data-busy-text="กำลังสร้าง..."><i class="bx bx-save me-1" aria-hidden="true"></i>สร้างปีและ 12 งวด</button>
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
                form: '#fiscal-year-form',
                redirect: true,
                reload: false
            });
        });
    </script>
@endpush
