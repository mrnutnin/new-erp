@extends('Purchasing::layout')

@section('title', 'Receipt '.$receipt->receipt_number)

@section('content')
@php
    $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'VOID' => 'ยกเลิก'];
    $classes = ['DRAFT' => 'neutral', 'APPROVED' => 'success', 'VOID' => 'danger'];
    $poLabels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'VOID' => 'ยกเลิก'];
    $poClasses = ['DRAFT' => 'neutral', 'APPROVED' => 'success', 'VOID' => 'danger'];
    $supplierLabel = $receipt->supplier ? trim($receipt->supplier->code.' · '.$receipt->supplier->name, ' ·') : '-';
    $warehouseLabel = $receipt->warehouse ? trim($receipt->warehouse->code.' · '.$receipt->warehouse->name, ' ·') : '-';
    $branchLabel = $receipt->warehouse?->branch ? trim($receipt->warehouse->branch->code.' · '.$receipt->warehouse->branch->name, ' ·') : '-';
@endphp

<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">PURCHASING / GOODS RECEIPT</p>
            <h1 class="h3 mb-2">{{ $receipt->receipt_number }}</h1>
            <p class="text-secondary mb-2">{{ $supplierLabel }}</p>
            <span class="badge app-status-{{ $classes[$receipt->status] ?? 'neutral' }}">{{ $labels[$receipt->status] ?? $receipt->status }}</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="{{ route($moduleRoutePrefix.'.purchase-receipts.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
            @if($receipt->status === 'DRAFT' && auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-receipts.update'))
                <a class="btn btn-app-soft" href="{{ route($moduleRoutePrefix.'.purchase-receipts.edit', $receipt) }}"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข</a>
            @endif
            @if(auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-receipts.print'))
                <a class="btn btn-app-soft" target="_blank" href="{{ route($moduleRoutePrefix.'.purchase-receipts.pdf', $receipt) }}"><i class="bx bx-printer me-1" aria-hidden="true"></i>พิมพ์</a>
            @endif
            @if($receipt->status === 'DRAFT' && auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-receipts.approve'))
                <button class="btn btn-app-primary js-gr-approve" data-url="{{ route($moduleRoutePrefix.'.purchase-receipts.approve', $receipt) }}"><i class="bx bx-check me-1" aria-hidden="true"></i>อนุมัติ</button>
            @endif
            @if(in_array($receipt->status, ['DRAFT', 'APPROVED'], true) && auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-receipts.void'))
                <button class="btn btn-app-danger js-gr-void" data-url="{{ route($moduleRoutePrefix.'.purchase-receipts.void', $receipt) }}"><i class="bx bx-x-circle me-1" aria-hidden="true"></i>ยกเลิกเอกสาร</button>
            @endif
            @if($receipt->status === 'DRAFT' && auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-receipts.delete'))
                <button class="btn btn-app-danger js-gr-delete" data-url="{{ route($moduleRoutePrefix.'.purchase-receipts.destroy', $receipt) }}"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบร่าง</button>
            @endif
        </div>
    </div>

    <div class="alert alert-info border-0 mb-4">
        <strong>ขั้นตอนถัดไป:</strong>
        {{ $receipt->status === 'DRAFT' ? 'ตรวจสอบจำนวน ต้นทุน และเอกสาร PO อ้างอิง แล้วกด “อนุมัติ”' : ($receipt->status === 'APPROVED' ? 'Receipt ได้รับอนุมัติแล้ว' : 'เอกสารนี้ไม่มีขั้นตอนที่ต้องดำเนินการต่อ') }}
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 mb-0">ข้อมูลใบรับสินค้า</h2>
                <span class="small text-secondary">ข้อมูลเอกสารและแหล่งอ้างอิง</span>
            </div>
            <div class="row g-3">
                <div class="col-md-6 col-xl-3"><small class="text-secondary">เลขที่ใบรับสินค้า</small><div class="fw-semibold">{{ $receipt->receipt_number }}</div></div>
                <div class="col-md-6 col-xl-3"><small class="text-secondary">สถานะ</small><div><span class="badge app-status-{{ $classes[$receipt->status] ?? 'neutral' }}">{{ $labels[$receipt->status] ?? $receipt->status }}</span></div></div>
                <div class="col-md-6 col-xl-3"><small class="text-secondary">วันที่รับสินค้า</small><div class="fw-semibold">{{ $receipt->business_date?->format($dateFormat) ?? '-' }}</div></div>
                <div class="col-md-6 col-xl-3"><small class="text-secondary">วันที่สร้าง</small><div class="fw-semibold">{{ $receipt->created_at?->format($dateFormat.' H:i') ?? '-' }}</div></div>
                <div class="col-md-6 col-xl-4"><small class="text-secondary">Supplier</small><div class="fw-semibold text-break">{{ $supplierLabel }}</div></div>
                <div class="col-md-6 col-xl-4"><small class="text-secondary">คลังรับสินค้า</small><div class="fw-semibold text-break">{{ $warehouseLabel }}</div></div>
                <div class="col-md-6 col-xl-4"><small class="text-secondary">สาขา</small><div class="fw-semibold text-break">{{ $branchLabel }}</div></div>
                <div class="col-md-6 col-xl-4">
                    <small class="text-secondary">เอกสาร PO อ้างอิง</small>
                    @if($receipt->purchaseOrder && auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-orders.view'))
                        <div class="fw-semibold"><a class="link-primary" href="{{ route($moduleRoutePrefix.'.purchase-orders.show', $receipt->purchaseOrder) }}"><i class="bx bx-file-search me-1" aria-hidden="true"></i>{{ $receipt->purchaseOrder->document_number }}</a></div>
                    @else
                        <div class="fw-semibold">{{ $receipt->purchaseOrder?->document_number ?? '-' }}</div>
                    @endif
                </div>
                <div class="col-md-6 col-xl-4"><small class="text-secondary">วันที่เอกสาร PO</small><div class="fw-semibold">{{ $receipt->purchaseOrder?->document_date?->format($dateFormat) ?? '-' }}</div></div>
                <div class="col-md-6 col-xl-4"><small class="text-secondary">สถานะ PO</small><div>@if($receipt->purchaseOrder)<span class="badge app-status-{{ $poClasses[$receipt->purchaseOrder->status] ?? 'neutral' }}">{{ $poLabels[$receipt->purchaseOrder->status] ?? $receipt->purchaseOrder->status }}</span>@else<span class="fw-semibold">-</span>@endif</div></div>
                <div class="col-md-6 col-xl-4"><small class="text-secondary">ผู้สร้าง</small><div class="fw-semibold">{{ $receipt->createdBy?->name ?? '-' }}</div></div>
                <div class="col-md-6 col-xl-4"><small class="text-secondary">ผู้อนุมัติ</small><div class="fw-semibold">{{ $receipt->approvedBy?->name ?? '-' }}</div></div>
                <div class="col-md-6 col-xl-4"><small class="text-secondary">วันที่อนุมัติ</small><div class="fw-semibold">{{ $receipt->approved_at?->format($dateFormat.' H:i') ?? '-' }}</div></div>
                <div class="col-12"><small class="text-secondary">หมายเหตุ</small><div class="fw-semibold text-break">{{ $receipt->description ?: '-' }}</div></div>
                @if($receipt->status === 'VOID')
                    <div class="col-12"><small class="text-secondary">เหตุผลยกเลิก</small><div class="text-danger text-break">{{ $receipt->void_reason ?: '-' }}</div></div>
                @endif
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <h2 class="h5 mb-3">รายการรับสินค้า</h2>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>สินค้า</th><th>หน่วยซื้อ</th><th class="text-end">จำนวนรับ</th><th>หน่วยสต็อก</th><th class="text-end">จำนวนสต็อก</th><th class="text-end">ต้นทุนรวม</th><th class="text-end">ต้นทุน/หน่วยสต็อก</th></tr></thead>
                    <tbody>
                        @foreach($receipt->lines as $line)
                            <tr>
                                <td>{{ ($line->item?->code ? $line->item->code.' · ' : '').($line->item?->name ?? '-') }}</td>
                                <td>{{ $line->purchaseUom?->code ?? '-' }}</td>
                                <td class="text-end">{{ $line->purchase_quantity }}</td>
                                <td>{{ $line->stockUom?->code ?? '-' }}</td>
                                <td class="text-end">{{ $line->stock_quantity }}</td>
                                <td class="text-end">{{ number_format((float) $line->total_cost, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $line->stock_unit_cost, 2) }}</td>
                            </tr>
                        @endforeach
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
    function request(button, data) {
        button.prop('disabled', true);
        $.post(button.data('url'), $.extend({_token: $('meta[name=csrf-token]').attr('content')}, data || {}))
            .done(function (response) {
                Swal.fire({icon: 'success', text: response.msg, timer: 1200, showConfirmButton: false}).then(function () { location.reload(); });
            })
            .fail(function (xhr) {
                button.prop('disabled', false);
                Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ดำเนินการไม่สำเร็จ'});
            });
    }
    $('.js-gr-approve').on('click', function () {
        var button = $(this);
        Swal.fire({icon: 'warning', title: 'อนุมัติ Receipt?', showCancelButton: true, confirmButtonText: 'อนุมัติ', cancelButtonText: 'กลับ'}).then(function (result) {
            if (result.isConfirmed) request(button);
        });
    });
    $('.js-gr-void').on('click', function () {
        var button = $(this);
        Swal.fire({icon: 'warning', title: 'ยกเลิกเอกสาร Receipt?', input: 'textarea', inputLabel: 'เหตุผลอย่างน้อย 10 ตัวอักษร', showCancelButton: true, confirmButtonText: 'ยกเลิกเอกสาร', cancelButtonText: 'กลับ', preConfirm: function (value) {
            if ($.trim(value || '').length < 10) {
                Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร');
                return false;
            }
            return value;
        }}).then(function (result) {
            if (result.isConfirmed) request(button, {reason: result.value});
        });
    });
    window.erpAjaxDelete({button: '.js-gr-delete', redirect: '{{ route($moduleRoutePrefix.'.purchase-receipts.index') }}', title: 'ลบร่างใบรับสินค้า?', text: 'เอกสารร่างจะถูกลบและไม่สามารถกู้คืนได้', confirmButtonText: 'ลบร่าง', cancelButtonText: 'กลับ'});
});
</script>
@endpush
