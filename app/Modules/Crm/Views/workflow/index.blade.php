@extends('Crm::layout')
@section('title','คู่มือการทำงาน | CRM')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4 crm-page module-dashboard module-dashboard--crm">
    <div class="module-dashboard-hero mb-4 crm-page-header"><p class="module-dashboard-kicker mb-2"><span></span>CRM / WORKFLOW CENTER</p><h1>คู่มือการทำงาน CRM</h1><p class="mb-0">เลือกขั้นตอนแล้วทำตามลำดับ ปุ่มเริ่มทำจะพาไปยังเมนูที่คุณมีสิทธิ์ใช้งาน</p></div>
    @php($modes=['setup'=>'เริ่มใช้งานครั้งแรก','daily'=>'งานประจำวัน'])
    @php($hasSetupBlocker=collect($workflows)->where('mode','setup')->flatMap(fn($workflow)=>$workflow['steps'])->contains(fn($step)=>in_array($step['status_code']??null,['NOT_READY','CONFIGURATION_WARNING'],true)))
    @php($defaultMode=$hasSetupBlocker?'setup':'daily')
    @php($process=['key'=>'crm','eyebrow'=>'CRM · SALES PROCESS','title'=>'จากรู้จักลูกค้าจนปิดการขาย','description'=>'เห็นความสัมพันธ์ระหว่างลูกค้า Opportunity งานติดตาม และเอกสารขายที่ส่งต่อไป POS','diagram'=><<<'MERMAID'
flowchart LR
    CUSTOMER["01 · Customer 360<br/>ข้อมูลลูกค้ากลาง"] --> OPP["02 · Opportunity<br/>โอกาสการขาย"]
    OPP --> ACT["03 · Activity / Calendar<br/>ติดตามลูกค้า"]
    ACT --> STAGE{"04 · ประเมินผล<br/>อัปเดต Stage"}
    STAGE -->|เดินหน้าต่อ| INTEREST["05 · Product Interest<br/>ความต้องการสินค้า"]
    STAGE -->|ยังไม่พร้อม| ACT
    STAGE -->|ไม่สำเร็จ| LOST["Lost<br/>ระบุเหตุผล"]
    INTEREST --> POS["06 · Sales Intake / POS<br/>คำนวณราคาและ Stock"]
    POS -->|POSTED| WON["Won<br/>ปิดการขาย"]
    classDef master fill:#eef8ff,stroke:#4b8ec8,color:#23415d,stroke-width:1.5px;
    classDef work fill:#f4efff,stroke:#8064c6,color:#493b74,stroke-width:1.5px;
    classDef decision fill:#fff7e4,stroke:#d59a29,color:#664b14,stroke-width:2px;
    classDef success fill:#eaf8f1,stroke:#2f9e72,color:#205d46,stroke-width:1.5px;
    classDef danger fill:#fff0f0,stroke:#d96a70,color:#713238,stroke-width:1.5px;
    class CUSTOMER,OPP master;
    class ACT,INTEREST work;
    class STAGE decision;
    class POS,WON success;
    class LOST danger;
MERMAID
,'notes'=>[['title'=>'ทีมขาย','icon'=>'bx-user-voice','text'=>'ทุก Opportunity ควรมีผู้รับผิดชอบและ Next action ที่ชัดเจน'],['title'=>'POS และ Finance','icon'=>'bx-receipt','class'=>'is-service','text'=>'CRM เตรียมความต้องการ ส่วนราคา ภาษี Stock และการลงบัญชีจบในระบบต้นทาง']],'control'=>'ก่อนเปลี่ยน Stage ให้บันทึกผลการติดตามและกำหนดงานถัดไป; เมื่อส่งต่อ POS แล้วให้ POS เป็นแหล่งข้อมูลหลักของเอกสารขาย'])
    <ul class="nav nav-pills gap-2 mb-4" role="tablist">
        @foreach($modes as $mode=>$label)<li class="nav-item"><button class="nav-link {{ $mode===$defaultMode?'active':'' }}" data-bs-toggle="pill" data-bs-target="#crm-workflow-{{ $mode }}" type="button">{{ $label }}</button></li>@endforeach
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#crm-process" type="button"><i class="bx bx-git-branch me-1" aria-hidden="true"></i>Process Workflow</button></li>
    </ul>
    <div class="tab-content">
        @foreach($modes as $mode=>$label)<div class="tab-pane fade {{ $mode===$defaultMode?'show active':'' }}" id="crm-workflow-{{ $mode }}">@php($rows=collect($workflows)->where('mode',$mode))@forelse($rows as $workflow)@include('Platform::workflow._workflow-card',['workflow'=>$workflow,'mode'=>$mode])@empty<div class="card border-0 shadow-sm"><div class="card-body p-4 text-center text-secondary">ยังไม่มีขั้นตอนในโหมดนี้</div></div>@endforelse</div>@endforeach
        <div class="tab-pane fade" id="crm-process">@include('Platform::workflow._process-diagram',['process'=>$process])</div>
    </div>
</div>
@endsection
