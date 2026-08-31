@extends('Settings::layout')

@section('title', 'แก้ไขรหัสและรูปแบบเอกสาร | Settings')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">SETTINGS / DOCUMENT SEQUENCES</p>
        <h1 class="h3 mb-2">แก้ไขรหัสและรูปแบบเอกสาร</h1>
        <p class="text-secondary mb-4">ประเภทเอกสารถูกกำหนดโดยระบบ แก้ไขได้เฉพาะรูปแบบ เลขนำหน้า รอบเลข และสถานะ</p>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form id="document-sequence-form" method="POST" action="{{ route('settings.document-sequences.update', $documentSequence) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="document_type" value="{{ $documentSequence->document_type }}">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">ประเภทเอกสาร</label>
                            <input class="form-control" value="{{ \App\Modules\Finance\Controllers\DocumentSequenceController::DOCUMENT_TYPE_LABELS[$documentSequence->document_type] ?? $documentSequence->document_type }}" readonly>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">ชื่อรูปแบบ</label>
                            <input class="form-control" name="name" maxlength="255" value="{{ old('name', $documentSequence->name) }}" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prefix</label>
                            <input class="form-control text-uppercase" name="prefix" maxlength="20" value="{{ old('prefix', $documentSequence->prefix) }}" required>
                            <div class="invalid-feedback" data-error-for="prefix"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">รอบ reset</label>
                            <select class="form-select" name="reset_rule">
                                <option value="NEVER" @selected(old('reset_rule', $documentSequence->reset_rule) === 'NEVER')>ไม่ reset</option>
                                <option value="YEARLY" @selected(old('reset_rule', $documentSequence->reset_rule) === 'YEARLY')>รายปี</option>
                                <option value="MONTHLY" @selected(old('reset_rule', $documentSequence->reset_rule) === 'MONTHLY')>รายเดือน</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="reset_rule"></div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">รูปแบบเลขเอกสาร</label>
                            <input class="form-control" name="number_format" maxlength="80" value="{{ old('number_format', $documentSequence->number_format) }}" required>
                            <div class="form-text">ใช้ {PREFIX}, {BRANCH}, {YY}, {YYMM}, {YYYY}, {MM}, {NUMBER:6}; ตัวอย่าง IV{BRANCH}{YYMM}{NUMBER:6}</div>
                            <div class="invalid-feedback" data-error-for="number_format"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">สถานะ</label>
                            <div class="form-check mt-2">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $documentSequence->is_active))>
                                <label class="form-check-label">ใช้งาน</label>
                            </div>
                            <div class="invalid-feedback" data-error-for="is_active"></div>
                        </div>
                    </div>

                    <input type="hidden" name="number_reuse_policy" value="{{ $documentSequence->number_reuse_policy ?: 'NEVER_REUSE' }}">
                    <div class="mt-4"><button class="btn btn-dark" type="submit">บันทึก</button> <a class="btn btn-outline-secondary" href="{{ route('settings.document-sequences.index') }}">ยกเลิก</a></div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>$(function(){window.erpAjaxForm({form:'#document-sequence-form',redirect:false});});</script>
@endpush
