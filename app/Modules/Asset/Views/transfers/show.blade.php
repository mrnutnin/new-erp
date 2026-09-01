@extends('Asset::layout')

@section('title', $transfer->document_number.' | New ERP')

@section('content')
@php
    $labels = ['DRAFT' => 'ร่าง', 'SUBMITTED' => 'รออนุมัติ', 'APPROVED' => 'พร้อมลงรายการ', 'POSTED' => 'ลงรายการแล้ว', 'CANCELLED' => 'ยกเลิก'];
    $badges = ['DRAFT' => 'app-badge-soft', 'SUBMITTED' => 'app-badge-info', 'APPROVED' => 'app-badge-info', 'POSTED' => 'app-badge-success', 'CANCELLED' => 'app-status-danger'];
    $auditTrail = collect([
        ['สร้างใบโอน', $transfer->created_at, $transfer->createdBy?->name, null],
        ['ส่งอนุมัติ', $transfer->submitted_at, $transfer->submittedBy?->name, null],
        ['อนุมัติ', $transfer->approved_at, $transfer->approvedBy?->name, null],
        ['ลงรายการ', $transfer->posted_at, $transfer->postedBy?->name, null],
        ['ยกเลิก', $transfer->cancelled_at, $transfer->cancelledBy?->name, $transfer->cancellation_reason],
    ])->filter(fn ($event) => $event[1])->sortByDesc(fn ($event) => $event[1]);
@endphp
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">ASSET / TRANSFER</p><h1 class="h3 mb-2">{{ $transfer->document_number }} <span class="badge {{ $badges[$transfer->status] }} fs-6 align-middle">{{ $labels[$transfer->status] }}</span></h1><p class="text-secondary mb-0">{{ $transfer->sourceBranch?->name }} → {{ $transfer->destinationBranch?->name }} · วันที่ {{ optional($transfer->document_date)->format('d/m/Y') }}</p></div>
        <div class="d-flex flex-wrap gap-2">
            @if($transfer->status === 'DRAFT' && auth()->user()->hasPermission('asset.transfers.create'))<button class="btn btn-dark js-transfer-action" data-url="{{ route('asset.transfers.submit', $transfer) }}" data-title="ส่งอนุมัติใบโอน?" data-text="ตรวจสอบรายการก่อนส่งอนุมัติ">ส่งอนุมัติ</button>@endif
            @if($transfer->status === 'SUBMITTED' && auth()->user()->hasPermission('asset.transfers.approve'))<button class="btn btn-primary js-transfer-action" data-url="{{ route('asset.transfers.approve', $transfer) }}" data-title="อนุมัติใบโอน?" data-text="เมื่ออนุมัติแล้วจึงสามารถลงรายการได้">อนุมัติ</button>@endif
            @if($transfer->status === 'APPROVED' && auth()->user()->hasPermission('asset.transfers.post'))<button class="btn btn-success js-transfer-action" data-url="{{ route('asset.transfers.post', $transfer) }}" data-title="ลงรายการโอนสินทรัพย์?" data-text="ระบบจะปรับสาขา สถานที่ และผู้ดูแลของสินทรัพย์">ลงรายการ</button>@endif
            @if(in_array($transfer->status, ['DRAFT', 'SUBMITTED', 'APPROVED'], true) && auth()->user()->hasPermission('asset.transfers.create'))<button class="btn btn-outline-danger js-transfer-cancel" data-url="{{ route('asset.transfers.cancel', $transfer) }}">ยกเลิก</button>@endif
            <a class="btn btn-outline-dark" href="{{ route('asset.transfers.index') }}">รายการทั้งหมด</a>
        </div>
    </div>
    <div class="alert alert-info border-0 shadow-sm">การโอนระหว่างสาขาเดียวกันของนิติบุคคล เป็นการย้ายความรับผิดชอบของสินทรัพย์และไม่สร้าง Journal Entry</div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="row g-3 mb-4"><div class="col-md-6"><span class="text-secondary d-block small">เหตุผล</span>{{ $transfer->reason }}</div><div class="col-md-3"><span class="text-secondary d-block small">สาขาต้นทาง</span>{{ $transfer->sourceBranch?->code }} · {{ $transfer->sourceBranch?->name }}</div><div class="col-md-3"><span class="text-secondary d-block small">สาขาปลายทาง</span>{{ $transfer->destinationBranch?->code }} · {{ $transfer->destinationBranch?->name }}</div></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>สินทรัพย์</th><th>สถานที่เดิม</th><th>สถานที่ใหม่</th></tr></thead><tbody>@foreach($transfer->lines as $line)<tr><td><div class="fw-semibold">{{ $line->asset_number_snapshot }}</div><div class="small text-secondary">{{ $line->asset_name_snapshot }}</div></td><td>{{ $line->oldLocation?->name ?? '-' }}</td><td>{{ $line->newLocation?->name ?? '-' }}</td></tr>@endforeach</tbody></table></div></div></div>
    <div class="card border-0 shadow-sm mt-4"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-1">ประวัติการทำรายการ</h2><p class="small text-secondary mb-0">Audit Trail · เวลาไทย (UTC+7)</p></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>วันเวลา</th><th>เหตุการณ์</th><th>ผู้ดำเนินการ</th><th>หมายเหตุ</th></tr></thead><tbody>@foreach($auditTrail as [$action, $at, $actor, $note])<tr><td>{{ $at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</td><td>{{ $action }}</td><td>{{ $actor ?? '-' }}</td><td>{{ $note ?? '-' }}</td></tr>@endforeach</tbody></table></div></div>
</div>
@endsection

@push('scripts')
<script>
$(function(){
    $('.js-transfer-action').on('click',function(){var button=$(this);Swal.fire({icon:'question',title:button.data('title'),text:button.data('text'),showCancelButton:true,confirmButtonText:'ยืนยัน',cancelButtonText:'กลับ'}).then(function(result){if(!result.isConfirmed)return;button.prop('disabled',true);$.post(button.data('url'),{_token:'{{ csrf_token() }}'}).done(function(response){Swal.fire({icon:'success',title:response.msg}).then(function(){window.location=response.redirect;});}).fail(function(xhr){window.erpAjaxError(xhr);}).always(function(){button.prop('disabled',false);});});});
    $('.js-transfer-cancel').on('click',function(){var button=$(this);Swal.fire({icon:'warning',title:'ยกเลิกใบโอน?',input:'textarea',inputLabel:'เหตุผล',inputPlaceholder:'ระบุเหตุผลอย่างน้อย 10 ตัวอักษร',showCancelButton:true,confirmButtonText:'ยืนยันยกเลิก',cancelButtonText:'กลับ',preConfirm:function(value){if(!value||value.trim().length<10){Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร');}}}).then(function(result){if(!result.isConfirmed)return;button.prop('disabled',true);$.post(button.data('url'),{_token:'{{ csrf_token() }}',cancellation_reason:result.value}).done(function(response){Swal.fire({icon:'success',title:response.msg}).then(function(){window.location=response.redirect;});}).fail(function(xhr){window.erpAjaxError(xhr);}).always(function(){button.prop('disabled',false);});});});
});
</script>
@endpush
