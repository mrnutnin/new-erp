@extends('Wms::layout')

@section('title', 'WMS Dashboard | New ERP')

@section('content')
<div class="container-fluid px-3 px-lg-4 py-4" id="wms-dashboard" data-url="{{ route('wms.dashboard.data', ['section' => '__section__']) }}">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / INVENTORY</p><h1 class="h3 mb-1">WMS Dashboard</h1><p class="text-secondary mb-0">ภาพรวมสต็อก งานค้าง และความเคลื่อนไหวของคลังที่เลือก</p></div>
        <div class="d-flex flex-wrap gap-2">
            @if(auth()->user()->hasPermission('wms.issues.create'))<a class="btn btn-app-soft" href="{{ route('wms.issues.create') }}"><i class="bx bx-minus-circle me-1" aria-hidden="true"></i>สร้างใบเบิก</a>@endif
            @if(auth()->user()->hasPermission('wms.transfers.create'))<a class="btn btn-app-soft" href="{{ route('wms.transfers.create') }}"><i class="bx bx-transfer me-1" aria-hidden="true"></i>สร้าง Transfer</a>@endif
        </div>
    </div>

    <section class="row g-3 mb-4" aria-label="WMS summary">
        @foreach ([['on_hand', 'คงเหลือในคลัง', 'หน่วยฐาน'], ['available', 'พร้อมใช้', 'หักยอดจองแล้ว'], ['reserved', 'ยอดจอง', 'หน่วยฐาน'], ['inventory_value', 'มูลค่าสินค้าคงเหลือ', 'ตาม valuation'], ['negative_stock', 'สินค้าติดลบ', 'ต้องตรวจสอบ'], ['pending_recost', 'รอ RECOST', 'ต้องดำเนินการ']] as [$key, $label, $hint])
            <div class="col-12 col-sm-6 col-xl-2"><article class="card border-0 shadow-sm h-100"><div class="card-body p-3"><div class="small text-secondary">{{ $label }}</div><div class="h4 mb-1 mt-2" data-summary="{{ $key }}">กำลังโหลด…</div><div class="small text-secondary">{{ $hint }}</div></div></article></div>
        @endforeach
    </section>

    <section class="card border-0 shadow-sm mb-4" aria-labelledby="wms-trend-title"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-2 mb-3"><div><h2 id="wms-trend-title" class="h5 mb-1">แนวโน้ม Stock Movement</h2><p class="text-secondary small mb-0">จำนวนสินค้าเข้า–ออกที่ลง Stock แล้ว ย้อนหลัง 6 เดือน</p></div><span class="badge app-badge-soft">Posted เท่านั้น</span></div><div id="wms-trend-state" class="small text-secondary">กำลังโหลดข้อมูล…</div><div style="height:280px"><canvas id="wms-trend-chart" aria-label="กราฟแนวโน้มสินค้าเข้าออก"></canvas></div></div></section>

    <section class="mb-4" aria-labelledby="wms-work-title"><h2 id="wms-work-title" class="h5 mb-3">งานที่ต้องติดตาม</h2><div class="row g-3"><div class="col-12 col-lg-6"><article class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h3 class="h6 mb-3">เอกสารและการโอน</h3><div class="wms-work-list" data-work-group="documents"><div class="small text-secondary">กำลังโหลด…</div></div></div></article></div><div class="col-12 col-lg-6"><article class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-lg-4"><h3 class="h6 mb-3">การควบคุมสต็อกและต้นทุน</h3><div class="wms-work-list" data-work-group="controls"><div class="small text-secondary">กำลังโหลด…</div></div></div></article></div></div></section>

    <section class="row g-3" aria-label="WMS operational detail">
        <div class="col-12"><article class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">สินค้าต่ำกว่าจุด Min</h2><p class="text-secondary small mb-0">แนะนำเติมถึง Max เพื่อให้พิจารณาต่อ</p></div><a class="btn btn-sm btn-outline-secondary" href="{{ route('wms.stock-policies.index') }}">Stock Policy</a></div><div class="table-responsive"><table id="wms-low-stock" class="table table-hover align-middle w-100" data-url="{{ route('wms.dashboard.data', ['section' => 'low-stock']) }}"><thead><tr><th>สินค้า</th><th class="text-end">พร้อมใช้</th><th class="text-end">Min</th><th class="text-end">Max</th><th class="text-end">แนะนำเติม</th></tr></thead></table></div></div></article></div>
        <div class="col-12"><article class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">ความเคลื่อนไหวล่าสุด</h2><p class="text-secondary small mb-0">เฉพาะรายการที่ลง Stock แล้ว</p></div><a class="btn btn-sm btn-outline-secondary" href="{{ route('wms.stock.index') }}">ดู Stock Card</a></div><div class="table-responsive"><table id="wms-movements" class="table table-hover align-middle w-100" data-url="{{ route('wms.dashboard.data', ['section' => 'movements']) }}"><thead><tr><th>วันที่</th><th>สินค้า</th><th>ประเภท</th><th>ทิศทาง</th><th class="text-end">จำนวน</th><th>อ้างอิง</th></tr></thead></table></div></div></article></div>
    </section>
