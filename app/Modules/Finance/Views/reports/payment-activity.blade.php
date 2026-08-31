@extends('Finance::layout')

@section('title', 'รายงานธุรกรรมการเงิน | Finance')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <p class="eyebrow mb-2">FINANCE / REPORTS</p>
        <h1 class="h3 mb-2">รายงานธุรกรรมการเงิน</h1>
        <p class="text-secondary mb-4">รายการรับเงินและจ่ายเงินจริงตามสาขาที่เลือก ใช้ตรวจสอบสถานะก่อนกระทบยอด</p>
        <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="finance-payment-report" data-url="{{ route('finance.reports.payment-activity.data') }}">
                <thead><tr><th>เลขที่</th><th>ประเภท</th><th>วันที่</th><th>คู่ค้า</th><th>บัญชีเงิน</th><th class="text-end">ยอดสุทธิ</th><th>Journal</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
            </table>
        </div></div></div>
    </div>
@endsection

@push('scripts')
<script>
$(function(){var t=$('#finance-payment-report'),x=$.fn.dataTable.render.text();t.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:t.data('url'),order:[[2,'desc']],buttons:[window.erpExcelButton(t)],columns:[{data:'document_number',name:'s.document_number',render:x.display},{data:'type_label',name:'s.document_type',render:x.display},{data:'date_label',name:'s.document_date',render:x.display},{data:'party_label',name:'p.code',render:x.display},{data:'bank_code',name:'b.code',render:x.display},{data:'net_amount',name:'s.net_amount',className:'text-end',render:$.fn.dataTable.render.number(',','.',2)},{data:'journal_number',name:'j.entry_number',defaultContent:'—',render:x.display},{data:'status_label',name:'s.status',render:x.display},{data:null,orderable:false,searchable:false,render:function(v,t,r){return t==='display'?'<a class="btn btn-sm btn-app-soft" href="'+x.display(r.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>':'';}}] }));});
</script>
@endpush
