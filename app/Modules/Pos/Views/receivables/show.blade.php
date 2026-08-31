@extends('Pos::layout')

@section('title', 'Statement ลูกหนี้ | POS')

@section('content')
@php($isPaid = (float) $remainingAmount <= 0)
@php($statusClass = $isPaid ? 'app-badge-success' : ((float) $receiptAmount + (float) $creditNoteAmount > 0 ? 'app-badge-info' : 'app-badge-soft'))
@php($statusLabel = $isPaid ? 'ชำระครบ' : ((float) $receiptAmount + (float) $creditNoteAmount > 0 ? 'ชำระบางส่วน' : 'ยังไม่ชำระ'))
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><p class="eyebrow mb-2">SALES / RECEIVABLES / STATEMENT</p><h1 class="h3 mb-1">Statement ลูกหนี้</h1><p class="text-secondary mb-0">{{ $openItem->document_number }} · {{ $openItem->party?->code }} · {{ $openItem->party?->name }}</p></div>
        <div class="d-flex flex-wrap justify-content-end gap-2">
            @if((float) $remainingAmount > 0 && auth()->user()->hasPermission('pos.receipts.create'))<a class="btn btn-success" href="{{ route('pos.physical-sales.receive-payment.create', $sale->id) }}"><i class="bx bx-money me-1"></i>รับชำระหนี้</a>@endif
            @if(auth()->user()->hasPermission('pos.physical-sales.view'))<a class="btn btn-app-soft" href="{{ route('pos.physical-sales.show', $sale->id) }}">ดู IV</a>@endif
            <a class="btn btn-outline-secondary" href="{{ route('pos.receivables.index') }}"><i class="bx bx-arrow-back me-1"></i>ย้อนกลับ</a>
        </div>
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="row g-4">
        <div class="col-6 col-lg-3"><div class="text-secondary small">วันที่เอกสาร</div><div class="fw-semibold">{{ $openItem->document_date?->format($dateFormat) }}</div></div>
        <div class="col-6 col-lg-3"><div class="text-secondary small">วันครบกำหนด</div><div class="fw-semibold">{{ $openItem->due_date?->format($dateFormat) ?: '—' }}</div><div class="small {{ $daysOverdue ? 'text-danger' : 'text-secondary' }}">{{ $daysOverdue ? 'ค้างชำระ '.$daysOverdue.' วัน' : 'ยังไม่เกินกำหนด' }}</div></div>
        <div class="col-6 col-lg-3"><div class="text-secondary small">สถานะ</div><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></div>
        <div class="col-6 col-lg-3"><div class="text-secondary small">บัญชี GL</div><div class="fw-semibold">{{ $openItem->account?->code }} · {{ $openItem->account?->name }}</div></div>
    </div></div></div>
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-secondary small">ยอดตั้งหนี้</div><strong class="fs-5">{{ number_format((float) $openItem->original_amount, 2) }}</strong></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-secondary small">รับชำระแล้ว</div><strong class="fs-5">{{ number_format((float) $receiptAmount, 2) }}</strong></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-secondary small">เงินรับล่วงหน้า / ลดหนี้</div><strong class="fs-5">{{ number_format((float) $activeAdvances->sum('amount') + (float) $creditNoteAmount, 2) }}</strong></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-secondary small">คงเหลือ</div><strong class="fs-5">{{ number_format((float) $remainingAmount, 2) }}</strong></div></div></div>
    </div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">ประวัติรับชำระและใบลดหนี้</h2><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>วันที่</th><th>เอกสาร</th><th>ประเภท</th><th class="text-end">ยอดตัด</th><th>สถานะ</th></tr></thead><tbody>@forelse($allocations as $allocation)@php($receiptId = preg_match('/^settlement:(\d+):intent:/', $allocation->source_id, $match) ? (int) $match[1] : null)@php($receipt = $receiptId ? $receipts->get($receiptId) : null)@php($isReversed = $allocation->reversal_date && ! $allocation->reversal_date->isAfter(today()))<tr><td>{{ $allocation->allocation_date?->format($dateFormat) }}</td><td>@if($receipt && auth()->user()->hasPermission('pos.receipts.view'))<a href="{{ route('pos.receipts.show', $receipt) }}">{{ $receipt->document_number }}</a>@else{{ $receipt?->document_number ?: ($allocation->creditOpenItem?->document_number ?: '—') }}@endif</td><td>{{ $receipt ? 'รับชำระหนี้' : 'ใบลดหนี้' }}</td><td class="text-end">{{ number_format((float) $allocation->amount, 2) }}</td><td><span class="badge {{ $isReversed ? 'text-bg-danger' : 'app-badge-success' }}">{{ $isReversed ? 'กลับรายการแล้ว' : 'ใช้งานอยู่' }}</span></td></tr>@empty<tr><td colspan="5" class="text-center text-secondary py-4">ยังไม่มีรายการตัดยอด</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5 mb-3">ประวัติใช้เงินรับล่วงหน้า</h2><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>วันที่</th><th>ใบรับเงินล่วงหน้า</th><th class="text-end">ยอดตัด</th><th>สถานะ</th></tr></thead><tbody>@forelse($advanceApplications as $application)@php($isReversed = $application->reversal_date && ! $application->reversal_date->isAfter(today()))<tr><td>{{ $application->application_date?->format($dateFormat) }}</td><td>{{ $application->advanceDeposit?->document_number ?: '—' }}</td><td class="text-end">{{ number_format((float) $application->amount, 2) }}</td><td><span class="badge {{ $isReversed ? 'text-bg-danger' : 'app-badge-success' }}">{{ $isReversed ? 'กลับรายการแล้ว' : 'ใช้งานอยู่' }}</span></td></tr>@empty<tr><td colspan="4" class="text-center text-secondary py-4">ยังไม่มีการใช้เงินรับล่วงหน้า</td></tr>@endforelse</tbody></table></div></div></div>
</div>
@endsection
