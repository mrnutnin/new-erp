@extends('Dashboard::layout')

@section('title', 'Executive Dashboard | New ERP')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4 executive-dashboard" id="executive-dashboard" data-url="{{ route('dashboard.data') }}">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
        <div>
            <p class="eyebrow mb-2">EXECUTIVE / ORGANIZATION OVERVIEW</p>
            <h1 class="h3 mb-1">Executive Dashboard</h1>
            <p class="text-secondary mb-0">ภาพรวมธุรกิจทั้งองค์กรและสาขา สำหรับการตัดสินใจของผู้บริหาร</p>
        </div>
        <div class="small text-secondary"><i class="bx bx-refresh me-1" aria-hidden="true"></i><span id="executive-refreshed">กำลังโหลดข้อมูล</span></div>
    </div>

    <section class="card border-0 shadow-sm executive-filter-card mb-3" aria-label="ตัวกรอง Dashboard">
        <div class="card-body p-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-3"><label class="form-label small mb-1" for="executive-date-from">ตั้งแต่วันที่</label><input class="form-control form-control-sm" id="executive-date-from" type="date" value="{{ $filters['date_from'] ?? now()->startOfMonth()->toDateString() }}"></div>
                <div class="col-12 col-md-3"><label class="form-label small mb-1" for="executive-date-to">ถึงวันที่</label><input class="form-control form-control-sm" id="executive-date-to" type="date" value="{{ $filters['date_to'] ?? now()->toDateString() }}"></div>
                <div class="col-12 col-md-2"><label class="form-label small mb-1" for="executive-company">บริษัท</label><select class="form-select form-select-sm" id="executive-company"><option value="1">{{ $company?->company_name ?? config('app.name') }}</option></select></div>
                <div class="col-12 col-md-2"><label class="form-label small mb-1" for="executive-branch">สาขา</label><select class="form-select form-select-sm" id="executive-branch"><option value="all">ทุกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? 'all') === (string) $branch->id)>{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div>
                <div class="col-12 col-md-2"><label class="form-label small mb-1" for="executive-business-unit">หน่วยธุรกิจ</label><select class="form-select form-select-sm" id="executive-business-unit"><option value="all">ทุกหน่วยธุรกิจ</option></select></div>
                <div class="col-12 d-flex justify-content-end gap-2 mt-2"><button class="btn btn-sm btn-dark" id="executive-apply" type="button"><i class="bx bx-refresh me-1" aria-hidden="true"></i>ใช้ตัวกรอง</button><button class="btn btn-sm btn-outline-secondary" id="executive-reset" type="button">ล้างตัวกรอง</button></div>
            </div>
        </div>
    </section>

    <section class="row g-3 mb-3" aria-label="KPI สำคัญ">
        @foreach([['sales','ยอดขายสุทธิ','bx-trending-up'],['gross_profit','กำไรขั้นต้น','bx-bar-chart-alt-2'],['cash_flow','กระแสเงินสดสุทธิ','bx-wallet'],['receivables','ลูกหนี้คงค้าง','bx-user'],['payables','เจ้าหนี้คงค้าง','bx-credit-card'],['inventory','มูลค่าสินค้าคงเหลือ','bx-package']] as [$key,$label,$icon])
            <div class="col-6 col-xl-2"><article class="card border-0 shadow-sm executive-kpi h-100" data-kpi="{{ $key }}"><div class="card-body p-3"><div class="d-flex justify-content-between align-items-center gap-2"><span class="small text-secondary">{{ $label }}</span><i class="bx {{ $icon }} executive-kpi-icon" aria-hidden="true"></i></div><div class="fs-5 fw-semibold mt-2" data-kpi-value>—</div><div class="small mt-1" data-kpi-change>กำลังโหลด</div></div></article></div>
        @endforeach
    </section>

    <section class="row g-3 mb-3">
        <div class="col-12 col-lg-8"><article class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center mb-2"><div><h2 class="h5 mb-1">แนวโน้มธุรกิจ</h2><p class="small text-secondary mb-0">ยอดขายเทียบกับเงินรับและเงินจ่าย</p></div><span class="badge app-status-neutral">ช่วงเวลาที่เลือก</span></div><div class="executive-chart-wrap" id="executive-trend-chart" aria-label="กราฟแนวโน้มธุรกิจ"></div></div></article></div>
        <div class="col-12 col-lg-4"><article class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-1">ผลงานตามสาขา</h2><p class="small text-secondary mb-3">เรียงจากยอดขายสุทธิ</p><div class="executive-chart-wrap executive-chart-small" id="executive-branch-chart" aria-label="กราฟผลงานตามสาขา"></div></div></article></div>
    </section>

    <section class="row g-3">
        <div class="col-12 col-lg-6"><article class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">สิ่งที่ต้องให้ความสนใจ</h2><i class="bx bx-error-circle text-warning fs-4" aria-hidden="true"></i></div><div id="executive-attention" class="executive-list"><div class="text-secondary small">กำลังโหลดข้อมูล</div></div></div></article></div>
        <div class="col-12 col-lg-6"><article class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">เรื่องที่ควรตัดสินใจ</h2><i class="bx bx-bulb text-warning fs-4" aria-hidden="true"></i></div><div id="executive-decisions" class="executive-list"><div class="text-secondary small">กำลังโหลดข้อมูล</div></div></div></article></div>
    </section>
</div>
@endsection

@push('styles')
<style>
    .executive-dashboard { --exec-blue: #6478c8; --exec-green: #2f9e72; --exec-red: #c84b58; --exec-orange: #c98a24; }
    .executive-filter-card { background: linear-gradient(120deg, #fff, #f7f9ff); }
    .executive-kpi { border-top: 3px solid transparent !important; transition: transform .2s ease, box-shadow .2s ease; }
    .executive-kpi:hover { transform: translateY(-2px); box-shadow: 0 .75rem 1.5rem rgba(45,49,66,.1) !important; }
    .executive-kpi-icon { color: var(--exec-blue); font-size: 1.2rem; }
    .executive-kpi[data-kpi="sales"], .executive-kpi[data-kpi="cash_flow"] { border-top-color: var(--exec-green) !important; }
    .executive-kpi[data-kpi="gross_profit"] { border-top-color: var(--exec-blue) !important; }
    .executive-kpi[data-kpi="receivables"], .executive-kpi[data-kpi="payables"] { border-top-color: var(--exec-orange) !important; }
    .executive-chart-wrap { position: relative; height: 260px; }
    .executive-chart-wrap .apexcharts-canvas { margin: 0 auto; }
    .executive-chart-fallback { height: 100%; display: grid; place-items: center; padding: 1rem; border: 1px dashed rgba(45,49,66,.16); border-radius: .55rem; color: #737b88; background: #fafbfc; text-align: center; }
    .executive-chart-small { height: 260px; }
    .executive-list { display: grid; gap: .55rem; }
    .executive-list-item { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .7rem .8rem; border: 1px solid rgba(45,49,66,.08); border-radius: .55rem; color: inherit; text-decoration: none; background: rgba(255,255,255,.62); }
    .executive-list-item:hover { background: #f7f9ff; }
    .executive-severity { width: .55rem; height: .55rem; flex: 0 0 auto; border-radius: 50%; background: var(--exec-orange); }
    .executive-severity.danger { background: var(--exec-red); }
    .executive-severity.success { background: var(--exec-green); }
    @media (max-width: 767.98px) {
        .executive-chart-wrap, .executive-chart-small { height: 220px; }
        .executive-filter-card .btn { flex: 1 1 auto; }
    }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
$(function () {
    var root = $('#executive-dashboard'), chart, branchChart, number = function (value) { return Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    function query() { return { date_from: $('#executive-date-from').val(), date_to: $('#executive-date-to').val(), company_id: $('#executive-company').val(), branch_id: $('#executive-branch').val(), business_unit_id: $('#executive-business-unit').val() }; }
    function syncUrl(filters) { var url = new URL(window.location.href); Object.keys(filters).forEach(function (key) { filters[key] && filters[key] !== 'all' ? url.searchParams.set(key, filters[key]) : url.searchParams.delete(key); }); window.history.replaceState({}, '', url.toString()); }
    function linkItem(item) { return $('<a>', { class: 'executive-list-item', href: item.href || '#', target: item.href ? '_self' : undefined }).append($('<span>', { class: 'd-flex align-items-center gap-2' }).append($('<span>', { class: 'executive-severity ' + (item.severity || '') })).append($('<span>', { text: item.title + (item.detail ? ' · ' + item.detail : '') }))).append($('<strong>', { class: 'small', text: item.count ?? '' })); }
    function render(payload) {
        $.each(payload.kpis || {}, function (key, item) { var box = root.find('[data-kpi="' + key + '"]'), value = item.value === null ? 'ข้อมูลไม่พร้อม' : number(item.value), change = item.change_percent === null || item.change_percent === undefined ? 'ข้อมูลตามช่วงเวลาที่เลือก' : ((item.change_percent >= 0 ? '▲ ' : '▼ ') + Math.abs(item.change_percent).toFixed(1) + '% เทียบช่วงก่อนหน้า'); box.find('[data-kpi-value]').text(value); box.find('[data-kpi-change]').text(item.value === null ? 'ข้อมูลไม่พร้อม' : change).removeClass('text-success text-danger text-secondary').addClass(item.change_percent === null || item.change_percent === undefined ? 'text-secondary' : (item.change_percent >= 0 ? 'text-success' : 'text-danger')); });
        $('#executive-refreshed').text('อัปเดต ' + new Date(payload.refreshed_at).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }));
        if (typeof ApexCharts === 'undefined') {
            $('#executive-trend-chart, #executive-branch-chart').html($('<div>', { class: 'executive-chart-fallback', text: 'กราฟไม่พร้อมใช้งานชั่วคราว แต่ข้อมูล KPI และรายการติดตามยังแสดงได้ตามปกติ' }));
        } else {
            if (chart) chart.destroy(); chart = new ApexCharts(document.getElementById('executive-trend-chart'), { chart: { type: 'area', height: 260, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' }, colors: ['#6478c8', '#2f9e72', '#c84b58'], series: [{ name: 'ยอดขาย', data: payload.trend.sales }, { name: 'รับเงิน', data: payload.trend.receipts }, { name: 'จ่ายเงิน', data: payload.trend.payments }], xaxis: { categories: payload.trend.labels, labels: { style: { colors: '#737b88' } } }, yaxis: { labels: { formatter: number } }, stroke: { curve: 'smooth', width: 2 }, fill: { type: 'gradient', gradient: { opacityFrom: .22, opacityTo: .03 } }, dataLabels: { enabled: false }, legend: { position: 'bottom', horizontalAlign: 'left' }, grid: { borderColor: '#edf0f4' }, tooltip: { y: { formatter: number } } }); chart.render();
            if (branchChart) branchChart.destroy(); branchChart = new ApexCharts(document.getElementById('executive-branch-chart'), { chart: { type: 'bar', height: 260, toolbar: { show: false }, fontFamily: 'inherit' }, colors: ['#6478c8'], series: [{ name: 'ยอดขายสุทธิ', data: (payload.branches || []).map(function (item) { return Number(item.value || 0); }) }], xaxis: { categories: (payload.branches || []).map(function (item) { return item.label; }), labels: { style: { colors: '#737b88' } } }, plotOptions: { bar: { horizontal: true, borderRadius: 5, barHeight: '55%' } }, dataLabels: { enabled: false }, legend: { show: false }, grid: { borderColor: '#edf0f4', yaxis: { lines: { show: false } } }, tooltip: { y: { formatter: number } } }); branchChart.render();
        }
        var renderList = function (selector, items, empty) { var box = $(selector).empty(); if (!items || !items.length) return box.append($('<div>', { class: 'text-secondary small', text: empty })); items.forEach(function (item) { box.append(linkItem(item)); }); }; renderList('#executive-attention', payload.attention, 'ไม่มีรายการที่ต้องให้ความสนใจ'); renderList('#executive-decisions', payload.decisions, 'ไม่มีเรื่องที่รอการตัดสินใจ');
    }
    function load() { var filters = query(); syncUrl(filters); root.addClass('is-loading'); $.getJSON(root.data('url'), filters).done(render).fail(function (xhr) { Swal.fire({ icon: 'error', title: 'โหลด Dashboard ไม่สำเร็จ', text: xhr.responseJSON?.message || 'กรุณาลองใหม่อีกครั้ง' }); }).always(function () { root.removeClass('is-loading'); }); }
    $('#executive-apply').on('click', load); $('#executive-reset').on('click', function () { $('#executive-date-from').val('{{ now()->startOfMonth()->toDateString() }}'); $('#executive-date-to').val('{{ now()->toDateString() }}'); $('#executive-company').val('1'); $('#executive-branch').val('all'); $('#executive-business-unit').val('all'); load(); }); load();
});
</script>
@endpush
