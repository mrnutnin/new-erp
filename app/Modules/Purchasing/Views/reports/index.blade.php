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

    <form class="card border-0 shadow-sm mb-4" method="GET" action="{{ route('purchasing.reports.pdf') }}" target="_blank">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div><h2 class="h5 mb-1">สรุปภาพรวมสำหรับพิมพ์</h2><p class="text-secondary small mb-0">สรุปจำนวนเอกสารและมูลค่าแยกตามสถานะ ภายในคลังที่คุณมีสิทธิ์</p></div>
                <button class="btn btn-app-primary" type="submit"><i class="bx bx-printer me-1" aria-hidden="true"></i>พิมพ์รายงาน PDF</button>
            </div>
            <div class="row g-3">
                <div class="col-12 col-md-4"><label class="form-label" for="report-date-from">วันที่เริ่มต้น</label><input class="form-control" id="report-date-from" type="date" name="date_from" value="{{ $dateFrom }}" required></div>
                <div class="col-12 col-md-4"><label class="form-label" for="report-date-to">วันที่สิ้นสุด</label><input class="form-control" id="report-date-to" type="date" name="date_to" value="{{ $dateTo }}" min="{{ $dateFrom }}" required></div>
            </div>
        </div>
    </form>

    <div class="row g-3">
        @foreach ($reports as $report)
            @if (auth()->user()->hasPermission($report['permission']))
                <div class="col-12 col-md-6 col-xl-3">
                    <a class="card h-100 border-0 shadow-sm text-decoration-none text-body report-card" href="{{ route($report['route'], $report['query'] ?? []) }}">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-light text-dark p-2"><i class="bx {{ $report['icon'] }} fs-5" aria-hidden="true"></i></span>
                                <i class="bx bx-right-arrow-alt text-dark fs-4" aria-hidden="true"></i>
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

@endsection
