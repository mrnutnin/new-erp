@props([
    'name' => 'signature_data',
    'id' => 'signature_pad',
    'label' => 'วาดลายเซ็น',
    'help' => 'วาดด้วยเมาส์ ปากกา หรือการสัมผัส แล้วกดบันทึกพร้อมข้อมูลในฟอร์ม',
    'uploadInputId' => 'signature_image',
])

<div class="platform-signature-pad" data-signature-pad data-upload-input-id="{{ $uploadInputId }}">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <label class="form-label mb-0" for="{{ $id }}">{{ $label }}</label>
        <button class="btn btn-sm btn-app-soft" type="button" data-signature-clear>
            <i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างลายเซ็น
        </button>
    </div>
    <canvas id="{{ $id }}" width="1200" height="360" data-signature-canvas aria-label="พื้นที่วาดลายเซ็น"></canvas>
    <input type="hidden" name="{{ $name }}" data-signature-value>
    <div class="form-text">{{ $help }}</div>
    <div class="invalid-feedback d-block" data-error-for="{{ $name }}">{{ $errors->first($name) }}</div>
</div>

@once
    @push('scripts')
        <script src="{{ asset('js/platform-signature-pad.js') }}?v={{ filemtime(public_path('js/platform-signature-pad.js')) }}"></script>
    @endpush
@endonce
