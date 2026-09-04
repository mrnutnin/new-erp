@extends('Accounting::layout')

@section('title', 'Accounting Dashboard | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div><p class="eyebrow mb-2">ACCOUNTING / OVERVIEW</p><h1 class="h3 mb-2">Accounting Dashboard</h1><p class="text-secondary mb-0">ภาพรวมการลงบัญชีและสถานะงวดปัจจุบัน · {{ $warehouse?->branch?->name }} — {{ $warehouse?->name }}</p></div>
            <div class="d-flex flex-wrap gap-2 align-items-end"><div><label class="form-label small mb-1" for="dashboard-branch">สาขา</label><select class="form-select form-select-sm" id="dashboard-branch"><option value="current">สาขาปัจจุบัน</option><option value="all">ทุกสาขาที่มีสิทธิ์</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div><div><label class="form-label small mb-1" for="dashboard-period">งวดบัญชี</label><select class="form-select form-select-sm" id="dashboard-period">@foreach($periods as $period)<option value="{{ $period->id }}">{{ $period->fiscalYear->name }} / {{ $period->name }}</option>@endforeach</select></div><a class="btn btn-app-soft btn-sm" href="{{ route('accounting.workflow.index') }}"><i class="bx bx-map-alt me-1" aria-hidden="true"></i>คู่มือการทำงาน</a></div>
        </div>
        <section class="row g-3 mb-4" aria-label="สรุปการเงิน">
            @foreach ([['cash','เงินสด/ธนาคาร','bx-building-house','success'],['receivable','ลูกหนี้คงค้าง (AR)','bx-user','info'],['payable','เจ้าหนี้คงค้าง (AP)','bx-wallet','warning'],['profit','กำไรสุทธิ','bx-line-chart','primary'],['revenue','รายได้ (MTD)','bx-coin-stack','info'],['expense','ค่าใช้จ่าย (MTD)','bx-down-arrow-circle','danger']] as [$key,$label,$icon,$color])
                <div class="col-12 col-sm-6 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3"><div class="d-flex align-items-center gap-2 mb-2"><span class="rounded-circle bg-{{ $color }} bg-opacity-10 text-{{ $color }} p-2"><i class="bx {{ $icon }} fs-5" aria-hidden="true"></i></span><div class="small text-secondary">{{ $label }}</div></div><div class="h4 mb-1" data-dashboard-financial="{{ $key }}">—</div><div class="small text-success">ภาพรวมงวดปัจจุบัน</div></div></div></div>
            @endforeach
        </section>
        <section class="row g-3 mb-4"><div class="col-12 col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">รายได้ vs ค่าใช้จ่าย</h2><div style="height:240px"><canvas id="accounting-revenue-chart" aria-label="กราฟรายได้และค่าใช้จ่าย"></canvas></div></div></div></div><div class="col-12 col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">กระแสเงินสด (Cash Flow)</h2><div style="height:180px"><canvas id="accounting-cash-chart" aria-label="กราฟกระแสเงินสด"></canvas></div><div class="d-flex justify-content-between"><span>Net Cash Flow</span><strong data-dashboard-financial="cash">—</strong></div></div></div></div></section>
        <section class="row g-3 mb-4" aria-label="สถานะบัญชี">@foreach ([['posted','Journal Posted'],['pending','รอดำเนินการ'],['accounts','บัญชี Postable']] as [$key,$label])<div class="col-6 col-md-4"><div class="card border-0 bg-light h-100"><div class="card-body p-3"><div class="small text-secondary">{{ $label }}</div><div class="h5 mb-0" data-dashboard-stat="{{ $key }}">—</div></div></div></div>@endforeach</section>
        <section class="row g-3 mb-4"><div class="col-12 col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">Accounts Receivable Aging</h2><div style="height:190px"><canvas id="accounting-ar-chart"></canvas></div><div class="d-grid gap-2 mt-3" data-aging="AR"></div></div></div></div><div class="col-12 col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">Accounts Payable Aging</h2><div style="height:190px"><canvas id="accounting-ap-chart"></canvas></div><div class="d-grid gap-2 mt-3" data-aging="AP"></div></div></div></div></section>
        <section class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">Accounting Alerts</h2><div class="text-secondary small" data-dashboard-alerts>กำลังตรวจสอบรายการแจ้งเตือน…</div></div></section>
        <section class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><h2 class="h5 mb-3">Integration Overview</h2><div class="row g-2"><div class="col-6 col-lg-3"><div class="border rounded p-3"><strong><i class="bx bx-shopping-bag text-warning me-1"></i>Sales → AR</strong><div class="small text-secondary">ยอดลูกหนี้จากการขาย</div><div class="fw-semibold" data-dashboard-integration="receivable">—</div></div></div><div class="col-6 col-lg-3"><div class="border rounded p-3"><strong><i class="bx bx-cart text-info me-1"></i>Purchase → AP</strong><div class="small text-secondary">ยอดเจ้าหนี้จากการซื้อ</div><div class="fw-semibold" data-dashboard-integration="payable">—</div></div></div><div class="col-6 col-lg-3"><div class="border rounded p-3"><strong><i class="bx bx-package text-success me-1"></i>Stock → Accounting</strong><div class="small text-secondary">ตรวจต้นทุนและ GL</div><div class="fw-semibold" data-dashboard-integration="stock">พร้อมตรวจสอบ</div></div></div><div class="col-6 col-lg-3"><div class="border rounded p-3"><strong><i class="bx bx-check-circle text-success me-1"></i>GL Balance</strong><div class="small text-success" data-dashboard-integration="gl">ปกติ</div></div></div></div></div></section>
        <section class="mb-4"><h2 class="h5 mb-3">ทางลัดงานบัญชี</h2><div class="row g-3">
            @if (auth()->user()->hasPermission('accounting.accounts.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-list-ul fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">ผังบัญชี</h2>
                            <p class="text-secondary">จัดการบัญชี หมวดบัญชี และโครงสร้างรายงาน PAE/NPAE</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('accounting.accounts.index') }}">เปิดผังบัญชี</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('accounting.periods.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-calendar fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">ปีและงวดบัญชี</h2>
                            <p class="text-secondary">ดูปีบัญชี งวดบัญชี และสถานะการเปิดหรือปิดงวด</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('accounting.fiscal-years.index') }}">เปิดรายการ</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('accounting.journal-books.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-book-bookmark fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">สมุดบัญชี</h2>
                            <p class="text-secondary">สมุดซื้อ ขาย รับ จ่าย และทั่วไปสำหรับการลงบัญชี</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('accounting.journal-books.index') }}">เปิดรายการ</a>
                        </div>
                    </div>
                </div>
            @endif
            @if (auth()->user()->hasPermission('accounting.reports.view'))
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <i class="bx bx-bar-chart-alt-2 fs-2 mb-3" aria-hidden="true"></i>
                            <h2 class="h5">รายงานบัญชี</h2>
                            <p class="text-secondary">GL, งบทดลอง, งบกำไรขาดทุน และงบดุลจากรายการที่ลงบัญชีแล้ว</p>
                            <a class="btn btn-outline-dark stretched-link" href="{{ route('accounting.reports.trial-balance.index') }}">เปิดรายงาน</a>
                        </div>
                    </div>
                </div>
            @endif
        </div></section>
    </div>
