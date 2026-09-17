@extends('Dashboard::layout')

@section('title', 'Executive Dashboard | MintERP')

@section('content')
<div class="executive-dashboard" id="executive-dashboard" data-url="{{ route('dashboard.data') }}">
    <section class="executive-hero">
        <div class="executive-orb executive-orb-one" aria-hidden="true"></div>
        <div class="executive-orb executive-orb-two" aria-hidden="true"></div>
        <div class="container-fluid px-3 px-lg-4 position-relative">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-4 executive-heading">
                <div>
                    <div class="executive-kicker"><span class="executive-live-dot"></span> EXECUTIVE COMMAND CENTER</div>
                    <h1 class="executive-title">ภาพรวมธุรกิจ<br><span>เพื่อการตัดสินใจที่ชัดเจน</span></h1>
                    <p class="executive-subtitle mb-0">ติดตามผลประกอบการ กระแสเงินสด และประเด็นสำคัญของทั้งองค์กรในหน้าจอเดียว</p>
                </div>
                <div class="executive-update-card">
                    <div class="executive-update-icon"><i class="bx bx-time-five" aria-hidden="true"></i></div>
                    <div><span>ข้อมูลล่าสุด</span><strong id="executive-refreshed">กำลังโหลดข้อมูล</strong></div>
                </div>
            </div>

            <section class="executive-filter-card" aria-label="ตัวกรอง Dashboard">
                <div class="executive-filter-heading">
                    <div><i class="bx bx-slider-alt" aria-hidden="true"></i><span>มุมมองข้อมูล</span></div>
                    <span id="executive-period-label">ช่วงเวลาที่เลือก</span>
                </div>
                <div class="row g-3 align-items-end">
                    <div class="col-6 col-lg-2"><label class="form-label" for="executive-date-from">ตั้งแต่วันที่</label><input class="form-control" id="executive-date-from" type="date" value="{{ $filters['date_from'] ?? now()->startOfMonth()->toDateString() }}"></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="executive-date-to">ถึงวันที่</label><input class="form-control" id="executive-date-to" type="date" value="{{ $filters['date_to'] ?? now()->toDateString() }}"></div>
                    <div class="col-12 col-md-4 col-lg-2"><label class="form-label" for="executive-company">บริษัท</label><select class="form-select" id="executive-company"><option value="1">{{ $company?->company_name ?? config('app.name') }}</option></select></div>
                    <div class="col-12 col-md-4 col-lg-2"><label class="form-label" for="executive-branch">สาขา</label><select class="form-select" id="executive-branch"><option value="all">ทุกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? 'all') === (string) $branch->id)>{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-4 col-lg-2"><label class="form-label" for="executive-business-unit">หน่วยธุรกิจ</label><select class="form-select" id="executive-business-unit"><option value="all">ทุกหน่วยธุรกิจ</option></select></div>
                    <div class="col-12 col-lg-2 d-flex gap-2"><button class="btn executive-apply flex-grow-1" id="executive-apply" type="button"><i class="bx bx-refresh" aria-hidden="true"></i><span>อัปเดต</span></button><button class="btn executive-reset" id="executive-reset" type="button" title="ล้างตัวกรอง" aria-label="ล้างตัวกรอง"><i class="bx bx-reset" aria-hidden="true"></i></button></div>
                </div>
            </section>
        </div>
    </section>

    <main class="container-fluid px-3 px-lg-4 executive-content">
        <section class="executive-kpi-grid" aria-label="KPI สำคัญ">
            @foreach([
                ['sales','ยอดขายสุทธิ','bx-line-chart','ยอดขายที่ลงรายการแล้ว'],
                ['gross_profit','กำไรขั้นต้น','bx-doughnut-chart','รายได้หลังหักต้นทุนขาย'],
                ['cash_flow','กระแสเงินสดสุทธิ','bx-wallet-alt','เงินรับสุทธิจากเงินจ่าย'],
                ['receivables','ลูกหนี้คงค้าง','bx-user-check','ยอดที่ลูกค้ายังค้างชำระ'],
                ['payables','เจ้าหนี้คงค้าง','bx-credit-card','ยอดที่องค์กรต้องชำระ'],
                ['inventory','มูลค่าสินค้าคงเหลือ','bx-package','มูลค่าสต็อกปัจจุบัน']
            ] as [$key,$label,$icon,$description])
                <article class="executive-kpi" data-kpi="{{ $key }}">
                    <div class="executive-kpi-top"><span class="executive-kpi-icon"><i class="bx {{ $icon }}" aria-hidden="true"></i></span><span class="executive-kpi-change" data-kpi-change>กำลังโหลด</span></div>
                    <div class="executive-kpi-label">{{ $label }}</div>
                    <div class="executive-kpi-value" data-kpi-value>—</div>
                    <div class="executive-kpi-description">{{ $description }}</div>
                </article>
            @endforeach
        </section>

        <section class="row g-4 mb-4" aria-label="กราฟวิเคราะห์ธุรกิจ">
            <div class="col-12 col-xl-8">
                <article class="executive-panel h-100">
                    <header class="executive-panel-header">
                        <div><span class="executive-section-label">PERFORMANCE</span><h2>แนวโน้มผลประกอบการ</h2><p>เปรียบเทียบยอดขาย เงินรับ และเงินจ่ายในช่วงเวลาที่เลือก</p></div>
                        <div class="executive-legend" aria-hidden="true"><span><i class="sales"></i>ยอดขาย</span><span><i class="receipts"></i>เงินรับ</span><span><i class="payments"></i>เงินจ่าย</span></div>
                    </header>
                    <div class="executive-chart-wrap" id="executive-trend-chart" aria-label="กราฟแนวโน้มธุรกิจ"></div>
                </article>
            </div>
            <div class="col-12 col-xl-4">
                <article class="executive-panel h-100">
                    <header class="executive-panel-header"><div><span class="executive-section-label">BRANCH RANKING</span><h2>ผลงานตามสาขา</h2><p>จัดอันดับจากยอดขายสุทธิสูงสุด</p></div><span class="executive-panel-icon"><i class="bx bx-buildings" aria-hidden="true"></i></span></header>
                    <div class="executive-chart-wrap" id="executive-branch-chart" aria-label="กราฟผลงานตามสาขา"></div>
                </article>
            </div>
        </section>

        <section class="row g-4" aria-label="ข้อมูลเพื่อการตัดสินใจ">
            <div class="col-12 col-xl-5">
                <article class="executive-panel executive-alert-panel h-100">
                    <header class="executive-panel-header"><div><span class="executive-section-label text-danger">ATTENTION</span><h2>สิ่งที่ต้องให้ความสนใจ</h2><p>รายการผิดปกติที่ควรตรวจสอบโดยเร็ว</p></div><span class="executive-panel-icon danger"><i class="bx bx-error" aria-hidden="true"></i></span></header>
                    <div id="executive-attention" class="executive-list"><div class="executive-list-loading">กำลังโหลดข้อมูล</div></div>
                </article>
            </div>
            <div class="col-12 col-xl-7">
                <article class="executive-panel executive-decision-panel h-100">
                    <header class="executive-panel-header"><div><span class="executive-section-label text-warning">DECISION</span><h2>เรื่องที่ควรตัดสินใจ</h2><p>โอกาสและความเสี่ยงที่ต้องการทิศทางจากผู้บริหาร</p></div><span class="executive-panel-icon warning"><i class="bx bx-bulb" aria-hidden="true"></i></span></header>
                    <div id="executive-decisions" class="executive-list"><div class="executive-list-loading">กำลังโหลดข้อมูล</div></div>
                </article>
            </div>
        </section>
    </main>
</div>
@endsection

@push('styles')
<style>
    .executive-dashboard { --exec-ink: #151b2d; --exec-muted: #737b8c; --exec-purple: #6d5ce8; --exec-blue: #3f8cff; --exec-cyan: #30c7d4; --exec-green: #22a879; --exec-red: #df5262; --exec-orange: #e79a32; min-height: 100vh; padding-bottom: 2rem; background: #f4f6fb; }
    .executive-hero { position: relative; overflow: hidden; padding: 2.5rem 0 5.25rem; color: var(--exec-ink); background: radial-gradient(circle at 78% 12%, rgba(109,92,232,.14), transparent 30%), radial-gradient(circle at 20% 75%, rgba(48,199,212,.09), transparent 25%), linear-gradient(135deg, #fff 0%, #f7f7ff 55%, #eef4ff 100%); }
    .executive-hero::before { position: absolute; inset: 0; content: ''; opacity: .42; background-image: linear-gradient(rgba(89,99,130,.07) 1px, transparent 1px), linear-gradient(90deg, rgba(89,99,130,.07) 1px, transparent 1px); background-size: 44px 44px; mask-image: linear-gradient(to bottom, #000, transparent 88%); }
    .executive-orb { position: absolute; border-radius: 50%; filter: blur(2px); pointer-events: none; }
    .executive-orb-one { top: -9rem; right: 8%; width: 25rem; height: 25rem; background: rgba(109,92,232,.08); box-shadow: 0 0 8rem rgba(109,92,232,.1); }
    .executive-orb-two { bottom: -11rem; left: 30%; width: 20rem; height: 20rem; background: rgba(48,199,212,.07); }
    .executive-heading { margin-bottom: 2rem; }
    .executive-kicker { display: flex; align-items: center; gap: .55rem; margin-bottom: .75rem; color: #6d5ce8; font-size: .72rem; font-weight: 700; letter-spacing: .14em; }
    .executive-live-dot { width: .5rem; height: .5rem; border-radius: 50%; background: #22a879; box-shadow: 0 0 0 .28rem rgba(34,168,121,.12); }
    .executive-title { max-width: 780px; margin: 0; color: #172033; font-size: clamp(2rem, 3vw, 3.25rem); font-weight: 700; line-height: 1.12; letter-spacing: -.04em; }
    .executive-title span { color: #6d5ce8; }
    .executive-subtitle { max-width: 680px; margin-top: 1rem; color: #667085; font-size: 1rem; line-height: 1.7; }
    .executive-update-card { display: flex; align-items: center; gap: .75rem; min-width: 190px; padding: .85rem 1rem; border: 1px solid rgba(109,92,232,.12); border-radius: 1rem; background: rgba(255,255,255,.78); box-shadow: 0 .75rem 2rem rgba(65,74,108,.08); backdrop-filter: blur(12px); }
    .executive-update-icon { display: grid; width: 2.5rem; height: 2.5rem; place-items: center; border-radius: .75rem; color: #6d5ce8; background: #f0edff; font-size: 1.25rem; }
    .executive-update-card span, .executive-update-card strong { display: block; }
    .executive-update-card span { color: #8a93a3; font-size: .72rem; }
    .executive-update-card strong { margin-top: .1rem; color: #344054; font-size: .83rem; font-weight: 600; }
    .executive-filter-card { padding: 1rem 1.15rem 1.15rem; border: 1px solid rgba(79,91,124,.1); border-radius: 1.15rem; background: rgba(255,255,255,.82); box-shadow: 0 1.25rem 3.5rem rgba(48,61,95,.1); backdrop-filter: blur(18px); }
    .executive-filter-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: .75rem; color: #7a8495; font-size: .75rem; }
    .executive-filter-heading > div { display: flex; align-items: center; gap: .4rem; color: #344054; font-weight: 600; }
    .executive-filter-card .form-label { margin-bottom: .35rem; color: #667085; font-size: .72rem; font-weight: 500; }
    .executive-filter-card .form-control, .executive-filter-card .form-select { min-height: 42px; border-color: #e4e7ec; border-radius: .72rem; color: #172033; background-color: #fff; font-size: .84rem; box-shadow: none; }
    .executive-filter-card .form-control:focus, .executive-filter-card .form-select:focus { border-color: #8799ff; box-shadow: 0 0 0 .2rem rgba(135,153,255,.14); }
    .executive-apply { min-height: 42px; border: 0; border-radius: .72rem; color: #fff; background: #6d5ce8; box-shadow: 0 .45rem 1rem rgba(109,92,232,.2); font-weight: 700; }
    .executive-apply:hover, .executive-apply:focus-visible { color: #fff; background: #5d4dd4; }
    .executive-apply i { margin-right: .3rem; font-size: 1.05rem; vertical-align: -2px; }
    .executive-reset { width: 42px; min-width: 42px; min-height: 42px; border: 1px solid #e4e7ec; border-radius: .72rem; color: #667085; background: #fff; }
    .executive-reset:hover, .executive-reset:focus-visible { color: #344054; background: #f2f4f7; }
    .executive-content { position: relative; z-index: 2; margin-top: -3rem; }
    .executive-kpi-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .executive-kpi { position: relative; overflow: hidden; min-width: 0; padding: 1.1rem; border: 1px solid rgba(26,36,57,.07); border-radius: 1.15rem; background: rgba(255,255,255,.97); box-shadow: 0 .8rem 2.25rem rgba(34,45,72,.075); transition: transform .2s ease, box-shadow .2s ease; }
    .executive-kpi::after { position: absolute; top: -.8rem; right: -1.1rem; width: 5rem; height: 5rem; content: ''; border-radius: 50%; background: rgba(109,92,232,.055); }
    .executive-kpi:hover { transform: translateY(-3px); box-shadow: 0 1.1rem 2.8rem rgba(34,45,72,.12); }
    .executive-kpi-top { display: flex; justify-content: space-between; align-items: center; gap: .5rem; margin-bottom: .9rem; }
    .executive-kpi-icon { position: relative; z-index: 1; display: grid; width: 2.35rem; height: 2.35rem; place-items: center; border-radius: .75rem; color: var(--exec-purple); background: #f0edff; font-size: 1.25rem; }
    .executive-kpi[data-kpi="sales"] .executive-kpi-icon, .executive-kpi[data-kpi="cash_flow"] .executive-kpi-icon { color: var(--exec-green); background: #eaf9f4; }
    .executive-kpi[data-kpi="receivables"] .executive-kpi-icon, .executive-kpi[data-kpi="payables"] .executive-kpi-icon { color: var(--exec-orange); background: #fff6e9; }
    .executive-kpi[data-kpi="inventory"] .executive-kpi-icon { color: var(--exec-blue); background: #edf5ff; }
    .executive-kpi-change { padding: .28rem .5rem; border-radius: 999px; color: #697386; background: #f2f4f7; font-size: .65rem; font-weight: 700; white-space: nowrap; }
    .executive-kpi-change.positive { color: #087a52; background: #eefaf5; }
    .executive-kpi-change.negative { color: #b42318; background: #fff1ee; }
    .executive-kpi-label { overflow: hidden; color: #667085; font-size: .75rem; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
    .executive-kpi-value { overflow: hidden; margin-top: .3rem; color: var(--exec-ink); font-size: clamp(1.2rem, 1.55vw, 1.65rem); font-weight: 750; letter-spacing: -.035em; text-overflow: ellipsis; white-space: nowrap; }
    .executive-kpi-description { overflow: hidden; margin-top: .4rem; color: #98a2b3; font-size: .65rem; text-overflow: ellipsis; white-space: nowrap; }
    .executive-panel { padding: 1.35rem; border: 1px solid rgba(26,36,57,.07); border-radius: 1.25rem; background: #fff; box-shadow: 0 .8rem 2.4rem rgba(34,45,72,.065); }
    .executive-panel-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: .8rem; }
    .executive-section-label { display: block; margin-bottom: .3rem; color: var(--exec-purple); font-size: .64rem; font-weight: 800; letter-spacing: .13em; }
    .executive-panel h2 { margin: 0; color: var(--exec-ink); font-size: 1.05rem; font-weight: 700; }
    .executive-panel-header p { margin: .3rem 0 0; color: #8a93a3; font-size: .75rem; }
    .executive-panel-icon { display: grid; flex: 0 0 auto; width: 2.5rem; height: 2.5rem; place-items: center; border-radius: .8rem; color: var(--exec-purple); background: #f0edff; font-size: 1.3rem; }
    .executive-panel-icon.danger { color: var(--exec-red); background: #fff0f2; }
    .executive-panel-icon.warning { color: var(--exec-orange); background: #fff6e9; }
    .executive-legend { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .7rem; color: #7a8495; font-size: .68rem; }
    .executive-legend span { display: flex; align-items: center; gap: .3rem; }
    .executive-legend i { width: .48rem; height: .48rem; border-radius: 50%; background: var(--exec-purple); }
    .executive-legend i.receipts { background: var(--exec-green); }
    .executive-legend i.payments { background: var(--exec-red); }
    .executive-chart-wrap { position: relative; height: 310px; }
    .executive-chart-wrap .apexcharts-canvas { margin: 0 auto; }
    .executive-chart-fallback { display: grid; height: 100%; place-items: center; padding: 1rem; border: 1px dashed #d9deea; border-radius: .9rem; color: #7a8495; background: #fafbfe; text-align: center; }
    .executive-list { display: grid; gap: .7rem; }
    .executive-list-item { display: flex; align-items: center; justify-content: space-between; gap: .8rem; min-height: 64px; padding: .7rem .8rem; border: 1px solid #edf0f5; border-radius: .9rem; color: inherit; text-decoration: none; background: #fafbfc; transition: border-color .18s ease, transform .18s ease, background .18s ease; }
    .executive-list-item:hover { transform: translateX(3px); border-color: #dce2ee; color: inherit; background: #fff; }
    .executive-list-main { display: flex; align-items: center; gap: .7rem; min-width: 0; }
    .executive-severity { display: grid; flex: 0 0 auto; width: 2.2rem; height: 2.2rem; place-items: center; border-radius: .7rem; color: #a85400; background: #fff7e8; font-size: 1.05rem; }
    .executive-severity.danger { color: #b42318; background: #fff1ee; }
    .executive-severity.success { color: #087a52; background: #eefaf5; }
    .executive-list-copy { min-width: 0; }
    .executive-list-copy strong, .executive-list-copy small { display: block; }
    .executive-list-copy strong { color: #344054; font-size: .82rem; }
    .executive-list-copy small { overflow: hidden; margin-top: .12rem; color: #8a93a3; font-size: .7rem; text-overflow: ellipsis; white-space: nowrap; }
    .executive-list-count { display: flex; flex: 0 0 auto; align-items: center; gap: .4rem; color: #475467; font-size: .78rem; font-weight: 700; }
    .executive-empty, .executive-list-loading { display: grid; min-height: 104px; place-items: center; padding: 1rem; border: 1px dashed #dfe4ed; border-radius: .9rem; color: #8a93a3; background: #fafbfc; font-size: .78rem; text-align: center; }
    .executive-empty i { display: block; margin-bottom: .3rem; color: var(--exec-green); font-size: 1.6rem; }
    .executive-dashboard.is-loading .executive-kpi-value { color: transparent; border-radius: .35rem; background: linear-gradient(90deg, #edf0f5 25%, #f8f9fb 50%, #edf0f5 75%); background-size: 200% 100%; animation: executive-shimmer 1.25s infinite; }
    @keyframes executive-shimmer { to { background-position: -200% 0; } }
    @media (max-width: 1399.98px) { .executive-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 767.98px) {
        .executive-hero { padding-top: 1.5rem; }
        .executive-title { font-size: 2rem; }
        .executive-update-card { align-self: stretch; }
        .executive-filter-card { padding: .9rem; }
        .executive-filter-heading > span { display: none; }
        .executive-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
        .executive-kpi { padding: .9rem; }
        .executive-kpi-description { display: none; }
        .executive-kpi-value { font-size: 1.15rem; }
        .executive-kpi-change { max-width: 88px; overflow: hidden; text-overflow: ellipsis; }
        .executive-panel { padding: 1rem; }
        .executive-panel-header { flex-wrap: wrap; }
        .executive-legend { justify-content: flex-start; }
        .executive-chart-wrap { height: 260px; }
    }
    @media (prefers-reduced-motion: reduce) { .executive-kpi, .executive-list-item { transition: none; } .executive-dashboard.is-loading .executive-kpi-value { animation: none; } }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.49.1/dist/apexcharts.min.js"></script>
<script>
$(function () {
    var root = $('#executive-dashboard'), chart, branchChart;
    var number = function (value) { return Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var money = function (value) { return '฿' + number(value); };
    var compact = function (value) { return Number(value || 0).toLocaleString('th-TH', { notation: 'compact', maximumFractionDigits: 1 }); };
    function query() { return { date_from: $('#executive-date-from').val(), date_to: $('#executive-date-to').val(), company_id: $('#executive-company').val(), branch_id: $('#executive-branch').val(), business_unit_id: $('#executive-business-unit').val() }; }
    function syncUrl(filters) { var url = new URL(window.location.href); Object.keys(filters).forEach(function (key) { filters[key] && filters[key] !== 'all' ? url.searchParams.set(key, filters[key]) : url.searchParams.delete(key); }); window.history.replaceState({}, '', url.toString()); }
    function thaiDate(value) { return value ? new Date(value + 'T00:00:00').toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' }) : ''; }
    function updatePeriod(filters) { $('#executive-period-label').text(thaiDate(filters.date_from) + ' — ' + thaiDate(filters.date_to)); }
    function linkItem(item) {
        var severity = item.severity || 'warning', icon = severity === 'danger' ? 'bx-error-circle' : (severity === 'success' ? 'bx-check-circle' : 'bx-bell');
        return $('<a>', { class: 'executive-list-item', href: item.href || '#' })
            .append($('<span>', { class: 'executive-list-main' }).append($('<span>', { class: 'executive-severity ' + severity }).append($('<i>', { class: 'bx ' + icon, 'aria-hidden': 'true' }))).append($('<span>', { class: 'executive-list-copy' }).append($('<strong>', { text: item.title })).append($('<small>', { text: item.detail || 'คลิกเพื่อดูรายละเอียดและดำเนินการ' }))))
            .append($('<span>', { class: 'executive-list-count' }).append($('<span>', { text: item.count ?? '' })).append($('<i>', { class: 'bx bx-chevron-right', 'aria-hidden': 'true' })));
    }
    function render(payload) {
        $.each(payload.kpis || {}, function (key, item) {
            var box = root.find('[data-kpi="' + key + '"]'), hasChange = item.change_percent !== null && item.change_percent !== undefined, positive = Number(item.change_percent) >= 0;
            box.find('[data-kpi-value]').text(item.value === null ? 'ข้อมูลไม่พร้อม' : money(item.value));
            box.find('[data-kpi-change]').text(item.value === null ? 'ไม่พร้อม' : (hasChange ? ((positive ? '▲ ' : '▼ ') + Math.abs(item.change_percent).toFixed(1) + '%') : 'ข้อมูลปัจจุบัน')).removeClass('positive negative').addClass(hasChange ? (positive ? 'positive' : 'negative') : '');
        });
        $('#executive-refreshed').text(new Date(payload.refreshed_at).toLocaleString('th-TH', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }));
        updatePeriod(payload.filters || query());
        if (typeof ApexCharts === 'undefined') {
            $('#executive-trend-chart, #executive-branch-chart').html($('<div>', { class: 'executive-chart-fallback', text: 'กราฟไม่พร้อมใช้งานชั่วคราว แต่ข้อมูลสำคัญส่วนอื่นยังใช้งานได้ตามปกติ' }));
        } else {
            if (chart) chart.destroy();
            chart = new ApexCharts(document.getElementById('executive-trend-chart'), { chart: { type: 'area', height: 310, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit', animations: { speed: 550 } }, colors: ['#6d5ce8', '#22a879', '#df5262'], series: [{ name: 'ยอดขาย', data: payload.trend.sales }, { name: 'เงินรับ', data: payload.trend.receipts }, { name: 'เงินจ่าย', data: payload.trend.payments }], xaxis: { categories: payload.trend.labels, axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { colors: '#8a93a3', fontSize: '11px' } } }, yaxis: { labels: { formatter: compact, style: { colors: '#8a93a3', fontSize: '11px' } } }, stroke: { curve: 'smooth', width: 3 }, fill: { type: 'gradient', gradient: { opacityFrom: .24, opacityTo: .015, stops: [0, 90, 100] } }, markers: { size: 0, hover: { size: 5 } }, dataLabels: { enabled: false }, legend: { show: false }, grid: { borderColor: '#edf0f5', strokeDashArray: 4, padding: { left: 8, right: 10 } }, tooltip: { shared: true, y: { formatter: money } }, noData: { text: 'ยังไม่มีข้อมูลในช่วงเวลานี้' } });
            chart.render();
            if (branchChart) branchChart.destroy();
            branchChart = new ApexCharts(document.getElementById('executive-branch-chart'), { chart: { type: 'bar', height: 310, toolbar: { show: false }, fontFamily: 'inherit' }, colors: ['#6d5ce8'], series: [{ name: 'ยอดขายสุทธิ', data: (payload.branches || []).map(function (item) { return Number(item.value || 0); }) }], xaxis: { categories: (payload.branches || []).map(function (item) { return item.label; }), labels: { formatter: compact, style: { colors: '#8a93a3', fontSize: '10px' } }, axisBorder: { show: false }, axisTicks: { show: false } }, yaxis: { labels: { maxWidth: 110, style: { colors: '#667085', fontSize: '11px' } } }, plotOptions: { bar: { horizontal: true, borderRadius: 7, barHeight: '44%', distributed: false } }, fill: { type: 'gradient', gradient: { type: 'horizontal', shadeIntensity: .2, gradientToColors: ['#3f8cff'], opacityFrom: 1, opacityTo: .9 } }, dataLabels: { enabled: false }, legend: { show: false }, grid: { borderColor: '#edf0f5', strokeDashArray: 4, yaxis: { lines: { show: false } } }, tooltip: { y: { formatter: money } }, noData: { text: 'ยังไม่มีข้อมูลสาขา' } });
            branchChart.render();
        }
        var renderList = function (selector, items, empty) { var box = $(selector).empty(); if (!items || !items.length) return box.append($('<div>', { class: 'executive-empty' }).append($('<div>').append($('<i>', { class: 'bx bx-check-circle', 'aria-hidden': 'true' })).append($('<span>', { text: empty })))); items.forEach(function (item) { box.append(linkItem(item)); }); };
        renderList('#executive-attention', payload.attention, 'ไม่มีรายการเร่งด่วนในขณะนี้');
        renderList('#executive-decisions', payload.decisions, 'ยังไม่มีเรื่องที่รอการตัดสินใจ');
    }
    function load() {
        var filters = query(); syncUrl(filters); updatePeriod(filters); root.addClass('is-loading').attr('aria-busy', 'true'); $('#executive-apply').prop('disabled', true).find('i').addClass('bx-spin');
        $.getJSON(root.data('url'), filters).done(render).fail(function (xhr) { Swal.fire({ icon: 'error', title: 'โหลด Dashboard ไม่สำเร็จ', text: xhr.responseJSON?.message || 'กรุณาตรวจสอบตัวกรองแล้วลองใหม่อีกครั้ง' }); }).always(function () { root.removeClass('is-loading').removeAttr('aria-busy'); $('#executive-apply').prop('disabled', false).find('i').removeClass('bx-spin'); });
    }
    $('#executive-apply').on('click', load);
    $('#executive-reset').on('click', function () { $('#executive-date-from').val('{{ now()->startOfMonth()->toDateString() }}'); $('#executive-date-to').val('{{ now()->toDateString() }}'); $('#executive-company').val('1'); $('#executive-branch').val('all'); $('#executive-business-unit').val('all'); load(); });
    load();
});
</script>
@endpush
