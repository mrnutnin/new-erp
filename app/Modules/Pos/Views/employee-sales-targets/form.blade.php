@extends('Pos::layout')

@section('title', ($target->exists ? 'แก้ไข' : 'เพิ่ม').' เป้าหมายพนักงาน | POS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="mb-4"><p class="eyebrow mb-2">POS / SALES / MASTER DATA</p><h1 class="h3 mb-2">{{ $target->exists ? 'แก้ไข' : 'เพิ่ม' }}เป้าหมายพนักงาน</h1><p class="text-secondary mb-0">กำหนดเป้าหมายเฉพาะพนักงานที่เปิดใช้งานในสาขาปัจจุบัน และช่วงเวลาต้องไม่ทับซ้อนกัน</p></div>
    <form id="employee-sales-target-form" method="POST" action="{{ $target->exists ? route('pos.employee-sales-targets.update', $target) : route('pos.employee-sales-targets.store') }}">@csrf @if($target->exists)@method('PUT')@endif
        <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="row g-3"><div class="col-lg-6"><label class="form-label" for="target-user">พนักงาน <span class="text-danger">*</span></label><select id="target-user" class="form-select" name="user_id" required><option value="">เลือกพนักงาน</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected((string) old('user_id', $target->user_id) === (string) $employee->id)>{{ $employee->name }}{{ $employee->username ? ' · '.$employee->username : '' }}</option>@endforeach</select><div class="invalid-feedback" data-error-for="user_id"></div></div><div class="col-md-3"><label class="form-label" for="target-start">เริ่มงวด <span class="text-danger">*</span></label><input id="target-start" class="form-control" type="date" name="period_start" value="{{ old('period_start', $target->period_start?->format('Y-m-d')) }}" required><div class="invalid-feedback" data-error-for="period_start"></div></div><div class="col-md-3"><label class="form-label" for="target-end">สิ้นสุดงวด <span class="text-danger">*</span></label><input id="target-end" class="form-control" type="date" name="period_end" value="{{ old('period_end', $target->period_end?->format('Y-m-d')) }}" required><div class="invalid-feedback" data-error-for="period_end"></div></div><div class="col-md-6"><label class="form-label" for="target-sales">เป้ายอดขาย <span class="text-danger">*</span></label><input id="target-sales" class="form-control text-end" type="number" min="0" step="0.01" name="sales_target" value="{{ old('sales_target', $target->sales_target) }}" required><div class="invalid-feedback" data-error-for="sales_target"></div></div><div class="col-md-6"><label class="form-label" for="target-gp">เป้ากำไรขั้นต้น <span class="text-danger">*</span></label><input id="target-gp" class="form-control text-end" type="number" min="0" step="0.01" name="gross_profit_target" value="{{ old('gross_profit_target', $target->gross_profit_target) }}" required><div class="invalid-feedback" data-error-for="gross_profit_target"></div></div></div><div class="mt-4"><button class="btn btn-dark" type="submit">บันทึก</button> <a class="btn btn-outline-secondary" href="{{ route('pos.employee-sales-targets.index') }}">ย้อนกลับ</a></div></div></div>
    </form>
</div>
@endsection

@push('scripts')
<script>$(function(){window.erpAjaxForm({form:'#employee-sales-target-form',redirect:{{ $target->exists ? 'false' : 'true' }}});});</script>
@endpush
