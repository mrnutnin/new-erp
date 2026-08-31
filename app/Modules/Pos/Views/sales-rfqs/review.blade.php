@extends('Pos::layout')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <div class="eyebrow">SALES / RFQ</div>
            <h1 class="h3 mb-1">พิจารณา RFQ {{ $x->document_number }}</h1>
            <p class="text-secondary mb-0">บันทึกต้นทุนประเมินเพื่อประเมินกำไรขั้นต้นก่อนอนุมัติ</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('pos.sales-rfqs.show', $x) }}">กลับรายละเอียด</a>
    </div>

    <form id="rfq-review" method="post" action="{{ route('pos.sales-rfqs.decide', $x) }}">
        @csrf
        <input type="hidden" name="decision" value="APPROVED">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="alert alert-info mb-3">ต้นทุนและกำไรในหน้านี้เป็นข้อมูลประกอบการอนุมัติ ยังไม่ใช่ต้นทุนขายจริง (COGS)</div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>รายการ</th>
                                <th class="text-end">จำนวน</th>
                                <th class="text-end">ยอดขายสุทธิ</th>
                                <th class="text-end">ต้นทุนประเมิน/หน่วย</th>
                                <th class="text-end">ต้นทุนรวม</th>
                                <th class="text-end">กำไรขั้นต้น</th>
                                <th class="text-end">GP%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($x->lines as $line)
                                <tr class="js-margin-line" data-quantity="{{ $line->quantity }}" data-sales="{{ $line->proposed_unit_price * $line->quantity - $line->proposed_discount_amount }}">
                                    <td>
                                        {{ data_get($line->item_snapshot, 'code') }} · {{ data_get($line->item_snapshot, 'name', $line->description) }}
                                        <input type="hidden" name="lines[{{ $loop->index }}][id]" value="{{ $line->id }}">
                                    </td>
                                    <td class="text-end">{{ number_format((float) $line->quantity, 4) }}</td>
                                    <td class="text-end js-sales">{{ number_format((float) ($line->proposed_unit_price * $line->quantity - $line->proposed_discount_amount), 2) }}</td>
                                    <td><input class="form-control text-end js-estimated-cost" name="lines[{{ $loop->index }}][estimated_unit_cost]" type="number" min="0" step="0.0001" inputmode="decimal"></td>
                                    <td class="text-end js-cost">0.00</td>
                                    <td class="text-end js-margin">0.00</td>
                                    <td class="text-end js-margin-percent">-</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light fw-semibold">
                            <tr>
                                <td colspan="2">รวม</td>
                                <td class="text-end" id="rfq-total-sales">0.00</td>
                                <td></td>
                                <td class="text-end" id="rfq-total-cost">0.00</td>
                                <td class="text-end" id="rfq-total-margin">0.00</td>
                                <td class="text-end" id="rfq-total-margin-percent">-</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="mt-4">
                    <label class="form-label" for="rfq-review-reason">เหตุผลการพิจารณา</label>
                    <textarea id="rfq-review-reason" class="form-control" name="reason" minlength="10" maxlength="500" required></textarea>
                </div>
                <div class="mt-3">
                    <button class="btn btn-success js-decision" type="submit" data-decision="APPROVED">อนุมัติ</button>
                    <button class="btn btn-outline-danger ms-2 js-decision" type="submit" data-decision="REJECTED">ไม่อนุมัติ</button>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const money = value => Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const refreshMargins = () => {
        let totalSales = 0;
        let totalCost = 0;
        $('.js-margin-line').each(function () {
            const line = $(this);
            const sales = Number(line.data('sales')) || 0;
            const cost = (Number(line.data('quantity')) || 0) * (Number(line.find('.js-estimated-cost').val()) || 0);
            const margin = sales - cost;
            totalSales += sales;
            totalCost += cost;
            line.find('.js-cost').text(money(cost));
            line.find('.js-margin').text(money(margin));
            line.find('.js-margin-percent').text(sales ? `${(margin / sales * 100).toFixed(2)}%` : '-');
        });
        const totalMargin = totalSales - totalCost;
        $('#rfq-total-sales').text(money(totalSales));
        $('#rfq-total-cost').text(money(totalCost));
        $('#rfq-total-margin').text(money(totalMargin));
        $('#rfq-total-margin-percent').text(totalSales ? `${(totalMargin / totalSales * 100).toFixed(2)}%` : '-');
    };

    $('.js-estimated-cost').on('input', refreshMargins);
    $('.js-decision').on('click', function () {
        const approving = $(this).data('decision') === 'APPROVED';
        $('[name="decision"]').val($(this).data('decision'));
        $('.js-estimated-cost').prop('required', approving);
    });
    refreshMargins();
    window.erpAjaxForm({ form: '#rfq-review', redirect: true });
});
</script>
@endpush
