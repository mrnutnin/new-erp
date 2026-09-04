@extends('Accounting::layout')

@section('title', 'ตรวจรายการค้าง | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / PERIOD CLOSE</p>
                <h1 class="h3 mb-2">ตรวจรายการค้างก่อนปิดงวด</h1>
                <p class="text-secondary mb-0">{{ $fiscalPeriod->fiscalYear->name }} / {{ $fiscalPeriod->name }} · {{ $fiscalPeriod->start_date->format('d/m/Y') }} – {{ $fiscalPeriod->end_date->format('d/m/Y') }}</p>
            </div>
            <a class="btn btn-outline-dark" href="{{ route('accounting.fiscal-years.show', $fiscalPeriod->fiscalYear) }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับงวดบัญชี</a>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                @if ($failures === [])
                    <div class="alert alert-success d-flex align-items-center mb-0" role="status">
                        <i class="bx bx-check-circle fs-4 me-2" aria-hidden="true"></i>
                        <div><strong>พร้อมปิดงวด</strong><div class="small">ไม่พบรายการค้างหรือเงื่อนไขผิดปกติในงวดนี้</div></div>
                    </div>
                @else
                    <div class="alert alert-warning d-flex align-items-center" role="status">
                        <i class="bx bx-error-circle fs-4 me-2" aria-hidden="true"></i>
                        <div><strong>ยังไม่พร้อมปิดงวด</strong><div class="small">กรุณาดำเนินการรายการด้านล่างให้เรียบร้อยก่อน Lock งวด</div></div>
                    </div>
                    <div class="list-group list-group-flush">
                        @foreach ($failures as $failure)
                            <div class="list-group-item px-0 d-flex gap-2"><i class="bx bx-error text-warning mt-1" aria-hidden="true"></i><span>{{ $failure }}</span></div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
