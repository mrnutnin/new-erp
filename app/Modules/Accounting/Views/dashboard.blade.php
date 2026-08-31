@extends('Accounting::layout')

@section('title', 'Accounting Dashboard | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="page-heading mb-4">
            <p class="eyebrow mb-2">ACCOUNTING DASHBOARD</p>
            <h1 class="h3 mb-2">{{ $program->name }}</h1>
            <p class="text-secondary mb-0">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</p>
        </div>

        <div class="row g-4">
            @if (auth()->user()->hasPermission('accounting.accounts.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-list-ul fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">ผังบัญชี</h2>
                            <p class="text-secondary">จัดการบัญชี หมวดบัญชี และโครงสร้างรายงาน PAE/NPAE</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('accounting.accounts.index') }}">เปิดผังบัญชี</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('accounting.periods.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-calendar fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">ปีและงวดบัญชี</h2>
                            <p class="text-secondary">ดูปีบัญชี งวดบัญชี และสถานะการเปิดหรือปิดงวด</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('accounting.fiscal-years.index') }}">เปิดรายการ</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('accounting.journal-books.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-book-bookmark fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">สมุดบัญชี</h2>
                            <p class="text-secondary">สมุดซื้อ ขาย รับ จ่าย และทั่วไปสำหรับการลงบัญชี</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('accounting.journal-books.index') }}">เปิดรายการ</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('accounting.reports.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-bar-chart-alt-2 fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">รายงานบัญชี</h2>
                            <p class="text-secondary">GL, งบทดลอง, งบกำไรขาดทุน และงบดุลจากรายการที่ลงบัญชีแล้ว</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('accounting.reports.trial-balance.index') }}">เปิดรายงาน</a>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
