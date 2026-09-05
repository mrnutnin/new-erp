@extends('Wms::layout')
@section('title','ใบเบิกสินค้า '.$document->document_number.' | WMS')
@php
    $statusLabels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิก'];
    $statusClasses = ['DRAFT' => 'app-status-neutral', 'APPROVED' => 'app-status-info', 'POSTED' => 'app-status-success', 'VOID' => 'app-status-danger'];
@endphp
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / ISSUE</p>
            <h1 class="h3 mb-2">{{ $document->document_number }}</h1>
            <span class="badge {{ $statusClasses[$document->status] ?? 'app-status-neutral' }}">{{ $statusLabels[$document->status] ?? $document->status }}</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('wms.issues.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับ</a>
            @if($document->status === 'DRAFT' && auth()->user()->hasPermission('wms.issues.delete'))
                <button class="btn btn-outline-danger js-delete" type="button" data-url="{{ route('wms.issues.destroy', $document) }}"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ Draft</button>
            @endif
            @if($document->status === 'DRAFT' && auth()->user()->hasPermission('wms.issues.approve'))
                <button class="btn btn-dark js-act" data-action="approve"><i class="bx bx-check me-1" aria-hidden="true"></i>อนุมัติ</button>
            @endif
            @if($document->status === 'APPROVED' && auth()->user()->hasPermission('wms.issues.post'))
                <button class="btn btn-dark js-act" data-action="post"><i class="bx bx-send me-1" aria-hidden="true"></i>ลง Stock</button>
            @endif
            @if($document->status === 'POSTED' && auth()->user()->hasPermission('wms.issue-returns.create'))
                <a class="btn btn-dark" href="{{ route('wms.issue-returns.create', ['issue_document_id' => $document->id]) }}"><i class="bx bx-undo me-1" aria-hidden="true"></i>สร้างใบรับคืน</a>
            @endif
        </div>
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><div class="row g-3"><div class="col-12 col-md-3"><small class="text-secondary">วันที่เอกสาร</small><div class="fw-semibold">{{ $document->document_date?->format($dateFormat) ?: '-' }}</div></div><div class="col-12 col-md-3"><small class="text-secondary">ประเภทการเบิก</small><div class="fw-semibold">{{ ['GENERAL' => 'เบิกทั่วไป', 'PRODUCTION' => 'เบิกเข้าผลิต', 'PROJECT' => 'เบิกโครงการ'][$document->issue_type] ?? $document->issue_type }}</div></div><div class="col-12 col-md-6"><small class="text-secondary">เหตุผล</small><div class="fw-semibold">{{ $document->reason ?: '-' }}</div></div></div></div></div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-1">รายการเบิก</h2><p class="text-secondary small mb-3">คงเหลือพร้อมใช้ ณ ปัจจุบัน ก่อนอนุมัติเอกสาร</p><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>#</th><th>สินค้า</th><th>หน่วย</th><th class="text-end">คงเหลือพร้อมใช้</th><th class="text-end">จำนวนเบิก</th><th>Movement</th><th class="text-end">ต้นทุน</th></tr></thead><tbody>@forelse($document->lines as $line)@php($available = (string) ($stockBalances[$line->item_id.':'.$line->uom_id]?->available ?? '0'))<tr><td>{{ $line->line_number }}</td><td>{{ $line->item?->code }} · {{ $line->item?->name }}</td><td>{{ $line->uom?->code }}</td><td class="text-end {{ \Brick\Math\BigDecimal::of($available)->isLessThan($line->quantity) ? 'text-danger fw-semibold' : 'text-success' }}">{{ \App\Modules\Wms\Support\WmsDecimal::format($available) }}</td><td class="text-end">{{ \App\Modules\Wms\Support\WmsDecimal::format($line->quantity) }}</td><td>{{ $line->movement?->id ? '#'.$line->movement->id : '-' }}</td><td class="text-end">{{ $line->allocation ? \App\Modules\Wms\Support\WmsDecimal::format($line->allocation->value) : '-' }}</td></tr>@empty<tr><td colspan="7" class="text-center text-secondary py-4">ไม่พบรายการเบิก</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">ประวัติเอกสาร</h2>@forelse($history as $h)<div class="border-bottom py-2"><strong>{{ $h->action }}</strong><small class="text-secondary ms-2">{{ $h->created_at?->format($dateFormat.' H:i') }} · {{ $h->user?->name ?? '-' }}</small></div>@empty<div class="text-secondary">ยังไม่มีประวัติ</div>@endforelse</div></div>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    $('.js-act').on('click', function () {
        const button = $(this), action = button.data('action');
        Swal.fire({icon: 'warning', title: action === 'approve' ? 'อนุมัติใบเบิก?' : 'ลง Stock ใบเบิก?', showCancelButton: true, confirmButtonText: 'ยืนยัน', cancelButtonText: 'ยกเลิก'}).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.post('{{ route('wms.issues.show', $document) }}/' + action, {_token: $('meta[name=csrf-token]').attr('content')}).done(function () { location.reload(); }).fail(function (xhr) { Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ดำเนินการไม่สำเร็จ'}); }).always(function () { button.prop('disabled', false); });
        });
    });
    $('.js-delete').on('click', function () {
        const button = $(this);
        Swal.fire({icon: 'warning', title: 'ลบร่างใบเบิก?', text: 'เอกสาร Draft จะถูกลบออกจากรายการ', showCancelButton: true, confirmButtonText: 'ลบ Draft', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc3545'}).then(function (result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true);
            $.ajax({url: button.data('url'), method: 'DELETE', data: {_token: $('meta[name=csrf-token]').attr('content')}}).done(function (response) { Swal.fire({icon: 'success', text: response.msg, timer: 1200, showConfirmButton: false}).then(function () { location.href = response.redirect; }); }).fail(function (xhr) { button.prop('disabled', false); Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ลบร่างใบเบิกไม่สำเร็จ'}); });
        });
    });
});
</script>
@endpush
