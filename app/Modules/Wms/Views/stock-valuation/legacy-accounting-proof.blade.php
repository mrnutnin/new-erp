@extends('Wms::layout')
@section('title', 'กู้คืน Journal Proof | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">WMS / ACCOUNTING RECOVERY</p><h1 class="h3 mb-2">กู้คืน Journal Proof ของเอกสารเดิม</h1><p class="text-secondary mb-0">สำหรับเอกสาร Issue/Issue Return ที่ลง Stock แล้วแต่ยังไม่มีหลักฐานบัญชี</p></div>
        <a class="btn btn-outline-secondary" href="{{ route('wms.stock-valuation.revaluation.index') }}">กลับ Revaluation Queue</a>
    </div>
    <div class="alert alert-warning border-0">Review ก่อนกู้คืน: ระบบไม่แก้จำนวน Stock และไม่ backfill Journal ด้วย SQL ใช้วันที่ลงบัญชีที่ระบุในงวด OPEN และเก็บวันที่เอกสารเดิมไว้เป็น effective date</div>
    <form method="get" class="card border-0 shadow-sm mb-4"><div class="card-body"><div class="row g-3 align-items-end">
        <div class="col-md-3"><label class="form-label" for="document-type">ประเภทเอกสาร</label><select class="form-select" id="document-type" name="document_type" required><option value="">เลือกประเภท</option><option value="ISSUE" @selected(($values['document_type'] ?? '') === 'ISSUE')>Issue</option><option value="ISSUE_RETURN" @selected(($values['document_type'] ?? '') === 'ISSUE_RETURN')>Issue Return</option></select></div>
        <div class="col-md-3"><label class="form-label" for="document-id">Document ID</label><input class="form-control" id="document-id" name="document_id" type="number" min="1" value="{{ $values['document_id'] ?? '' }}" required></div>
        <div class="col-md-3"><label class="form-label" for="posting-date">วันที่ลงบัญชี</label><input class="form-control" id="posting-date" name="posting_date" type="date" value="{{ $values['posting_date'] ?? now()->toDateString() }}" required></div>
        <div class="col-md-3"><button class="btn btn-dark w-100" type="submit">ตรวจสอบเอกสาร</button></div>
    </div></div></form>
    @if($preview)
    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <h2 class="h5">{{ $preview['document_type'] }} · {{ $preview['document_number'] }}</h2>
        <div class="row g-3"><div class="col-md-4"><div class="small text-secondary">จำนวน Cost Allocation</div><div class="h4">{{ $preview['summary']['rows'] }}</div></div><div class="col-md-4"><div class="small text-secondary">มูลค่ารวม</div><div class="h4">{{ $preview['summary']['value'] }}</div></div><div class="col-md-4"><div class="small text-secondary">Posting Date</div><div class="h4">{{ $preview['posting_date'] }}</div></div></div>
    </div></div>
    <div class="alert {{ ($preview['ready'] || $preview['already_recovered']) ? 'alert-success' : 'alert-danger' }} border-0"><strong>{{ $preview['already_recovered'] ? 'เอกสารนี้กู้คืน Journal proof แล้ว' : ($preview['ready'] ? 'พร้อมกู้คืน' : 'ยังไม่พร้อม') }}</strong>@if(!$preview['ready'] && !$preview['already_recovered'])<ul class="mb-0 mt-2">@foreach($preview['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>@endif</div>
    @if(!$preview['already_recovered'])<div class="card border-0 shadow-sm"><div class="card-body">
        <form method="post" action="{{ route('wms.stock-valuation.legacy-accounting-proof.recover') }}" onsubmit="return confirm('ยืนยันสร้าง Journal proof สำหรับเอกสารเดิมหรือไม่?')">
            @csrf
            <input type="hidden" name="document_type" value="{{ $preview['document_type'] }}"><input type="hidden" name="document_id" value="{{ $preview['document_id'] }}"><input type="hidden" name="posting_date" value="{{ $preview['posting_date'] }}">
            <label class="form-label" for="recovery-reason">เหตุผลการกู้คืน <span class="text-danger">*</span></label><textarea class="form-control mb-3" id="recovery-reason" name="reason" minlength="10" maxlength="1000" required></textarea>
            <button class="btn btn-dark" type="submit" @disabled(!$preview['ready'])>กู้คืน Journal Proof</button>
        </form>
    </div></div>@endif
    @else
    <div class="alert alert-info border-0">กรุณาเลือกประเภทเอกสาร ระบุ Document ID และวันที่ลงบัญชี เพื่อเริ่มตรวจสอบ</div>
    @endif
</div>
@endsection
