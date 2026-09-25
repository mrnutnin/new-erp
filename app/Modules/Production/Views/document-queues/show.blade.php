@extends('Production::layout')

@section('title', 'รายละเอียด'.$document->document_number.' | Production')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">PRODUCTION / {{ $kind === 'issue' ? 'MATERIAL ISSUE' : 'FINISHED RECEIPT' }}</p>
            <h1 class="h3 mb-2">{{ $document->document_number }}</h1>
            <span class="badge app-status-{{ $statusClass }}">{{ $statusLabel }}</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="{{ $backUrl }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้าคิว</a>
            @if($canApprove)
                <button class="btn btn-app-primary js-queue-action" type="button" data-action="approve" data-url="{{ $approveUrl }}"><i class="bx bx-check me-1" aria-hidden="true"></i>อนุมัติ</button>
            @elseif($canPost)
                <button class="btn btn-app-primary js-queue-action" type="button" data-action="post" data-url="{{ $postUrl }}" @disabled($kind === 'receipt' && !($postReadiness['ready'] ?? false))><i class="bx bx-send me-1" aria-hidden="true"></i>ลง Stock และ GL</button>
            @endif
        </div>
    </div>

    <div class="alert {{ $document->status === 'DRAFT' ? 'alert-warning' : ($document->status === 'APPROVED' ? 'alert-info' : 'alert-success') }} border-0 mb-4" role="status">
        @if($document->status === 'DRAFT') ตรวจเอกสารและรายการก่อนอนุมัติ
        @elseif($document->status === 'APPROVED') ตรวจสอบความพร้อม แล้วลง Stock และ GL
        @elseif($document->status === 'POSTED') เอกสารลง Stock และ GL แล้ว
        @else เอกสารนี้ไม่มีขั้นตอนที่ต้องดำเนินการต่อ
        @endif
    </div>

    @if($kind === 'receipt' && $document->status === 'APPROVED' && !($postReadiness['ready'] ?? false))
        <div class="alert alert-warning border-0 mb-4"><strong>ยังลง Stock และ GL ไม่ได้</strong><ul class="mb-0 mt-2">@foreach($postReadiness['blockers'] ?? [] as $blocker)<li>{{ is_array($blocker) ? ($blocker['message'] ?? 'ตรวจพบเงื่อนไขที่ยังไม่พร้อม') : $blocker }}</li>@endforeach</ul></div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <div class="row g-3">
                <div class="col-12 col-md-4"><small class="text-secondary d-block">วันที่เอกสาร</small><strong>{{ $document->document_date?->format($dateFormat) ?: '-' }}</strong></div>
                <div class="col-12 col-md-4"><small class="text-secondary d-block">คลังสินค้า</small><strong>{{ $document->warehouse?->code }} · {{ $document->warehouse?->name }}</strong></div>
                <div class="col-12 col-md-4"><small class="text-secondary d-block">เหตุผล</small><strong>{{ $document->reason ?: '-' }}</strong></div>
                @if($kind === 'receipt')
                    <div class="col-12"><small class="text-secondary d-block">ใบเบิกวัตถุดิบต้นทาง</small><strong>{{ $document->sourceIssue?->document_number ?: '-' }}</strong></div>
                @endif
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <h2 class="h5 mb-3">รายการสินค้า</h2>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>#</th><th>สินค้า</th><th>หน่วย</th><th class="text-end">จำนวน</th>@if($kind === 'receipt')<th class="text-end">มูลค่า</th>@endif</tr></thead>
                    <tbody>
                        @forelse($document->lines as $line)
                            <tr>
                                <td>{{ $line->line_number }}</td>
                                <td>{{ $line->item?->code }} · {{ $line->item?->name }}</td>
                                <td>{{ $line->uom?->code ?: '-' }}</td>
                                <td class="text-end">{{ \App\Modules\Wms\Support\WmsDecimal::format($line->quantity) }}</td>
                                @if($kind === 'receipt')<td class="text-end">{{ \App\Modules\Wms\Support\WmsDecimal::format($line->value) }}</td>@endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $kind === 'receipt' ? 5 : 4 }}" class="text-center text-secondary py-4">ไม่พบรายการ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('.js-queue-action').on('click', function () {
        const button = $(this), action = button.data('action');
        const label = action === 'approve' ? 'อนุมัติ' : 'ลง Stock และ GL';
        const title = action === 'approve' ? 'อนุมัติเอกสาร?' : 'ลง Stock และ GL?';
        const impact = action === 'approve' ? 'เอกสารจะเปลี่ยนสถานะเป็นอนุมัติ' : 'ระบบจะลงรายการ Stock และบันทึกบัญชี';
        Swal.fire({icon: 'warning', title: title, text: impact, showCancelButton: true, confirmButtonText: label, cancelButtonText: 'กลับ'}).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.post(button.data('url'), {_token: $('meta[name=csrf-token]').attr('content')})
                .done(function (response) {
                    Swal.fire({icon: 'success', text: response.msg, timer: 1200, showConfirmButton: false}).then(function () {
                        window.location.href = response.redirect;
                    });
                })
                .fail(function (xhr) {
                    button.prop('disabled', false);
                    Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ดำเนินการไม่สำเร็จ'});
                });
        });
    });
});
</script>
@endpush
