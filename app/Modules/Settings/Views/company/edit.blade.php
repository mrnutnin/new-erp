@extends('Settings::layout')

@section('title', 'Global Setting | New ERP')

@section('content')
    @php($canUpdate = auth()->user()->hasPermission('settings.company.update'))
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="page-heading mb-4">
            <p class="eyebrow mb-2">SETTINGS</p>
            <h1 class="h3 mb-2">ตั้งค่าระบบ</h1>
            <p class="text-secondary mb-0">ค่าระดับบริษัท ใช้ร่วมกันทุกสาขาและทุกโปรแกรม</p>
        </div>

        <div class="row g-4">
            <div class="col-12">
                @if ($readiness->flatten()->isNotEmpty())
                    <div class="alert alert-warning" role="alert">
                        <div class="fw-semibold mb-1"><i class="bx bx-error-circle me-1" aria-hidden="true"></i>ระบบยังไม่พร้อมสร้างรายการธุรกิจ</div>
                        <div class="small">กรอกค่าที่จำเป็นให้ครบ: {{ $readiness->flatten()->unique()->implode(', ') }}</div>
                    </div>
                @else
                    <div class="alert alert-success" role="status">
                        <i class="bx bx-check-circle me-1" aria-hidden="true"></i>Global Settings ที่จำเป็นพร้อมใช้งาน
                    </div>
                @endif

                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <h2 class="h5 mb-4">ข้อมูลบริษัทและรูปแบบระบบ</h2>
                        <form id="company-setting-form" action="{{ route('settings.company.update') }}" method="post" enctype="multipart/form-data" novalidate>
                            @csrf
                            @method('PUT')

                            <div class="row g-3">
                                <div class="col-12 col-md-8">
                                    <label class="form-label" for="company_name">ชื่อบริษัท</label>
                                    <input class="form-control" id="company_name" name="company_name" value="{{ old('company_name', $setting->company_name) }}" required @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="company_name"></div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="tax_id">เลขประจำตัวผู้เสียภาษี</label>
                                    <input class="form-control" id="tax_id" name="tax_id" value="{{ old('tax_id', $setting->tax_id) }}" inputmode="numeric" maxlength="13" @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="tax_id"></div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="company_address">ที่อยู่บริษัทสำหรับเอกสาร</label>
                                    <textarea class="form-control" id="company_address" name="company_address" rows="2" maxlength="2000" @disabled(! $canUpdate)>{{ old('company_address', $setting->company_address) }}</textarea>
                                    <div class="invalid-feedback" data-error-for="company_address"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="logo">โลโก้บริษัทสำหรับเอกสาร PDF</label>
                                    <input class="form-control" id="logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp" @disabled(! $canUpdate)>
                                    <div class="form-text">JPG, PNG หรือ WEBP ขนาดไม่เกิน 2 MB</div>
                                    <div class="invalid-feedback" data-error-for="logo"></div>
                                    @if ($setting->logo_path)
                                        <img class="mt-2" src="{{ asset('storage/'.$setting->logo_path) }}" alt="โลโก้บริษัท" style="max-height:64px;max-width:240px;">
                                    @endif
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="locale">ภาษาเริ่มต้น</label>
                                    <select class="form-select" id="locale" name="locale" @disabled(! $canUpdate)>
                                        <option value="th" @selected($setting->locale === 'th')>ไทย</option>
                                        <option value="en" @selected($setting->locale === 'en')>English</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="locale"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="timezone">เขตเวลา</label>
                                    <select class="form-select" id="timezone" name="timezone" @disabled(! $canUpdate)>
                                        <option value="Asia/Bangkok" @selected($setting->timezone === 'Asia/Bangkok')>Asia/Bangkok</option>
                                        <option value="UTC" @selected($setting->timezone === 'UTC')>UTC</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="timezone"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="base_currency">สกุลเงินฐาน</label>
                                    <input class="form-control text-uppercase" id="base_currency" name="base_currency" value="{{ old('base_currency', $setting->base_currency) }}" maxlength="3" required @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="base_currency"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="date_format">รูปแบบวันที่</label>
                                    <select class="form-select" id="date_format" name="date_format" @disabled(! $canUpdate)>
                                        <option value="d/m/Y" @selected($setting->date_format === 'd/m/Y')>31/12/2026</option>
                                        <option value="Y-m-d" @selected($setting->date_format === 'Y-m-d')>2026-12-31</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="date_format"></div>
                                </div>
                            </div>

                            <hr class="my-4">
                            <h2 class="h5 mb-3">บัญชี ภาษี และเอกสาร</h2>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="accounting_profile">มาตรฐานรายงานบัญชี</label>
                                    <select class="form-select" id="accounting_profile" name="accounting_profile" @disabled(! $canUpdate)>
                                        <option value="">กรุณาเลือก</option>
                                        <option value="PAE" @selected(old('accounting_profile', $setting->accounting_profile) === 'PAE')>PAE</option>
                                        <option value="NPAE" @selected(old('accounting_profile', $setting->accounting_profile) === 'NPAE')>NPAE</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="accounting_profile"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="fiscal_year_start_month">เดือนเริ่มปีบัญชี</label>
                                    <input class="form-control" id="fiscal_year_start_month" name="fiscal_year_start_month" type="number" min="1" max="12" value="{{ old('fiscal_year_start_month', $setting->fiscal_year_start_month) }}" @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="fiscal_year_start_month"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="default_vat_rate">VAT เริ่มต้น (%)</label>
                                    <input class="form-control" id="default_vat_rate" name="default_vat_rate" type="number" min="0" max="100" step="0.01" value="{{ old('default_vat_rate', $setting->default_vat_rate) }}" @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="default_vat_rate"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="default_withholding_tax_rate">หัก ณ ที่จ่ายเริ่มต้น (%)</label>
                                    <input class="form-control" id="default_withholding_tax_rate" name="default_withholding_tax_rate" type="number" min="0" max="100" step="0.01" value="{{ old('default_withholding_tax_rate', $setting->default_withholding_tax_rate) }}" @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="default_withholding_tax_rate"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="tax_decimal_places">ทศนิยมการคำนวณภาษี</label>
                                    <input class="form-control" id="tax_decimal_places" name="tax_decimal_places" type="number" min="0" max="4" step="1" value="{{ old('tax_decimal_places', $setting->tax_decimal_places ?? 2) }}" @disabled(! $canUpdate)>
                                    <div class="form-text">ใช้ปัดเศษทั้งระดับบรรทัดและยอดรวมเอกสาร</div>
                                    <div class="invalid-feedback" data-error-for="tax_decimal_places"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="manual_discount_approval_threshold">เพดานส่วนลดที่ต้องระบุเหตุผล (%)</label>
                                    <input class="form-control" id="manual_discount_approval_threshold" name="manual_discount_approval_threshold" type="number" min="0" max="100" step="0.01" value="{{ old('manual_discount_approval_threshold', $setting->manual_discount_approval_threshold ?? 0) }}" @disabled(! $canUpdate)>
                                    <div class="form-text">นับเฉพาะส่วนลดนอก Price List; หากเกินเพดาน ต้องระบุเหตุผลก่อนอนุมัติ</div>
                                    <div class="invalid-feedback" data-error-for="manual_discount_approval_threshold"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="document_sequence_reset">รอบเริ่มเลขเอกสารใหม่</label>
                                    <select class="form-select" id="document_sequence_reset" name="document_sequence_reset" @disabled(! $canUpdate)>
                                        <option value="">กรุณาเลือก</option>
                                        <option value="NEVER" @selected(old('document_sequence_reset', $setting->document_sequence_reset) === 'NEVER')>ไม่เริ่มใหม่</option>
                                        <option value="YEARLY" @selected(old('document_sequence_reset', $setting->document_sequence_reset) === 'YEARLY')>รายปี</option>
                                        <option value="MONTHLY" @selected(old('document_sequence_reset', $setting->document_sequence_reset) === 'MONTHLY')>รายเดือน</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="document_sequence_reset"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="posting_sla_minutes">SLA การลงบัญชี (นาที)</label>
                                    <input class="form-control" id="posting_sla_minutes" name="posting_sla_minutes" type="number" min="1" max="10080" value="{{ old('posting_sla_minutes', $setting->posting_sla_minutes) }}" @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="posting_sla_minutes"></div>
                                </div>
                            </div>

                            <hr class="my-4">
                            <h2 class="h5 mb-3">สินค้าคงเหลือ</h2>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="inventory_costing_method">วิธีคำนวณต้นทุนทั้งบริษัท</label>
                                    <select class="form-select" id="inventory_costing_method" name="inventory_costing_method" @disabled(! $canUpdate)>
                                        <option value="">กรุณาเลือก</option>
                                        <option value="AVG" @selected(old('inventory_costing_method', $setting->inventory_costing_method) === 'AVG')>AVG</option>
                                        <option value="FIFO" @selected(old('inventory_costing_method', $setting->inventory_costing_method) === 'FIFO')>FIFO</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="inventory_costing_method"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="allow_negative_stock">อนุญาตสต็อกติดลบ</label>
                                    <select class="form-select" id="allow_negative_stock" name="allow_negative_stock" required @disabled(! $canUpdate)>
                                        <option value="">กรุณาเลือก</option>
                                        <option value="0" @selected(old('allow_negative_stock', $setting->allow_negative_stock) === false || old('allow_negative_stock') === '0')>ไม่อนุญาต</option>
                                        <option value="1" @selected(old('allow_negative_stock', $setting->allow_negative_stock) === true || old('allow_negative_stock') === '1')>อนุญาต</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="allow_negative_stock"></div>
                                </div>
                                <div class="col-12 col-md-6" id="negative-cost-field">
                                    <label class="form-label" for="negative_stock_cost_method">ต้นทุนชั่วคราวเมื่อสต็อกติดลบ</label>
                                    <select class="form-select" id="negative_stock_cost_method" name="negative_stock_cost_method" @disabled(! $canUpdate)>
                                        <option value="">กรุณาเลือก</option>
                                        <option value="CURRENT_AVERAGE" @selected(old('negative_stock_cost_method', $setting->negative_stock_cost_method) === 'CURRENT_AVERAGE')>ต้นทุนเฉลี่ยปัจจุบัน</option>
                                        <option value="LAST_KNOWN" @selected(old('negative_stock_cost_method', $setting->negative_stock_cost_method) === 'LAST_KNOWN')>ต้นทุนล่าสุด</option>
                                        <option value="STANDARD" @selected(old('negative_stock_cost_method', $setting->negative_stock_cost_method) === 'STANDARD')>ต้นทุนมาตรฐาน</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="negative_stock_cost_method"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="recost_sla_minutes">SLA การคำนวณต้นทุนใหม่ (นาที)</label>
                                    <input class="form-control" id="recost_sla_minutes" name="recost_sla_minutes" type="number" min="1" max="10080" value="{{ old('recost_sla_minutes', $setting->recost_sla_minutes) }}" @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="recost_sla_minutes"></div>
                                </div>
                            </div>

                            <hr class="my-4">
                            <h2 class="h5 mb-3">การเก็บข้อมูลและการมีผล</h2>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="audit_retention_days">อายุการเก็บ Audit (วัน)</label>
                                    <input class="form-control" id="audit_retention_days" name="audit_retention_days" type="number" min="1" max="36500" value="{{ old('audit_retention_days', $setting->audit_retention_days) }}" @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="audit_retention_days"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="file_retention_days">อายุการเก็บไฟล์ (วัน)</label>
                                    <input class="form-control" id="file_retention_days" name="file_retention_days" type="number" min="1" max="36500" value="{{ old('file_retention_days', $setting->file_retention_days) }}" @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="file_retention_days"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="effective_from">มีผลตั้งแต่วันที่</label>
                                    <input class="form-control" id="effective_from" name="effective_from" type="date" max="{{ now()->toDateString() }}" value="{{ old('effective_from', optional($setting->effective_from)->toDateString() ?? now()->toDateString()) }}" required @disabled(! $canUpdate)>
                                    <div class="invalid-feedback" data-error-for="effective_from"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Version ปัจจุบัน</label>
                                    <input class="form-control" value="{{ $setting->settings_version }}" disabled>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="change_reason">เหตุผลการเปลี่ยนแปลง</label>
                                    <textarea class="form-control" id="change_reason" name="change_reason" rows="2" maxlength="500" required @disabled(! $canUpdate)>{{ old('change_reason') }}</textarea>
                                    <div class="invalid-feedback" data-error-for="change_reason"></div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a class="btn btn-outline-dark" href="{{ route('programs.index') }}">
                                    <i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้าโปรแกรม
                                </a>
                                @if ($canUpdate)
                                    <button class="btn btn-dark" type="submit" data-busy-text="กำลังบันทึก...">
                                        <i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกการตั้งค่า
                                    </button>
                                @endif
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
            const $allowNegativeStock = $('#allow_negative_stock');
            const $negativeCostField = $('#negative-cost-field');

            function toggleNegativeCost() {
                const enabled = $allowNegativeStock.val() === '1';
                $negativeCostField.toggle(enabled);
                $('#negative_stock_cost_method').prop('disabled', !enabled || {{ $canUpdate ? 'false' : 'true' }});
            }

            $allowNegativeStock.on('change', toggleNegativeCost);
            toggleNegativeCost();

            window.erpAjaxForm({
                form: '#company-setting-form',
                reload: false
            });
        });
    </script>
@endpush
