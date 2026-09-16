@props([
    'hasSignature' => false,
    'previewUrl' => null,
    'previewAlt' => 'ลายเซ็นปัจจุบัน',
])

<div class="col-12" data-signature-section>
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-8">
            <div class="border-top pt-4 mt-2">
                <h3 class="h6 mb-1">ลายเซ็น</h3>
                <p class="small text-secondary mb-3">เลือกอัปโหลดไฟล์ หรือวาดลายเซ็นด้วยเมาส์ ปากกา หรือหน้าจอสัมผัส</p>
                <x-platform::file-uploader
                    name="signature_image"
                    id="signature_image"
                    label="อัปโหลดลายเซ็น"
                    accept="image/jpeg,image/png"
                    max-file-size="2MB"
                    help="JPG หรือ PNG ขนาดไม่เกิน 2 MB"
                    :image-preview="true"
                    :preview-url="$previewUrl"
                    :preview-alt="$previewAlt"
                />
                <div class="d-flex align-items-center gap-3 my-3" aria-hidden="true">
                    <hr class="flex-grow-1 my-0">
                    <span class="small text-secondary">หรือ</span>
                    <hr class="flex-grow-1 my-0">
                </div>
                <x-platform::signature-pad />
                @if ($hasSignature)
                    <div class="form-check mt-2">
                        <input class="form-check-input" id="remove_signature" name="remove_signature" type="checkbox" value="1">
                        <label class="form-check-label text-danger" for="remove_signature">ลบลายเซ็นปัจจุบัน</label>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
