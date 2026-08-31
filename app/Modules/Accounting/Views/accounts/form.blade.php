@extends('Accounting::layout')

@section('title', ($account->exists ? 'แก้ไข' : 'เพิ่ม').'บัญชี | New ERP')

@section('content')
    @php($accountClass = old('account_class', $account->control_account_type ? 'CONTROL' : ($account->is_postable ? 'SUBACCOUNT' : 'SUMMARY')))
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <p class="eyebrow mb-2">ACCOUNTING / CHART OF ACCOUNTS</p>
            <h1 class="h3 mb-2">{{ $account->exists ? 'แก้ไขบัญชี' : 'เพิ่มบัญชี' }}</h1>
            <p class="text-secondary mb-0">รองรับระดับ 1–5 โดยบัญชีแม่ต้องเป็นบัญชีรวมและอยู่ในหมวดเดียวกัน</p>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <form id="account-form" action="{{ $account->exists ? route('accounting.accounts.update', $account) : route('accounting.accounts.store') }}" method="post" novalidate>
                            @csrf
                            @if ($account->exists) @method('PUT') @endif

                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="code">รหัสบัญชี</label>
                                    <input class="form-control text-uppercase" id="code" name="code" value="{{ old('code', $account->code) }}" maxlength="50" required>
                                    <div class="invalid-feedback" data-error-for="code"></div>
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label" for="name">ชื่อบัญชี</label>
                                    <input class="form-control" id="name" name="name" value="{{ old('name', $account->name) }}" required>
                                    <div class="invalid-feedback" data-error-for="name"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="account_type_id">หมวดบัญชี</label>
                                    <select class="form-select" id="account_type_id" name="account_type_id" required>
                                        <option value="">กรุณาเลือก</option>
                                        @foreach ($types as $type)
                                            <option value="{{ $type->id }}" @selected((string) old('account_type_id', $account->account_type_id) === (string) $type->id)>{{ $type->name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="invalid-feedback" data-error-for="account_type_id"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="parent_id">บัญชีแม่</label>
                                    <select class="form-select js-parent-select" id="parent_id" name="parent_id">
                                        <option value="">ไม่มี — บัญชีระดับบนสุด</option>
                                        @if ($selectedParent)
                                            <option value="{{ $selectedParent->id }}" selected>{{ $selectedParent->code }} · {{ $selectedParent->name }}</option>
                                        @endif
                                    </select>
                                    <div class="invalid-feedback" data-error-for="parent_id"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="reporting_profile">Profile รายงาน</label>
                                    <select class="form-select" id="reporting_profile" name="reporting_profile">
                                        <option value="">ใช้ทั้ง PAE และ NPAE</option>
                                        <option value="PAE" @selected(old('reporting_profile', $account->reporting_profile) === 'PAE')>PAE</option>
                                        <option value="NPAE" @selected(old('reporting_profile', $account->reporting_profile) === 'NPAE')>NPAE</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="reporting_profile"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="control_account_type">ประเภท Control Account</label>
                                    <select class="form-select" id="control_account_type" name="control_account_type">
                                        <option value="">ไม่ใช่ Control Account</option>
                                        @foreach (['AR', 'AP', 'INVENTORY', 'CASH', 'BANK', 'CREDIT_CARD', 'CHEQUE', 'FIXED_ASSET', 'INPUT_VAT', 'OUTPUT_VAT', 'WITHHOLDING_TAX', 'WIP'] as $controlType)
                                            <option value="{{ $controlType }}" @selected(old('control_account_type', $account->control_account_type) === $controlType)>{{ $controlType }}</option>
                                        @endforeach
                                    </select>
                                    <div class="invalid-feedback" data-error-for="control_account_type"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="account_class">ประเภทบัญชี</label>
                                    <select class="form-select" id="account_class" name="account_class" required>
                                        <option value="SUMMARY" @selected($accountClass === 'SUMMARY')>บัญชีรวม — ใช้เป็นบัญชีแม่</option>
                                        <option value="SUBACCOUNT" @selected($accountClass === 'SUBACCOUNT')>บัญชีย่อย — ลงรายการได้</option>
                                        <option value="CONTROL" @selected($accountClass === 'CONTROL')>บัญชีคุม — รับรายการจากระบบย่อย</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="account_class"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <input type="hidden" name="is_active" value="0">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $account->is_active))>
                                        <label class="form-check-label" for="is_active">เปิดใช้งาน</label>
                                        <div class="invalid-feedback" data-error-for="is_active"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a class="btn btn-outline-dark" href="{{ route('accounting.accounts.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>ยกเลิก</a>
                                <button class="btn btn-dark" type="submit" data-busy-text="กำลังบันทึก..."><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกบัญชี</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $parent = $('.js-parent-select');
            $parent.select2({
                width: '100%',
                placeholder: 'ค้นหาบัญชีแม่',
                allowClear: true,
                ajax: {
                    url: @json(route('accounting.accounts.parent-options')),
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            q: params.term || '',
                            page: params.page || 1,
                            account_type_id: $('#account_type_id').val(),
                            account_id: @json($account->id)
                        };
                    },
                    processResults: function (data) { return data; },
                    cache: true
                }
            });

            $('#account_type_id').on('change', function () {
                $parent.val(null).trigger('change');
            });

            $('#account_class').on('change', function () {
                var isControl = $(this).val() === 'CONTROL';
                $('#control_account_type').prop('disabled', !isControl).prop('required', isControl);
                if (!isControl) {
                    $('#control_account_type').val('');
                }
            }).trigger('change');

            window.erpAjaxForm({
                form: '#account-form',
                redirect: @json(! $account->exists),
                reload: false
            });
        });
    </script>
@endpush
