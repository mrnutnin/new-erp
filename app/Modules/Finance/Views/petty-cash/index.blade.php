@extends('Finance::layout')

@section('title', 'เงินสดย่อย | Finance')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">FINANCE / PETTY CASH</p>
                <h1 class="h3 mb-2">เงินสดย่อย</h1>
                <p class="text-secondary mb-0">บันทึกค่าใช้จ่ายวงเงินย่อย ส่งอนุมัติ และลงบัญชีจากกองเงินสด</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if (auth()->user()->hasPermission('finance.petty-cash.create'))
                    <a class="btn btn-dark" href="{{ route('finance.petty-cash.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบสำคัญ</a>
                @endif
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4" aria-labelledby="petty-cash-filter-title">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 id="petty-cash-filter-title" class="h5 mb-1">ตัวกรอง</h2><p class="small text-secondary mb-0">กรองข้อมูลก่อนดูรายการ</p></div><button type="button" id="petty-cash-filter-reset" class="btn btn-sm btn-outline-secondary">ล้างตัวกรอง</button></div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label" for="petty-cash-status">สถานะ</label><select id="petty-cash-status" class="form-select"><option value="">ทุกสถานะ</option><option value="DRAFT">ร่าง</option><option value="SUBMITTED">รออนุมัติ</option><option value="APPROVED">อนุมัติแล้ว</option><option value="POSTED">ลงบัญชีแล้ว</option><option value="REVERSED">ยกเลิกรายการแล้ว</option><option value="VOID">ยกเลิก</option></select></div>
                    <div class="col-md-4"><label class="form-label" for="petty-cash-fund">วงเงินสดย่อย</label><select id="petty-cash-fund" class="form-select"><option value="">ทุกวงเงินสดย่อย</option>@foreach(($fundOptions ?? []) as $value => $label)<option value="{{ is_numeric($value) ? $value : data_get($label, 'id') }}">{{ is_scalar($label) ? $label : (data_get($label, 'label') ?? data_get($label, 'name') ?? data_get($label, 'code')) }}</option>@endforeach</select></div>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm" aria-labelledby="petty-cash-table-title">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3"><h2 id="petty-cash-table-title" class="h5 mb-1">รายการใบสำคัญเงินสดย่อย</h2><p class="small text-secondary mb-0">ค้นหา จัดเรียง และส่งออกข้อมูลได้</p></div>
                <div class="table-responsive"><table id="petty-cash-table" class="table table-hover align-middle w-100" data-url="{{ route('finance.petty-cash.data') }}"><thead><tr><th>เลขที่</th><th>วันที่</th><th>วงเงินสดย่อย</th><th>ผู้รับเงิน</th><th class="text-end">ยอดรวม</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead></table></div>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
<script>
$(function(){var $table=$('#petty-cash-table'),text=$.fn.dataTable.render.text(),labels={DRAFT:'ร่าง',SUBMITTED:'รออนุมัติ',APPROVED:'อนุมัติแล้ว',POSTED:'ลงบัญชีแล้ว',REVERSED:'ยกเลิกรายการแล้ว',VOID:'ยกเลิก'},classes={DRAFT:'app-status-neutral',SUBMITTED:'app-status-info',APPROVED:'app-status-info',POSTED:'app-status-success',REVERSED:'app-status-danger',VOID:'app-status-danger'};var table=$table.DataTable($.extend(true,{},window.erpDataTableDefaults,{ajax:{url:$table.data('url'),data:function(d){d.status=$('#petty-cash-status').val();d.petty_cash_fund_id=$('#petty-cash-fund').val();}},order:[[1,'desc']],buttons:[window.erpExcelButton($table)],columns:[{data:'document_number',name:'finance_petty_cash_vouchers.document_number',render:text.display},{data:'document_date_label',name:'finance_petty_cash_vouchers.document_date',render:text.display},{data:'fund_label',name:'finance_petty_cash_funds.id',render:text.display},{data:'payee_name',name:'finance_petty_cash_vouchers.payee_name',defaultContent:'—',render:text.display},{data:'total_amount',name:'finance_petty_cash_vouchers.total_amount',className:'text-end',render:$.fn.dataTable.render.number(',','.',2)},{data:'status',name:'finance_petty_cash_vouchers.status',render:function(v,t){return t==='display'?'<span class="badge '+(classes[v]||'app-status-neutral')+'">'+text.display(labels[v]||v)+'</span>':v;}},{data:null,orderable:false,searchable:false,className:'text-end text-nowrap',render:function(v,t,r){if(t!=='display')return '';var a=['<a class="btn btn-sm btn-app-soft" href="'+text.display(r.show_url)+'" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="bx bx-show" aria-hidden="true"></i></a>'];if(r.edit_url)a.push('<a class="btn btn-sm btn-app-soft" href="'+text.display(r.edit_url)+'" title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit" aria-hidden="true"></i></a>');return a.join(' ');}}]}));$('#petty-cash-status,#petty-cash-fund').on('change',function(){table.ajax.reload();});$('#petty-cash-filter-reset').on('click',function(){$('#petty-cash-status,#petty-cash-fund').val('');table.ajax.reload();});});
</script>
@endpush
