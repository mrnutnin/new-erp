@extends('Accounting::layout')

@section('title', ($accountMapping->exists ? 'แก้ไข' : 'เพิ่ม').' การตั้งค่าการลงบัญชี | New ERP')

@section('content')
    @php($isLegacy = $accountMapping->exists && $accountMapping->event_code === null)
    @php($legacyLabel = $isLegacy ? app(\App\Modules\Accounting\Services\AccountMappingService::class)->label($accountMapping->key) : null)
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div><p class="eyebrow mb-2">ACCOUNTING / POSTING CONFIGURATION</p><h1 class="h3 mb-1">{{ $accountMapping->exists ? 'แก้ไข' : 'เพิ่ม' }}การตั้งค่าการลงบัญชี</h1><p class="text-secondary mb-0">กำหนดบัญชีตาม Posting event และบทบาทบัญชี โดยไม่เปลี่ยน Journal ที่ Post แล้ว</p></div>
            <a class="btn btn-outline-secondary" href="{{ route('accounting.account-mappings.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>รายการทั้งหมด</a>
        </div>
        @if ($isLegacy)<div class="alert alert-warning border-0 shadow-sm d-flex gap-2"><i class="bx bx-error-circle fs-5" aria-hidden="true"></i><div><strong>Legacy Mapping</strong><div class="small">รายการนี้รองรับ Feature เดิมระหว่าง rollout เท่านั้น โปรดใช้ “คัดลอกเป็น Event” เพื่อสร้างการตั้งค่าใหม่</div></div></div>@endif
        @if ($copyFromLegacy)<div class="alert alert-info border-0 shadow-sm">คัดลอกจาก Legacy Mapping: <strong>{{ $copyFromLegacy->key }}</strong> · เลือก Event ที่ใช้บทบาทบัญชีนี้ แล้วตรวจสอบบัญชีก่อนบันทึก</div>@endif
        <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4">
            <form id="account-mapping-form" method="POST" action="{{ $accountMapping->exists ? route('accounting.account-mappings.update', $accountMapping) : route('accounting.account-mappings.store') }}">
                @csrf @if ($accountMapping->exists) @method('PUT') @endif
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="mapping-event">Posting event @unless($isLegacy)<span class="text-danger">*</span>@endunless</label>
                        <select class="form-select" id="mapping-event" name="event_code" @disabled($accountMapping->exists) required><option value="">เลือก Event/เอกสาร</option>@foreach($events as $code => $event)<option value="{{ $code }}" @selected(old('event_code', $accountMapping->event_code) === $code)>{{ $event['module'] }} · {{ $event['document'] }} ({{ $code }})</option>@endforeach</select>@if($accountMapping->exists && !$isLegacy)<input type="hidden" name="event_code" value="{{ $accountMapping->event_code }}">@endif
                        <div class="form-text">Event และ role ถูกกำหนดจาก Accounting contract</div><div class="invalid-feedback" data-error-for="event_code"></div>
                    </div>
                    <div class="col-md-6"><label class="form-label" for="mapping-key">บทบาทบัญชี <span class="text-danger">*</span></label>
                        <select class="form-select" id="mapping-key" name="key" required @disabled($accountMapping->exists)><option value="">เลือกบทบาทบัญชี</option></select>@if($accountMapping->exists)<input type="hidden" name="key" value="{{ $accountMapping->key }}">@endif
                        <div class="invalid-feedback" data-error-for="key"></div>
                    </div>
                    <div class="col-md-8"><label class="form-label" for="mapping-account">บัญชี GL <span class="text-danger">*</span></label>
                        <select class="form-select" id="mapping-account" name="account_id" data-url="{{ route('accounting.account-mappings.account-options') }}" required><option value="">เลือก Event และบทบาทบัญชีก่อนค้นหา</option>@if($selectedAccount)<option value="{{ $selectedAccount->id }}" selected>{{ $selectedAccount->code }} · {{ $selectedAccount->name }}</option>@endif</select><div class="invalid-feedback" data-error-for="account_id"></div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input type="hidden" name="is_active" value="0"><input class="form-check-input" id="mapping-active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $accountMapping->is_active))><label class="form-check-label" for="mapping-active">เปิดใช้งานการตั้งค่านี้</label></div></div>
                    <div class="col-12"><label class="form-label" for="mapping-reason">เหตุผล @unless($isLegacy)<span class="text-danger">*</span>@endunless</label><textarea class="form-control" id="mapping-reason" name="reason" rows="3" minlength="10" maxlength="500" @required(!$isLegacy)>{{ old('reason') }}</textarea><div class="form-text">บันทึกใน Audit Trail เพื่อให้ตรวจสอบการตั้งค่าได้</div><div class="invalid-feedback" data-error-for="reason"></div></div>
                </div>
                <div class="mt-4 d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="{{ route('accounting.account-mappings.index') }}">ยกเลิก</a><button class="btn btn-dark" type="submit"><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึก</button></div>
            </form>
        </div></div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    var events = @json($events), isLegacy = @json($isLegacy), initialRole = @json(old('key', $isLegacy ? $accountMapping->key : $copyRole)), legacyLabel = @json($legacyLabel), legacyKey = @json($isLegacy ? $accountMapping->key : null);
    var $event = $('#mapping-event'), $role = $('#mapping-key'), $account = $('#mapping-account');
    function roles() { var selected = events[$event.val()] || {roles: []}; $role.empty().append(new Option('เลือกบทบาทบัญชี', '')); selected.roles.forEach(function(role) { $role.append(new Option(role.label, role.account_role, false, role.account_role === initialRole)); }); if (!isLegacy) $role.prop('disabled', !$event.val()); }
    if (isLegacy) { $role.append(new Option(legacyLabel, legacyKey, true, true)); } else { roles(); }
    $account.select2({width:'100%', theme:'bootstrap-5', placeholder:'ค้นหารหัสหรือชื่อบัญชี', allowClear:true, ajax:{url:$account.data('url'),dataType:'json',delay:250,data:function(params){return {event_code:$event.val(),key:$role.val(),q:params.term||'',page:params.page||1};},processResults:function(data){return data;},cache:true}});
    $event.on('change', function () { initialRole = null; roles(); $account.val(null).trigger('change'); }); $role.on('change', function () { $account.val(null).trigger('change'); });
    window.erpAjaxForm({form:'#account-mapping-form',redirect:{{ $accountMapping->exists ? 'false' : 'true' }}});
});
</script>
@endpush