</div>
@endsection

@push('scripts')
<script>
$(function(){
    var root=$('#wms-dashboard'), endpoint=function(section){return root.data('url').replace('__section__',section);}, money=function(v){return Number(v||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});}, count=function(v){return Number(v||0).toLocaleString('th-TH');};
    $.getJSON(endpoint('summary')).done(function(data){$.each(data,function(key,value){root.find('[data-summary="'+key+'"]').text(['inventory_value'].indexOf(key)>=0?money(value):(['negative_stock','pending_recost'].indexOf(key)>=0?count(value):value));});}).fail(function(){root.find('[data-summary]').text('โหลดไม่สำเร็จ');});
    $.getJSON(endpoint('trend')).done(function(data){$('#wms-trend-state').remove();if(!window.Chart){return;}new Chart(document.getElementById('wms-trend-chart'),{type:'bar',data:{labels:data.labels,datasets:[{label:'รับเข้า',data:data.in,backgroundColor:'#198754',borderRadius:6},{label:'จ่ายออก',data:data.out,backgroundColor:'#dc3545',borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{tooltip:{callbacks:{label:function(c){return c.dataset.label+': '+Number(c.raw||0).toLocaleString('th-TH');}}}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return Number(v).toLocaleString('th-TH');}}}}}});}).fail(function(){$('#wms-trend-state').text('โหลดกราฟไม่สำเร็จ');});
    var workLinks={opening_balances:['Opening Balance รอ Post','{{ route('wms.opening-balances.index') }}'],issues:['ใบเบิกรอดำเนินการ','{{ route('wms.issues.index') }}'],returns:['ใบรับคืนรอดำเนินการ','{{ route('wms.issue-returns.index') }}'],stock_counts:['Stock Count รอดำเนินการ','{{ route('wms.stock-counts.index') }}'],adjustments:['Adjustment รอดำเนินการ','{{ route('wms.inventory-adjustments.index') }}'],transfers:['Transfer รอดำเนินการ','{{ route('wms.transfers.index') }}'],recost:['RECOST รอดำเนินการ','{{ route('wms.stock-valuation.index') }}']};
    $.getJSON(endpoint('work')).done(function(data){var groups={documents:['opening_balances','issues','returns','transfers'],controls:['stock_counts','adjustments','recost']};$.each(groups,function(group,keys){var box=root.find('[data-work-group="'+group+'"]').empty();keys.forEach(function(key){var item=workLinks[key];box.append($('<a>',{href:item[1],class:'d-flex justify-content-between align-items-center text-reset text-decoration-none border-bottom py-2'}).append($('<span>',{text:item[0]})).append($('<span>',{class:'badge app-badge-soft fs-6',text:count(data[key])})));});});}).fail(function(){root.find('.wms-work-list').text('โหลดรายการไม่สำเร็จ');});
    var text=$.fn.dataTable.render.text(), defaults=window.erpDataTableDefaults;
    $('#wms-low-stock').DataTable($.extend(true,{},defaults,{ajax:$('#wms-low-stock').data('url'),pageLength:5,buttons:[window.erpExcelButton($('#wms-low-stock'))],columns:[{data:'item_label',render:text.display},{data:'available',className:'text-end',render:text.display},{data:'min_quantity',className:'text-end',render:text.display},{data:'max_quantity',className:'text-end',render:text.display},{data:'recommended',className:'text-end fw-semibold',render:text.display}]}));
    $('#wms-movements').DataTable($.extend(true,{},defaults,{ajax:$('#wms-movements').data('url'),pageLength:5,buttons:[window.erpExcelButton($('#wms-movements'))],order:[[0,'desc']],columns:[{data:'business_date',render:text.display},{data:'item_label',render:text.display},{data:'movement_type',render:text.display},{data:'direction_label',render:text.display},{data:'base_quantity',className:'text-end',render:text.display},{data:'source_reference',render:function(v){return text.display(v||'—');}}]}));
});
</script>
@endpush
