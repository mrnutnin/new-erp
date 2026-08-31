@extends('layouts.app')

@section('title', 'Dashboard | New ERP')

@section('content')
    <div class="container py-5">
        <div class="page-heading mb-4">
            <p class="eyebrow mb-2">DASHBOARD</p>
            <h1 class="h3 mb-2">{{ $program->name }}</h1>
            <p class="text-secondary mb-0">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</p>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <h2 class="h5">พร้อมสำหรับพัฒนา module ถัดไป</h2>
                <p class="text-secondary">หน้าเริ่มต้นนี้ยืนยันว่า Login, Program Context และ Warehouse Context ทำงานครบแล้ว</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-dark" href="{{ route('programs.index') }}">เปลี่ยนโปรแกรม</a>
                    <a class="btn btn-outline-dark" href="{{ route('branches.index') }}">เปลี่ยนสาขา</a>
                </div>
            </div>
        </div>
    </div>
@endsection
