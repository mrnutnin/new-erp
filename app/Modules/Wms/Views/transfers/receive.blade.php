@extends('Wms::layout')

@section('title', 'รับโอนสินค้าเข้า | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / TRANSFER IN</p>
            <h1 class="h3 mb-2">รับโอนสินค้าเข้า</h1>
            <p class="text-secondary mb-0">{{ $transfer->document_number }} · {{ $transfer->sourceWarehouse?->name ?: '-' }} → {{ $transfer->destinationWarehouse?->name ?: '-' }}</p>
        </div>
        <a class="btn btn-app-soft" href="{{ route('wms.transfers.incoming.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับรายการ</a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <div class="row g-3 mb-3">
                <div class="col-md-4"><div class="text-secondary small">วันที่เอกสาร</div><div class="fw-semibold">{{ $transfer->document_date?->format($dateFormat) ?: '-' }}</div></div>
                <div class="col-md-4"><div class="text-secondary small">สถานะ</div><span class="badge app-status-info">ส่งออกแล้ว</span></div>
                <div class="col-md-4"><div class="text-secondary small">จำนวนทศนิยม</div><div class="fw-semibold">{{ $decimalPlaces }} ตำแหน่ง</div></div>
            </div>
            <div class="alert alert-info mb-0">MVP นี้รับโอนเต็มจำนวนตามยอดที่ส่งออกเท่านั้น ระบบจะคำนวณจำนวนให้และไม่อนุญาตให้แก้ไขทีละรายการ</div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <h2 class="h5 mb-3">รายการสินค้าที่รอรับ</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>#</th><th>สินค้า</th><th>หน่วย Stock</th><th class="text-end">ส่งออก</th><th class="text-end">รับแล้ว</th><th class="text-end">ปฏิเสธแล้ว</th><th class="text-end">รับครั้งนี้</th></tr></thead>
                    <tbody>
                    @forelse($lines as $line)
                        <tr>
                            <td>{{ $line['line_number'] }}</td><td>{{ $line['item_label'] }}</td><td>{{ $line['uom_label'] }}</td>
                            <td class="text-end">{{ $line['dispatched_base_quantity'] }}</td><td class="text-end">{{ $line['accepted_base_quantity'] }}</td><td class="text-end">{{ $line['rejected_base_quantity'] }}</td>
                            <td class="text-end fw-semibold text-primary">{{ $line['remaining_base_quantity'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">ไม่มีรายการที่รอรับ</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <form id="transfer-complete-form" method="POST" action="{{ route('wms.transfers.complete', $transfer) }}">
                @csrf
                <input type="hidden" name="action" id="transfer-action" value="accept">
                <input type="hidden" name="full_receipt" value="1">
                <input type="hidden" name="command_key" value="transfer-receive-{{ $transfer->id }}">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4"><label class="form-label">วันที่รับ/ปฏิเสธ</label><input class="form-control" type="date" name="business_date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></div>
                    <div class="col-md-8"><label class="form-label">เหตุผล (จำเป็นเมื่อปฏิเสธ)</label><textarea class="form-control" name="reason" rows="2" placeholder="ระบุเหตุผลเมื่อปฏิเสธ หรือหมายเหตุการรับเข้า"></textarea></div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-4"><button class="btn btn-app-primary" type="submit" data-transfer-action="accept"><i class="bx bx-check me-1" aria-hidden="true"></i>รับเข้าเต็มจำนวน</button><button class="btn btn-outline-danger" type="submit" data-transfer-action="reject"><i class="bx bx-x me-1" aria-hidden="true"></i>ปฏิเสธทั้งรายการ</button></div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var form = $('#transfer-complete-form');
    form.on('click', '[data-transfer-action]', function () { $('#transfer-action').val($(this).data('transfer-action')); });
    form.on('submit', function (event) {
        event.preventDefault();
        var action = $('#transfer-action').val(), reason = $.trim(form.find('[name="reason"]').val() || '');
        if (action === 'reject' && !reason) { Swal.fire({icon:'warning', text:'กรุณาระบุเหตุผลก่อนปฏิเสธรายการ'}); return; }
        var buttons = form.find('button[type="submit"]'); buttons.prop('disabled', true);
        $.ajax({url:form.attr('action'), method:'POST', data:form.serialize()}).done(function (response) {
            Swal.fire({icon:'success', text:response.msg || 'ดำเนินการแล้ว', timer:1400, showConfirmButton:false}).then(function () { window.location.href = @json(route('wms.transfers.incoming.index')); });
        }).fail(function (xhr) { Swal.fire({icon:'error', text:xhr.responseJSON?.message || 'ไม่สามารถดำเนินการได้'}); }).always(function () { buttons.prop('disabled', false); });
    });
});
</script>
@endpush
