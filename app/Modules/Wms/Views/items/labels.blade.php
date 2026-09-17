@extends('Wms::layout')

@section('title', 'พิมพ์สติกเกอร์สินค้า | WMS')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / ITEMS / LABELS</p><h1 class="h3 mb-2">พิมพ์สติกเกอร์สินค้า</h1><p class="text-secondary mb-0">กำหนดขนาด รูปแบบรหัส และจำนวนสำเนาก่อนสร้างไฟล์สำหรับเครื่องพิมพ์ฉลาก</p></div>
        <a class="btn btn-app-soft" href="{{ route('wms.items.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
    </div>

    <form action="{{ route('wms.items.labels.print') }}" method="get" target="_blank">
        @foreach($items as $item)<input type="hidden" name="item_ids[]" value="{{ $item->id }}">@endforeach
        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4">
                    <h2 class="h5 mb-1">ตั้งค่าการพิมพ์</h2><p class="small text-secondary mb-4">เลือกพิมพ์ด้วยเครื่องพิมพ์ฉลากหรือจัดหลายดวงลงกระดาษ A4 และพิมพ์ที่ขนาดจริง 100%</p>
                    <div class="mb-3"><label class="form-label" for="label-size">ขนาดสติกเกอร์ <span class="text-danger">*</span></label><select class="form-select" id="label-size" name="size" required>@foreach($sizes as $value => $size)<option value="{{ $value }}" @selected(old('size', '50x30') === $value)>{{ $size['label'] }}</option>@endforeach</select></div>
                    <div class="mb-3"><label class="form-label" for="label-symbol">รหัสบนสติกเกอร์ <span class="text-danger">*</span></label><select class="form-select" id="label-symbol" name="symbol" required><option value="BARCODE">Barcode (Code 128)</option><option value="QR">QR Code</option><option value="BOTH" selected>Barcode และ QR Code</option></select><div class="form-text">ทั้งสองรูปแบบสร้างจากรหัสสินค้าโดยตรง</div></div>
                    <div class="mb-4"><label class="form-label" for="label-copies">จำนวนสำเนาต่อสินค้า <span class="text-danger">*</span></label><input class="form-control" id="label-copies" name="copies" type="number" min="1" max="20" value="1" required><div class="form-text">รวมทั้งหมดไม่เกิน 500 ดวงต่อครั้ง</div></div>
                    <button class="btn btn-app-primary w-100" type="submit"><i class="bx bx-printer me-1" aria-hidden="true"></i>สร้างไฟล์สำหรับพิมพ์</button>
                </div></div>
            </div>
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4">
                    <div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 class="h5 mb-1">สินค้าที่เลือก</h2><p class="small text-secondary mb-0">{{ $items->count() }} รายการ</p></div><span class="badge app-status-info">รหัสสินค้าเป็นข้อมูลใน QR/Barcode</span></div>
                    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th>หมวด</th><th>หน่วย</th></tr></thead><tbody>@foreach($items as $item)<tr><td class="font-monospace fw-semibold">{{ $item->code }}</td><td>{{ $item->name }}</td><td>{{ $item->category?->name ?? '—' }}</td><td>{{ $item->baseUom?->code ?: $item->base_uom }}</td></tr>@endforeach</tbody></table></div>
                </div></div>
            </div>
        </div>
    </form>
</div>
@endsection
