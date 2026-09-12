@extends('Wms::layout')
@php
    $initialLines = isset($document)
        ? $document->lines->map(fn ($line) => [
            'item_id' => $line->item_id,
            'text' => trim(($line->item?->code ?? '') . ' · ' . ($line->item?->name ?? ''), ' ·'),
            'uom_id' => $line->uom_id,
            'uom_label' => trim(($line->uom?->code ?? '') . ' · ' . ($line->uom?->name ?? ''), ' ·'),
            'counted_quantity' => (string) $line->counted_quantity,
            'note' => $line->note,
        ])->values()->all()
        : [];
@endphp
@section('content')<div class="container-fluid px-3 px-lg-4 py-4"><p class="eyebrow">WMS / STOCK COUNT</p><h1>{{ isset($document) ? 'แก้ไขเอกสารตรวจนับสินค้า' : 'สร้างเอกสารตรวจนับสินค้า' }}</h1><form id="count-form" method="post" action="{{ isset($document) ? route('wms.stock-counts.update',$document) : route('wms.stock-counts.store') }}"><input type="hidden" name="_token" value="{{ csrf_token() }}">@if(isset($document))<input type="hidden" name="_method" value="PUT">@endif<div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="row g-3"><div class="col-md-3"><label class="form-label">วันที่ตรวจนับ</label><input class="form-control" type="date" name="document_date" value="{{ isset($document) ? $document->document_date?->format('Y-m-d') : now()->format('Y-m-d') }}" required></div><div class="col-md-9"><label class="form-label">หมายเหตุ</label><input class="form-control" name="reason" value="{{ $document->reason ?? '' }}" placeholder="เช่น ตรวจนับประจำเดือน"></div></div></div></div><div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="d-flex justify-content-between"><h2 class="h5">รายการตรวจนับ</h2><button type="button" class="btn btn-app-soft" id="add-line"><i class="bx bx-plus"></i> เพิ่มรายการ</button></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>สินค้า</th><th>หน่วย</th><th>ยอดตรวจนับจริง</th><th>หมายเหตุ</th><th></th></tr></thead><tbody id="lines"></tbody></table></div><button class="btn btn-dark" type="submit"><i class="bx bx-save"></i> {{ isset($document) ? 'บันทึกการแก้ไข' : 'บันทึกร่าง' }}</button> <a class="btn btn-outline-secondary" href="{{ route('wms.stock-counts.index') }}">ยกเลิก</a></div></div></form></div>@endsection
@push('scripts')
<script>
$(function () {
    let index = 0;
    const initialLines = @json($initialLines);

    function addLine(line = null) {
        const n = index++;
        $('#lines').append(
            '<tr>' +
            '<td><select class="form-select js-item" name="lines[' + n + '][item_id]" required><option value="">ค้นหาสินค้า</option></select></td>' +
            '<td><input class="form-control js-uom-label" readonly><input type="hidden" class="js-uom" name="lines[' + n + '][uom_id]"></td>' +
            '<td><input class="form-control text-end" type="number" min="0" step="any" name="lines[' + n + '][counted_quantity]" required></td>' +
            '<td><input class="form-control" name="lines[' + n + '][note]"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger js-remove" title="ลบรายการ" aria-label="ลบรายการ"><i class="bx bx-trash"></i></button></td>' +
            '</tr>'
        );
        const select = $('#lines tr:last .js-item');
        select.select2({
            theme: 'bootstrap-5',
            width: '100%',
            ajax: {
                url: '{{ route('wms.stock-counts.item-options') }}',
                delay: 250,
                data: params => ({ q: params.term || '', page: params.page || 1 }),
                processResults: data => data
            }
        }).on('select2:select', event => {
            const data = event.params.data;
            const row = select.closest('tr');
            row.find('.js-uom').val(data.uom_id);
            row.find('.js-uom-label').val(data.uom_label);
        });
        if (line) {
            select.append(new Option(line.text, line.item_id, true, true)).trigger('change');
            const row = select.closest('tr');
            row.find('.js-uom').val(line.uom_id);
            row.find('.js-uom-label').val(line.uom_label);
            row.find('input[name$="[counted_quantity]"]').val(line.counted_quantity);
            row.find('input[name$="[note]"]').val(line.note || '');
        }
    }

    initialLines.length ? initialLines.forEach(addLine) : addLine();
    $('#add-line').on('click', () => addLine());
    $(document).on('click', '.js-remove', function () {
        if ($('#lines tr').length > 1) $(this).closest('tr').remove();
    });
    window.erpAjaxForm({ form: '#count-form', redirect: true });
});
</script>
@endpush
