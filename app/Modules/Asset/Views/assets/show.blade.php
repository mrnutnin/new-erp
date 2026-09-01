@extends('Asset::layout')

@section('title', 'ทะเบียนสินทรัพย์ '.$asset->asset_number)

@section('content')
@php($statusLabels = ['DRAFT' => 'ร่าง', 'REGISTERED' => 'ขึ้นทะเบียนแล้ว', 'ACTIVE' => 'ใช้งาน', 'SUSPENDED' => 'ระงับใช้งาน', 'UNDER_REPAIR' => 'อยู่ระหว่างซ่อม', 'HELD_FOR_DISPOSAL' => 'รอจำหน่าย', 'DISPOSED' => 'จำหน่ายแล้ว', 'WRITTEN_OFF' => 'ตัดออกแล้ว'])
@php($statusClasses = ['DRAFT' => 'app-badge-soft', 'REGISTERED' => 'app-badge-info', 'ACTIVE' => 'app-badge-success', 'SUSPENDED' => 'app-badge-warning', 'UNDER_REPAIR' => 'app-badge-warning', 'HELD_FOR_DISPOSAL' => 'app-badge-warning', 'DISPOSED' => 'app-status-danger', 'WRITTEN_OFF' => 'app-status-danger'])
@php($historyLabels = ['REGISTERED_DRAFT' => 'สร้างข้อมูลตัวอย่าง', 'REGISTER_DRAFT_CREATED' => 'สร้างทะเบียนร่าง', 'REGISTER_DRAFT_UPDATED' => 'แก้ไขทะเบียนร่าง', 'REGISTER_DRAFT_DELETED' => 'ลบทะเบียนร่าง', 'MAINTENANCE_STARTED' => 'เริ่มซ่อมบำรุง', 'MAINTENANCE_COMPLETED' => 'ปิดงานซ่อมบำรุง'])
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">ASSET / REGISTER</p>
            <h1 class="h3 mb-1">{{ $asset->asset_number }}</h1>
            <p class="text-secondary mb-0">{{ $asset->name }} · สาขา {{ $asset->branch?->name ?? '-' }}</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge {{ $statusClasses[$asset->status] ?? 'app-badge-soft' }}">{{ $statusLabels[$asset->status] ?? $asset->status }}</span>
            <a class="btn btn-outline-dark" href="{{ route('asset.assets.label', $asset) }}" target="_blank"><i class="bx bx-barcode me-1" aria-hidden="true"></i>พิมพ์ป้าย</a>
            @if ($asset->status === 'DRAFT' && auth()->user()->hasPermission('asset.register.update'))
                <a class="btn btn-outline-dark" href="{{ route('asset.assets.edit', $asset) }}"><i class="bx bx-edit me-1" aria-hidden="true"></i>แก้ไข</a>
            @endif
            @if ($asset->status === 'DRAFT' && auth()->user()->hasPermission('asset.capitalizations.create'))
                <a class="btn btn-dark" href="{{ route('asset.capitalizations.create', ['source_type' => 'MANUAL_RECLASS', 'asset_id' => $asset->id]) }}"><i class="bx bx-book-add me-1" aria-hidden="true"></i>ตั้งทุนและลงบัญชี</a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('asset.assets.index') }}">กลับรายการ</a>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="asset-detail-tabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#asset-overview" type="button">ภาพรวม</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#asset-values" type="button">บัญชี/ภาษี</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#asset-depreciation" type="button">ค่าเสื่อม</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#asset-location" type="button">ตำแหน่งและผู้ดูแล</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#asset-maintenance" type="button">ซ่อมบำรุง</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#asset-attachments" type="button">เอกสารแนบ</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#asset-history" type="button">ประวัติ</button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="asset-overview"><div class="card border-0 shadow-sm"><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><span class="small text-secondary d-block">ชื่อสินทรัพย์</span><strong>{{ $asset->name }}</strong></div>
            <div class="col-md-4"><span class="small text-secondary d-block">หมวดสินทรัพย์</span><strong>{{ $asset->category ? $asset->category->code.' · '.$asset->category->name : '-' }}</strong></div>
            <div class="col-md-4"><span class="small text-secondary d-block">สินทรัพย์หลัก</span><strong>{{ $asset->parent ? $asset->parent->asset_number.' · '.$asset->parent->name : '-' }}</strong></div>
            <div class="col-md-3"><span class="small text-secondary d-block">วันที่ขึ้นทะเบียน</span><strong>{{ $asset->registration_date?->format($dateFormat) ?? '-' }}</strong></div>
            <div class="col-md-3"><span class="small text-secondary d-block">วันที่ได้มา</span><strong>{{ $asset->acquisition_date?->format($dateFormat) ?? '-' }}</strong></div>
            <div class="col-md-3"><span class="small text-secondary d-block">พร้อมใช้งาน</span><strong>{{ $asset->placed_in_service_date?->format($dateFormat) ?? '-' }}</strong></div>
            <div class="col-md-3"><span class="small text-secondary d-block">Tag / Barcode</span><strong>{{ $asset->tag_number ?? $asset->barcode_value ?? '-' }}</strong></div>
            <div class="col-md-4"><span class="small text-secondary d-block">ยี่ห้อ / รุ่น</span><strong>{{ collect([$asset->brand, $asset->model])->filter()->join(' · ') ?: '-' }}</strong></div>
            <div class="col-md-4"><span class="small text-secondary d-block">Serial / ผู้ผลิต</span><strong>{{ collect([$asset->serial_number, $asset->manufacturer])->filter()->join(' · ') ?: '-' }}</strong></div>
            <div class="col-md-4"><span class="small text-secondary d-block">ผู้ขาย</span><strong>{{ $asset->supplier?->name ?? '-' }}</strong></div>
            <div class="col-12"><span class="small text-secondary d-block">รายละเอียด</span><span>{{ $asset->description ?: '-' }}</span></div>
        </div></div></div></div>

        <div class="tab-pane fade" id="asset-values">
            <div class="card border-0 shadow-sm"><div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4"><div><h2 class="h6 mb-1">มูลค่าทางบัญชี</h2><p class="small text-secondary mb-0">ยอดปัจจุบันจากทะเบียนสินทรัพย์</p></div><span class="badge text-bg-light">{{ $asset->currency_code }}</span></div>
                <div class="row g-3">
                    @foreach ([['ต้นทุนเดิม', number_format((float) $asset->original_cost, 2)], ['ต้นทุนตามบัญชี', number_format((float) $asset->book_cost, 2)], ['ค่าเสื่อมสะสม', number_format((float) $asset->book_accumulated_depreciation, 2)], ['ด้อยค่าสะสม', number_format((float) $asset->book_accumulated_impairment, 2)], ['มูลค่าตามบัญชี', number_format((float) $asset->book_value, 2)]] as [$label, $value])
                        <div class="col-6 col-lg"><div class="border rounded-3 p-3 h-100"><span class="small text-secondary d-block mb-1">{{ $label }}</span><strong class="fs-6">{{ $value }}</strong></div></div>
                    @endforeach
                </div>
                <div class="row g-3 mt-1 small"><div class="col-md-4"><span class="text-secondary">อัตราแลกเปลี่ยน</span><strong class="ms-2">{{ number_format((float) $asset->exchange_rate, 6) }}</strong></div><div class="col-md-4"><span class="text-secondary">ประกันสิ้นสุด</span><strong class="ms-2">{{ $asset->insurance_end_date?->format($dateFormat) ?? '-' }}</strong></div><div class="col-md-4"><span class="text-secondary">รับประกันสิ้นสุด</span><strong class="ms-2">{{ $asset->warranty_end_date?->format($dateFormat) ?? '-' }}</strong></div></div>
            </div></div>
        </div>

        <div class="tab-pane fade" id="asset-depreciation">
            <div class="alert alert-info border-0 shadow-sm small mb-3"><i class="bx bx-info-circle me-1" aria-hidden="true"></i>ตารางนี้เป็น <strong>ประมาณการเพื่อการดู</strong> จาก profile ปัจจุบัน ยังไม่ใช่ผลการคำนวณที่อนุมัติหรือบันทึกบัญชี<br><span class="d-inline-block mt-1">หมายเหตุ: หากกำหนดมูลค่าซาก ตารางจะสิ้นสุดที่มูลค่าซากนั้น; หากมูลค่าซากเป็น 0 มูลค่าคงเหลือปลายอายุจะเป็น 0</span></div>
            @forelse ($asset->depreciationBooks as $book)
                @php($preview = $depreciationPreviews[$book->id] ?? null)
                <div class="card border-0 shadow-sm mb-3"><div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3"><div><div class="d-flex align-items-center gap-2 mb-1"><h2 class="h6 mb-0">{{ $book->book_type === 'BOOK' ? 'ค่าเสื่อมทางบัญชี' : 'ค่าเสื่อมทางภาษี' }}</h2><span class="badge text-bg-light">เส้นตรง</span></div><p class="small text-secondary mb-0">เริ่ม {{ $book->start_date?->format($dateFormat) ?? '-' }} · อายุ {{ number_format($book->useful_life_months) }} เดือน · คิดล่าสุด {{ $book->last_depreciation_date?->format($dateFormat) ?? '-' }}</p></div><div class="btn-group btn-group-sm" role="group" aria-label="วิธีเฉลี่ยค่าเสื่อม"><button class="btn btn-outline-dark js-depreciation-method" data-book="{{ $book->id }}" data-method="FULL_MONTH" type="button">เต็มเดือน</button><button class="btn btn-outline-dark active js-depreciation-method" data-book="{{ $book->id }}" data-method="DAILY" type="button">รายวัน</button></div></div>
                    @if ($preview)
                        <div class="row g-3 mb-3 small"><div class="col-sm-4"><div class="border rounded-3 p-3 h-100"><span class="text-secondary d-block">สูตรเส้นตรง</span><strong>({{ number_format((float) $book->depreciable_cost, 2) }} − {{ number_format((float) $book->residual_value, 2) }}) ÷ {{ number_format($book->useful_life_months) }} เดือน</strong></div></div><div class="col-sm-4"><div class="border rounded-3 p-3 h-100"><span class="text-secondary d-block">ฐานที่คิดค่าเสื่อม</span><strong>{{ number_format((float) $preview['FULL_MONTH']['depreciable_amount'], 2) }}</strong></div></div><div class="col-sm-4"><div class="border rounded-3 p-3 h-100"><span class="text-secondary d-block">มูลค่าปลายอายุ</span><strong>{{ number_format((float) $preview['FULL_MONTH']['residual_value'], 2) }}</strong></div></div></div>
                        @foreach (['FULL_MONTH' => 'เต็มเดือน', 'DAILY' => 'รายวัน'] as $method => $methodLabel)
                            @php($schedule = $preview[$method])
                            <div class="js-depreciation-schedule {{ $method === 'FULL_MONTH' ? 'd-none' : '' }}" data-book="{{ $book->id }}" data-method="{{ $method }}">
                                <p class="small text-secondary mb-2">{{ $method === 'FULL_MONTH' ? 'คิดเต็มเดือนที่เริ่มใช้งาน โดยปรับงวดสุดท้ายให้ยอดรวมตรงฐานค่าเสื่อม' : 'เฉลี่ยตามจำนวนวันใช้งานจริงในแต่ละเดือน โดยปรับงวดสุดท้ายให้ยอดรวมตรงฐานค่าเสื่อม' }}@if($schedule['total_days']) · รวม {{ number_format($schedule['total_days']) }} วัน@endif</p>
                                <div class="table-responsive"><table class="table table-hover align-middle mb-0 small"><thead class="table-light"><tr><th>งวด</th><th>ช่วงคำนวณ</th>@if($method === 'DAILY')<th class="text-end">วัน</th>@endif<th class="text-end">ค่าเสื่อมงวดนี้</th><th class="text-end">ค่าเสื่อมสะสม</th><th class="text-end">มูลค่าคงเหลือ</th></tr></thead><tbody>@foreach ($schedule['rows'] as $row)<tr><td>{{ $row['number'] }}</td><td>{{ $row['period_start']->format($dateFormat) }} – {{ $row['period_end']->format($dateFormat) }}</td>@if($method === 'DAILY')<td class="text-end">{{ number_format($row['days']) }}</td>@endif<td class="text-end">{{ number_format((float) $row['depreciation'], 2) }}</td><td class="text-end">{{ number_format((float) $row['accumulated_depreciation'], 2) }}</td><td class="text-end">{{ number_format((float) $row['closing_value'], 2) }}</td></tr>@endforeach</tbody><tfoot class="table-light"><tr><th colspan="{{ $method === 'DAILY' ? 3 : 2 }}">รวมตามประมาณการ</th><th class="text-end">{{ number_format((float) $schedule['depreciable_amount'], 2) }}</th><th class="text-end">{{ number_format((float) $schedule['depreciable_amount'], 2) }}</th><th class="text-end">{{ number_format((float) $schedule['residual_value'], 2) }}</th></tr></tfoot></table></div>
                            </div>
                        @endforeach
                    @else
                        <p class="text-secondary mb-0">ยังไม่มีวันที่เริ่มคิดหรืออายุใช้งานสำหรับสร้างตัวอย่างตารางค่าเสื่อม</p>
                    @endif
                </div></div>
            @empty
                <div class="card border-0 shadow-sm"><div class="card-body text-center text-secondary py-5">ยังไม่มี profile ค่าเสื่อม</div></div>
            @endforelse
        </div>

        <div class="tab-pane fade" id="asset-location"><div class="card border-0 shadow-sm"><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><span class="small text-secondary d-block">คลัง</span><strong>{{ $asset->warehouse ? $asset->warehouse->code.' · '.$asset->warehouse->name : '-' }}</strong></div>
            <div class="col-md-4"><span class="small text-secondary d-block">สถานที่</span><strong>{{ $asset->location ? $asset->location->code.' · '.$asset->location->name : '-' }}</strong></div>
            <div class="col-md-4"><span class="small text-secondary d-block">ผู้ดูแล</span><strong>{{ $asset->custodian?->name ?? '-' }}</strong></div>
            <div class="col-12"><span class="small text-secondary d-block">เหตุผลสถานะ</span><span>{{ $asset->status_reason ?: '-' }}</span></div>
        </div></div></div></div>

        <div class="tab-pane fade" id="asset-maintenance"><div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h6 mb-1">ประวัติงานซ่อมบำรุง</h2><p class="small text-secondary mb-0">เรียงจากรายการล่าสุด</p></div>@if(auth()->user()->hasPermission('asset.maintenance.create'))<a class="btn btn-sm btn-dark" href="{{ route('asset.maintenance.create') }}">แจ้งซ่อม</a>@endif</div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>เลขที่เอกสาร</th><th>วันที่แจ้ง</th><th>ประเภท</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead><tbody>@forelse($asset->maintenanceRequests as $maintenance)<tr><td>{{ $maintenance->document_number }}</td><td>{{ $maintenance->reported_date?->format($dateFormat) }}</td><td>{{ ['CORRECTIVE'=>'ซ่อมแก้ไข','PREVENTIVE'=>'บำรุงรักษาเชิงป้องกัน','INSPECTION'=>'ตรวจสอบ'][$maintenance->maintenance_type] ?? $maintenance->maintenance_type }}</td><td>{{ ['OPEN'=>'เปิดงาน','ASSIGNED'=>'มอบหมายแล้ว','IN_PROGRESS'=>'กำลังซ่อม','WAITING_PARTS'=>'รออะไหล่','COMPLETED'=>'ปิดงานแล้ว','CANCELLED'=>'ยกเลิก'][$maintenance->status] ?? $maintenance->status }}</td><td class="text-end"><a class="btn btn-sm btn-outline-dark" href="{{ route('asset.maintenance.show',$maintenance) }}">ดู</a></td></tr>@empty<tr><td colspan="5" class="text-center text-secondary py-4">ยังไม่มีประวัติงานซ่อมบำรุง</td></tr>@endforelse</tbody></table></div></div></div></div>

        <div class="tab-pane fade" id="asset-attachments"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><h2 class="h6 mb-0">เอกสารแนบ</h2>@if(auth()->user()->hasPermission('asset.attachments.manage'))<button class="btn btn-sm btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#asset-attachment-form" type="button"><i class="bx bx-upload me-1" aria-hidden="true"></i>อัปโหลด</button>@endif</div>@if(auth()->user()->hasPermission('asset.attachments.manage'))<div class="collapse mb-3" id="asset-attachment-form"><form id="attachment-form" method="post" enctype="multipart/form-data" action="{{ route('asset.assets.attachments.store', $asset) }}" novalidate>@csrf<div class="row g-3"><div class="col-md-4"><label class="form-label" for="file_type">ประเภท <span class="text-danger">*</span></label><select class="form-select" id="file_type" name="file_type" required><option value="">เลือกประเภท</option>@foreach(['PHOTO'=>'รูปภาพ','INVOICE'=>'ใบซื้อ/ใบแจ้งหนี้','WARRANTY'=>'รับประกัน','INSURANCE'=>'ประกันภัย','OTHER'=>'อื่น ๆ'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select><div class="invalid-feedback" data-error-for="file_type"></div></div><div class="col-md-8"><label class="form-label" for="file">ไฟล์ <span class="text-danger">*</span></label><input class="form-control" id="file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" required><div class="form-text">PDF, JPG, PNG หรือ WebP ขนาดไม่เกิน 10 MB</div><div class="invalid-feedback" data-error-for="file"></div></div></div><div class="mt-3"><button class="btn btn-dark" type="submit" data-busy-text="กำลังอัปโหลด...">บันทึกเอกสาร</button></div></form></div>@endif<div id="asset-photo-gallery" class="row g-3 mb-4 d-none"></div><div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>ประเภท</th><th>ชื่อไฟล์</th><th>ขนาด</th><th>ผู้อัปโหลด</th><th>เวลา</th><th class="text-end">จัดการ</th></tr></thead><tbody id="asset-attachment-rows"><tr><td colspan="6" class="text-center text-secondary py-4">กำลังโหลด...</td></tr></tbody></table></div></div></div></div>

        <div class="modal fade" id="asset-photo-modal" tabindex="-1" aria-labelledby="asset-photo-title" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="asset-photo-title">รูปภาพสินทรัพย์</h2><button class="btn-close" data-bs-dismiss="modal" type="button" aria-label="ปิด"></button></div><div class="modal-body text-center"><img id="asset-photo-preview" class="img-fluid" alt="รูปภาพสินทรัพย์"></div></div></div></div>

        <div class="tab-pane fade" id="asset-history"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6 mb-3">ประวัติสินทรัพย์</h2>@forelse ($asset->histories as $history)<div class="border-bottom py-3"><div class="d-flex justify-content-between gap-3"><strong>{{ $historyLabels[$history->event_type] ?? $history->event_type }}</strong><span class="small text-secondary">{{ $history->occurred_at?->format($dateFormat.' H:i') }}</span></div><div class="small text-secondary">{{ $history->actor?->name ?? 'ระบบ' }}@if($history->source_document_number) · {{ $history->source_document_number }}@endif</div>@if($history->reason)<div class="mt-1">{{ $history->reason }}</div>@endif</div>@empty<p class="text-secondary mb-0">ยังไม่มีประวัติสินทรัพย์</p>@endforelse</div></div></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $(document).on('click', '.js-depreciation-method', function () {
        var $button = $(this), book = $button.data('book'), method = $button.data('method');
        $('.js-depreciation-method[data-book="' + book + '"]').removeClass('active');
        $button.addClass('active');
        $('.js-depreciation-schedule[data-book="' + book + '"]').addClass('d-none');
        $('.js-depreciation-schedule[data-book="' + book + '"][data-method="' + method + '"]').removeClass('d-none');
    });
    var $rows=$('#asset-attachment-rows'),$gallery=$('#asset-photo-gallery'),text=$.fn.dataTable.render.text(),listUrl=@json(route('asset.assets.attachments.index', $asset)),types={PHOTO:'รูปภาพ',INVOICE:'ใบซื้อ/ใบแจ้งหนี้',WARRANTY:'รับประกัน',INSURANCE:'ประกันภัย',REPAIR_REPORT:'รายงานซ่อม',DISPOSAL_EVIDENCE:'หลักฐานจำหน่าย',OTHER:'อื่น ๆ'};
    function loadAttachments(){ $.getJSON(listUrl).done(function(response){ var rows=response.data||[],photos=rows.filter(function(row){return row.preview_url;});$gallery.toggleClass('d-none',!photos.length).html(photos.map(function(row){return '<div class="col-6 col-md-3"><button class="btn p-0 border w-100 js-preview-asset-photo" data-url="'+text.display(row.preview_url)+'" data-name="'+text.display(row.original_name)+'" type="button"><img class="img-fluid rounded" src="'+text.display(row.preview_url)+'" alt="'+text.display(row.original_name)+'"></button></div>';}).join(''));$rows.html(rows.length?rows.map(function(row){var actions=(row.preview_url?'<button class="btn btn-sm btn-outline-secondary js-preview-asset-photo" data-url="'+text.display(row.preview_url)+'" data-name="'+text.display(row.original_name)+'" type="button">ดูรูป</button> ':'')+'<a class="btn btn-sm btn-outline-dark" href="'+text.display(row.download_url)+'">ดาวน์โหลด</a>';if(row.delete_url){actions+=' <button class="btn btn-sm btn-outline-danger js-delete-attachment" data-url="'+text.display(row.delete_url)+'" type="button">ลบ</button>';}return '<tr><td>'+text.display(types[row.file_type]||row.file_type)+'</td><td>'+text.display(row.original_name)+'</td><td>'+Number(row.bytes).toLocaleString()+' bytes</td><td>'+text.display(row.uploaded_by)+'</td><td>'+text.display(row.uploaded_at||'-')+'</td><td class="text-end">'+actions+'</td></tr>';}).join(''):'<tr><td colspan="6" class="text-center text-secondary py-4">ยังไม่มีเอกสารแนบ</td></tr>'); }).fail(function(){$rows.html('<tr><td colspan="6" class="text-center text-danger py-4">โหลดเอกสารแนบไม่สำเร็จ</td></tr>');}); }
    loadAttachments();
    window.erpAjaxForm({form:'#attachment-form',reload:false,redirect:false});
    window.erpAjaxDelete({button:'.js-delete-attachment',reload:false,confirm:'ยืนยันการลบเอกสารแนบนี้หรือไม่?'});
    $(document).on('click','.js-preview-asset-photo',function(){ $('#asset-photo-preview').attr({src:$(this).data('url'),alt:$(this).data('name')});$('#asset-photo-title').text($(this).data('name'));bootstrap.Modal.getOrCreateInstance(document.getElementById('asset-photo-modal')).show(); });
    $(document).ajaxComplete(function(event, xhr, settings){ if(((settings.url===@json(route('asset.assets.attachments.store', $asset))&&settings.type==='POST')||settings.type==='DELETE')&&xhr.status>=200&&xhr.status<300){loadAttachments();} });
});
</script>
@endpush
