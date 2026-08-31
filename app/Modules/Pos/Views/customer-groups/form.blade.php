@extends('Pos::layout')
@section('title', ($customerGroup->exists ? 'แก้ไข' : 'เพิ่ม').' กลุ่มลูกค้า | POS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4"><p class="eyebrow mb-2">POS / MASTER DATA</p><h1 class="h3 mb-4">{{ $customerGroup->exists ? 'แก้ไข' : 'เพิ่ม' }} กลุ่มลูกค้า</h1>
<div class="card border-0 shadow-sm"><div class="card-body p-4"><form id="customer-group-form" method="{{ $customerGroup->exists ? 'PUT' : 'POST' }}" action="{{ $customerGroup->exists ? route('pos.customer-groups.update', $customerGroup) : route('pos.customer-groups.store') }}">
<div class="row g-3"><div class="col-md-4"><label class="form-label">รหัสกลุ่มลูกค้า</label><input class="form-control" name="code" maxlength="30" value="{{ old('code', $customerGroup->code) }}" required><div class="form-text">ใช้ A-Z, 0-9, จุด ขีดกลาง หรือขีดล่าง</div><div class="invalid-feedback" data-error-for="code"></div></div><div class="col-md-8"><label class="form-label">ชื่อกลุ่มลูกค้า</label><input class="form-control" name="name" value="{{ old('name', $customerGroup->name) }}" required><div class="invalid-feedback" data-error-for="name"></div></div><div class="col-md-3 form-check mt-4 ms-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $customerGroup->exists ? $customerGroup->is_active : true))><label class="form-check-label">เปิดใช้งาน</label></div></div>
<div class="mt-4"><button class="btn btn-dark" type="submit">บันทึก</button> <a class="btn btn-outline-secondary" href="{{ route('pos.customer-groups.index') }}">ยกเลิก</a></div>
</form></div></div></div>
@endsection
@push('scripts')<script>$(function(){window.erpAjaxForm({form:'#customer-group-form',redirect:{{ $customerGroup->exists ? 'false' : 'true' }}});});</script>@endpush
