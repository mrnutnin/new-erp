@props([
    'name',
    'id' => null,
    'label' => null,
    'accept' => null,
    'maxFileSize' => null,
    'help' => null,
    'required' => false,
    'multiple' => false,
    'maxFiles' => null,
    'disabled' => false,
    'imagePreview' => false,
    'previewUrl' => null,
    'previewAlt' => 'ไฟล์ปัจจุบัน',
])

@php
    $inputId = $id ?: str_replace(['[', ']', '.'], ['-', '', '-'], $name);
    $hasError = $errors->has($name);
@endphp

<div class="platform-file-uploader" data-platform-file-uploader>
    @if ($label)
        <label class="form-label" for="{{ $inputId }}">
            {{ $label }}
            @if ($required)
                <span class="text-danger" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    @if ($previewUrl)
        <div class="platform-file-uploader__current mb-2">
            <span class="platform-file-uploader__current-label">
                <i class="bx bx-image-alt" aria-hidden="true"></i>ภาพปัจจุบัน
            </span>
            <img src="{{ $previewUrl }}" alt="{{ $previewAlt }}" loading="lazy">
            <span class="platform-file-uploader__current-help">เลือกไฟล์ใหม่ด้านล่างเพื่อแทนที่ภาพนี้</span>
        </div>
    @endif

    <input
        {{ $attributes->class(['filepond', 'is-invalid' => $hasError]) }}
        id="{{ $inputId }}"
        name="{{ $name }}{{ $multiple ? '[]' : '' }}"
        type="file"
        data-platform-file-input
        data-image-preview="{{ $imagePreview ? 'true' : 'false' }}"
        @if ($maxFileSize) data-max-file-size="{{ $maxFileSize }}" @endif
        @if ($maxFiles) data-max-files="{{ $maxFiles }}" @endif
        @if ($accept) accept="{{ $accept }}" @endif
        @required($required)
        @disabled($disabled)
        @if ($multiple) multiple @endif
    >

    @if ($help)
        <div class="form-text">{{ $help }}</div>
    @endif
    <div class="invalid-feedback d-block" data-error-for="{{ $name }}">{{ $errors->first($name) }}</div>
</div>

@once
    @push('styles')
        <link rel="stylesheet" href="https://unpkg.com/filepond@4.32.12/dist/filepond.min.css">
        <link rel="stylesheet" href="https://unpkg.com/filepond-plugin-image-preview@4.6.12/dist/filepond-plugin-image-preview.min.css">
    @endpush

    @push('scripts')
        <script src="https://unpkg.com/filepond-plugin-file-validate-type@1.2.9/dist/filepond-plugin-file-validate-type.min.js"></script>
        <script src="https://unpkg.com/filepond-plugin-file-validate-size@2.2.8/dist/filepond-plugin-file-validate-size.min.js"></script>
        <script src="https://unpkg.com/filepond-plugin-image-preview@4.6.12/dist/filepond-plugin-image-preview.min.js"></script>
        <script src="https://unpkg.com/filepond@4.32.12/dist/filepond.min.js"></script>
        <script src="{{ asset('js/platform-file-uploader.js') }}?v={{ filemtime(public_path('js/platform-file-uploader.js')) }}"></script>
    @endpush
@endonce
