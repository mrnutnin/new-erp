@extends('Accounting::layout')

@section('title', $journalEntry->entry_number.' | New ERP')

@section('content')
    @php($statusLabels = ['DRAFT' => 'Draft', 'VALIDATED' => 'รออนุมัติ', 'POSTED' => 'ลงบัญชีแล้ว', 'REVERSED' => 'กลับรายการแล้ว'])
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / GENERAL JOURNAL</p>
                <h1 class="h3 mb-2">{{ $journalEntry->entry_number }}</h1>
                <p class="text-secondary mb-0">{{ $journalEntry->description }}</p>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-dark" href="{{ route('accounting.journal-entries.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
                @if ($journalEntry->status === 'DRAFT' && auth()->user()->hasPermission('accounting.journal-entries.update'))
                    <a class="btn btn-dark" href="{{ route('accounting.journal-entries.edit', $journalEntry) }}"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข Draft</a>
                @endif
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-6 col-lg-3"><div class="small text-secondary">สถานะ</div><span class="badge text-bg-secondary">{{ $statusLabels[$journalEntry->status] ?? $journalEntry->status }}</span></div>
                    <div class="col-6 col-lg-3"><div class="small text-secondary">วันที่ลงบัญชี</div><div class="fw-semibold">{{ $journalEntry->entry_date->format('d/m/Y') }}</div></div>
                    <div class="col-6 col-lg-3"><div class="small text-secondary">สมุดบัญชี</div><div class="fw-semibold">{{ $journalEntry->book->code }} · {{ $journalEntry->book->name }}</div></div>
                    <div class="col-6 col-lg-3"><div class="small text-secondary">งวดบัญชี</div><div class="fw-semibold">{{ $journalEntry->period->fiscalYear->name }} / {{ $journalEntry->period->period_number }}</div></div>
                    <div class="col-6 col-lg-3"><div class="small text-secondary">สาขา / คลัง</div><div>{{ $journalEntry->branch->name }} · {{ $journalEntry->warehouse->name }}</div></div>
                    <div class="col-6 col-lg-3"><div class="small text-secondary">วันที่เอกสาร</div><div>{{ $journalEntry->document_date?->format('d/m/Y') ?? '—' }}</div></div>
                    <div class="col-6 col-lg-3"><div class="small text-secondary">เอกสารอ้างอิง</div><div>{{ $journalEntry->source_reference ?: '—' }}</div></div>
                    <div class="col-6 col-lg-3"><div class="small text-secondary">ผู้บันทึก</div><div>{{ $journalEntry->createdBy?->name ?? '—' }}</div></div>
                    @if ($journalEntry->reversalOf)
                        <div class="col-12"><div class="small text-secondary">กลับจากรายการ</div><a href="{{ route('accounting.journal-entries.show', $journalEntry->reversalOf) }}">{{ $journalEntry->reversalOf->entry_number }}</a></div>
                    @elseif ($journalEntry->reversal)
                        <div class="col-12"><div class="small text-secondary">รายการกลับ</div><a href="{{ route('accounting.journal-entries.show', $journalEntry->reversal) }}">{{ $journalEntry->reversal->entry_number }}</a></div>
                    @endif
                </div>
            </div>
        </div>

        @if ($journalEntry->validated_at || $journalEntry->posted_at || $journalEntry->reversed_at)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">ประวัติอนุมัติ</h2>
                    <div class="row g-3">
                        @if ($journalEntry->validated_at)
                            <div class="col-12 col-lg-4"><div class="small text-secondary">ส่งอนุมัติ</div><div class="fw-semibold">{{ $journalEntry->validatedBy?->name ?? '—' }} · {{ $journalEntry->validated_at->format('d/m/Y H:i') }}</div><div>{{ $journalEntry->validation_reason }}</div></div>
                        @endif
                        @if ($journalEntry->posted_at)
                            <div class="col-12 col-lg-4"><div class="small text-secondary">อนุมัติลงบัญชี</div><div class="fw-semibold">{{ $journalEntry->postedBy?->name ?? '—' }} · {{ $journalEntry->posted_at->format('d/m/Y H:i') }}</div><div>{{ $journalEntry->posting_reason }}</div></div>
                        @endif
                        @if ($journalEntry->reversed_at)
                            <div class="col-12 col-lg-4"><div class="small text-secondary">กลับรายการ</div><div class="fw-semibold">{{ $journalEntry->reversedBy?->name ?? '—' }} · {{ $journalEntry->reversed_at->format('d/m/Y H:i') }}</div><div>{{ $journalEntry->reversal_reason }}</div></div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if ($journalEntry->status === 'DRAFT' && auth()->user()->hasPermission('accounting.journal-entries.submit'))
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h5">ส่งรายการเพื่ออนุมัติ</h2>
                    <form id="submit-journal-form" action="{{ route('accounting.journal-entries.submit', $journalEntry) }}" method="post">
                        @csrf @method('PUT')
                        <label class="form-label" for="submit_reason">เหตุผล</label>
                        <textarea class="form-control" id="submit_reason" name="reason" rows="2" minlength="10" maxlength="500" required></textarea>
                        <div class="invalid-feedback" data-error-for="reason"></div>
                        <div class="invalid-feedback d-block" data-error-for="status"></div>
                        <div class="invalid-feedback d-block" data-error-for="lines"></div>
                        <button class="btn btn-dark mt-3" type="submit" data-busy-text="กำลังส่ง..."><i class="bx bx-send me-1" aria-hidden="true"></i>ส่งอนุมัติ</button>
                    </form>
                </div>
            </div>
        @elseif ($journalEntry->status === 'VALIDATED' && auth()->user()->hasPermission('accounting.journal-entries.approve'))
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h5">อนุมัติและลงบัญชี</h2>
                    <p class="text-secondary">เมื่อลงบัญชีแล้วจะไม่สามารถแก้ไขเอกสารนี้ได้</p>
                    <form id="approve-journal-form" action="{{ route('accounting.journal-entries.approve', $journalEntry) }}" method="post">
                        @csrf @method('PUT')
                        <label class="form-label" for="approve_reason">เหตุผล</label>
                        <textarea class="form-control" id="approve_reason" name="reason" rows="2" minlength="10" maxlength="500" required></textarea>
                        <div class="invalid-feedback" data-error-for="reason"></div>
                        <div class="invalid-feedback d-block" data-error-for="status"></div>
                        <div class="invalid-feedback d-block" data-error-for="lines"></div>
                        <button class="btn btn-dark mt-3" type="submit" data-busy-text="กำลังลงบัญชี..."><i class="bx bx-check me-1" aria-hidden="true"></i>อนุมัติลงบัญชี</button>
                    </form>
                </div>
            </div>
        @elseif ($journalEntry->status === 'POSTED' && auth()->user()->hasPermission('accounting.journal-entries.reverse'))
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h5">กลับรายการ</h2>
                    <p class="text-secondary">ระบบจะสร้างรายการใหม่โดยสลับเดบิต–เครดิต เอกสารเดิมจะยังอยู่ครบ</p>
                    <form id="reverse-journal-form" action="{{ route('accounting.journal-entries.reverse', $journalEntry) }}" method="post">
                        @csrf @method('PUT')
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="reversal_date">วันที่กลับรายการ</label>
                                <input class="form-control" type="date" id="reversal_date" name="reversal_date" value="{{ now()->toDateString() }}" required>
                                <div class="invalid-feedback" data-error-for="reversal_date"></div>
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="form-label" for="reversal_reason">เหตุผล</label>
                                <textarea class="form-control" id="reversal_reason" name="reason" rows="2" minlength="10" maxlength="500" required></textarea>
                                <div class="invalid-feedback" data-error-for="reason"></div>
                            </div>
                        </div>
                        <div class="invalid-feedback d-block" data-error-for="status"></div>
                        <div class="invalid-feedback d-block" data-error-for="lines"></div>
                        <button class="btn btn-outline-danger mt-3" type="submit" data-busy-text="กำลังกลับรายการ..."><i class="bx bx-revision me-1" aria-hidden="true"></i>ยืนยันกลับรายการ</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>#</th><th>บัญชี</th><th>คำอธิบาย</th><th>ภาษี</th><th class="text-end">เดบิต</th><th class="text-end">เครดิต</th></tr></thead>
                        <tbody>
                            @foreach ($journalEntry->lines as $line)
                                <tr>
                                    <td>{{ $line->line_number }}</td>
                                    <td>
                                        {{ $line->account->code }} · {{ $line->account->name }}
                                        @if ($line->subledger_type && $line->subledger_id)
                                            <div class="small text-secondary">{{ $line->subledger_type }} · {{ $line->subledger_id }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $line->description ?: '—' }}</td>
                                    <td>
                                        @if ($line->taxCode)
                                            <span class="badge text-bg-info">{{ $line->taxCode->code }}</span>
                                            <div class="small text-secondary">ฐาน {{ number_format((float) $line->tax_base, 2) }} · ภาษี {{ number_format((float) $line->tax_amount, 2) }}</div>
                                            <div class="small text-secondary">Tax Point {{ $line->tax_point_date?->format('d/m/Y') ?: '—' }} · รับ/จ่าย {{ $line->tax_settlement_date?->format('d/m/Y') ?: '—' }}</div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-end">{{ number_format((float) $line->debit, 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $line->credit, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr class="fw-semibold"><td colspan="4" class="text-end">รวม</td><td class="text-end">{{ number_format((float) $journalEntry->lines->sum('debit'), 2) }}</td><td class="text-end">{{ number_format((float) $journalEntry->lines->sum('credit'), 2) }}</td></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            window.erpAjaxForm({ form: '#submit-journal-form', reload: true });
            window.erpAjaxForm({ form: '#approve-journal-form', reload: true });
            window.erpAjaxForm({ form: '#reverse-journal-form', redirect: true });
        });
    </script>
@endpush
