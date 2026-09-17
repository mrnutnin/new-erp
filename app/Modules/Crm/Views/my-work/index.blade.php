@extends('Crm::layout')
@section('title', 'งานของฉัน | CRM')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4 crm-page module-dashboard module-dashboard--crm">
    <div class="module-dashboard-hero d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4 crm-page-header">
        <div><p class="module-dashboard-kicker mb-2"><span></span>CRM / MY WORK</p><h1>งานของฉัน</h1><p class="mb-0">เริ่มต้นวันด้วยลูกค้าที่ต้องติดตาม และไม่ปล่อยให้โอกาสการขายตกหล่น</p></div>
        @if(auth()->user()->hasPermission('crm.opportunities.create'))<a class="btn btn-app-primary" href="{{ route('crm.opportunities.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างโอกาสการขาย</a>@endif
    </div>

    <section class="row g-3 mb-4 module-dashboard-summary crm-work-summary" aria-label="สรุปงานของฉัน">
        @foreach([
            ['TODAY','วันนี้','today_count','bx-calendar-star'],
            ['OVERDUE','เกินกำหนด','overdue_count','bx-alarm-exclamation'],
            ['UPCOMING','ถัดไป','upcoming_count','bx-calendar-event'],
            ['NO_NEXT_ACTION','ยังไม่มีงานถัดไป','no_next_count','bx-calendar-x'],
            ['COMPLETED','เสร็จล่าสุด','completed_count','bx-check-circle'],
        ] as [$code,$label,$field,$icon])
            <div class="col-6 col-lg"><button class="card border-0 shadow-sm h-100 w-100 text-start crm-work-filter {{ $view === $code ? 'is-active' : '' }}" type="button" data-view="{{ $code }}"><span class="card-body d-block"><i class="bx {{ $icon }} crm-work-summary-icon" aria-hidden="true"></i><span class="small text-secondary d-block mt-2">{{ $label }}</span><strong class="fs-3 crm-kpi-value">{{ number_format((int)($summary->{$field} ?? 0)) }}</strong></span></button></div>
        @endforeach
    </section>

    <div class="card border-0 shadow-sm mb-4 crm-filter-card"><div class="card-body p-3 p-lg-4"><form class="d-flex flex-column flex-sm-row gap-2" id="my-work-form"><input type="hidden" name="view" id="my-work-view" value="{{ $view }}"><label class="visually-hidden" for="my-work-search">ค้นหางาน</label><div class="input-group"><span class="input-group-text bg-white"><i class="bx bx-search" aria-hidden="true"></i></span><input class="form-control" id="my-work-search" name="q" value="{{ $search }}" maxlength="100" placeholder="ค้นหาชื่อโอกาส ลูกค้า หรือโทรศัพท์"></div><button class="btn btn-app-primary flex-shrink-0" type="submit">ค้นหา</button></form></div></div>

    <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h2 class="h5 mb-0" id="my-work-heading">งานวันนี้</h2><span class="small text-secondary" id="my-work-summary" aria-live="polite">กำลังโหลด...</span></div>
    <div class="row g-3" id="my-work-list" aria-live="polite" aria-busy="true"><div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body py-5 text-center text-secondary"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>กำลังโหลดงาน...</div></div></div></div>
    <nav class="mt-4" id="my-work-pagination" aria-label="หน้ารายการงานของฉัน"></nav>
</div>
@endsection
@push('scripts')
<script>
$(function(){
 const form=$('#my-work-form'),list=$('#my-work-list'),pagination=$('#my-work-pagination'),summary=$('#my-work-summary'),heading=$('#my-work-heading'),viewInput=$('#my-work-view'),indexUrl=@json(route('crm.my-work.index')),dataUrl=@json(route('crm.my-work.data')),labels={TODAY:'งานวันนี้',OVERDUE:'งานเกินกำหนด',UPCOMING:'งานถัดไป',NO_NEXT_ACTION:'โอกาสที่ยังไม่มีงานถัดไป',COMPLETED:'งานที่เสร็จแล้ว',ALL:'งานทั้งหมด'};
 let request;
 function query(page){const params=new URLSearchParams(new FormData(form[0]));if(page>1)params.set('page',page);return params;}
 function load(page,updateUrl){
  if(request)request.abort();const params=query(page||1);list.attr('aria-busy','true').html('<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body py-5 text-center text-secondary"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>กำลังโหลดงาน...</div></div></div>');pagination.empty();summary.text('กำลังโหลด...');heading.text(labels[viewInput.val()]||'งานของฉัน');
  request=$.getJSON(dataUrl,params.toString()).done(function(data){list.html(data.html).attr('aria-busy','false');pagination.html(data.pagination);summary.text(data.total?'แสดง '+Number(data.from).toLocaleString('th-TH')+'–'+Number(data.to).toLocaleString('th-TH')+' จาก '+Number(data.total).toLocaleString('th-TH')+' รายการ':'ไม่พบรายการ');if(updateUrl)history.pushState({},'',indexUrl+'?'+params.toString());}).fail(function(xhr,status){if(status==='abort')return;list.attr('aria-busy','false').html('<div class="col-12"><div class="alert alert-danger">โหลดงานไม่สำเร็จ กรุณาลองใหม่</div></div>');summary.text('โหลดข้อมูลไม่สำเร็จ');});
 }
 $('.crm-work-filter').on('click',function(){viewInput.val($(this).data('view'));$('.crm-work-filter').removeClass('is-active');$(this).addClass('is-active');load(1,true);});
 form.on('submit',function(event){event.preventDefault();load(1,true);});
 pagination.on('click','a.page-link',function(event){event.preventDefault();load(Number(new URL(this.href).searchParams.get('page')||1),true);});
 list.on('click','.js-complete-work',function(){const button=$(this);button.prop('disabled',true);$.post(button.data('url'),{_token:$('meta[name=csrf-token]').attr('content')}).done(function(){load(Number(new URL(location.href).searchParams.get('page')||1),false);}).fail(function(xhr){button.prop('disabled',false);window.erpAjaxError(xhr);});});
 window.addEventListener('popstate',function(){location.reload();});
 load(Number(new URL(location.href).searchParams.get('page')||1),false);
});
</script>
@endpush
