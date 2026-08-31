@extends('Accounting::layout')

@section('title', ($accountMapping->exists ? 'แก้ไข' : 'เพิ่ม').' Account Mapping | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">ACCOUNTING / SETTINGS</p>
        <h1 class="h3 mb-4">{{ $accountMapping->exists ? 'แก้ไข' : 'เพิ่ม' }} Account Mapping</h1>
        <div class="card border-0 shadow-sm"><div class="card-body p-4">
            <form id="account-mapping-form" method="POST" action="{{ $accountMapping->exists ? route('accounting.account-mappings.update', $accountMapping) : route('accounting.account-mappings.store') }}">
                @csrf @if ($accountMapping->exists) @method('PUT') @endif
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label" for="mapping-key">ประเภท Mapping</label>
                        <select class="form-select" id="mapping-key" name="key" required @disabled($accountMapping->exists)>
                            <option value="">เลือกประเภท</option>
                            @foreach ($availableKeys as $key => $label)<option value="{{ $key }}" @selected(old('key', $accountMapping->key) === $key)>{{ $label }}</option>@endforeach
                        </select>
                        @if ($accountMapping->exists)<input type="hidden" name="key" value="{{ $accountMapping->key }}">@endif
                        <div class="invalid-feedback" data-error-for="key"></div>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label" for="mapping-account">บัญชี GL</label>
                        <select class="form-select" id="mapping-account" name="account_id" data-url="{{ route('accounting.account-mappings.account-options') }}" required>
                            <option value="">ค้นหารหัสหรือชื่อบัญชี</option>
                            @if ($selectedAccount)<option value="{{ $selectedAccount->id }}" selected>{{ $selectedAccount->code }} · {{ $selectedAccount->name }}</option>@endif
                        </select>
                        <div class="invalid-feedback" data-error-for="account_id"></div>
                    </div>
                    <div class="col-12 form-check ms-2"><input type="hidden" name="is_active" value="0"><input class="form-check-input" id="mapping-active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $accountMapping->is_active))><label class="form-check-label" for="mapping-active">ใช้งาน</label><div class="invalid-feedback" data-error-for="is_active"></div></div>
                </div>
                <div class="mt-4 d-flex gap-2"><button class="btn btn-dark" type="submit"><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึก</button><a class="btn btn-outline-secondary" href="{{ route('accounting.account-mappings.index') }}">ยกเลิก</a></div>
            </form>
        </div></div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $key = $('#mapping-key');
            var $account = $('#mapping-account');
            $account.select2({ width: '100%', placeholder: 'ค้นหารหัสหรือชื่อบัญชี', allowClear: true, ajax: { url: $account.data('url'), dataType: 'json', delay: 250, data: function (params) { return { key: $key.val(), q: params.term || '', page: params.page || 1 }; }, processResults: function (data) { return data; }, cache: true } });
            $key.on('change', function () { $account.val(null).trigger('change'); });
            window.erpAjaxForm({ form: '#account-mapping-form', redirect: {{ $accountMapping->exists ? 'false' : 'true' }} });
        });
    </script>
@endpush
