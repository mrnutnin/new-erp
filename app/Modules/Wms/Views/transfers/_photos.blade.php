@php
    $outgoingPhotos = $transferPhotos->get('OUTGOING', collect());
    $incomingPhotos = $transferPhotos->get('INCOMING', collect());
@endphp

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h5 mb-1">รูปเปรียบเทียบการโอนสินค้า</h2>
                <p class="text-secondary small mb-0">เปรียบเทียบสภาพสินค้าก่อนส่งออกและตอนรับเข้าปลายทาง</p>
            </div>
            <span class="badge app-badge-soft">สูงสุดฝั่งละ 5 รูป</span>
        </div>

        <div class="row g-4">
            @foreach ([
                ['stage' => 'OUTGOING', 'title' => 'ก่อนส่งออกจากต้นทาง', 'photos' => $outgoingPhotos, 'canUpload' => $canUploadOutgoingPhotos],
                ['stage' => 'INCOMING', 'title' => 'ตอนรับเข้าปลายทาง', 'photos' => $incomingPhotos, 'canUpload' => $canUploadIncomingPhotos],
            ] as $column)
                <section class="col-12 col-lg-6" aria-labelledby="transfer-photo-title-{{ strtolower($column['stage']) }}">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h3 class="h6 mb-0" id="transfer-photo-title-{{ strtolower($column['stage']) }}">{{ $column['title'] }}</h3>
                        <span class="small text-secondary">{{ $column['photos']->count() }}/5 รูป</span>
                    </div>

                    <div class="transfer-photo-grid mb-3">
                        @forelse ($column['photos'] as $photo)
                            <a class="transfer-photo-card" href="{{ route('wms.transfers.photos.preview', [$transfer, $photo]) }}" target="_blank" rel="noopener" title="เปิดรูป {{ $photo->original_name }}">
                                <img src="{{ route('wms.transfers.photos.preview', [$transfer, $photo]) }}" alt="{{ $column['title'] }}: {{ $photo->original_name }}" loading="lazy">
                                <span>{{ $photo->uploadedBy?->name ?: 'ผู้ใช้งาน' }} · {{ $photo->created_at?->format('d/m/Y H:i') }}</span>
                            </a>
                        @empty
                            <div class="transfer-photo-empty"><i class="bx bx-image-alt" aria-hidden="true"></i><span>ยังไม่มีรูปภาพ</span></div>
                        @endforelse
                    </div>

                    @if ($column['canUpload'] && $column['photos']->count() < 5)
                        <form class="js-transfer-photo-form border rounded-3 p-3" method="POST" enctype="multipart/form-data" action="{{ route('wms.transfers.photos.store', $transfer) }}">
                            @csrf
                            <input type="hidden" name="stage" value="{{ $column['stage'] }}">
                            <x-platform::file-uploader
                                name="photos"
                                :label="'แนบรูป'.$column['title']"
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
                </section>
            @endforeach
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            $(function () {
                $('.js-transfer-photo-form').on('submit', function (event) {
                    event.preventDefault();
                    var form = $(this), button = form.find('button[type="submit"]');
                    form.find('[data-error-for]').text('');
                    button.prop('disabled', true);
                    $.ajax({
                        url: form.attr('action'),
                        method: 'POST',
                        data: new FormData(this),
                        processData: false,
                        contentType: false
                    }).done(function (response) {
                        Swal.fire({icon: 'success', text: response.msg || 'อัปโหลดรูปแล้ว', timer: 1200, showConfirmButton: false}).then(function () { location.reload(); });
                    }).fail(function (xhr) {
                        var errors = xhr.responseJSON?.errors || {};
                        var message = errors.photos?.[0] || Object.keys(errors).filter(function (key) { return key.indexOf('photos.') === 0; }).map(function (key) { return errors[key][0]; })[0];
                        if (message) form.find('[data-error-for="photos"]').text(message);
                        Swal.fire({icon: 'error', text: message || xhr.responseJSON?.message || 'ไม่สามารถอัปโหลดรูปได้'});
                    }).always(function () {
                        button.prop('disabled', false);
                    });
                });
            });
        </script>
    @endpush
@endonce
