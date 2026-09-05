@extends('Finance::layout')

@section('title', 'เอกสารเคลียร์เงินสดย่อย | Finance')

@section('content')
    @php($labels = ['DRAFT' => 'ร่าง', 'SUBMITTED' => 'รออนุมัติ', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'REVERSED' => 'ยกเลิกรายการแล้ว', 'VOID' => 'ยกเลิก'])
    @php($classes = ['DRAFT' => 'app-status-neutral', 'SUBMITTED' => 'app-status-info', 'APPROVED' => 'app-status-success', 'POSTED' => 'app-status-success', 'REVERSED' => 'app-status-danger', 'VOID' => 'app-status-danger'])

    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">FINANCE / PETTY CASH</p>
                <h1 class="h3 mb-1">{{ $clearing->document_number ?: 'เคลียร์เงินสดย่อย #'.$clearing->id }}</h1>
                <span class="badge {{ $classes[$clearing->status] ?? 'app-status-neutral' }}">{{ $labels[$clearing->status] ?? $clearing->status }}</span>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="{{ route('finance.petty-cash-clearings.index') }}">กลับ</a>

                @if($clearing->journalEntry && auth()->user()->hasPermission('accounting.journal-entries.view'))
                    <button class="btn btn-app-soft" type="button" data-journal-preview-url="{{ route('accounting.journal-preview.show', $clearing->journalEntry) }}"><i class="bx bx-book-open me-1" aria-hidden="true"></i>ดู GL</button>
                @endif
                @if($clearing->reversalJournalEntry && auth()->user()->hasPermission('accounting.journal-entries.view'))
                    <button class="btn btn-outline-danger" type="button" data-journal-preview-url="{{ route('accounting.journal-preview.show', $clearing->reversalJournalEntry) }}"><i class="bx bx-undo me-1" aria-hidden="true"></i>ดู GL ยกเลิก</button>
                @endif

                @if($clearing->status === 'DRAFT' && auth()->user()->hasPermission('finance.petty-cash-clearings.update'))
                    <a class="btn btn-outline-dark" href="{{ route('finance.petty-cash-clearings.edit', $clearing) }}">แก้ไข</a>
                    <form method="POST" action="{{ route('finance.petty-cash-clearings.destroy', $clearing) }}" class="d-inline" onsubmit="event.preventDefault(); var form=this; Swal.fire({icon:'warning',title:'ลบเอกสาร Draft?',text:'เอกสารจะถูกลบออกจากรายการ',showCancelButton:true,confirmButtonText:'ลบเอกสาร',cancelButtonText:'ยกเลิก',confirmButtonColor:'#dc3545'}).then(function(result){if(!result.isConfirmed)return; $.ajax({url:form.action,method:'DELETE',data:{_token:$('meta[name=csrf-token]').attr('content')},headers:{Accept:'application/json'}}).done(function(response){Swal.fire({icon:'success',text:response.msg||'ลบเอกสารแล้ว'}).then(function(){window.location.href=response.redirect;});}).fail(function(xhr){Swal.fire({icon:'error',text:xhr.responseJSON?.message||'ไม่สามารถลบเอกสารได้'});});});">@csrf @method('DELETE')<button class="btn btn-outline-danger" type="submit">ลบ Draft</button></form>
                @endif

                @if($clearing->status === 'DRAFT' && auth()->user()->hasPermission('finance.petty-cash-clearings.submit'))
                    <button class="btn btn-dark js-action" data-url="{{ route('finance.petty-cash-clearings.submit', $clearing) }}">ส่งอนุมัติ</button>
                @endif

                @if($clearing->status === 'SUBMITTED' && auth()->user()->hasPermission('finance.petty-cash-clearings.approve'))
                    <button class="btn btn-dark js-action" data-url="{{ route('finance.petty-cash-clearings.approve', $clearing) }}">อนุมัติ</button>
                    <button class="btn btn-outline-danger js-void" data-url="{{ route('finance.petty-cash-clearings.reject', $clearing) }}">ไม่อนุมัติ</button>
                @endif

                @if($clearing->status === 'APPROVED' && auth()->user()->hasPermission('finance.petty-cash-clearings.post'))
                    <button class="btn btn-dark js-action" data-method="POST" data-url="{{ route('finance.petty-cash-clearings.post', $clearing) }}">ลงบัญชี</button>
                @endif

                @if($clearing->status === 'POSTED' && auth()->user()->hasPermission('finance.petty-cash-clearings.reverse'))
                    <button class="btn btn-outline-danger js-reverse" data-url="{{ route('finance.petty-cash-clearings.reverse', $clearing) }}">ยกเลิกรายการ</button>
                @endif

                @if(in_array($clearing->status, ['SUBMITTED', 'APPROVED'], true) && auth()->user()->hasPermission('finance.petty-cash-clearings.void'))
                    <button class="btn btn-outline-danger js-void" data-url="{{ route('finance.petty-cash-clearings.void', $clearing) }}">ยกเลิก</button>
                @endif
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <div class="row g-4">
                    <div class="col-md-4"><div class="small text-secondary">วันที่เคลียร์</div>{{ $clearing->clearing_date?->format('d/m/Y') }}</div>
                    <div class="col-md-4"><div class="small text-secondary">วงเงินสดย่อย</div>{{ $clearing->fund?->name ?: '—' }}<div class="small text-secondary">{{ $clearing->fund?->cashBankAccount?->code }} · {{ $clearing->fund?->cashBankAccount?->name }}</div></div>
                    <div class="col-md-4"><div class="small text-secondary">สถานะ</div><span class="badge {{ $classes[$clearing->status] ?? 'app-status-neutral' }}">{{ $labels[$clearing->status] ?? $clearing->status }}</span></div>
                    <div class="col-md-4"><div class="small text-secondary">ยอดตามทะเบียน</div><strong>{{ number_format((float) $clearing->expected_amount, 2) }}</strong></div>
                    <div class="col-md-4"><div class="small text-secondary">ยอดเงินจริง</div><strong>{{ number_format((float) $clearing->actual_amount, 2) }}</strong></div>
                    <div class="col-md-4"><div class="small text-secondary">ผลต่าง</div><strong class="{{ (float) $clearing->variance_amount === 0.0 ? '' : 'text-danger' }}">{{ number_format((float) $clearing->variance_amount, 2) }}</strong></div>
                    <div class="col-12"><div class="small text-secondary">เหตุผลผลต่าง</div>{{ $clearing->reason ?: '—' }}</div>
                </div>
            </div>
        </div>
        @include('Finance::partials.petty-cash-attachments', ['subject' => $clearing, 'subjectType' => 'PETTY_CASH_CLEARING'])
        <div class="card border-0 shadow-sm mt-4"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">ประวัติเอกสาร</h2>@forelse($history ?? [] as $event)<div class="d-flex gap-3 border-bottom py-2"><div class="small text-secondary text-nowrap">{{ $event->created_at?->format('d/m/Y H:i') }}</div><div><strong>{{ $event->action }}</strong><div class="small text-secondary">{{ $event->user?->name ?? 'ระบบ' }}</div>@if($event->reason)<div class="small mt-1"><span class="text-secondary">รายละเอียด:</span> {{ $event->reason }}</div>@endif</div></div>@empty<p class="text-secondary mb-0">ยังไม่มีประวัติเอกสาร</p>@endforelse</div></div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            function send(url, data, method) {
                        $.ajax({
                            url: url,
                            method: method || 'PUT',
                    data: data || {},
                    headers: {Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')}
                }).done(function (response) {
                    Swal.fire({icon: 'success', text: response.msg}).then(function () { location.reload(); });
                }).fail(function (xhr) {
                    Swal.fire({icon: 'error', text: xhr.responseJSON?.message || 'ไม่สามารถดำเนินการได้'});
                });
            }

            $('.js-action').on('click', function () {
                var button = $(this);
                Swal.fire({icon: 'question', text: 'ยืนยันการดำเนินการ?', showCancelButton: true}).then(function (result) {
                    if (result.isConfirmed) send(button.data('url'), null, button.data('method'));
                });
            });

            $('.js-void').on('click', function () {
                var button = $(this);
                Swal.fire({input: 'textarea', inputLabel: 'เหตุผลการยกเลิก', showCancelButton: true, preConfirm: function (value) {
                    if (!$.trim(value || '')) { Swal.showValidationMessage('กรุณาระบุเหตุผล'); return false; }
                    return value;
                }}).then(function (result) {
                    if (result.isConfirmed) send(button.data('url'), {reason: result.value});
                });
            });

            $('.js-reverse').on('click', function () {
                var button = $(this);
                Swal.fire({title: 'ยกเลิกรายการ GL', input: 'textarea', inputLabel: 'เหตุผล (อย่างน้อย 10 ตัวอักษร)', inputAttributes: {maxlength: 500}, showCancelButton: true, preConfirm: function (value) {
                    value = $.trim(value || '');
                    if (value.length < 10) { Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร'); return false; }
                    return value;
                }}).then(function (result) {
                    if (result.isConfirmed) send(button.data('url'), {reason: result.value, reversal_date: new Date().toISOString().slice(0, 10)});
                });
            });
        });
    </script>
@endpush
