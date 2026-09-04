@extends('Accounting::layout')

@section('title', $fiscalYear->name.' | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">ACCOUNTING / FISCAL PERIODS</p>
                <h1 class="h3 mb-2">{{ $fiscalYear->name }}</h1>
                <p class="text-secondary mb-0">{{ $fiscalYear->start_date->format('d/m/Y') }} – {{ $fiscalYear->end_date->format('d/m/Y') }}</p>
            </div>
            <a class="btn btn-outline-dark" href="{{ route('accounting.fiscal-years.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับรายการ</a>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>งวด</th>
                                        <th>ชื่อ</th>
                                        <th>วันเริ่ม</th>
                                        <th>วันสิ้นสุด</th>
                                        <th>สถานะ</th>
                                        @if (auth()->user()->hasPermission('accounting.periods.view') || auth()->user()->hasPermission('accounting.periods.close') || auth()->user()->hasPermission('accounting.periods.reopen') || auth()->user()->hasPermission('accounting.periods.lock'))
                                            <th class="text-end">จัดการ</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($fiscalYear->periods as $period)
                                        <tr>
                                            <td>{{ $period->period_number }}</td>
                                            <td>{{ $period->name }}</td>
                                            <td>{{ $period->start_date->format('d/m/Y') }}</td>
                                            <td>{{ $period->end_date->format('d/m/Y') }}</td>
                                            <td>
                                                <span class="badge {{ $period->status === 'OPEN' ? 'text-bg-success' : ($period->status === 'SOFT_CLOSE' ? 'text-bg-warning' : 'text-bg-dark') }}">
                                                    {{ ['OPEN' => 'เปิด', 'SOFT_CLOSE' => 'Soft close', 'LOCKED' => 'ล็อค'][ $period->status ] }}
                                                </span>
                                                @if ($period->status === 'LOCKED' && $period->locked_at)
                                                    <div class="small text-secondary mt-1">{{ $period->locked_at->format('d/m/Y H:i') }}</div>
                                                @endif
                                            </td>
                                            @if (auth()->user()->hasPermission('accounting.periods.view') || auth()->user()->hasPermission('accounting.periods.close') || auth()->user()->hasPermission('accounting.periods.reopen') || auth()->user()->hasPermission('accounting.periods.lock'))
                                                <td class="text-end">
                                                    @if (auth()->user()->hasPermission('accounting.periods.view'))
                                                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('accounting.fiscal-periods.readiness', $period) }}" title="ตรวจรายการค้างก่อนปิดงวด">
                                                            <i class="bx bx-list-check me-1" aria-hidden="true"></i>ตรวจรายการค้าง
                                                        </a>
                                                    @endif
                                                    @if ($period->status === 'OPEN' && auth()->user()->hasPermission('accounting.periods.close'))
                                                        <button class="btn btn-sm btn-outline-dark js-period-status"
                                                                type="button"
                                                                data-url="{{ route('accounting.fiscal-periods.soft-close', $period) }}"
                                                                data-title="Soft close งวด {{ $period->name }}"
                                                                data-label="ยืนยัน Soft close">
                                                            <i class="bx bx-lock-open-alt me-1" aria-hidden="true"></i>Soft close
                                                        </button>
                                                    @elseif ($period->status === 'SOFT_CLOSE' && auth()->user()->hasPermission('accounting.periods.lock'))
                                                        <button class="btn btn-sm btn-outline-dark js-period-status"
                                                                type="button"
                                                                data-url="{{ route('accounting.fiscal-periods.lock', $period) }}"
                                                                data-title="Lock งวด {{ $period->name }}"
                                                                data-label="ยืนยัน Lock">
                                                            <i class="bx bx-lock me-1" aria-hidden="true"></i>Lock
                                                        </button>
                                                    @elseif ($period->status !== 'OPEN' && auth()->user()->hasPermission('accounting.periods.reopen'))
                                                        <button class="btn btn-sm btn-outline-dark js-period-status"
                                                                type="button"
                                                                data-url="{{ route('accounting.fiscal-periods.reopen', $period) }}"
                                                                data-title="เปิดงวด {{ $period->name }} อีกครั้ง"
                                                                data-label="ยืนยัน Reopen">
                                                            <i class="bx bx-lock-open me-1" aria-hidden="true"></i>Reopen
                                                        </button>
                                                    @endif
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="period-status-modal" tabindex="-1" aria-labelledby="period-status-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="period-status-form" method="post" novalidate>
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="period-status-title">เปลี่ยนสถานะงวดบัญชี</h2>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label" for="period-status-reason">เหตุผล</label>
                        <textarea class="form-control" id="period-status-reason" name="reason" rows="4" maxlength="500" required></textarea>
                        <div class="invalid-feedback" data-error-for="reason"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-dark" type="button" data-bs-dismiss="modal">ยกเลิก</button>
                        <button class="btn btn-dark" id="period-status-submit" type="submit" data-busy-text="กำลังบันทึก...">ยืนยัน</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $modal = $('#period-status-modal');
            var $form = $('#period-status-form');

            $(document).on('click', '.js-period-status', function () {
                var $button = $(this);
                $form.attr('action', $button.data('url'));
                $form.find('[name="reason"]').val('').removeClass('is-invalid');
                $form.find('[data-error-for="reason"]').text('');
                $('#period-status-title').text($button.data('title'));
                $('#period-status-submit').text($button.data('label'));
                bootstrap.Modal.getOrCreateInstance($modal[0]).show();
            });

            window.erpAjaxForm({
                form: '#period-status-form',
                reload: true
            });
        });
    </script>
@endpush
