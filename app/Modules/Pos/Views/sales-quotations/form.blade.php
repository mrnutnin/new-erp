@extends('Pos::layout')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="app-eyebrow">SALES / QUOTATION</p>
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
        <div><h1 class="h2 mb-2">แก้ไขใบเสนอราคา {{ $quotation->document_number }}</h1><span class="badge app-badge-info">ร่าง</span></div>
        <a class="btn btn-app-soft" href="{{ route('pos.sales-quotations.show', $quotation) }}">กลับรายละเอียด</a>
    </div>
    <form method="post" action="{{ route('pos.sales-quotations.update', $quotation) }}" id="quotation-form">
        @csrf @method('PUT')
        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="row g-3">
            <div class="col-md-4"><div class="text-secondary small">ลูกค้า</div><div>{{ $quotation->party_code }} · {{ $quotation->party_name }}</div></div>
            <div class="col-md-4"><div class="text-secondary small">อ้างอิงใบขอราคา</div><div>{{ $quotation->rfq?->document_number ?? '—' }}</div></div>
            <div class="col-md-4"><div class="text-secondary small">วันที่เอกสาร</div><div>{{ optional($quotation->document_date)->format('d/m/Y') }}</div></div>
        </div>@if($quotation->description)<hr><div class="text-secondary small">รายละเอียดจาก RFQ</div><div>{{ $quotation->description }}</div>@endif</div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5 mb-3">ราคาเสนอขาย</h2><p class="text-secondary small">รายการและจำนวนถูกล็อกจาก RFQ แก้ไขได้เฉพาะคำอธิบาย ราคา และส่วนลด</p>
            <div class="table-responsive"><table class="table align-middle" id="quotation-lines"><thead><tr><th>รายการ</th><th class="text-end">จำนวน</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">ส่วนลด</th><th class="text-end">รวม</th></tr></thead><tbody>
            @foreach($quotation->lines as $i => $line)<tr class="quotation-line"><td><input type="hidden" name="lines[{{ $i }}][id]" value="{{ $line->id }}"><input name="lines[{{ $i }}][description]" class="form-control" value="{{ old("lines.$i.description", $line->description) }}" required></td><td class="text-end"><span class="quantity" data-value="{{ $line->quantity }}">{{ number_format((float) $line->quantity, 4) }}</span></td><td><input name="lines[{{ $i }}][unit_price]" class="form-control text-end js-price" inputmode="decimal" value="{{ old("lines.$i.unit_price", $line->unit_price) }}" required></td><td><input name="lines[{{ $i }}][discount_amount]" class="form-control text-end js-discount" inputmode="decimal" value="{{ old("lines.$i.discount_amount", $line->discount_amount) }}"></td><td class="text-end fw-semibold js-total">0.00</td></tr>@endforeach
            </tbody><tfoot><tr><th colspan="4" class="text-end">ยอดรวม</th><th class="text-end" id="quotation-total">0.00</th></tr></tfoot></table></div>
            <div class="d-flex gap-2 mt-3"><button class="btn btn-dark" type="submit">บันทึกใบเสนอราคา</button><a class="btn btn-outline-secondary" href="{{ route('pos.sales-quotations.show', $quotation) }}">ยกเลิก</a></div>
        </div></div>
    </form>
</div>
@endsection
@push('scripts')<script>
(function(){function n(v){var x=Number(String(v||'').replace(/,/g,''));return Number.isFinite(x)?x:0}function recalc(){var total=0;document.querySelectorAll('.quotation-line').forEach(function(row){var q=n(row.querySelector('.quantity').dataset.value),p=n(row.querySelector('.js-price').value),d=n(row.querySelector('.js-discount').value),v=Math.max(0,q*p-d);total+=v;row.querySelector('.js-total').textContent=v.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})});document.getElementById('quotation-total').textContent=total.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}document.querySelectorAll('.js-price,.js-discount').forEach(function(x){x.addEventListener('input',recalc)});recalc()})();
</script>@endpush
