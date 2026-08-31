@extends('Wms::layout')

@section('title', 'Purchasing Dashboard | New ERP')

@section('content')
    @php($isInventory = ($program?->code ?? null) === 'inventory')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">{{ $isInventory ? 'WMS / INVENTORY' : 'PURCHASING' }}</p>
        <h1 class="h3 mb-2">{{ $isInventory ? 'WMS Dashboard' : 'Purchasing Dashboard' }}</h1>
        <p class="text-secondary mb-0">{{ $isInventory ? 'ศูนย์กลางสินค้า หน่วยนับ และความเคลื่อนไหวในคลัง' : 'ศูนย์กลางข้อมูล Supplier และกระบวนการจัดซื้อ' }}</p>

        @if ($isInventory)
            <section class="card border-0 shadow-sm mt-4" aria-labelledby="min-max-alert-title">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <p class="eyebrow mb-1">STOCK POLICY ALERT</p>
                            <h2 id="min-max-alert-title" class="h5 mb-1">สินค้าที่ต่ำกว่าจุด Min</h2>
                            <p class="text-secondary small mb-0">ระบบแนะนำจำนวนเติมถึง Max เพื่อให้ผู้ใช้พิจารณาสร้าง PR เอง</p>
                        </div>
                        <span class="badge app-status-{{ $minMaxAlerts->isNotEmpty() ? 'warning' : 'success' }}">
                            {{ $minMaxAlerts->count() }} รายการ
                        </span>
                    </div>
                    @if (auth()->user()->hasPermission('wms.purchase-requisitions.create'))
                        <div class="d-flex justify-content-end mb-3">
                            <a id="min-max-create-pr" class="btn btn-dark btn-sm disabled" aria-disabled="true" href="#">
                                <i class="bx bx-file me-1" aria-hidden="true"></i>สร้าง PR จากรายการที่เลือก
                            </a>
                        </div>
                    @endif
                    @if ($minMaxAlerts->isEmpty())
                        <div class="alert alert-success border-0 mb-0">ยังไม่มีสินค้าที่ต่ำกว่าจุด Min</div>
                    @else
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead><tr><th><input class="form-check-input" type="checkbox" id="min-max-select-all" aria-label="เลือกทั้งหมด"></th><th>สินค้า</th><th class="text-end">คงเหลือ</th><th class="text-end">จอง</th><th class="text-end">พร้อมใช้</th><th class="text-end">PO ค้างรับ</th><th class="text-end">Min</th><th class="text-end">Max</th><th class="text-end">แนะนำเติม</th></tr></thead>
                                <tbody>
                                @foreach ($minMaxAlerts as $alert)
                                    <tr>
                                        <td><input class="form-check-input js-min-max-item" type="checkbox" value="{{ $alert['item_id'] }}" aria-label="เลือก {{ $alert['item_label'] }}"></td>
                                        <td>{{ $alert['item_label'] }}</td>
                                        <td class="text-end">{{ \App\Modules\Wms\Support\WmsDecimal::format($alert['on_hand']) }}</td>
                                        <td class="text-end text-warning">{{ \App\Modules\Wms\Support\WmsDecimal::format($alert['reserved']) }}</td>
                                        <td class="text-end text-danger">{{ \App\Modules\Wms\Support\WmsDecimal::format($alert['available']) }}</td>
                                        <td class="text-end text-info">{{ \App\Modules\Wms\Support\WmsDecimal::format($alert['open_po']) }}</td>
                                        <td class="text-end">{{ \App\Modules\Wms\Support\WmsDecimal::format($alert['min']) }}</td>
                                        <td class="text-end">{{ \App\Modules\Wms\Support\WmsDecimal::format($alert['max']) }}</td>
                                        <td class="text-end fw-semibold text-info">{{ \App\Modules\Wms\Support\WmsDecimal::format($alert['recommended']) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>
        @endif
    </div>
@endsection
@push('scripts')
@if (auth()->user()->hasPermission('wms.purchase-requisitions.create'))
<script>
$(function () {
    const create = $('#min-max-create-pr');
    const all = $('#min-max-select-all');
    const rows = $('.js-min-max-item');
    const base = @json(route('wms.purchase-requisitions.create'));
    function sync() {
        const ids = rows.filter(':checked').map(function () { return this.value; }).get();
        const url = new URL(base, window.location.origin);
        url.searchParams.set('source', 'min-max');
        ids.forEach(function (id) { url.searchParams.append('item_ids[]', id); });
        create.attr('href', ids.length ? url.toString() : '#').toggleClass('disabled', !ids.length).attr('aria-disabled', ids.length ? 'false' : 'true');
        all.prop('checked', ids.length > 0 && ids.length === rows.length);
    }
    all.on('change', function () { rows.prop('checked', this.checked); sync(); });
    rows.on('change', sync);
});
</script>
@endif
@endpush
