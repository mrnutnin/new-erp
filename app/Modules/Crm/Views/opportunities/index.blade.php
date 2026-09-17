@extends('Crm::layout')
@section('title', 'โอกาสการขาย | CRM')
@php
    $hasFilters = collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty();
@endphp
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4 crm-page module-dashboard module-dashboard--crm">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4 crm-page-header module-dashboard-hero">
        <div><p class="module-dashboard-kicker mb-2"><span></span>CRM / OPPORTUNITIES</p><h1 class="h3 mb-1">โอกาสการขาย</h1><p class="text-secondary mb-0">ติดตามลูกค้าเป้าหมายตั้งแต่เริ่มติดต่อจนเชื่อมต่อเอกสารขายใน POS</p></div>
        <div class="d-flex flex-wrap gap-2"><a class="btn btn-app-soft" href="{{ route('crm.opportunities.kanban') }}"><i class="bx bx-columns me-1" aria-hidden="true"></i>Kanban Pipeline</a>@if(auth()->user()->hasPermission('crm.opportunities.create'))<a class="btn btn-app-primary" href="{{ route('crm.opportunities.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างโอกาสการขาย</a>@endif</div>
    </div>

    <div class="card border-0 shadow-sm mb-4 crm-filter-card"><div class="card-body p-3 p-lg-4">
        <div class="d-flex justify-content-between align-items-center gap-2">
            <div><h2 class="h5 mb-1">ค้นหาและกรอง</h2><p class="small text-secondary mb-0">ค้นหาชื่อ ลูกค้า ผู้ติดต่อ หรือผู้รับผิดชอบ</p></div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-app-soft d-md-none" type="button" data-bs-toggle="collapse" data-bs-target="#opportunity-filters" aria-expanded="{{ $hasFilters ? 'true' : 'false' }}" aria-controls="opportunity-filters"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>ตัวกรอง</button>
                @if($hasFilters)<a class="btn btn-sm btn-app-soft" href="{{ route('crm.opportunities.index') }}"><i class="bx bx-reset me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">ล้างตัวกรอง</span></a>@endif
            </div>
        </div>
        <form class="collapse d-md-block mt-3 {{ $hasFilters ? 'show' : '' }}" id="opportunity-filters" method="get" action="{{ route('crm.opportunities.index') }}">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-4"><label class="form-label" for="q">คำค้นหา</label><div class="input-group"><span class="input-group-text bg-white"><i class="bx bx-search" aria-hidden="true"></i></span><input class="form-control" id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="ชื่อโอกาส ลูกค้า โทรศัพท์..."></div></div>
                <div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="stage-filter">ขั้นตอน</label><select class="form-select" id="stage-filter" name="stage"><option value="">ทุกขั้นตอน</option>@foreach($stages as $code=>$stage)<option value="{{ $code }}" @selected(($filters['stage'] ?? '') === $code)>{{ $stage['label'] }}</option>@endforeach</select></div>
                <div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="owner-filter">ผู้รับผิดชอบ</label><select class="form-select" id="owner-filter" name="owner_id" data-url="{{ route('crm.opportunities.owner-options') }}"><option value="">ทุกคน</option>@if($selectedOwner)<option value="{{ $selectedOwner->id }}" selected>{{ $selectedOwner->employee_code ? $selectedOwner->employee_code.' · ' : '' }}{{ $selectedOwner->name }}</option>@endif</select></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="date-from">ปิดตั้งแต่</label><input class="form-control" id="date-from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}"></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="date-to">ถึงวันที่</label><input class="form-control" id="date-to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}"></div>
                <div class="col-12"><button class="btn btn-app-primary" type="submit"><i class="bx bx-search me-1" aria-hidden="true"></i>ค้นหา</button></div>
            </div>
        </form>
    </div></div>

    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-1 mb-3" aria-live="polite">
        <h2 class="h5 mb-0">รายการโอกาสการขาย</h2>
        <span class="small text-secondary" id="opportunity-summary">กำลังโหลด...</span>
    </div>

    <div class="row g-3 crm-opportunity-list" id="opportunity-list" aria-live="polite" aria-busy="true">
        <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body py-5 text-center text-secondary"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>กำลังโหลดรายการ...</div></div></div>
    </div>
    <nav class="mt-4" id="opportunity-pagination" aria-label="หน้ารายการโอกาสการขาย"></nav>
</div>
@endsection
@push('scripts')
<script>
$(function(){
 const form=$('#opportunity-filters'),owner=$('#owner-filter'),list=$('#opportunity-list'),pagination=$('#opportunity-pagination'),summary=$('#opportunity-summary'),indexUrl=@json(route('crm.opportunities.index')),dataUrl=@json(route('crm.opportunities.data'));
 let request;
 window.erpInitSelect2(owner,{theme:'bootstrap-5',placeholder:'ทุกคน',allowClear:true,ajax:{url:owner.data('url'),dataType:'json',delay:250,data:p=>({q:p.term||'',page:p.page||1}),processResults:d=>d}});
 function params(page){const values=new URLSearchParams(new FormData(form[0]));if(page>1)values.set('page',page);return values;}
 function load(page=1,updateUrl=false){
  if(request)request.abort();
  const query=params(page);list.attr('aria-busy','true').html('<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body py-5 text-center text-secondary"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>กำลังโหลดรายการ...</div></div></div>');pagination.empty();summary.text('กำลังโหลด...');
  request=$.ajax({url:dataUrl,data:query.toString(),dataType:'json'}).done(function(data){
   list.html(data.html).attr('aria-busy','false');pagination.html(data.pagination);summary.text(data.total?'แสดง '+Number(data.from).toLocaleString('th-TH')+'–'+Number(data.to).toLocaleString('th-TH')+' จาก '+Number(data.total).toLocaleString('th-TH')+' รายการ':'ไม่พบรายการ');
   if(updateUrl){history.pushState({},'',indexUrl+(query.toString()?'?'+query.toString():''));window.scrollTo({top:Math.max(0,list.offset().top-100),behavior:'smooth'});}
  }).fail(function(xhr,status){if(status==='abort')return;list.attr('aria-busy','false').html('<div class="col-12"><div class="alert alert-danger mb-0">โหลดรายการไม่สำเร็จ <button class="btn btn-sm btn-app-soft ms-2" type="button" id="retry-opportunities">ลองอีกครั้ง</button></div></div>');summary.text('โหลดข้อมูลไม่สำเร็จ');});
 }
 form.on('submit',function(event){event.preventDefault();load(1,true);});
 pagination.on('click','a.page-link',function(event){event.preventDefault();const page=Number(new URL(this.href).searchParams.get('page')||1);load(page,true);});
 list.on('click','#retry-opportunities',()=>load(Number(new URL(location.href).searchParams.get('page')||1)));
 window.addEventListener('popstate',()=>location.reload());
 load(Number(new URL(location.href).searchParams.get('page')||1));
});
</script>
@endpush
