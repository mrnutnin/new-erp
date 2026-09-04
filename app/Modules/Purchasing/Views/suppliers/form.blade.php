@extends($moduleRoutePrefix === 'purchasing' ? 'Purchasing::layout' : 'Wms::layout')

@section('title', ($supplier->exists ? 'แก้ไข' : 'เพิ่ม').' Supplier | Purchasing')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">PURCHASING / MASTER DATA</p>
        <h1 class="h3 mb-4">{{ $supplier->exists ? 'แก้ไข' : 'เพิ่ม' }} Supplier</h1>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form
                    id="supplier-form"
                    method="POST"
                    action="{{ $supplier->exists ? route($moduleRoutePrefix.'.suppliers.update', $supplier) : route($moduleRoutePrefix.'.suppliers.store') }}"
                >
                    @csrf
                    @if ($supplier->exists)
                        @method('PUT')
                    @endif

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="supplier-code">รหัส Supplier</label>
                            <input class="form-control" id="supplier-code" name="code" maxlength="30" value="{{ old('code', $supplier->code) }}" placeholder="เว้นว่างเพื่อให้ระบบสร้างอัตโนมัติ" @required($supplier->exists)>
                            <div class="invalid-feedback" data-error-for="code"></div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="supplier-name">ชื่อ Supplier</label>
                            <input class="form-control" id="supplier-name" name="name" value="{{ old('name', $supplier->name) }}" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="supplier-type">ประเภท</label>
                            <select class="form-select" id="supplier-type" name="type" required>
                                <option value="COMPANY" @selected(old('type', $supplier->type) === 'COMPANY')>นิติบุคคล</option>
                                <option value="INDIVIDUAL" @selected(old('type', $supplier->type) === 'INDIVIDUAL')>บุคคลธรรมดา</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="type"></div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="supplier-tax-id">เลขประจำตัวผู้เสียภาษี</label>
                            <input class="form-control" id="supplier-tax-id" name="tax_id" inputmode="numeric" maxlength="13" value="{{ old('tax_id', $supplier->tax_id) }}">
                            <div class="invalid-feedback" data-error-for="tax_id"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="supplier-branch-code">รหัสสาขา</label>
                            <input class="form-control" id="supplier-branch-code" name="branch_code" inputmode="numeric" maxlength="5" value="{{ old('branch_code', $supplier->branch_code) }}" placeholder="เช่น 00000" required>
                            <div class="invalid-feedback" data-error-for="branch_code"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="supplier-contact-name">ชื่อผู้ติดต่อ</label>
                            <input class="form-control" id="supplier-contact-name" name="contact_name" value="{{ old('contact_name', $supplier->contact_name) }}">
                            <div class="invalid-feedback" data-error-for="contact_name"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="supplier-phone">โทรศัพท์</label>
                            <input class="form-control" id="supplier-phone" name="phone" maxlength="50" value="{{ old('phone', $supplier->phone) }}">
                            <div class="invalid-feedback" data-error-for="phone"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="supplier-email">อีเมล</label>
                            <input class="form-control" id="supplier-email" name="email" type="email" value="{{ old('email', $supplier->email) }}">
                            <div class="invalid-feedback" data-error-for="email"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="supplier-address">ที่อยู่</label>
                            <textarea class="form-control" id="supplier-address" name="address" rows="3">{{ old('address', $supplier->address) }}</textarea>
                            <div class="invalid-feedback" data-error-for="address"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="supplier-payment-term">เงื่อนไขการชำระเงิน</label>
                            <select class="form-select" id="supplier-payment-term" name="payment_term_id">
                                <option value="">ไม่กำหนด</option>
                                @foreach ($paymentTerms as $paymentTerm)
                                    <option value="{{ $paymentTerm->id }}" @selected((string) old('payment_term_id', $supplierRole->payment_term_id) === (string) $paymentTerm->id)>
                                        {{ $paymentTerm->code }} · {{ $paymentTerm->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback" data-error-for="payment_term_id"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="supplier-credit-limit">วงเงินเครดิต</label>
                            <input class="form-control text-end" id="supplier-credit-limit" name="credit_limit" type="number" min="0" step="0.01" value="{{ old('credit_limit', $supplierRole->credit_limit ?? '0.00') }}" required>
                            <div class="invalid-feedback" data-error-for="credit_limit"></div>
                        </div>
                        <div class="col-md-2 form-check mt-md-5">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" id="supplier-is-active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $supplierRole->is_active))>
                            <label class="form-check-label" for="supplier-is-active">ใช้งาน</label>
                            <div class="invalid-feedback" data-error-for="is_active"></div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button class="btn btn-dark" type="submit"><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึก</button>
                        <a class="btn btn-outline-secondary" href="{{ route($moduleRoutePrefix.'.suppliers.index') }}">ยกเลิก</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            window.erpAjaxForm({
                form: '#supplier-form',
                redirect: {{ $supplier->exists ? 'false' : 'true' }}
            });
        });
    </script>
@endpush
