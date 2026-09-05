@extends('Finance::layout')

@section('title', ($fund->exists ? 'แก้ไข' : 'เพิ่ม').' วงเงินสดย่อย | Finance')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div><p class="eyebrow mb-2">FINANCE / MASTER DATA</p><h1 class="h3 mb-1">{{ $fund->exists ? 'แก้ไข' : 'เพิ่ม' }}วงเงินสดย่อย</h1></div>
        <a class="btn btn-outline-secondary" href="{{ route('finance.petty-cash-funds.index') }}">กลับ</a>
    </div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4">
        <form id="petty-cash-fund-form" method="POST" action="{{ $fund->exists ? route('finance.petty-cash-funds.update', $fund) : route('finance.petty-cash-funds.store') }}">
            @csrf
            @if($fund->exists) @method('PUT') @endif
            <input type="hidden" name="warehouse_id" value="{{ request()->attributes->get('selectedWarehouse')->id }}">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">ชื่อวงเงินสดย่อย <span class="text-danger">*</span></label><input class="form-control" name="name" maxlength="150" value="{{ old('name', $fund->name) }}" placeholder="เช่น วงเงินสดย่อย ฝ่ายขาย" required><div class="invalid-feedback" data-error-for="name"></div></div>
                <div class="col-md-6"><label class="form-label">บัญชีเงินสด <span class="text-danger">*</span></label><select class="form-select" name="bank_account_id" required><option value="">เลือกบัญชีเงินสด</option>@foreach($cashBankAccountOptions as $id=>$label)<option value="{{ $id }}" @selected((int) old('bank_account_id',$fund->bank_account_id)===(int)$id)>{{ $label }}</option>@endforeach</select><div class="invalid-feedback" data-error-for="bank_account_id"></div></div>
                <div class="col-md-6"><label class="form-label">ผู้ดูแลวงเงิน</label><select class="form-select" name="custodian_user_id"><option value="">— ไม่ระบุ —</option>@foreach($userOptions as $id=>$label)<option value="{{ $id }}" @selected((int) old('custodian_user_id',$fund->custodian_user_id)===(int)$id)>{{ $label }}</option>@endforeach</select><div class="invalid-feedback" data-error-for="custodian_user_id"></div></div>
                <div class="col-md-6"><label class="form-label">วงเงิน <span class="text-danger">*</span></label><input class="form-control text-end" type="number" name="fund_limit" min="0" step="0.01" value="{{ old('fund_limit',$fund->fund_limit) }}" required><div class="invalid-feedback" data-error-for="fund_limit"></div></div>
                <div class="col-12"><input type="hidden" name="is_active" value="0"><div class="form-check"><input class="form-check-input" id="fund-active" type="checkbox" name="is_active" value="1" @checked(old('is_active',$fund->is_active))><label class="form-check-label" for="fund-active">เปิดใช้งานวงเงินสดย่อย</label></div></div>
            </div>
            <div class="mt-4 d-flex gap-2"><button class="btn btn-dark" type="submit">บันทึก</button><a class="btn btn-outline-secondary" href="{{ route('finance.petty-cash-funds.index') }}">ยกเลิก</a></div>
        </form>
    </div></div>
    @if($fund->exists)
        <div class="card border-0 shadow-sm mt-4"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">ประวัติเอกสาร</h2>
            @forelse($auditLogs as $log)
                <div class="d-flex gap-3 border-bottom py-2"><div class="small text-secondary text-nowrap">{{ $log->created_at?->format('d/m/Y H:i') }}</div><div><strong>{{ $log->action }}</strong><div class="small text-secondary">{{ $log->user?->name ?? 'ระบบ' }}</div>@if($log->reason)<div class="small mt-1"><span class="text-secondary">รายละเอียด:</span> {{ $log->reason }}</div>@endif</div></div>
            @empty
                <p class="text-secondary mb-0">ยังไม่มีประวัติ</p>
            @endforelse
        </div></div>
    @endif
</div>
@endsection

@push('scripts')
<script>$(function(){window.erpAjaxForm({form:'#petty-cash-fund-form',redirect:{{ $fund->exists ? 'false' : 'true' }}});});</script>
@endpush
