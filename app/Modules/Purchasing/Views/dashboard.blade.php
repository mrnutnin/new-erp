@extends('Purchasing::layout')

@section('title', 'Purchasing Dashboard | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">PURCHASING</p>
        <h1 class="h3 mb-2">Purchasing Dashboard</h1>
        <p class="text-secondary mb-0">ศูนย์กลางข้อมูล Supplier และกระบวนการจัดซื้อ</p>

        <section class="card border-0 shadow-sm mt-4" aria-labelledby="purchasing-start-title">
            <div class="card-body p-4">
                <p class="eyebrow mb-1">START HERE</p>
                <h2 id="purchasing-start-title" class="h5 mb-2">เริ่มงานจัดซื้อ</h2>
                <p class="text-secondary mb-0">ตรวจสอบใบขอซื้อที่รอดำเนินการ แล้วสร้างใบสั่งซื้อหรือเอกสารจัดซื้อถัดไปตามขั้นตอน</p>
            </div>
        </section>
    </div>
@endsection
