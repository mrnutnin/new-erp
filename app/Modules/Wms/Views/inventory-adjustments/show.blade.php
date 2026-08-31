@extends('Wms::layout')
@php($wmsDecimal = \App\Modules\Wms\Support\WmsDecimal::class)
@section('title', 'รายละเอียดรายการปรับปรุงสินค้า')
@section('content')
@php
    $statusLabels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว'];
    $statusClasses = ['DRAFT' => 'neutral', 'APPROVED' => 'info', 'POSTED' => 'success'];
    $directionLabels = ['GAIN' => 'เพิ่มสินค้า', 'LOSS' => 'ลดสินค้า'];
    $directionClasses = ['GAIN' => 'text-success', 'LOSS' => 'text-danger'];
    $journal = $adjustment->allocation?->journalEntry;
@endphp
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">WMS / INVENTORY ADJUSTMENT</p>
            <h1 class="h3 mb-2">รายละเอียดรายการปรับปรุงสินค้า #{{ $adjustment->id }}</h1>
            <p class="text-secondary mb-0">ตรวจสอบรายการ Stock, Cost Allocation และ Journal ที่เชื่อมโยงกัน</p>
        </div>
        <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="{{ route('wms.inventory-adjustments.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับรายการ</a>
        @if($adjustment->status === 'POSTED' && $adjustment->reversal_status !== 'REVERSED' && config('erp.inventory.adjustment_posting_enabled', false) && auth()->user()->hasPermission('wms.inventory-adjustments.reverse'))
            <button class="btn btn-outline-danger" id="adjustment-reverse"><i class="bx bx-revision me-1" aria-hidden="true"></i>กลับรายการ</button>
        @endif</div>
    </div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div><span class="badge app-status-{{ $statusClasses[$adjustment->status] ?? 'neutral' }}">{{ $statusLabels[$adjustment->status] ?? $adjustment->status }}</span>
                <span class="badge app-status-neutral ms-1 {{ $directionClasses[$adjustment->direction] ?? '' }}">{{ $directionLabels[$adjustment->direction] ?? $adjustment->direction }}</span></div>
            <div class="small text-secondary">วันที่รายการ {{ $adjustment->business_date?->format($dateFormat) ?? '-' }}</div>
        </div>
        <div class="row g-3">
            <div class="col-md-4"><small class="text-secondary d-block">สินค้า</small><strong>{{ $adjustment->item?->code }} · {{ $adjustment->item?->name }}</strong></div>
            <div class="col-md-2"><small class="text-secondary d-block">หน่วย</small><strong>{{ $adjustment->uom?->code ?? '-' }}</strong></div>
            <div class="col-md-2"><small class="text-secondary d-block">จำนวน</small><strong>{{ $wmsDecimal::format($adjustment->quantity) }}</strong></div>
            <div class="col-md-2"><small class="text-secondary d-block">มูลค่ารวม</small><strong>{{ $wmsDecimal::format($adjustment->value) }}</strong></div>
            <div class="col-md-2"><small class="text-secondary d-block">คลังสินค้า</small><strong>{{ $adjustment->warehouse?->code ?? '-' }}</strong></div>
            <div class="col-12"><small class="text-secondary d-block">เหตุผล</small><div>{{ $adjustment->reason }}</div></div>
        </div>
    </div></div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">Stock Movement</h2>
        @if($adjustment->movement)
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>ประเภท</th><th>ทิศทาง</th><th>สถานะ</th><th class="text-end">จำนวน</th><th>เอกสารอ้างอิง</th><th>วันที่ลงรายการ</th></tr></thead><tbody><tr>
                <td>{{ $adjustment->movement->movement_type }}</td><td class="{{ $directionClasses[$adjustment->direction] ?? '' }}">{{ $directionLabels[$adjustment->direction] ?? $adjustment->movement->direction }}</td><td>{{ $adjustment->movement->status === 'POSTED' ? 'ลงรายการแล้ว' : $adjustment->movement->status }}</td><td class="text-end">{{ $wmsDecimal::format($adjustment->movement->base_quantity) }}</td><td>{{ $adjustment->movement->source_reference ?? '-' }}</td><td>{{ $adjustment->movement->posted_at?->format('d/m/Y H:i') ?? '-' }}</td>
            </tr></tbody></table></div>
        @else <p class="text-secondary mb-0">ยังไม่มี Stock Movement — รายการยังไม่ถูกลงบัญชี</p> @endif
    </div></div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">Cost Allocation</h2>
        @if($adjustment->allocation)
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>วิธีคำนวณ</th><th>สถานะต้นทุน</th><th>Revision</th><th class="text-end">จำนวน</th><th class="text-end">ต้นทุนต่อหน่วย</th><th class="text-end">มูลค่า</th></tr></thead><tbody><tr>
                <td>{{ $adjustment->allocation->method }}</td><td>{{ $adjustment->allocation->cost_status === 'FINAL' ? 'คำนวณเสร็จแล้ว' : $adjustment->allocation->cost_status }}</td><td>{{ $adjustment->allocation->revision }}</td><td class="text-end">{{ $wmsDecimal::format($adjustment->allocation->quantity) }}</td><td class="text-end">{{ $wmsDecimal::format($adjustment->allocation->unit_cost) }}</td><td class="text-end">{{ $wmsDecimal::format($adjustment->allocation->value) }}</td>
            </tr></tbody></table></div>
        @else <p class="text-secondary mb-0">ยังไม่มี Cost Allocation</p> @endif
    </div></div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">Journal</h2>
        @if($journal)
            <div class="d-flex flex-wrap gap-3 small text-secondary mb-3"><span>เลขที่ {{ $journal->entry_number ?? '-' }}</span><span>วันที่ {{ $journal->entry_date?->format($dateFormat) ?? '-' }}</span><span>สถานะ {{ $journal->status }}</span></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>#</th><th>บัญชี</th><th>คำอธิบาย</th><th class="text-end">เดบิต</th><th class="text-end">เครดิต</th></tr></thead><tbody>@foreach($journal->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->account?->code ?? '-' }} · {{ $line->account?->name ?? '-' }}</td><td>{{ $line->description ?? '-' }}</td><td class="text-end">{{ $wmsDecimal::format($line->debit) }}</td><td class="text-end">{{ $wmsDecimal::format($line->credit) }}</td></tr>@endforeach</tbody></table></div>
        @else <p class="text-secondary mb-0">ยังไม่มี Journal — รายการยังไม่ถูกลงบัญชี</p> @endif
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5 mb-3">ประวัติรายการ</h2>
        @forelse($history as $event)<div class="d-flex gap-3 border-bottom py-2"><div class="small text-secondary text-nowrap">{{ $event->created_at?->format('d/m/Y H:i') }}</div><div><strong>{{ $event->action }}</strong><div class="small text-secondary">{{ $event->user?->name ?? 'ระบบ' }}</div>@if($event->reason)<div class="small mt-1"><span class="text-secondary">เหตุผล:</span> {{ $event->reason }}</div>@endif</div></div>@empty<p class="text-secondary mb-0">ยังไม่มีประวัติ</p>@endforelse
    </div></div>
</div>
@endsection
@push('scripts')
<script>$(function(){ $('#adjustment-reverse').on('click',function(){var b=$(this);Swal.fire({icon:'warning',title:'ยืนยันกลับรายการ Adjustment?',input:'textarea',inputPlaceholder:'ระบุเหตุผลอย่างน้อย 10 ตัวอักษร',showCancelButton:true,confirmButtonText:'ยืนยัน',cancelButtonText:'ยกเลิก'}).then(function(x){if(!x.isConfirmed)return;$.post('{{ route('wms.inventory-adjustments.reverse',$adjustment) }}',{_token:$('meta[name=csrf-token]').attr('content'),reversal_date:'{{ now()->format('Y-m-d') }}',reason:x.value||''}).done(function(r){Swal.fire({icon:'success',text:r.msg,timer:1200,showConfirmButton:false}).then(function(){location.reload();});}).fail(function(x){Swal.fire({icon:'error',text:x.responseJSON?.message||'กลับรายการไม่สำเร็จ'});});});});});</script>
@endpush
