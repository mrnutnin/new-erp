@extends('Pos::layout')

@section('title', 'Sales Dashboard | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4" id="pos-dashboard" data-url="{{ route('pos.dashboard.data', ['section' => '__section__']) }}">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div><p class="eyebrow mb-2">POS / SALES</p><h1 class="h3 mb-1">Sales Dashboard</h1><p class="text-secondary mb-0">ภาพรวมของสาขา {{ $branch->name }} เพื่อดูยอดขาย เป้าหมาย และงานที่ต้องทำต่อ</p></div>
            @if ($canViewReports)<a class="btn btn-app-soft" href="{{ route('pos.sales-reports.sales-target-performance.index') }}"><i class="bx bx-bar-chart-alt-2 me-1" aria-hidden="true"></i>ดูรายงานผลงานเทียบเป้า</a>@endif
        </div>

        @if ($canViewReports)
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">ยอดขายสุทธิวันนี้</div><div class="fs-3 fw-semibold mt-1" data-summary="sales_today">—</div><small class="text-secondary">HS/IV ที่ Post แล้ว หักรับคืน</small></div></div></div>
                <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">ยอดขายสุทธิเดือนนี้</div><div class="fs-3 fw-semibold mt-1" data-summary="sales_month">—</div><small class="text-secondary">ภาพรวมทุกคลังในสาขา</small></div></div></div>
                <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">ผลงานเทียบเป้าสาขา</div><div class="fs-3 fw-semibold mt-1" data-summary="target_percent">—</div><small class="text-secondary" data-summary="target_hint">กำลังโหลดข้อมูล</small></div></div></div>
                <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">HS/IV ร่างรอดำเนินการ</div><div class="fs-3 fw-semibold mt-1" data-work="draft_physical_sales">—</div>@if($canViewPhysicalSales)<a class="small" href="{{ route('pos.physical-sales.index') }}">ไปจัดการเอกสารขาย</a>@endif</div></div></div>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">HS · ขายสดเดือนนี้</div><div class="fs-4 fw-semibold mt-1" data-summary="hs_month">—</div><small class="text-secondary">เอกสารขายสดที่ Post แล้ว</small></div></div></div>
                <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">IV · ขายเชื่อเดือนนี้</div><div class="fs-4 fw-semibold mt-1" data-summary="iv_month">—</div><small class="text-secondary">เอกสารขายเชื่อที่ Post แล้ว</small></div></div></div>
                <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-secondary">ลดหนี้ / รับคืนเดือนนี้</div><div class="fs-4 fw-semibold mt-1" data-summary="credit_note_month">—</div><small class="text-secondary">หักเฉพาะเอกสารต้นทางที่ยัง Post</small></div></div></div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-xl-8"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between gap-3 mb-3"><div><h2 class="h5 mb-1">แนวโน้มยอดขายสุทธิ 7 วันล่าสุด</h2><p class="small text-secondary mb-0">รวม HS/IV ที่ Post แล้ว และหักยอดรับคืนตามวันที่ Post</p></div><span class="badge app-status-info align-self-start">ยอดสุทธิ</span></div><canvas id="sales-trend-chart" aria-label="กราฟแนวโน้มยอดขายสุทธิ 7 วันล่าสุด" role="img"></canvas></div></div></div>
                <div class="col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-1">สัดส่วนเอกสารขายเดือนนี้</h2><p class="small text-secondary">นับเอกสารที่ Post แล้ว</p><canvas id="sales-mix-chart" aria-label="กราฟสัดส่วนเอกสารขายเงินสดและขายเชื่อ" role="img"></canvas></div></div></div>
            </div>
            <div class="row g-4 mb-4"><div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 class="h5 mb-1">ยอดขายเทียบเป้าสาขา</h2><p class="small text-secondary mb-0">ตัวเลขยอดขายไม่รวม VAT ใช้ฐานเดียวกับรายงานผลงานเทียบเป้า</p></div><a class="btn btn-sm btn-outline-secondary" href="{{ route('pos.sales-reports.sales-target-performance.index') }}">ดูรายละเอียด</a></div><canvas id="target-chart" aria-label="กราฟยอดขายเทียบเป้าสาขา" role="img"></canvas></div></div></div>
                <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">งานที่ต้องติดตาม</h2><div class="vstack gap-3">
                    @if ($canViewOrders)
                        <div class="d-flex justify-content-between gap-3"><div><div class="fw-semibold">ใบสั่งขายรอยืนยัน</div><small class="text-secondary">พร้อมสร้าง HS/IV หลังตรวจสอบ</small></div><a class="btn btn-sm btn-app-soft align-self-center" href="{{ route('pos.sales-orders.index') }}?status=CONFIRMED"><span data-work="confirmed_orders">—</span> รายการ</a></div>
                        <div class="border-top"></div>
                        <div class="d-flex justify-content-between gap-3"><div><div class="fw-semibold">ใบสั่งขายร่าง</div><small class="text-secondary">ตรวจทานก่อนยืนยัน</small></div><a class="btn btn-sm btn-outline-secondary align-self-center" href="{{ route('pos.sales-orders.index') }}?status=DRAFT"><span data-work="draft_orders">—</span> รายการ</a></div>
                    @endif
                    @if ($canViewPhysicalSales)
                        <div class="border-top"></div>
                        <div class="d-flex justify-content-between gap-3"><div><div class="fw-semibold">เอกสารขายร่าง</div><small class="text-secondary">จัดทำและ Post เพื่อให้ยอดสะท้อนรายงาน</small></div><a class="btn btn-sm btn-outline-secondary align-self-center" href="{{ route('pos.physical-sales.index') }}"><span data-work="draft_physical_sales">—</span> รายการ</a></div>
                    @endif
                    @if ($canManageBranchTargets)
                        <div class="border-top"></div>
                        <div class="d-flex justify-content-between gap-3"><div><div class="fw-semibold">ตั้งเป้าสาขา</div><small class="text-secondary">กำหนดเป้ารายเดือนเพื่อใช้ติดตามทีมขาย</small></div><a class="btn btn-sm btn-outline-secondary align-self-center" href="{{ route('pos.branch-sales-targets.create') }}">ตั้งเป้า</a></div>
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

@push('scripts')
<script>
$(function () {
    var root = $('#pos-dashboard');
    var money = function (value) { return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value || 0); };
    var url = function (section) { return root.data('url').replace('__section__', section); };
    var request = function (section, success) { return $.getJSON(url(section)).done(success).fail(function () { root.find('[data-section="'+section+'"]').text('ไม่สามารถโหลดข้อมูลได้'); }); };
    var chart = function (id, config) { var canvas = document.getElementById(id); if (canvas && window.Chart) new Chart(canvas, config); };
    var colors = { primary: '#1d70f7', primarySoft: '#9abcf5', success: '#3c8f6b' };

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
