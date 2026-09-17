@extends('Pos::layout')

@section('title', 'Sales Dashboard | MintERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4 pos-dashboard" id="pos-dashboard" data-url="{{ route('pos.dashboard.data', ['section' => '__section__']) }}">
        <header class="pos-hero mb-4">
            <div class="pos-hero-orb" aria-hidden="true"></div>
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-4 position-relative">
                <div><div class="pos-kicker"><span></span> POS / SALES PERFORMANCE</div><h1>ศูนย์ควบคุมการขาย</h1><p class="mb-0">ภาพรวมยอดขาย เป้าหมาย และงานสำคัญของสาขาแบบครบถ้วนในหน้าจอเดียว</p><div class="pos-branch-chip"><i class="bx bx-map" aria-hidden="true"></i>{{ $branch->name }}</div></div>
                @if ($canViewReports)<a class="btn pos-report-button" href="{{ route('pos.sales-reports.sales-target-performance.index') }}"><span><small>วิเคราะห์เชิงลึก</small><strong>ผลงานเทียบเป้า</strong></span><i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>@endif
            </div>
        </header>

        @if ($canViewReports)
            <section class="row g-3 mb-4" aria-label="ตัวชี้วัดการขายสำคัญ">
                <div class="col-sm-6 col-xl-3"><article class="card pos-kpi h-100"><div class="card-body"><div class="pos-kpi-head"><span class="pos-kpi-icon green"><i class="bx bx-line-chart" aria-hidden="true"></i></span><span class="app-status-success badge">วันนี้</span></div><div class="pos-kpi-label">ยอดขายสุทธิวันนี้</div><div class="pos-kpi-value" data-summary="sales_today">—</div><small>HS/IV ที่ Post แล้ว หักยอดรับคืน</small></div></article></div>
                <div class="col-sm-6 col-xl-3"><article class="card pos-kpi h-100"><div class="card-body"><div class="pos-kpi-head"><span class="pos-kpi-icon purple"><i class="bx bx-calendar-check" aria-hidden="true"></i></span><span class="app-status-primary badge">เดือนนี้</span></div><div class="pos-kpi-label">ยอดขายสุทธิเดือนนี้</div><div class="pos-kpi-value" data-summary="sales_month">—</div><small>ภาพรวมทุกคลังในสาขา</small></div></article></div>
                <div class="col-sm-6 col-xl-3"><article class="card pos-kpi h-100"><div class="card-body"><div class="pos-kpi-head"><span class="pos-kpi-icon blue"><i class="bx bx-target-lock" aria-hidden="true"></i></span><span class="app-status-info badge">เป้าหมาย</span></div><div class="pos-kpi-label">ผลงานเทียบเป้าสาขา</div><div class="pos-kpi-value" data-summary="target_percent">—</div><small data-summary="target_hint">กำลังโหลดข้อมูล</small></div></article></div>
                <div class="col-sm-6 col-xl-3"><article class="card pos-kpi h-100"><div class="card-body"><div class="pos-kpi-head"><span class="pos-kpi-icon orange"><i class="bx bx-file" aria-hidden="true"></i></span><span class="app-status-warning badge">รอดำเนินการ</span></div><div class="pos-kpi-label">HS/IV ร่าง</div><div class="pos-kpi-value" data-work="draft_physical_sales">—</div>@if($canViewPhysicalSales)<a class="pos-kpi-link" href="{{ route('pos.physical-sales.index') }}">จัดการเอกสาร <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>@endif</div></article></div>
            </section>
            <section class="pos-sales-split mb-4" aria-label="องค์ประกอบยอดขายประจำเดือน">
                <article><span class="pos-split-icon hs">HS</span><div><small>ขายสดเดือนนี้</small><strong data-summary="hs_month">—</strong><p>เอกสารขายสดที่ Post แล้ว</p></div></article>
                <article><span class="pos-split-icon iv">IV</span><div><small>ขายเชื่อเดือนนี้</small><strong data-summary="iv_month">—</strong><p>เอกสารขายเชื่อที่ Post แล้ว</p></div></article>
                <article><span class="pos-split-icon cn"><i class="bx bx-undo" aria-hidden="true"></i></span><div><small>ลดหนี้ / รับคืนเดือนนี้</small><strong data-summary="credit_note_month">—</strong><p>หักเฉพาะเอกสารต้นทางที่ยัง Post</p></div></article>
            </section>

            <div class="row g-4 mb-4">
                <div class="col-xl-8"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between gap-3 mb-3"><div><h2 class="h5 mb-1">แนวโน้มยอดขายสุทธิ 7 วันล่าสุด</h2><p class="small text-secondary mb-0">รวม HS/IV ที่ Post แล้ว และหักยอดรับคืนตามวันที่ Post</p></div><span class="badge app-status-info align-self-start">ยอดสุทธิ</span></div><canvas id="sales-trend-chart" aria-label="กราฟแนวโน้มยอดขายสุทธิ 7 วันล่าสุด" role="img"></canvas></div></div></div>
                <div class="col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-1">สัดส่วนเอกสารขายเดือนนี้</h2><p class="small text-secondary">นับเอกสารที่ Post แล้ว</p><canvas id="sales-mix-chart" aria-label="กราฟสัดส่วนเอกสารขายเงินสดและขายเชื่อ" role="img"></canvas></div></div></div>
            </div>
            <div class="row g-4 mb-4"><div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 class="h5 mb-1">ยอดขายเทียบเป้าสาขา</h2><p class="small text-secondary mb-0">ตัวเลขยอดขายไม่รวม VAT ใช้ฐานเดียวกับรายงานผลงานเทียบเป้า</p></div><a class="btn btn-sm btn-app-soft" href="{{ route('pos.sales-reports.sales-target-performance.index') }}">ดูรายละเอียด</a></div><canvas id="target-chart" aria-label="กราฟยอดขายเทียบเป้าสาขา" role="img"></canvas></div></div></div>
                <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">งานที่ต้องติดตาม</h2><div class="vstack gap-3">
                    @if ($canViewOrders)
                        <div class="d-flex justify-content-between gap-3"><div><div class="fw-semibold">ใบสั่งขายรอยืนยัน</div><small class="text-secondary">พร้อมสร้าง HS/IV หลังตรวจสอบ</small></div><a class="btn btn-sm btn-app-soft align-self-center" href="{{ route('pos.sales-orders.index') }}?status=CONFIRMED"><span data-work="confirmed_orders">—</span> รายการ</a></div>
                        <div class="border-top"></div>
                        <div class="d-flex justify-content-between gap-3"><div><div class="fw-semibold">ใบสั่งขายร่าง</div><small class="text-secondary">ตรวจทานก่อนยืนยัน</small></div><a class="btn btn-sm btn-app-soft align-self-center" href="{{ route('pos.sales-orders.index') }}?status=DRAFT"><span data-work="draft_orders">—</span> รายการ</a></div>
                    @endif
                    @if ($canViewPhysicalSales)
                        <div class="border-top"></div>
                        <div class="d-flex justify-content-between gap-3"><div><div class="fw-semibold">เอกสารขายร่าง</div><small class="text-secondary">จัดทำและ Post เพื่อให้ยอดสะท้อนรายงาน</small></div><a class="btn btn-sm btn-app-soft align-self-center" href="{{ route('pos.physical-sales.index') }}"><span data-work="draft_physical_sales">—</span> รายการ</a></div>
                    @endif
                    @if ($canManageBranchTargets)
                        <div class="border-top"></div>
                        <div class="d-flex justify-content-between gap-3"><div><div class="fw-semibold">ตั้งเป้าสาขา</div><small class="text-secondary">กำหนดเป้ารายเดือนเพื่อใช้ติดตามทีมขาย</small></div><a class="btn btn-sm btn-app-soft align-self-center" href="{{ route('pos.branch-sales-targets.create') }}">ตั้งเป้า</a></div>
                    @endif
                </div></div></div></div>
            </div>
            <div class="row g-4 mb-4">
                <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 class="h5 mb-1">สินค้าขายดีเดือนนี้</h2><p class="small text-secondary mb-0">เรียงตามยอดขายสุทธิ ไม่รวม VAT และหักจำนวนที่รับคืนแล้ว</p></div><span class="badge app-status-info">Top 5</span></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th class="text-center" style="width: 4rem">อันดับ</th><th>สินค้า</th><th class="text-end">จำนวน (หน่วย Stock)</th><th class="text-end">ยอดขายสุทธิ</th></tr></thead><tbody id="top-items-body"><tr><td class="text-center text-secondary" colspan="4">กำลังโหลดสินค้าขายดี</td></tr></tbody></table></div></div></div></div>
                <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-1">เอกสารที่ผ่านขั้นตอนแล้ว</h2><p class="small text-secondary mb-3">จำนวนเอกสารเดือนนี้ตามสถานะพร้อมใช้งานของแต่ละประเภท</p><div class="list-group list-group-flush" id="document-counts-body"><div class="text-secondary small py-3">กำลังโหลดจำนวนเอกสาร</div></div></div></div></div>
            </div>
        @endif

        <div class="row g-4"><div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><h2 class="h5 mb-0">เอกสารขายที่ Post ล่าสุด</h2>@if($canViewPhysicalSales)<a class="btn btn-sm btn-app-soft" href="{{ route('pos.physical-sales.index') }}">ดูทั้งหมด</a>@endif</div>@if($canViewPhysicalSales)<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>เลขที่</th><th>ลูกค้า</th><th>วันที่ Post</th><th class="text-end">ยอดรวม</th></tr></thead><tbody id="recent-sales-body"><tr><td class="text-center text-secondary" colspan="4">กำลังโหลดเอกสารล่าสุด</td></tr></tbody></table></div>@else<div class="text-secondary py-4">คุณไม่มีสิทธิ์ดูเอกสารขาย</div>@endif</div></div></div><div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">ลำดับการทำงาน</h2><ol class="mb-0 ps-3"><li class="mb-3">ตรวจสอบและยืนยันใบสั่งขาย</li><li class="mb-3">สร้างและ Post HS/IV เพื่อให้ยอดขายสะท้อนใน Dashboard</li><li class="mb-0">ติดตามเป้าสาขาและผลงานพนักงานจากรายงานเทียบเป้า</li></ol></div></div></div></div>
    </div>
@endsection

@push('styles')
<style>
    .pos-dashboard { --pos-ink: #172033; --pos-muted: #7a8495; --pos-purple: #6d5ce8; --pos-blue: #3f8cff; --pos-green: #22a879; --pos-orange: #e79a32; }
    .pos-hero { position: relative; overflow: hidden; padding: 2rem; border: 1px solid rgba(91,88,180,.1); border-radius: 1.4rem; background: radial-gradient(circle at 82% 18%, rgba(109,92,232,.14), transparent 28%), radial-gradient(circle at 12% 90%, rgba(48,199,212,.08), transparent 25%), linear-gradient(135deg, #fff 0%, #f7f7ff 58%, #eef5ff 100%); box-shadow: 0 1rem 3rem rgba(48,61,95,.08); }
    .pos-hero::before { position: absolute; inset: 0; content: ''; opacity: .35; background-image: linear-gradient(rgba(89,99,130,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(89,99,130,.06) 1px, transparent 1px); background-size: 38px 38px; mask-image: linear-gradient(90deg, #000, transparent 80%); }
    .pos-hero-orb { position: absolute; top: -7rem; right: 9%; width: 18rem; height: 18rem; border-radius: 50%; background: rgba(109,92,232,.06); box-shadow: 0 0 6rem rgba(109,92,232,.1); }
    .pos-kicker { display: flex; align-items: center; gap: .5rem; margin-bottom: .55rem; color: var(--pos-purple); font-size: .68rem; font-weight: 800; letter-spacing: .13em; }
    .pos-kicker span { width: .45rem; height: .45rem; border-radius: 50%; background: var(--pos-green); box-shadow: 0 0 0 .25rem rgba(34,168,121,.1); }
    .pos-hero h1 { margin: 0; color: var(--pos-ink); font-size: clamp(1.8rem, 3vw, 2.7rem); font-weight: 750; letter-spacing: -.04em; }
    .pos-hero p { max-width: 650px; margin-top: .65rem; color: #667085; }
    .pos-branch-chip { display: inline-flex; align-items: center; gap: .4rem; margin-top: 1rem; padding: .4rem .7rem; border: 1px solid #e4e7ec; border-radius: 999px; color: #475467; background: rgba(255,255,255,.78); font-size: .75rem; font-weight: 600; }
    .pos-report-button { display: flex; min-width: 210px; align-items: center; justify-content: space-between; gap: 1rem; padding: .8rem 1rem; border: 1px solid rgba(109,92,232,.13); border-radius: 1rem; color: #5c4bcf; background: rgba(255,255,255,.82); box-shadow: 0 .65rem 1.8rem rgba(60,67,105,.08); text-align: left; }
    .pos-report-button:hover, .pos-report-button:focus-visible { color: #4d3dbd; background: #fff; transform: translateY(-1px); }
    .pos-report-button small, .pos-report-button strong { display: block; }
    .pos-report-button small { color: #8a93a3; font-size: .65rem; }
    .pos-report-button strong { font-size: .83rem; }
    .pos-report-button > i { font-size: 1.35rem; }
    .pos-dashboard .card { overflow: hidden; border: 1px solid rgba(30,42,66,.07) !important; border-radius: 1.15rem; box-shadow: 0 .65rem 2rem rgba(34,45,72,.06) !important; }
    .pos-kpi { transition: transform .2s ease, box-shadow .2s ease; }
    .pos-kpi:hover { transform: translateY(-3px); box-shadow: 0 1rem 2.5rem rgba(34,45,72,.11) !important; }
    .pos-kpi .card-body { padding: 1.15rem; }
    .pos-kpi-head { display: flex; align-items: center; justify-content: space-between; gap: .6rem; margin-bottom: .9rem; }
    .pos-kpi-icon { display: grid; width: 2.45rem; height: 2.45rem; place-items: center; border-radius: .78rem; font-size: 1.28rem; }
    .pos-kpi-icon.green { color: #16865f; background: #eaf9f4; }
    .pos-kpi-icon.purple { color: var(--pos-purple); background: #f0edff; }
    .pos-kpi-icon.blue { color: #2777d8; background: #edf5ff; }
    .pos-kpi-icon.orange { color: #b66b0a; background: #fff6e9; }
    .pos-kpi-label { color: #667085; font-size: .76rem; font-weight: 600; }
    .pos-kpi-value { overflow: hidden; margin-top: .28rem; color: var(--pos-ink); font-size: clamp(1.45rem, 2vw, 1.9rem); font-weight: 750; letter-spacing: -.035em; text-overflow: ellipsis; white-space: nowrap; }
    .pos-kpi small { display: block; overflow: hidden; margin-top: .35rem; color: #98a2b3; font-size: .67rem; text-overflow: ellipsis; white-space: nowrap; }
    .pos-kpi-link { display: inline-flex; align-items: center; gap: .25rem; margin-top: .35rem; color: #6d5ce8; font-size: .7rem; font-weight: 600; text-decoration: none; }
    .pos-sales-split { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); overflow: hidden; border: 1px solid rgba(30,42,66,.07); border-radius: 1.15rem; background: #fff; box-shadow: 0 .65rem 2rem rgba(34,45,72,.05); }
    .pos-sales-split article { display: flex; align-items: center; gap: .9rem; min-width: 0; padding: 1rem 1.2rem; }
    .pos-sales-split article + article { border-left: 1px solid #edf0f5; }
    .pos-split-icon { display: grid; flex: 0 0 auto; width: 2.6rem; height: 2.6rem; place-items: center; border-radius: .8rem; color: #5d4dd4; background: #f0edff; font-size: .72rem; font-weight: 800; }
    .pos-split-icon.iv { color: #2777d8; background: #edf5ff; }
    .pos-split-icon.cn { color: #b42318; background: #fff1ee; font-size: 1.15rem; }
    .pos-sales-split div { min-width: 0; }
    .pos-sales-split small, .pos-sales-split strong, .pos-sales-split p { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pos-sales-split small { color: #7a8495; font-size: .7rem; }
    .pos-sales-split strong { margin-top: .1rem; color: var(--pos-ink); font-size: 1.15rem; }
    .pos-sales-split p { margin: .1rem 0 0; color: #98a2b3; font-size: .64rem; }
    .pos-dashboard .card h2 { color: var(--pos-ink); font-weight: 700; }
    .pos-dashboard .table thead th { color: #7a8495; background: #fafbfc; font-size: .72rem; font-weight: 650; }
    .pos-dashboard .table tbody td { border-color: #f0f2f6; }
    .pos-dashboard canvas { max-height: 310px; }
    .pos-dashboard .list-group-item { border-color: #edf0f5; background: transparent; }
    @media (max-width: 767.98px) {
        .pos-hero { padding: 1.25rem; }
        .pos-report-button { width: 100%; }
        .pos-sales-split { grid-template-columns: 1fr; }
        .pos-sales-split article + article { border-top: 1px solid #edf0f5; border-left: 0; }
    }
    @media (prefers-reduced-motion: reduce) { .pos-kpi, .pos-report-button { transition: none; } }
</style>
@endpush

@push('scripts')
<script>
$(function () {
    var root = $('#pos-dashboard');
    var money = function (value) { return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value || 0); };
    var url = function (section) { return root.data('url').replace('__section__', section); };
    var request = function (section, success) { return $.getJSON(url(section)).done(success).fail(function () { root.find('[data-section="'+section+'"]').text('ไม่สามารถโหลดข้อมูลได้'); }); };
    var chart = function (id, config) { var canvas = document.getElementById(id); if (canvas && window.Chart) new Chart(canvas, config); };
    var colors = { primary: '#6d5ce8', primarySoft: '#c8c1fa', success: '#22a879' };

    request('summary', function (data) {
        root.find('[data-summary="sales_today"]').text(money(data.sales_today));
        root.find('[data-summary="sales_month"]').text(money(data.sales_month));
        root.find('[data-summary="hs_month"]').text(money(data.hs_month));
        root.find('[data-summary="iv_month"]').text(money(data.iv_month));
        root.find('[data-summary="credit_note_month"]').text(money(data.credit_note_month));
        root.find('[data-summary="target_percent"]').text(data.target_percent === null ? '—' : data.target_percent.toFixed(2)+'%');
        root.find('[data-summary="target_hint"]').text(data.target_percent === null ? 'ยังไม่ได้กำหนดเป้าประจำเดือน' : 'อ้างอิงยอดขายไม่รวม VAT');
        chart('target-chart', { type: 'bar', data: { labels: ['ยอดขายสาขา'], datasets: [{ label: 'เป้า', data: [data.target_sales], backgroundColor: colors.primarySoft, borderRadius: 6 }, { label: 'ทำได้', data: [data.actual_target_sales], backgroundColor: colors.primary, borderRadius: 6 }] }, options: { responsive: true, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (context) { return context.dataset.label+' '+money(context.raw); } } } }, scales: { y: { beginAtZero: true, ticks: { callback: money } } } } });
    }).always(function () {
        request('trend', function (data) { chart('sales-trend-chart', { type: 'line', data: { labels: data.labels, datasets: [{ label: 'ยอดขายสุทธิ', data: data.values, borderColor: colors.primary, backgroundColor: 'rgba(29,112,247,.12)', fill: true, tension: .35, pointRadius: 3 }] }, options: { responsive: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (context) { return 'ยอดขายสุทธิ '+money(context.raw); } } } }, scales: { y: { beginAtZero: true, ticks: { callback: money } } } } }); }).always(function () {
            request('mix', function (data) { chart('sales-mix-chart', { type: 'doughnut', data: { labels: ['HS · ขายสด', 'IV · ขายเชื่อ'], datasets: [{ data: data.values, backgroundColor: [colors.primary, colors.success], borderWidth: 0 }] }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } } }); }).always(function () {
                request('work', function (data) { $.each(data, function (key, value) { root.find('[data-work="'+key+'"]').text(value === null ? '—' : new Intl.NumberFormat('th-TH').format(value)); }); }).always(function () {
                    request('recent', function (items) { var body = $('#recent-sales-body').empty(); if (!items.length) { body.append($('<tr>').append($('<td>', { colspan: 4, class: 'text-center text-secondary', text: 'ยังไม่มีเอกสารขายที่ Post แล้วในสาขานี้' }))); return; } $.each(items, function (_, item) { var row = $('<tr>'); var link = $('<a>', { href: item.show_url, text: item.document_number }); row.append($('<td>').append(link).append($('<small>', { class: 'text-secondary d-block', text: item.document_type === 'HS' ? 'ขายสด' : 'ขายเชื่อ' }))); row.append($('<td>', { text: item.party_name })); row.append($('<td>', { text: item.posting_date })); row.append($('<td>', { class: 'text-end', text: money(item.total_amount) })); body.append(row); }); }).always(function () {
                        request('top-items', function (items) {
                            var body = $('#top-items-body').empty();
                            if (!items.length) { body.append($('<tr>').append($('<td>', { colspan: 4, class: 'text-center text-secondary', text: 'ยังไม่มีรายการสินค้าที่ Post แล้วในเดือนนี้' }))); return; }
                            $.each(items, function (_, item) {
                                var row = $('<tr>');
                                row.append($('<td>', { class: 'text-center fw-semibold', text: item.rank }));
                                row.append($('<td>').append($('<div>', { class: 'fw-semibold', text: item.item_name })).append($('<small>', { class: 'text-secondary d-block', text: item.item_code })));
                                row.append($('<td>', { class: 'text-end', text: new Intl.NumberFormat('th-TH', { maximumFractionDigits: 2 }).format(item.quantity) }));
                                row.append($('<td>', { class: 'text-end fw-semibold', text: money(item.net_sales) }));
                                body.append(row);
                            });
                        }).always(function () {
                            request('document-counts', function (items) {
                                var body = $('#document-counts-body').empty();
                                if (!items.length) { body.append($('<div>', { class: 'text-secondary small py-3', text: 'คุณไม่มีสิทธิ์ดูเอกสารในส่วนนี้' })); return; }
                                $.each(items, function (_, item) {
                                    var row = $('<div>').addClass('list-group-item px-0 d-flex justify-content-between align-items-center gap-3');
                                    row.append($('<span>', { text: item.label }));
                                    row.append($('<span>', { class: 'badge app-badge-soft fs-6', text: new Intl.NumberFormat('th-TH').format(item.count) }));
                                    body.append(row);
                                });
                            }).always(function () {
                                request('receivable-alert', function (alert) {
                                    if (!alert.count || !window.Swal) return;
                                    Swal.fire({
                                        toast: true,
                                        position: 'top-end',
                                        icon: 'warning',
                                        title: 'มีลูกหนี้ใกล้ครบกำหนดชำระ',
                                        html: 'พบ <strong>'+new Intl.NumberFormat('th-TH').format(alert.count)+'</strong> รายการ ยอดคงเหลือ <strong>'+money(alert.total_amount)+'</strong> บาท',
                                        showConfirmButton: true,
                                        confirmButtonText: 'ดูรายการ',
                                        showCancelButton: true,
                                        cancelButtonText: 'ภายหลัง',
                                        timer: 12000,
                                        timerProgressBar: true
                                    }).then(function (result) { if (result.isConfirmed) window.location.href = alert.url; });
                                });
                            });
                        });
                    });
                });
            });
        });
    });
});
</script>
@endpush
