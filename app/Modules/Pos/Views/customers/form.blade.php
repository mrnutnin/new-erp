@extends('Pos::layout')

@section('title', ($customer->exists ? 'แก้ไข' : 'เพิ่ม').' ลูกค้า | POS / Sales')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">POS / SALES / MASTER DATA</p>
        <h1 class="h3 mb-4">{{ $customer->exists ? 'แก้ไข' : 'เพิ่ม' }} ลูกค้า</h1>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <form id="customer-form" method="POST" action="{{ $customer->exists ? route('pos.customers.update', $customer) : route('pos.customers.store') }}">
                    @csrf
                    @if ($customer->exists)
                        @method('PUT')
                    @endif

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="customer-code">รหัสลูกค้า</label>
                            @if ($customer->exists)
                                <input class="form-control" id="customer-code" name="code" value="{{ $customer->code }}" readonly>
                            @else
                                <input class="form-control" id="customer-code" value="ระบบกำหนดอัตโนมัติเมื่อบันทึก" readonly>
                                <input type="hidden" name="code" value="">
                            @endif
                            <div class="invalid-feedback" data-error-for="code"></div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="customer-name">ชื่อลูกค้า</label>
                            <input class="form-control" id="customer-name" name="name" maxlength="255" value="{{ old('name', $customer->name) }}" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="customer-type">ประเภทลูกค้า</label>
                            <select class="form-select" id="customer-type" name="type" required>
                                <option value="COMPANY" @selected(old('type', $customer->type) === 'COMPANY')>นิติบุคคล</option>
                                <option value="INDIVIDUAL" @selected(old('type', $customer->type) === 'INDIVIDUAL')>บุคคลธรรมดา</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="type"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="customer-tax-id">เลขประจำตัวผู้เสียภาษี</label>
                            <input class="form-control" id="customer-tax-id" name="tax_id" maxlength="13" inputmode="numeric" value="{{ old('tax_id', $customer->tax_id) }}">
                            <div class="invalid-feedback" data-error-for="tax_id"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="customer-branch-code">รหัสสาขาภาษี</label>
                            <input class="form-control" id="customer-branch-code" name="branch_code" maxlength="5" inputmode="numeric" placeholder="00000 = สำนักงานใหญ่" value="{{ old('branch_code', $customer->branch_code) }}" required>
                            <div class="invalid-feedback" data-error-for="branch_code"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="customer-contact">ผู้ติดต่อ</label>
                            <input class="form-control" id="customer-contact" name="contact_name" maxlength="255" value="{{ old('contact_name', $customer->contact_name) }}">
                            <div class="invalid-feedback" data-error-for="contact_name"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="customer-phone">โทรศัพท์</label>
                            <input class="form-control" id="customer-phone" name="phone" maxlength="50" value="{{ old('phone', $customer->phone) }}">
                            <div class="invalid-feedback" data-error-for="phone"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="customer-email">อีเมล</label>
                            <input class="form-control" id="customer-email" name="email" type="email" maxlength="255" value="{{ old('email', $customer->email) }}">
                            <div class="invalid-feedback" data-error-for="email"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="customer-payment-term">เงื่อนไขการชำระเงิน</label>
                            <select class="form-select" id="customer-payment-term" name="payment_term_id">
                                <option value="">ไม่กำหนด</option>
                                @foreach ($paymentTerms as $paymentTerm)
                                    <option value="{{ $paymentTerm->id }}" @selected((string) old('payment_term_id', $customerRole->payment_term_id) === (string) $paymentTerm->id)>
                                        {{ $paymentTerm->code }} · {{ $paymentTerm->name }} ({{ $paymentTerm->credit_days }} วัน)
                                    </option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback" data-error-for="payment_term_id"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="customer-group">กลุ่มลูกค้า</label>
                            <select class="form-select" id="customer-group" name="customer_group_id">
                                <option value="">ไม่กำหนด</option>
                                @php($selectedGroup = old('customer_group_id', $customer->customerGroups->first()?->id))
                                @foreach ($customerGroups as $customerGroup)
                                    <option value="{{ $customerGroup->id }}" @selected((string) $selectedGroup === (string) $customerGroup->id)>{{ $customerGroup->code }} · {{ $customerGroup->name }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback" data-error-for="customer_group_id"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="customer-credit-limit">วงเงินเครดิต</label>
                            <input class="form-control text-end" id="customer-credit-limit" name="credit_limit" type="number" min="0" step="0.01" value="{{ old('credit_limit', $customerRole->credit_limit) }}" required>
                            <div class="invalid-feedback" data-error-for="credit_limit"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">สถานะ</label>
                            <div class="form-check mt-2">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" id="customer-active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $customerRole->is_active))>
                                <label class="form-check-label" for="customer-active">ใช้งาน</label>
                            </div>
                            <div class="invalid-feedback" data-error-for="is_active"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="customer-address">ที่อยู่สำหรับออกเอกสาร</label>
                            <textarea class="form-control" id="customer-address" name="address" rows="3" maxlength="2000">{{ old('address', $customer->address) }}</textarea>
                            <div class="invalid-feedback" data-error-for="address"></div>
                        </div>
                        @php($addressRows = old('addresses', $customer->addresses->sortBy('id')->map(fn ($address) => $address->only(['id', 'address_type', 'label', 'recipient_name', 'address_line', 'district', 'amphoe', 'province', 'postal_code', 'phone']))->values()->all()))
                        @php($addressIndex = 0)
                        @foreach (['BILLING' => 'ที่อยู่ออกบิล', 'SHIPPING' => 'ที่อยู่จัดส่ง'] as $addressType => $addressLabel)
                            <div class="col-12">
                                <div class="border rounded-3 p-3">
                                    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                                        <div><h2 class="h6 mb-0">{{ $addressLabel }}</h2><div class="form-text">เพิ่มได้หลายรายการ เลือกเปิดแก้ไขเฉพาะรายการที่ต้องการ</div></div>
                                        <button class="btn btn-outline-secondary btn-sm js-add-address" type="button" data-address-type="{{ $addressType }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มที่อยู่</button>
                                    </div>
                                    <div class="row g-3" data-address-list="{{ $addressType }}">
                                        @forelse (collect($addressRows)->where('address_type', $addressType) as $address)
                                            @php($index = $addressIndex++)
                                            <div class="col-12 js-address-card">
                                                <details class="border rounded-3 p-3 bg-light-subtle" @if ($loop->first) open @endif>
                                                    <summary class="d-flex justify-content-between align-items-center gap-2"><span><strong>{{ $address['label'] ?: 'ที่อยู่' }}</strong><span class="text-secondary ms-2">{{ $address['recipient_name'] ?: 'ยังไม่ได้ระบุผู้รับ' }}</span></span><span class="text-secondary small">คลิกเพื่อแก้ไข</span></summary>
                                                    <div class="pt-3">
                                                        <input type="hidden" name="addresses[{{ $index }}][id]" value="{{ $address['id'] ?? '' }}">
                                                        <input type="hidden" name="addresses[{{ $index }}][address_type]" value="{{ $addressType }}">
                                                        <div class="d-flex justify-content-end mb-3"><button class="btn btn-outline-danger btn-sm js-remove-address" type="button"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบที่อยู่</button></div>
                                                        <div class="row g-2">
                                                            <div class="col-md-6"><label class="form-label">ชื่อเรียกที่อยู่</label><input class="form-control" name="addresses[{{ $index }}][label]" maxlength="100" value="{{ $address['label'] ?? '' }}"></div>
                                                            <div class="col-md-6"><label class="form-label">ผู้รับ</label><input class="form-control" name="addresses[{{ $index }}][recipient_name]" maxlength="255" value="{{ $address['recipient_name'] ?? '' }}"></div>
                                                            <div class="col-12"><label class="form-label">ที่อยู่</label><textarea class="form-control" name="addresses[{{ $index }}][address_line]" rows="2" maxlength="2000" required>{{ $address['address_line'] ?? '' }}</textarea></div>
                                                            <div class="col-md-6"><label class="form-label">ตำบล/แขวง</label><input class="form-control" name="addresses[{{ $index }}][district]" maxlength="100" value="{{ $address['district'] ?? '' }}"></div>
                                                            <div class="col-md-6"><label class="form-label">อำเภอ/เขต</label><input class="form-control" name="addresses[{{ $index }}][amphoe]" maxlength="100" value="{{ $address['amphoe'] ?? '' }}"></div>
                                                            <div class="col-md-4"><label class="form-label">จังหวัด</label><input class="form-control" name="addresses[{{ $index }}][province]" maxlength="100" value="{{ $address['province'] ?? '' }}"></div>
                                                            <div class="col-md-4"><label class="form-label">รหัสไปรษณีย์</label><input class="form-control" name="addresses[{{ $index }}][postal_code]" maxlength="10" value="{{ $address['postal_code'] ?? '' }}"></div>
                                                            <div class="col-md-4"><label class="form-label">โทรศัพท์ผู้รับ</label><input class="form-control" name="addresses[{{ $index }}][phone]" maxlength="50" value="{{ $address['phone'] ?? '' }}"></div>
                                                        </div>
                                                    </div>
                                                </details>
                                            </div>
                                        @empty
                                            <div class="col-12 js-address-empty"><div class="text-center text-secondary border rounded-3 py-3">ยังไม่มี{{ $addressLabel }} กด “เพิ่มที่อยู่” เพื่อเริ่มต้น</div></div>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4">
                        <button class="btn btn-dark" type="submit">บันทึก</button>
                        <a class="btn btn-outline-secondary" href="{{ route('pos.customers.index') }}">ยกเลิก</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var addressIndex = {{ $addressIndex }};

            function addressCard(type) {
                var index = addressIndex++;

                return `<div class="col-12 js-address-card"><details class="border rounded-3 p-3 bg-light-subtle" open>
                    <input type="hidden" name="addresses[${index}][address_type]" value="${type}">
                    <summary class="d-flex justify-content-between align-items-center gap-2"><span><strong>ที่อยู่ใหม่</strong><span class="text-secondary ms-2">กรอกรายละเอียดด้านล่าง</span></span><span class="text-secondary small">คลิกเพื่อยุบ</span></summary>
                    <div class="pt-3"><div class="d-flex justify-content-end mb-3"><button class="btn btn-outline-danger btn-sm js-remove-address" type="button"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบที่อยู่</button></div><div class="row g-2">
                        <div class="col-md-6"><label class="form-label">ชื่อเรียกที่อยู่</label><input class="form-control" name="addresses[${index}][label]" maxlength="100"></div>
                        <div class="col-md-6"><label class="form-label">ผู้รับ</label><input class="form-control" name="addresses[${index}][recipient_name]" maxlength="255"></div>
                        <div class="col-12"><label class="form-label">ที่อยู่</label><textarea class="form-control" name="addresses[${index}][address_line]" rows="2" maxlength="2000" required></textarea></div>
                        <div class="col-md-6"><label class="form-label">ตำบล/แขวง</label><input class="form-control" name="addresses[${index}][district]" maxlength="100"></div>
                        <div class="col-md-6"><label class="form-label">อำเภอ/เขต</label><input class="form-control" name="addresses[${index}][amphoe]" maxlength="100"></div>
                        <div class="col-md-4"><label class="form-label">จังหวัด</label><input class="form-control" name="addresses[${index}][province]" maxlength="100"></div>
                        <div class="col-md-4"><label class="form-label">รหัสไปรษณีย์</label><input class="form-control" name="addresses[${index}][postal_code]" maxlength="10"></div>
                        <div class="col-md-4"><label class="form-label">โทรศัพท์ผู้รับ</label><input class="form-control" name="addresses[${index}][phone]" maxlength="50"></div>
                    </div></div>
                </details></div>`;
            }

            $(document).on('click', '.js-add-address', function () {
                var $list = $('[data-address-list="' + $(this).data('address-type') + '"]');
                $list.find('.js-address-empty').remove();
                $list.append(addressCard($(this).data('address-type')));
            }).on('click', '.js-remove-address', function () {
                $(this).closest('.js-address-card').remove();
            }).on('input', '[name$="[label]"], [name$="[recipient_name]"]', function () {
                var $card = $(this).closest('.js-address-card');
                var label = $card.find('[name$="[label]"]').val() || 'ที่อยู่';
                var recipient = $card.find('[name$="[recipient_name]"]').val() || 'ยังไม่ได้ระบุผู้รับ';

                $card.find('summary strong').text(label);
                $card.find('summary strong + span').text(recipient);
            });

            window.erpAjaxForm({form: '#customer-form', redirect: {{ $customer->exists ? 'false' : 'true' }}});
        });
    </script>
@endpush
