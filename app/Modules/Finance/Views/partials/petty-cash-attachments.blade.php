@php
    $isVoucher = $subjectType === 'PETTY_CASH_VOUCHER';
    $isEmployeeAdvanceClearing = $subjectType === 'EMPLOYEE_ADVANCE_CLEARING';
    $prefix = $isVoucher ? 'finance.petty-cash' : ($isEmployeeAdvanceClearing ? 'finance.employee-advance-clearings' : 'finance.petty-cash-clearings');
    $managePermission = $isVoucher ? 'finance.petty-cash.update' : ($isEmployeeAdvanceClearing ? 'finance.employee-advance-clearings.update' : 'finance.petty-cash-clearings.update');
    $fileTypes = $isVoucher ? ['RECEIPT' => 'ใบเสร็จรับเงิน', 'TAX_INVOICE' => 'ใบกำกับภาษี', 'WHT_CERTIFICATE' => 'หนังสือรับรอง WHT', 'OTHER' => 'อื่น ๆ'] : ($isEmployeeAdvanceClearing ? ['RECEIPT' => 'ใบเสร็จรับเงิน', 'TAX_INVOICE' => 'ใบกำกับภาษี', 'WHT_CERTIFICATE' => 'หนังสือรับรอง WHT', 'REFUND' => 'หลักฐานคืนเงิน', 'OTHER' => 'อื่น ๆ'] : ['CASH_COUNT' => 'ใบตรวจนับเงินสด', 'RETURN' => 'หลักฐานคืนเงิน', 'OTHER' => 'อื่น ๆ']);
@endphp
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div><h2 class="h5 mb-1">เอกสารแนบและหลักฐาน</h2><p class="small text-secondary mb-0">ไฟล์จะถูกเก็บแบบ Private และบันทึกประวัติ Audit Log</p></div>
            @if(auth()->user()->hasPermission($managePermission))<button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#petty-cash-attachment-form"><i class="bx bx-upload me-1" aria-hidden="true"></i>แนบเอกสาร</button>@endif
        </div>
        @if(auth()->user()->hasPermission($managePermission))
            <div class="collapse mb-3" id="petty-cash-attachment-form">
                <form id="petty-cash-attachment-upload" method="POST" enctype="multipart/form-data" action="{{ route($prefix.'.attachments.store', $subject) }}">
                    @csrf
                    <div class="row g-3 align-items-end"><div class="col-md-4"><label class="form-label">ประเภทหลักฐาน <span class="text-danger">*</span></label><select class="form-select" name="file_type" required>@foreach($fileTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div><div class="col-md-6"><label class="form-label">ไฟล์ <span class="text-danger">*</span></label><input class="form-control" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp" required><div class="form-text">PDF, JPG, PNG หรือ WebP ขนาดไม่เกิน 10 MB</div></div><div class="col-md-2"><button class="btn btn-dark w-100" type="submit">บันทึกไฟล์</button></div></div>
                </form>
            </div>
        @endif
        <div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>ประเภท</th><th>ชื่อไฟล์</th><th>ผู้อัปโหลด</th><th>เวลา</th><th class="text-end">จัดการ</th></tr></thead><tbody id="petty-cash-attachment-rows"><tr><td colspan="5" class="text-center text-secondary py-3">กำลังโหลด...</td></tr></tbody></table></div>
    </div>
</div>
@push('scripts')
<script>
$(function(){var url=@json(route($prefix.'.attachments.index',$subject)),storeUrl=@json(route($prefix.'.attachments.store',$subject)),types=@json($fileTypes),text=$.fn.dataTable.render.text();function load(){ $.getJSON(url).done(function(r){var rows=r.data||[];$('#petty-cash-attachment-rows').html(rows.length?rows.map(function(a){var actions='<a class="btn btn-sm btn-outline-dark" href="'+text.display(a.download_url)+'">ดาวน์โหลด</a>';if(a.preview_url)actions+=' <a class="btn btn-sm btn-outline-secondary" target="_blank" href="'+text.display(a.preview_url)+'">เปิดดู</a>';if(a.delete_url)actions+=' <button class="btn btn-sm btn-outline-danger js-delete-petty-attachment" data-url="'+text.display(a.delete_url)+'" type="button">ลบ</button>';return '<tr><td>'+text.display(types[a.file_type]||a.file_type)+'</td><td>'+text.display(a.original_name)+'</td><td>'+text.display(a.uploaded_by)+'</td><td>'+text.display(a.uploaded_at||'-')+'</td><td class="text-end">'+actions+'</td></tr>';}).join(''):'<tr><td colspan="5" class="text-center text-secondary py-3">ยังไม่มีเอกสารแนบ</td></tr>');});}load();window.erpAjaxForm({form:'#petty-cash-attachment-upload',reload:false,redirect:false});$(document).on('click','.js-delete-petty-attachment',function(){var b=$(this);Swal.fire({icon:'warning',text:'ยืนยันลบเอกสารแนบนี้หรือไม่?',showCancelButton:true,confirmButtonText:'ลบ',cancelButtonText:'ยกเลิก'}).then(function(r){if(!r.isConfirmed)return;$.ajax({url:b.data('url'),method:'DELETE',headers:{'X-CSRF-TOKEN':$('meta[name="csrf-token"]').attr('content'),Accept:'application/json'}}).done(function(r){Swal.fire({icon:'success',text:r.msg});load();}).fail(window.erpAjaxError);});});$(document).ajaxComplete(function(e,x,s){if(s.url===storeUrl&&s.type==='POST'&&x.status<300)load();});});
</script>
@endpush
