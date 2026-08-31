@extends('Purchasing::layout')

@section('title', 'รายงานปฏิบัติการ | Purchasing')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">PURCHASING / REPORTS</p>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-2">รายงานปฏิบัติการจัดซื้อ</h1>
            <p class="text-secondary mb-0">เลือกหน้ารายงานเพื่อค้นหาและติดตามเอกสารตามวันที่ Supplier และสถานะ</p>
        </div>
        <span class="badge app-status-info">ใช้ DataTable แบบ server-side</span>
    </div>

    <div class="row g-3">
        @foreach ($reports as $report)
            @if (auth()->user()->hasPermission($report['permission']))
                <div class="col-12 col-md-6 col-xl-3">
                    <a class="card h-100 border-0 shadow-sm text-decoration-none text-body report-card" href="{{ route($report['route'], $report['query'] ?? []) }}">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <span class="report-icon"><i class="bx {{ $report['icon'] }}" aria-hidden="true"></i></span>
                                <i class="bx bx-right-arrow-alt text-primary fs-4" aria-hidden="true"></i>
                            </div>
                            <h2 class="h6 mb-2">{{ $report['title'] }}</h2>
                            <p class="text-secondary small mb-0">{{ $report['description'] }}</p>
                        </div>
                    </a>
                </div>
            @endif
        @endforeach
    </div>

    <div class="alert alert-info border-0 mt-4 mb-0" role="note">
        <i class="bx bx-info-circle me-1" aria-hidden="true"></i>
        แต่ละหน้ารายงานเป็นรายการเอกสารต้นทางโดยตรง จึงใช้ตัวกรองและการค้นหาของ DataTable เดิม และไม่โหลดข้อมูลทั้งหมดพร้อมกัน
    </div>
</div>

<style>
    .report-card { transition: transform .15s ease, box-shadow .15s ease; }
    .report-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(31, 41, 55, .10) !important; }
    .report-icon { width: 2.5rem; height: 2.5rem; display: inline-flex; align-items: center; justify-content: center; border-radius: .8rem; background: #e8efff; color: #3564d8; font-size: 1.25rem; }
</style>
@endsection
