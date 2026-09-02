@extends('Pos::layout')
@section('title', 'ใบขาย '.$sale->document_number)
@section('content')
@php($statusBadges = ['DRAFT' => 'app-badge-soft', 'POSTED' => 'app-badge-success', 'VOID' => 'text-bg-danger'])
@php($statusLabels = ['DRAFT' => 'ร่าง', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก'])
@php($hsBankAccounts = $sale->document_type === 'HS' ? \App\Modules\Finance\Models\BankAccount::query()->where('warehouse_id', $sale->warehouse_id)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']) : collect())
@php($allJournalUrls = collect([$sale->journal_entry_id, $sale->cogs_journal_entry_id])->merge($receipts->pluck('journal_entry_id'))->filter()->unique()->map(fn ($id) => route('accounting.journal-preview.show', $id))->values())
@php($fullCancellationHasVat = (float) $sale->tax_amount !== 0.0)
@php($fullCancellationHasWht = (float) $sale->withholding_amount !== 0.0)
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="app-eyebrow mb-2">SALES / PHYSICAL SALES</p>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><h1 class="h2 mb-2">{{ $sale->document_number }}</h1><span class="badge app-badge-info">{{ $sale->document_type === 'HS' ? 'ขายสด' : 'ขายเชื่อ' }}</span> @include('Pos::partials.document-status-badge', ['status' => $sale->status, 'label' => $statusLabels[$sale->status] ?? $sale->status])</div>
        <div class="d-flex flex-wrap justify-content-end gap-2">
            @if ($sale->status === 'DRAFT')
                @if(auth()->user()->hasPermission('pos.physical-sales.post'))
                    <button class="btn btn-primary d-inline-flex align-items-center gap-1 js-post-physical-sale" type="button" @disabled(!($postReadiness['ready'] ?? true))><i class="bx bx-check-circle"></i>ยืนยันขาย</button>
                @endif
                <button class="btn btn-outline-danger d-inline-flex align-items-center gap-1 js-void-sale" data-url="{{ route('pos.physical-sales.void', $sale) }}" type="button"><i class="bx bx-x-circle"></i>ยกเลิก</button>
            @endif
            @if ($sale->status === 'POSTED' && $paymentOpenItem && auth()->user()->hasPermission('pos.receipts.create'))
                <a class="btn btn-success d-inline-flex align-items-center gap-1" href="{{ route('pos.physical-sales.receive-payment.create', $sale) }}"><i class="bx bx-money"></i>รับชำระหนี้</a>
            @endif
            @if ($sale->status === 'POSTED' && auth()->user()->hasPermission('pos.physical-sales.cancel-full'))
                @if ($sale->document_type === 'IV' && $hasPostedReceipt)
                    <button class="btn btn-outline-danger d-inline-flex align-items-center gap-1" type="button" disabled title="กรุณายกเลิกเอกสารรับชำระหนี้ก่อน"><i class="bx bx-x-circle"></i>ยกเลิกทั้งใบ</button>
                @else
                    <button class="btn btn-outline-danger d-inline-flex align-items-center gap-1 js-cancel-full-sale" type="button"><i class="bx bx-x-circle"></i>ยกเลิกทั้งใบ</button>
                @endif
            @endif
            @if(auth()->user()->hasPermission('pos.physical-sales.print'))<a class="btn btn-app-soft d-inline-flex align-items-center gap-1" href="{{ route('pos.physical-sales.pdf', $sale) }}" target="_blank" rel="noopener"><i class="bx bx-printer"></i>พิมพ์ PDF</a>@endif
            <a class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" href="{{ route('pos.physical-sales.index') }}"><i class="bx bx-arrow-back"></i>กลับรายการ</a>
        </div>
    </div>
    @if($sale->status === 'DRAFT' && !($postReadiness['ready'] ?? true))
        <div class="alert alert-warning"><strong>ยังยืนยันขายไม่ได้</strong><ul class="mb-0 mt-1">@foreach($postReadiness['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
    @endif
    @if($sale->status === 'DRAFT')
        <div class="alert alert-info">เอกสารฉบับนี้เป็นร่างและยังไม่กระทบ Stock/GL</div>
    @elseif($sale->status === 'VOID')
        <div class="alert alert-danger">เอกสารฉบับนี้ถูกยกเลิกทั้งใบแล้ว ระบบได้กลับรายการ Stock, COGS และ GL ตามเอกสารรับคืน/ลดหนี้@if($sale->cancellation_return_id) <a class="alert-link" href="{{ route('pos.sales-returns.show', $sale->cancellation_return_id) }}">ดูเอกสารรับคืน/ลดหนี้</a>@endif</div>
    @else
        <div class="alert alert-success">เอกสารฉบับนี้ลงบัญชีแล้ว</div>
    @endif
    @if ($sale->document_type === 'IV' && $hasPostedReceipt)
        <div class="alert alert-warning">ไม่สามารถยกเลิก IV ได้ เพราะมีการรับชำระหนี้แล้ว กรุณายกเลิกเอกสารรับชำระหนี้ก่อน</div>
    @endif
    @if ($flowDocuments)
        @include('Pos::partials.document-trail', ['flowDocuments' => $flowDocuments])
    @endif
    @include('Pos::partials.sales-document-header', ['document' => $sale, 'sourceIntake' => $sourceIntake])
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4">@if(auth()->user()->hasPermission('accounting.journal-entries.view') && $allJournalUrls->isNotEmpty())<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-0">รายการลงบัญชี</h2><small class="text-secondary">ดู Debit / Credit โดยไม่ต้องออกจากเอกสาร</small></div><div class="d-flex flex-wrap gap-2">@if($allJournalUrls->count() > 1)<button class="btn btn-primary d-inline-flex align-items-center gap-1" type="button" data-journal-preview-urls='@json($allJournalUrls)'><i class="bx bx-book-open"></i>ดู GL ทั้งรายการ</button>@endif @if($sale->journal_entry_id)<button class="btn btn-app-soft d-inline-flex align-items-center gap-1" type="button" data-journal-preview-url="{{ route('accounting.journal-preview.show', $sale->journal_entry_id) }}"><i class="bx bx-book-open"></i>GL รายได้</button>@endif @if($sale->cogs_journal_entry_id)<button class="btn btn-app-soft d-inline-flex align-items-center gap-1" type="button" data-journal-preview-url="{{ route('accounting.journal-preview.show', $sale->cogs_journal_entry_id) }}"><i class="bx bx-package"></i>GL ต้นทุน</button>@endif</div></div>@endif<div class="row g-3"><div class="col-md-4"><div class="text-secondary small">วันที่ลงบัญชี</div><div>{{ $sale->posting_date?->format($dateFormat) ?: '—' }}</div></div><div class="col-md-4"><div class="text-secondary small">สถานะ</div><div><span class="badge {{ $statusBadges[$sale->status] ?? 'app-badge-soft' }}">{{ $statusLabels[$sale->status] ?? $sale->status }}</span></div></div><div class="col-md-4"><div class="text-secondary small">เอกสารต้นทาง</div><div>@if($source)<a href="{{ route('pos.sales-orders.show', $source) }}">ใบสั่งขาย {{ $source->document_number }}</a>@else<span>{{ $sale->source_type }} #{{ $sale->source_id }}</span>@endif</div></div></div></div></div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="d-flex justify-content-between"><h2 class="h5">รายการสินค้า</h2><strong>{{ number_format((float) $sale->total_amount, 2) }}</strong></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>#</th><th>สินค้า</th><th>หน่วย</th><th class="text-end">จำนวน</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">รวม</th></tr></thead><tbody>@forelse($sale->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->item_snapshot['code'] ?? $line->item?->code }} · {{ $line->item_snapshot['name'] ?? $line->item?->name }}</td><td>{{ $line->saleUom?->code ?? '—' }}</td><td class="text-end">{{ number_format((float) $line->quantity, 4) }}</td><td class="text-end">{{ number_format((float) $line->unit_price, 2) }}</td><td class="text-end">{{ number_format((float) $line->line_total, 2) }}</td></tr>@empty<tr><td colspan="6" class="text-center text-secondary">ไม่มีรายการสินค้า</td></tr>@endforelse</tbody></table></div><div class="d-flex justify-content-end mt-4"><section class="col-sm-8 col-md-6 col-lg-5 col-xl-4" aria-label="สรุปยอด"><div class="bg-body-tertiary rounded-3 p-3 p-md-4"><dl class="mb-0"><div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-secondary">ยอดก่อนส่วนลด</dt><dd class="fw-semibold">{{ number_format((float) $sale->subtotal, 2) }}</dd></div><div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-secondary">ส่วนลด</dt><dd class="fw-semibold">{{ number_format((float) $sale->discount_amount, 2) }}</dd></div><div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-secondary">ฐานภาษี</dt><dd class="fw-semibold">{{ number_format((float) $sale->tax_base, 2) }}</dd></div><div class="d-flex justify-content-between"><dt class="fw-normal text-secondary">ภาษี</dt><dd class="fw-semibold">{{ number_format((float) $sale->tax_amount, 2) }}</dd></div></dl><div class="border-top mt-3 pt-3 d-flex justify-content-between align-items-center"><span class="fs-5">Grand Total</span><strong class="fs-3">{{ number_format((float) $sale->total_amount, 2) }}</strong></div>@if($sale->advanceDepositApplications->whereNull('reversed_at')->isNotEmpty())@php($advanceDepositTotal = (float) $sale->advanceDepositApplications->whereNull('reversed_at')->sum('amount'))<div class="border-top mt-3 pt-3"><div class="d-flex justify-content-between align-items-center mb-2"><span class="fw-semibold">ใช้เงินรับล่วงหน้า</span><strong>{{ number_format($advanceDepositTotal, 2) }}</strong></div>@foreach($sale->advanceDepositApplications->whereNull('reversed_at') as $application)<div class="d-flex justify-content-between small text-secondary"><span>{{ $application->advanceDeposit?->document_number ?: 'ใบรับเงินล่วงหน้า' }}</span><span>{{ number_format((float) $application->amount, 2) }}</span></div>@endforeach<div class="border-top mt-3 pt-3 d-flex justify-content-between"><span>ยอดรับผ่านเงินสด/ธนาคาร</span><strong>{{ number_format(max(0, (float) $sale->total_amount - (float) $sale->withholding_amount - $advanceDepositTotal), 2) }}</strong></div></div>@endif</div></section></div></div></div>
    @if ($sale->status === 'POSTED')
        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="h5 mb-1">รายละเอียดการรับชำระเงิน</h2><p class="text-secondary small mb-0">{{ $sale->document_type === 'HS' ? 'ช่องทางรับเงินจริงที่บันทึกพร้อมใบขาย' : 'แสดงรายการรับเงินที่ตัดยอดกับใบขายฉบับนี้' }}</p></div><span class="badge app-badge-success">{{ $sale->document_type === 'HS' ? $sale->tenders->count() : $receipts->count() }} รายการ</span></div>@if($sale->document_type === 'HS')<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>วันที่รับ</th><th>ช่องทางรับเงิน</th><th>เลขอ้างอิง</th><th class="text-end">ยอดรับจริง</th></tr></thead><tbody>@forelse($sale->tenders as $tender)<tr><td>{{ $sale->posting_date?->format($dateFormat) ?: '—' }}</td><td>{{ $tender->bankAccount?->code ?? '—' }} · {{ $tender->bankAccount?->name ?? '—' }}</td><td>{{ $tender->reference ?: '—' }}</td><td class="text-end fw-semibold">{{ number_format((float) $tender->amount, 2) }}</td></tr>@empty<tr><td colspan="4" class="text-center text-secondary py-4">ไม่มีเงินรับสำหรับใบขายมูลค่า 0.00</td></tr>@endforelse</tbody></table></div>@else<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>ใบรับเงิน</th><th>วันที่รับ</th><th>ช่องทางรับเงิน</th><th class="text-end">ยอดตัดเอกสาร</th><th class="text-end">หัก ณ ที่จ่าย</th><th class="text-end">เงินรับจริง</th><th>สถานะ</th></tr></thead><tbody>@forelse($receipts as $receipt)<tr><td>{{ $receipt->document_number }}</td><td>{{ $receipt->settlement_date?->format($dateFormat) ?: '—' }}</td><td>@forelse($receipt->tenders as $tender)<div>{{ $tender->bankAccount?->code ?? '—' }} · {{ $tender->bankAccount?->name ?? '—' }} <span class="text-secondary">{{ $tender->reference ? '· '.$tender->reference : '' }}</span></div>@empty<span class="text-secondary">—</span>@endforelse</td><td class="text-end">{{ number_format((float) $receipt->allocationIntents->where('open_item_id', $saleOpenItem?->id)->sum('amount'), 2) }}</td><td class="text-end">{{ number_format((float) $receipt->withholding_amount, 2) }}</td><td class="text-end fw-semibold">{{ number_format((float) $receipt->net_amount, 2) }}</td><td><span class="badge {{ $receipt->status === 'POSTED' ? 'app-badge-success' : ($receipt->status === 'VOID' ? 'text-bg-danger' : 'app-badge-soft') }}">{{ ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก'][$receipt->status] ?? $receipt->status }}</span></td></tr>@empty<tr><td colspan="7" class="text-center text-secondary py-4">ยังไม่มีรายการรับชำระเงิน</td></tr>@endforelse</tbody></table></div>@endif</div></div>
    @endif
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5">ประวัติเอกสาร</h2>@forelse($history as $event)<div class="border-bottom py-2"><span class="text-secondary">{{ $event->created_at?->format($dateFormat.' H:i') }}</span> <strong>{{ ['pos.physical-sale.created'=>'สร้างใบขาย'][$event->action] ?? $event->action }}</strong> {{ $event->user?->name ?? 'ระบบ' }}</div>@empty<p class="text-secondary">ยังไม่มีประวัติ</p>@endforelse</div></div>
</div>
<div class="modal fade" id="physical-sale-cancel-full-modal" tabindex="-1" aria-labelledby="physical-sale-cancel-full-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="physical-sale-cancel-full-form" method="post" action="{{ route('pos.physical-sales.cancel-full', $sale) }}" novalidate>
                @csrf
                <div class="modal-header"><div><p class="app-eyebrow mb-1">POS / FULL CANCELLATION</p><h2 class="modal-title h4 mb-0" id="physical-sale-cancel-full-title">ยืนยันยกเลิกทั้งใบ</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
                <div class="modal-body">
                    <div class="alert alert-danger mb-4"><strong>การดำเนินการนี้ย้อนกลับไม่ได้</strong><br>ระบบจะสร้างเอกสารรับคืนสินค้าและใบลดหนี้เต็มจำนวน พร้อมกลับ Stock, COGS และ GL ของ {{ $sale->document_number }} โดยเก็บ audit trail ไว้ครบถ้วน</div>
                    <section class="border rounded-3 p-3 mb-4" aria-label="สรุปผลกระทบทางภาษีและการชำระเงิน">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><div><h3 class="h6 mb-1">สรุปรายการที่จะกลับ</h3><p class="small text-secondary mb-0">อ้างอิงข้อมูลที่บันทึกไว้ใน {{ $sale->document_number }}</p></div><strong>{{ number_format((float) $sale->total_amount, 2) }}</strong></div>
                        <div class="row g-3 small">
                            <div class="col-md-6"><div class="bg-body-tertiary rounded-3 p-3 h-100"><div class="text-secondary mb-1">ภาษีขาย</div><div class="fw-semibold">{{ $fullCancellationHasVat ? 'VAT '.number_format((float) $sale->tax_amount, 2) : 'ไม่มีภาษี' }}</div><div class="text-secondary mt-1">{{ $fullCancellationHasVat ? 'ออกใบลดหนี้และกลับรายการภาษีขายเต็มจำนวน' : 'ไม่มีรายการภาษีขายให้กลับ' }}</div></div></div>
                            <div class="col-md-6"><div class="bg-body-tertiary rounded-3 p-3 h-100"><div class="text-secondary mb-1">ภาษีหัก ณ ที่จ่าย</div><div class="fw-semibold">{{ $fullCancellationHasWht ? number_format((float) $sale->withholding_amount, 2) : 'ไม่หัก ณ ที่จ่าย' }}</div><div class="text-secondary mt-1">{{ $fullCancellationHasWht ? 'กลับสิทธิ์ภาษีหัก ณ ที่จ่ายพร้อมรายการบัญชี' : 'ไม่มีสิทธิ์ภาษีหัก ณ ที่จ่ายให้กลับ' }}</div></div></div>
                        </div>
                    </section>
                    <div class="alert alert-info small mb-4"><strong>เงื่อนไขก่อนยืนยัน</strong><br>วันที่กลับรายการต้องไม่ก่อนวันที่ Post และอยู่ในงวดบัญชีเปิด; เอกสารต้องยังไม่มีใบรับคืนที่ลงบัญชีแล้ว; ระบบจะตรวจสอบความพร้อมของการกลับ VAT, หัก ณ ที่จ่าย และรายการรับชำระเงินอีกครั้งก่อนบันทึก</div>
                    <div class="invalid-feedback d-block mb-3" data-error-for="physical_sale" role="alert"></div>
                    <div class="row g-3"><div class="col-md-4"><label class="form-label" for="cancel-full-reversal-date">วันที่กลับรายการ</label><input class="form-control" id="cancel-full-reversal-date" type="date" name="reversal_date" value="{{ today()->format('Y-m-d') }}" required><div class="form-text">ต้องอยู่ในงวดบัญชีที่เปิดอยู่</div><div class="invalid-feedback" data-error-for="reversal_date"></div></div><div class="col-md-8"><label class="form-label" for="cancel-full-reason">เหตุผล <span class="text-danger">*</span></label><textarea class="form-control" id="cancel-full-reason" name="reason" rows="3" minlength="10" maxlength="1000" required placeholder="ระบุเหตุผลอย่างน้อย 10 ตัวอักษร"></textarea><div class="form-text">ต้องระบุอย่างน้อย 10 ตัวอักษร เพื่อใช้เป็น audit trail</div><div class="invalid-feedback" data-error-for="reason"></div></div></div>
                </div>
                <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">กลับ</button><button class="btn btn-danger" id="cancel-full-submit" type="submit">ยืนยันยกเลิกและกลับรายการ</button></div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="physical-sale-post-modal" tabindex="-1" aria-labelledby="physical-sale-post-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-height: calc(100vh - 2rem);">
        <div class="modal-content d-flex" style="max-height: calc(100vh - 2rem);">
            <form class="d-flex flex-column flex-grow-1" id="physical-sale-post-form" method="post" action="{{ route('pos.physical-sales.post', $sale) }}" novalidate style="min-height: 0;">
                @csrf
                <div class="modal-header"><div><p class="eyebrow mb-1">POS / POST SALE</p><h2 class="modal-title h4 mb-0" id="physical-sale-post-title">{{ $sale->document_type === 'HS' ? 'ยืนยันขายและรับชำระเงิน' : 'ยืนยันขาย' }}</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
                <div class="modal-body flex-grow-1" style="min-height: 0; overflow-y: auto; overscroll-behavior: contain;">
                    @php($withholdingMaximumBase = max(0, (float) $sale->tax_base))
                    <div class="row g-3 mb-4"><div class="col-md-4"><label class="form-label" for="post-date">วันที่ Post</label><input class="form-control" id="post-date" type="date" name="posting_date" value="{{ today()->format('Y-m-d') }}" required><div class="invalid-feedback" data-error-for="posting_date"></div></div><div class="col-md-8"><div class="border rounded-3 p-3 h-100 bg-body-tertiary"><div class="row text-center"><div class="col-6 col-xl-3"><div class="small text-secondary">ยอดก่อนภาษี</div><strong>{{ number_format($withholdingMaximumBase, 2) }}</strong></div><div class="col-6 col-xl-3"><div class="small text-secondary">รวมทั้งสิ้น</div><strong>{{ number_format((float) $sale->total_amount, 2) }}</strong></div><div class="col-6 col-xl-3"><div class="small text-secondary">หัก ณ ที่จ่าย</div><strong id="post-wht-amount">{{ number_format((float) $sale->withholding_amount, 2) }}</strong></div><div class="col-6 col-xl-3"><div class="small text-secondary">รับสุทธิ</div><strong id="post-net-amount">{{ number_format((float) $sale->total_amount - (float) $sale->withholding_amount, 2) }}</strong></div></div></div></div></div>
                    <section class="border rounded-3 p-3 mb-4"><h3 class="h6 mb-3">ภาษีหัก ณ ที่จ่าย</h3><div class="row g-3 align-items-end"><div class="col-md-5"><label class="form-label" for="post-wht-code">ผู้ซื้อหักภาษี ณ ที่จ่ายหรือไม่</label><select class="form-select" id="post-wht-code" name="withholding_tax_code_id"><option value="">ไม่หัก ณ ที่จ่าย</option>@foreach($whtTaxCodes as $code)<option value="{{ $code->id }}" data-rate="{{ $code->rate }}" @selected((int) $sale->withholding_tax_code_id === $code->id)>{{ $code->code }} · {{ $code->name }} ({{ $code->rate }}%)</option>@endforeach</select></div><div class="col-md-4"><label class="form-label" for="post-wht-base">ฐานหัก ณ ที่จ่าย</label><input class="form-control text-end" id="post-wht-base" type="number" name="withholding_base" min="0" max="{{ $withholdingMaximumBase }}" step="0.01" value="{{ $sale->withholding_base }}"><div class="form-text">ใช้ยอดก่อนภาษีได้สูงสุด {{ number_format($withholdingMaximumBase, 2) }}</div><div class="invalid-feedback" data-error-for="withholding_base"></div></div><div class="col-md-3"><div class="form-label">ยอดหัก ณ ที่จ่าย</div><div class="form-control-plaintext text-end fw-semibold" id="post-wht-calculated" data-raw="{{ (float) $sale->withholding_amount }}">{{ number_format((float) $sale->withholding_amount, 2) }}</div></div></div></section>
                    @if($sale->document_type === 'HS' && (float) $sale->total_amount > 0)
                        <section class="border rounded-3 p-3 mb-4"><h3 class="h6 mb-1">ใช้เงินรับล่วงหน้า</h3><p class="text-secondary small mb-3">ระบบจะแสดงเฉพาะใบรับเงินล่วงหน้าที่ใช้กับใบขายนี้ได้ โดยตรวจสอบสิทธิ์จากเซิร์ฟเวอร์</p><div class="border rounded-3 p-2" id="post-eligible-advance-deposits" data-url="{{ route('pos.physical-sales.eligible-advance-deposits', $sale) }}"><span class="text-secondary small">กำลังโหลดใบรับเงินล่วงหน้า…</span></div><div class="table-responsive mt-3 d-none" id="post-advance-allocation-wrap"><table class="table align-middle mb-0"><thead><tr><th>ใบรับเงินล่วงหน้า</th><th class="text-end">คงเหลือ</th><th class="text-end">ยอดนำมาใช้</th></tr></thead><tbody id="post-advance-allocation-rows"></tbody></table></div><div class="d-flex justify-content-end mt-2"><strong>ใช้เงินรับล่วงหน้ารวม <span id="post-advance-total">0.00</span></strong></div><div class="form-text">ไม่เลือกหากไม่ใช้เงินรับล่วงหน้า; ระบบตรวจสอบยอดและสิทธิ์อีกครั้งก่อน Post</div></section>
                        <section><div class="d-flex justify-content-between align-items-center mb-3"><div><h3 class="h6 mb-1">ช่องทางรับเงิน</h3><p class="text-secondary small mb-0">ระบุได้หลายบัญชี โดยยอดรับจริงต้องไม่น้อยกว่ายอดสุทธิ</p></div><button class="btn btn-outline-primary" id="add-post-tender" type="button"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มช่องทาง</button></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>บัญชีเงินสด/ธนาคาร</th><th class="text-end">จำนวนเงิน</th><th>เลขอ้างอิง</th><th></th></tr></thead><tbody id="post-tender-rows"></tbody></table></div><div class="invalid-feedback d-block" data-error-for="tenders"></div><div class="d-flex justify-content-end"><strong>ยอดรับจริง <span id="post-received-total">0.00</span></strong></div><div class="alert alert-warning mt-3 mb-0 d-none" id="post-overpayment-note">ยอดรับเกินจะบันทึกเป็นเงินรับล่วงหน้าของลูกค้ารายนี้</div></section>
                    @endif
                </div>
                <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">กลับ</button><button class="btn btn-primary" id="post-submit" type="submit">{{ $sale->document_type === 'HS' ? 'ยืนยันขายและรับเงิน' : 'ยืนยันขาย' }}</button></div>
            </form>
        </div>
    </div>
</div>
@if($sale->document_type === 'HS')<template id="post-tender-template"><tr><td><select class="form-select js-tender-account" name="tenders[__INDEX__][bank_account_id]" required><option value="">เลือกบัญชีเงินสด/ธนาคาร</option>@foreach($hsBankAccounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></td><td><input class="form-control text-end js-tender-amount" type="number" name="tenders[__INDEX__][amount]" min="0.01" step="0.01" required></td><td><input class="form-control" name="tenders[__INDEX__][reference]" maxlength="100" placeholder="เช่น เลขสลิป"></td><td class="text-end"><button class="btn btn-outline-danger js-remove-post-tender" type="button" aria-label="ลบช่องทาง"><i class="bx bx-trash" aria-hidden="true"></i></button></td></tr></template>@endif
@endsection
@push('scripts')
<script>
$(function () {
    const total = {{ Js::from((float) $sale->total_amount) }}, money = value => Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const $modal = $('#physical-sale-post-modal'), $form = $('#physical-sale-post-form'), $whtCode = $('#post-wht-code'), $whtBase = $('#post-wht-base'), $rows = $('#post-tender-rows'), $eligibleAdvances = $('#post-eligible-advance-deposits'), $advanceRows = $('#post-advance-allocation-rows');
    let tenderIndex = 0;
    function netAmount() { return total - (Number($('#post-wht-calculated').data('raw')) || 0); }
    function advanceTotal() { return $advanceRows.find('.js-advance-allocation-amount').toArray().reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0); }
    function cashDue() { return Math.max(0, netAmount() - advanceTotal()); }
    function syncAdvances() {
        let availableDue = netAmount();
        $advanceRows.find('.js-advance-allocation-amount').each(function () { const maximum = Math.min(Number($(this).data('remaining') || 0), availableDue); let amount = Math.max(0, parseFloat(this.value) || 0); if (amount > maximum) { amount = maximum; this.value = amount.toFixed(2); } $(this).attr('max', maximum.toFixed(2)); availableDue -= amount; });
        $('#post-advance-total').text(money(advanceTotal()));
        const automaticTender = $rows.children('tr').first();
        if (automaticTender.data('auto-default')) automaticTender.find('.js-tender-amount').val(cashDue().toFixed(2));
        syncTenders();
    }
    function syncWht() {
        const rate = Number($whtCode.find(':selected').data('rate') || 0), enabled = !!$whtCode.val();
        $whtBase.prop('disabled', !enabled);
        if (!enabled) $whtBase.val('0.00');
        const amount = enabled ? Number((Number($whtBase.val() || 0) * rate / 100).toFixed(2)) : 0;
        $('#post-wht-calculated, #post-wht-amount').text(money(amount));
        $('#post-wht-calculated').data('raw', amount);
        $('#post-net-amount').text(money(netAmount()));
        syncTenders();
    }
    function syncTenders() {
        const received = $rows.find('.js-tender-amount').toArray().reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
        $('#post-received-total').text(money(received));
        $('#post-overpayment-note').toggleClass('d-none', received <= cashDue() + 0.00001);
    }
    function addTender(amount) {
        const row = $($('#post-tender-template').html().replaceAll('__INDEX__', tenderIndex++));
        if (amount !== undefined) { row.find('.js-tender-amount').val(amount.toFixed(2)); row.data('auto-default', true); }
        $rows.append(row);
    }
    $('.js-post-physical-sale').on('click', function () {
        $form.find('.is-invalid').removeClass('is-invalid'); $form.find('[data-error-for]').text('');
        if ($eligibleAdvances.length && !$eligibleAdvances.data('loaded')) $.getJSON($eligibleAdvances.data('url')).done(data => { const options = data.results || []; $eligibleAdvances.empty(); if (!options.length) $eligibleAdvances.append('<span class="text-secondary small">ไม่มีใบรับเงินล่วงหน้าที่ใช้กับใบขายนี้ได้</span>'); options.forEach(option => $eligibleAdvances.append(`<label class="form-check d-flex align-items-center gap-2 py-1 mb-0"><input class="form-check-input js-eligible-advance" type="checkbox" value="${option.id}" data-remaining="${option.remaining_amount || option.remaining || 0}"><span>${$('<div>').text(option.text).html()}</span></label>`)); $eligibleAdvances.data('loaded', true); }).fail(() => $eligibleAdvances.html('<span class="text-danger small">ไม่สามารถโหลดใบรับเงินล่วงหน้าได้</span>'));
        syncWht();
        if ($rows.length && !$rows.children().length) addTender(cashDue());
        syncAdvances();
        bootstrap.Modal.getOrCreateInstance($modal[0]).show();
    });
    $whtCode.on('change', syncWht); $whtBase.on('input', syncWht);
    $('#add-post-tender').on('click', () => addTender());
    $eligibleAdvances.on('change', '.js-eligible-advance', function () { $advanceRows.empty(); let index = 0; $eligibleAdvances.find('.js-eligible-advance:checked').each(function () { const remaining = Number($(this).data('remaining') || 0), label = $(this).siblings('span').text(); $advanceRows.append(`<tr><td>${$('<div>').text(label).html()}<input type="hidden" name="advance_allocations[${index}][advance_deposit_id]" value="${this.value}"></td><td class="text-end">${money(remaining)}</td><td><input class="form-control text-end js-advance-allocation-amount" type="number" name="advance_allocations[${index}][amount]" min="0.01" step="0.01" data-remaining="${remaining}" value="${Math.min(remaining, Math.max(0, netAmount() - advanceTotal())).toFixed(2)}" required></td></tr>`); index++; }); $('#post-advance-allocation-wrap').toggleClass('d-none', !$advanceRows.children().length); syncAdvances(); });
    $advanceRows.on('input', '.js-advance-allocation-amount', syncAdvances);
    $rows.on('input', '.js-tender-amount', function () { $(this).closest('tr').data('auto-default', false); syncTenders(); }).on('click', '.js-remove-post-tender', function () { $(this).closest('tr').remove(); syncTenders(); });
    $form.on('submit', function (event) {
        event.preventDefault();
        const submit = $('#post-submit'); if (submit.data('submitting')) return;
        $rows.children('tr').filter(function () { return !$(this).find('.js-tender-account').val() && !$(this).find('.js-tender-amount').val() && !$(this).find('[name$="[reference]"]').val(); }).remove();
        const received = $rows.find('.js-tender-amount').toArray().reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0), errors = [];
        if (!$form.find('[name="posting_date"]').val()) errors.push('วันที่ Post');
        if ($rows.length && $rows.find('.js-tender-account').filter(function () { return !this.value; }).length) errors.push('บัญชีเงินสด/ธนาคาร');
        if ($rows.length && received + 0.00001 < cashDue()) errors.push('ยอดรับเงินให้ครบ '+money(cashDue()));
        if (errors.length) { Swal.fire({ icon: 'error', text: 'กรุณาระบุ' + errors.join(' และ ') }); return; }
        submit.data('submitting', 1).prop('disabled', true);
        $.post($form.attr('action'), $form.serialize()).done(response => { bootstrap.Modal.getInstance($modal[0]).hide(); Swal.fire({ icon: 'success', text: response.msg }).then(() => location.reload()); }).fail(xhr => { const errors = xhr.responseJSON?.errors || {}; const message = Object.values(errors).flat()[0] || xhr.responseJSON?.message || 'ไม่สามารถยืนยันขายได้'; Swal.fire({ icon: 'error', text: message }); }).always(() => submit.data('submitting', 0).prop('disabled', false));
    });
    $('.js-void-sale').on('click', function () { const button = $(this); Swal.fire({ icon: 'warning', title: 'ยกเลิก HS/IV', input: 'textarea', inputLabel: 'เหตุผล (อย่างน้อย 10 ตัวอักษร)', showCancelButton: true, confirmButtonText: 'ยืนยันยกเลิก', cancelButtonText: 'กลับ', preConfirm: value => { if (!value || value.trim().length < 10) Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร'); return value; } }).then(result => { if (!result.isConfirmed) return; button.prop('disabled', true); $.post(button.data('url'), { _token: $('meta[name="csrf-token"]').attr('content'), reason: result.value }).done(response => Swal.fire({ icon: 'success', text: response.msg }).then(() => location.reload())).fail(xhr => { button.prop('disabled', false); Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || xhr.responseText || 'ไม่สามารถยกเลิกได้' }); }); }); });
    const $cancelFullModal = $('#physical-sale-cancel-full-modal'), $cancelFullForm = $('#physical-sale-cancel-full-form');
    $('.js-cancel-full-sale').on('click', function () { $cancelFullForm.find('.is-invalid').removeClass('is-invalid'); $cancelFullForm.find('[data-error-for]').text(''); bootstrap.Modal.getOrCreateInstance($cancelFullModal[0]).show(); });
    $cancelFullForm.on('submit', function (event) { event.preventDefault(); const submit = $('#cancel-full-submit'); if (submit.data('submitting')) return; const reason = $('#cancel-full-reason').val().trim(), reversalDate = $('#cancel-full-reversal-date').val(); if (!reversalDate || reason.length < 10) { Swal.fire({ icon: 'error', text: 'กรุณาระบุวันที่กลับรายการและเหตุผลอย่างน้อย 10 ตัวอักษร' }); return; } submit.data('submitting', 1).prop('disabled', true); $.post($cancelFullForm.attr('action'), $cancelFullForm.serialize()).done(response => { bootstrap.Modal.getInstance($cancelFullModal[0]).hide(); Swal.fire({ icon: 'success', text: response.msg }).then(() => location.reload()); }).fail(xhr => { const errors = xhr.responseJSON?.errors || {}; Object.entries(errors).forEach(([field, messages]) => { const input = $cancelFullForm.find(`[name="${field}"]`); input.addClass('is-invalid'); $cancelFullForm.find(`[data-error-for="${field}"]`).text(messages[0]); }); Swal.fire({ icon: 'error', text: Object.values(errors).flat()[0] || xhr.responseJSON?.message || 'ไม่สามารถยกเลิกทั้งใบได้' }); }).always(() => submit.data('submitting', 0).prop('disabled', false)); });
});
</script>
@endpush
