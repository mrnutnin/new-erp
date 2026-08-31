@extends('Accounting::layout')

@section('title', 'ตรวจสอบ Import ผังบัญชี | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / IMPORT BATCH #{{ $batch->id }}</p>
                <h1 class="h3 mb-2">ผลตรวจสอบผังบัญชี</h1>
                <p class="text-secondary mb-0">{{ $batch->original_filename }} · {{ $batch->template_version }}</p>
            </div>
            <a class="btn btn-outline-dark" href="{{ route('accounting.account-import.create') }}">อัปโหลดไฟล์ใหม่</a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-secondary">ทั้งหมด</div><div class="h3 mb-0">{{ $batch->total_rows }}</div></div></div></div>
            <div class="col-12 col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-secondary">ผ่าน</div><div class="h3 mb-0">{{ $batch->valid_rows }}</div></div></div></div>
            <div class="col-12 col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-secondary">ผิดพลาด</div><div class="h3 mb-0">{{ $batch->error_rows }}</div></div></div></div>
        </div>

        @if ($batch->error_rows > 0)
            <div class="alert alert-danger d-flex flex-wrap justify-content-between align-items-center gap-2" role="alert">
                <span>ต้องแก้ทุกแถวที่ผิดพลาดแล้วอัปโหลดไฟล์ใหม่ ระบบจะไม่ Import บางส่วน</span>
                <a class="btn btn-sm btn-outline-danger" href="{{ route('accounting.account-import.errors', $batch) }}">ดาวน์โหลด Error Workbook</a>
            </div>
        @elseif ($batch->status === 'VALIDATED' && auth()->user()->hasPermission('accounting.accounts.import.commit'))
            <div class="alert alert-success" role="status">ข้อมูลผ่านการตรวจสอบครบ พร้อมนำเข้าผังบัญชี</div>
            <form id="account-import-commit-form" action="{{ route('accounting.account-import.commit', $batch) }}" method="post" class="mb-4">
                @csrf
                @method('PUT')
                <button class="btn btn-dark" type="submit" data-busy-text="กำลังนำเข้า...">
                    <i class="bx bx-check me-1" aria-hidden="true"></i>ยืนยัน Import {{ $batch->total_rows }} บัญชี
                </button>
            </form>
        @elseif ($batch->status === 'COMMITTED')
            <div class="alert alert-info" role="status">Batch นี้ Import แล้วเมื่อ {{ $batch->committed_at->format('d/m/Y H:i') }}</div>
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">ตัวอย่างผลตรวจสอบ 100 แถวแรก</h2>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>แถว</th><th>รหัส</th><th>ชื่อบัญชี</th><th>ระดับ</th><th>ประเภท</th><th>ผลตรวจ</th></tr></thead>
                        <tbody>
                            @foreach (array_slice($batch->staged_rows, 0, 100) as $row)
                                <tr>
                                    <td>{{ $row['row_number'] }}</td>
                                    <td>{{ $row['normalized']['code'] }}</td>
                                    <td>{{ $row['normalized']['name'] }}</td>
                                    <td>{{ $row['normalized']['level'] }}</td>
                                    <td>{{ $row['normalized']['account_class'] }}</td>
                                    <td>{{ $row['errors'] === [] ? 'ผ่าน' : implode(' · ', $row['errors']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            window.erpAjaxForm({ form: '#account-import-commit-form', redirect: true });
        });
    </script>
@endpush