@endsection

@push('scripts')
<script>
$(function(){
    var format=window.erpAccountingFormat||function(v){return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}; var charts=[];
    $('h2').filter(function(){return $.trim($(this).text())==='Integration Overview';}).closest('.card').find('.row > div > div').addClass('h-100 d-flex flex-column').find('[data-dashboard-integration]').addClass('text-end d-block mt-auto');
    function load(){ if(typeof Chart!=='undefined'){['accounting-revenue-chart','accounting-cash-chart','accounting-ar-chart','accounting-ap-chart'].forEach(function(id){var c=Chart.getChart(document.getElementById(id));if(c)c.destroy();});} $.getJSON('{{ route('accounting.dashboard.summary') }}',{branch_scope:$('#dashboard-branch').val(),period_id:$('#dashboard-period').val()}).done(function(payload){
        $.each(payload.stats||{},function(key,value){$('[data-dashboard-stat="'+key+'"]').text(Number(value||0).toLocaleString('en-US'));}); $.each(payload.financial||{},function(key,value){$('[data-dashboard-financial="'+key+'"]').text(format(value));$('[data-dashboard-integration="'+key+'"]').text(format(value));});
        var labels={current:'Current','days_1_30':'1-30 วัน','days_31_60':'31-60 วัน','days_61_90':'61-90 วัน','over_90':'>90 วัน'}; $.each(['AR','AP'],function(_,type){var data=payload.aging&&payload.aging[type]||{},box=$('[data-aging="'+type+'"]').empty();$.each(labels,function(key,label){box.append('<div class="d-flex justify-content-between"><span>'+label+(key==='over_90'?' ⚠️':'')+'</span><strong>'+format(data[key]||0)+'</strong></div>');});}); var alerts=[];if((payload.aging?.AR?.over_90||0)>0)alerts.push('🔴 ลูกหนี้เกิน 90 วัน · '+format(payload.aging.AR.over_90));if((payload.stats?.pending||0)>0)alerts.push('🔴 Journal รออนุมัติ · '+Number(payload.stats.pending).toLocaleString('en-US')+' รายการ');$('[data-dashboard-alerts]').html(alerts.length?alerts.map(function(a){return '<div class="mb-2">'+a+'</div>';}).join(''):'ไม่มีรายการที่ต้องแจ้งเตือน');
        function drawCharts(){if(typeof Chart==='undefined')return;var tr=payload.trend||[],labs=tr.map(function(r){return r.month;});new Chart(document.getElementById('accounting-revenue-chart'),{type:'bar',data:{labels:labs,datasets:[{type:'line',label:'รายได้',data:tr.map(function(r){return +r.revenue||0;}),borderColor:'#16a34a',backgroundColor:'#16a34a'},{type:'line',label:'ค่าใช้จ่าย',data:tr.map(function(r){return +r.expense||0;}),borderColor:'#ef4444',backgroundColor:'#ef4444'},{type:'bar',label:'กำไร',data:tr.map(function(r){return (+r.revenue||0)-(+r.expense||0);}),backgroundColor:'#bfdbfe'}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{callback:function(v){return format(v);}}}}}});new Chart(document.getElementById('accounting-cash-chart'),{type:'doughnut',data:{labels:['เงินเข้า','เงินออก'],datasets:[{data:[payload.financial?.revenue||0,payload.financial?.expense||0],backgroundColor:['#22c55e','#ef4444']}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});[['AR','accounting-ar-chart'],['AP','accounting-ap-chart']].forEach(function(item){var data=payload.aging?.[item[0]]||{};new Chart(document.getElementById(item[1]),{type:'doughnut',data:{labels:Object.keys(labels).map(function(k){return labels[k];}),datasets:[{data:Object.keys(labels).map(function(k){return data[k]||0;}),backgroundColor:['#22c55e','#84cc16','#fbbf24','#fb923c','#ef4444']}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}}}});});}
        if(typeof Chart==='undefined'){var script=document.createElement('script');script.src='https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js';script.onload=drawCharts;document.head.appendChild(script);}else drawCharts();
        $('[data-dashboard-period]').text(payload.period_label||'งวดบัญชีปัจจุบัน');
    }).fail(function(){}); }
    $('#dashboard-branch,#dashboard-period').on('change',load); load();
});
</script>
@endpush
