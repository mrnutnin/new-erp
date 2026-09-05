@extends('Finance::layout')

@section('title', 'รายงานธุรกรรมการเงิน | Finance')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">FINANCE / REPORTS</p>
        <h1 class="h3 mb-2">รายงานธุรกรรมการเงิน</h1>
        <p class="text-secondary mb-4">รายการรับเงินและจ่ายเงินจริงตามสาขาที่เลือก ใช้ตรวจสอบสถานะก่อนกระทบยอด</p>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h6 mb-0">ตัวกรอง</h2><button class="btn btn-sm btn-outline-secondary" id="finance-payment-report-reset" type="button">ล้างตัวกรอง</button></div><div class="row g-3"><div class="col-md-3"><label class="form-label" for="finance-payment-report-branch">สาขา</label><select class="form-select" id="finance-payment-report-branch"><option value="current">สาขาปัจจุบัน</option><option value="all">ทุกสาขาที่มีสิทธิ์</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label" for="finance-payment-report-type">ประเภท</label><select class="form-select" id="finance-payment-report-type"><option value="">ทั้งหมด</option><option value="RECEIPT">รับเงิน</option><option value="PAYMENT">จ่ายเงิน</option></select></div><div class="col-md-3"><label class="form-label" for="finance-payment-report-from">วันที่เริ่มต้น</label><input class="form-control" type="date" id="finance-payment-report-from"></div><div class="col-md-3"><label class="form-label" for="finance-payment-report-to">วันที่สิ้นสุด</label><input class="form-control" type="date" id="finance-payment-report-to"></div><div class="col-md-3"><label class="form-label" for="finance-payment-report-status">สถานะ</label><select class="form-select" id="finance-payment-report-status"><option value="">ทั้งหมด</option><option value="DRAFT">ร่าง</option><option value="APPROVED">อนุมัติแล้ว</option><option value="POSTED">ลงบัญชีแล้ว</option><option value="VOID">ยกเลิก</option></select></div></div></div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="finance-payment-report" data-url="{{ route('finance.reports.payment-activity.data') }}">
                <thead><tr><th>เลขที่</th><th>สาขา</th><th>ประเภท</th><th>วันที่</th><th>คู่ค้า</th><th>บัญชีเงิน</th><th class="text-end">ยอดสุทธิ</th><th>Journal</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
            </table>
        </div></div></div>
    </div>
@endsection

@push('scripts')
<script>
$(function(){var t=$('#finance-payment-report'),b=$('#finance-payment-report-branch'),type=$('#finance-payment-report-type'),from=$('#finance-payment-report-from'),to=$('#finance-payment-report-to'),s=$('#finance-payment-report-status'),x=$.fn.dataTable.render.text();var dt=t.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:t.data('url'),data:function(d){d.branch_scope=b.val();d.document_type=type.val();d.from=from.val();d.to=to.val();d.status=s.val()}},order:[[3,'desc']],buttons:[window.erpExcelButton(t,function(){return{branch_scope:b.val(),document_type:type.val(),from:from.val(),to:to.val(),status:s.val()}})],columns:[{data:'document_number',name:'s.document_number',render:x.display},{data:'branch_code',name:'br.code',render:x.display},{data:'type_label',name:'s.document_type',render:x.display},{data:'date_label',name:'s.document_date',render:x.display},{data:'party_label',name:'p.code',render:x.display},{data:'bank_code',name:'b.code',render:x.display},{data:'net_amount',name:'s.net_amount',className:'text-end',render:$.fn.dataTable.render.number(',','.',2)},{data:'journal_number',name:'j.entry_number',defaultContent:'—',render:x.display},{data:'status_label',name:'s.status',render:x.display},{data:null,orderable:false,searchable:false,render:function(v,t,r){return t==='display'?'<a class="btn btn-sm btn-app-soft" href="'+x.display(r.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>':'';}}] }));b.add(type).add(from).add(to).add(s).on('change',function(){dt.ajax.reload()});$('#finance-payment-report-reset').on('click',function(){b.val('current');type.val('');from.val('');to.val('');s.val('');dt.ajax.reload()});});
</script>
@endpush
