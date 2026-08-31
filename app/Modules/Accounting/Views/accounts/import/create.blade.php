@extends('Accounting::layout')

@section('title', 'Import ผังบัญชี | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="page-heading mb-4">
            <p class="eyebrow mb-2">ACCOUNTING / IMPORT</p>
            <h1 class="h3 mb-2">Import ผังบัญชี</h1>
            <p class="text-secondary mb-0">ระบบจะ Stage และตรวจสอบทุกแถวก่อน ยังไม่สร้างบัญชีจนกว่าจะกดยืนยัน</p>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <div class="alert alert-info" role="status">
                    ใช้ไฟล์ {{ \App\Modules\Accounting\Support\ChartOfAccountsTemplate::VERSION }} เท่านั้น และวางบัญชีแม่ไว้ก่อนบัญชีย่อย รองรับสูงสุด 2,000 แถว
                </div>
                <form id="account-import-form" action="{{ route('accounting.account-import.stage') }}" method="post" enctype="multipart/form-data" novalidate>
                    @csrf
                    <div class="row g-3">
                        <div class="col-12 col-md-5">
                            <label class="form-label" for="source_system">ระบบต้นทาง</label>
                            <select class="form-select" id="source_system" name="source_system" required>
                                <option value="">กรุณาเลือก</option>
                                <option value="express">Express</option>
                                <option value="winspeed">WinSpeed</option>
                                <option value="minterp">Minterp</option>
                                <option value="other">อื่น ๆ / เริ่มระบบใหม่</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="source_system"></div>
                        </div>
                        <div class="col-12 col-md-7">
                            <label class="form-label" for="file">ไฟล์ Excel (.xlsx)</label>
                            <input class="form-control" id="file" name="file" type="file" accept=".xlsx" required>
                            <div class="invalid-feedback" data-error-for="file"></div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between mt-4">
                        <a class="btn btn-outline-dark" href="{{ route('accounting.accounts.index') }}">กลับผังบัญชี</a>
                        <button class="btn btn-dark" type="submit" data-busy-text="กำลังตรวจสอบ...">
                            <i class="bx bx-upload me-1" aria-hidden="true"></i>อัปโหลดและตรวจสอบ
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
            window.erpAjaxForm({ form: '#account-import-form', redirect: true });
        });
    </script>
@endpush
