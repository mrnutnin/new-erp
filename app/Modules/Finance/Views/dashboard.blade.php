@extends('Finance::layout')

@section('title', 'Finance Dashboard | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">FINANCE</p>
        <h1 class="h3 mb-2">Finance Dashboard</h1>
        <p class="text-secondary mb-4">ศูนย์กลางรับเงิน จ่ายเงิน ลูกหนี้ เจ้าหนี้ และเชื่อมรายการบัญชีผ่าน Accounting</p>
        <div class="card border-0 shadow-sm"><div class="card-body p-4"><span class="badge text-bg-info mb-3">Accounting posting contract พร้อมใช้งาน</span><p class="mb-0">ขั้นถัดไปคือสร้างเอกสารรับเงิน/จ่ายเงิน และส่งผลกระทบเข้าบัญชีผ่าน SettlementPostingService</p></div></div>
    </div>
@endsection
