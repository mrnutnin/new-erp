<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h5 mb-1">{{ $photoTitle }}</h2>
                <p class="text-secondary small mb-0">แนบรูปภาพประกอบเอกสาร (ไม่บังคับ)</p>
            </div>
            <span class="badge app-badge-soft">{{ $document->photos->count() }}/5 รูป</span>
        </div>

        <div class="transfer-photo-grid mb-3">
            @forelse ($document->photos as $photo)
                <a class="transfer-photo-card" href="{{ route($photoPreviewRoute, [$document, $photo]) }}" target="_blank" rel="noopener" title="เปิดรูป {{ $photo->original_name }}">
                    <img src="{{ route($photoPreviewRoute, [$document, $photo]) }}" alt="{{ $photoTitle }}: {{ $photo->original_name }}" loading="lazy">
                    <span>{{ $photo->uploadedBy?->name ?: 'ผู้ใช้งาน' }} · {{ $photo->created_at?->format('d/m/Y H:i') }}</span>
                </a>
            @empty
                <div class="transfer-photo-empty"><i class="bx bx-image-alt" aria-hidden="true"></i><span>ยังไม่มีรูปภาพ</span></div>
            @endforelse
        </div>

        @if (auth()->user()->hasPermission($photoUploadPermission) && $document->photos->count() < 5)
            <form class="js-document-photo-form border rounded-3 p-3" method="POST" enctype="multipart/form-data" action="{{ route($photoStoreRoute, $document) }}">
                @csrf
                <x-platform::file-uploader
                    name="photos"
                    label="แนบรูปภาพ"
                    accept="image/jpeg,image/png,image/webp"
                    max-file-size="10MB"
                    :max-files="5"
                    :multiple="true"
                    :image-preview="true"
                    help="JPG, PNG หรือ WEBP ขนาดไม่เกิน 10 MB ต่อรูป"
                />
                <div class="invalid-feedback d-block" data-error-for="photos.0"></div>
                <button class="btn btn-sm btn-app-soft mt-2" type="submit" data-busy-text="กำลังอัปโหลด...">
                    <i class="bx bx-upload me-1" aria-hidden="true"></i>อัปโหลดรูป
                </button>
            </form>
        @endif
    </div>
</div>

@once
    @push('scripts')
        <script>
            $(function () {
                $('.js-document-photo-form').on('submit', function (event) {
                    event.preventDefault();
                    var form = $(this), button = form.find('button[type="submit"]');
                    form.find('[data-error-for]').text('');
                    button.prop('disabled', true);
                    $.ajax({url: form.attr('action'), method: 'POST', data: new FormData(this), processData: false, contentType: false})
                        .done(function (response) {
                            Swal.fire({icon: 'success', text: response.msg || 'อัปโหลดรูปแล้ว', timer: 1200, showConfirmButton: false}).then(function () { location.reload(); });
                        })
                        .fail(function (xhr) {
                            var errors = xhr.responseJSON?.errors || {};
                            var message = errors.photos?.[0] || Object.keys(errors).filter(function (key) { return key.indexOf('photos.') === 0; }).map(function (key) { return errors[key][0]; })[0];
                            form.find('[data-error-for="photos"]').text(message || '');
                            Swal.fire({icon: 'error', text: message || xhr.responseJSON?.message || 'ไม่สามารถอัปโหลดรูปได้'});
                        })
                        .always(function () { button.prop('disabled', false); });
                });
            });
        </script>
    @endpush
@endonce
