@extends('Finance::layout')

@section('title', 'รายละเอียดรายการคงค้าง | Finance')

@section('content')
    @php
        $isAr = $ledgerType === 'AR';
        $outstanding = (float) $openItem->original_amount - (float) $allocatedAmount;
        $status = $allocatedAmount <= 0 ? 'OPEN' : ($outstanding <= 0 ? 'CLOSED' : 'PARTIAL');
        $statusLabel = ['OPEN' => 'เปิดคงค้าง', 'PARTIAL' => 'จัดสรรบางส่วน', 'CLOSED' => 'ปิดแล้ว'][$status];
    @endphp
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div><p class="eyebrow mb-2">FINANCE / {{ $ledgerType }} / OPEN ITEM</p><h1 class="h3 mb-2">รายละเอียดรายการคงค้าง</h1><p class="text-secondary mb-0">{{ $openItem->document_number }} · {{ $isAr ? 'ลูกหนี้' : 'เจ้าหนี้' }}</p></div>
            <a class="btn btn-outline-dark" href="{{ url()->previous() }}"><i class="bx bx-arrow-back me-1"></i>กลับรายการ</a>
        </div>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="row g-4">
            <div class="col-6 col-lg-3"><div class="text-secondary small">เอกสาร</div><div class="fw-semibold">{{ $openItem->document_number }}</div><div class="small text-secondary">{{ $openItem->document_type }}</div></div>
            <div class="col-6 col-lg-3"><div class="text-secondary small">คู่ค้า</div><div class="fw-semibold">{{ $openItem->party?->code }} · {{ $openItem->party?->name }}</div></div>
            <div class="col-6 col-lg-3"><div class="text-secondary small">วันที่เอกสาร / ครบกำหนด</div><div>{{ optional($openItem->document_date)->format($dateFormat) }} / {{ $openItem->due_date ? $openItem->due_date->format($dateFormat) : '—' }}</div></div>
            <div class="col-6 col-lg-3"><div class="text-secondary small">สถานะ</div><span class="badge text-bg-info">{{ $statusLabel }}</span></div>
            <div class="col-6 col-lg-3"><div class="text-secondary small">ยอดตั้งต้น</div><div class="fw-semibold">{{ number_format((float) $openItem->original_amount, 2) }}</div></div>
            <div class="col-6 col-lg-3"><div class="text-secondary small">จัดสรรแล้ว</div><div>{{ number_format((float) $allocatedAmount, 2) }}</div></div>
            <div class="col-6 col-lg-3"><div class="text-secondary small">คงเหลือ</div><div class="fw-semibold">{{ number_format(max(0, $outstanding), 2) }}</div></div>
            <div class="col-6 col-lg-3"><div class="text-secondary small">บัญชี GL</div><div>{{ $openItem->account?->code }} · {{ $openItem->account?->name }}</div></div>
        </div></div></div>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">รายการจัดสรร AR/AP</h2><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>วันที่</th><th>ทิศทาง</th><th>เอกสารคู่กัน</th><th>แหล่งที่มา</th><th class="text-end">จำนวนเงิน</th><th>สถานะ</th></tr></thead><tbody>
        @forelse($allocationRows as $allocation)
            @php($counterpart = $counterparts[(int) ($allocation->debit_open_item_id == $openItem->id ? $allocation->credit_open_item_id : $allocation->debit_open_item_id)] ?? null)
            <tr><td>{{ $allocation->allocation_date_label }}</td><td>{{ $allocation->debit_open_item_id == $openItem->id ? 'เดบิต → เครดิต' : 'เครดิต → เดบิต' }}</td><td>{{ $counterpart?->document_number ?? '—' }}</td><td>{{ $allocation->source_type }} / {{ $allocation->source_id }}</td><td class="text-end">{{ number_format((float) $allocation->amount, 2) }}</td><td><span class="badge {{ $allocation->reversal_date ? 'text-bg-warning' : 'text-bg-success' }}">{{ $allocation->reversal_date ? 'กลับรายการแล้ว' : 'ใช้งานอยู่' }}</span></td></tr>
        @empty
            <tr><td colspan="6" class="text-center text-secondary py-4">ยังไม่มีการจัดสรร</td></tr>
        @endforelse</tbody></table></div></div></div>
        @if($advanceApplicationRows->isNotEmpty())
            <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">ตัดด้วยเงินล่วงหน้า / เงินมัดจำ</h2><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>วันที่</th><th>อ้างอิง</th><th class="text-end">จำนวนเงิน</th><th>สถานะ</th></tr></thead><tbody>@foreach($advanceApplicationRows as $application)<tr><td>{{ $application->application_date_label }}</td><td>{{ $application->source_type }} / {{ $application->source_id }}</td><td class="text-end">{{ number_format((float) $application->amount, 2) }}</td><td><span class="badge {{ $application->reversal_date ? 'text-bg-warning' : 'text-bg-success' }}">{{ $application->reversal_date ? 'กลับรายการแล้ว' : 'ใช้งานอยู่' }}</span></td></tr>@endforeach</tbody></table></div></div></div>
        @endif
        @if($openItem->journalEntryLine?->entry)
            <div class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5 mb-3">รายการ Journal ที่อ้างอิง</h2><div class="row g-3"><div class="col-md-3"><span class="text-secondary small">เลขที่ Journal</span><div class="fw-semibold">{{ $openItem->journalEntryLine->entry->entry_number }}</div></div><div class="col-md-3"><span class="text-secondary small">วันที่ลงบัญชี</span><div>{{ optional($openItem->journalEntryLine->entry->entry_date)->format($dateFormat) }}</div></div><div class="col-md-3"><span class="text-secondary small">เหตุการณ์</span><div>{{ $openItem->journalEntryLine->entry->source_event }}</div></div><div class="col-md-3"><span class="text-secondary small">สถานะ</span><div>{{ $openItem->journalEntryLine->entry->status }}</div></div></div></div></div>
        @endif
    </div>
@endsection
