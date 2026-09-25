@extends('Production::layout')
@section('title', 'คำขอสั่งผลิตจากฝ่ายขาย | Production')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><p class="eyebrow mb-2">PRODUCTION / DEMAND</p><h1 class="h3 mb-1">คำขอสั่งผลิตจากฝ่ายขาย</h1><p class="text-secondary mb-0">เฉพาะรายการที่ฝ่ายขายขอผลิตแล้วและยังไม่มี WO หรือ HS/IV ที่ใช้งานอยู่ · ตรวจข้อกำหนดลูกค้าก่อนสร้าง WO; POS ยังขายจากสต็อกได้ · คลังเบิก/รับผลิต: <strong>{{ request()->attributes->get('selectedWarehouse')?->code ?? '—' }}</strong> (เปลี่ยนคลังในบริบทด้านบนก่อนสร้าง)</p></div>
        <a class="btn btn-app-soft" href="{{ route('production.orders.index') }}"><i class="bx bx-list-ul me-1" aria-hidden="true"></i>ใบสั่งผลิต</a>
    </div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"><h2 class="h6 mb-0">ตัวกรอง</h2><button id="demand-reset" class="btn btn-sm btn-app-soft" type="button"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
        <div class="card-body"><div class="row g-3 align-items-end"><div class="col-12 col-md-4"><label class="form-label" for="demand-readiness">ความพร้อมสร้างใบสั่งผลิต</label><select id="demand-readiness" class="form-select"><option value="">ทั้งหมด</option><option value="READY">สร้างใบสั่งผลิตได้</option><option value="NEEDS_BOM">ตั้งค่า BOM ก่อน</option><option value="INVALID">สินค้า/หน่วยไม่พร้อม</option></select></div><div class="col-6 col-md-3"><label class="form-label" for="demand-date-from">กำหนดส่งตั้งแต่</label><input id="demand-date-from" class="form-control" type="date"></div><div class="col-6 col-md-3"><label class="form-label" for="demand-date-to">ถึง</label><input id="demand-date-to" class="form-control" type="date"></div><div class="col-12 col-md-auto"><button id="demand-search" class="btn btn-app-soft" type="button"><i class="bx bx-search me-1" aria-hidden="true"></i>ค้นหา</button></div></div></div>
    </div>
    <div id="demand-feedback" class="alert alert-danger" role="alert" hidden></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="table-responsive"><table id="production-demand-table" class="table table-hover align-middle w-100" data-url="{{ route('production.demand.data') }}"><thead><tr><th>ลำดับ</th><th>Sales Order / ลูกค้า</th><th>สินค้า</th><th class="text-end">จำนวน</th><th>ข้อกำหนดลูกค้า</th><th>กำหนดส่ง</th><th>เริ่มได้ตั้งแต่</th><th>ความพร้อม</th><th>จัดการ</th></tr></thead></table></div></div></div>
</div>
@if(auth()->user()->hasPermission('production.orders.create'))
<div class="modal fade" id="demand-create-modal" tabindex="-1" aria-labelledby="demand-create-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form id="demand-create-form" class="modal-content" method="post">
            @csrf
            <div class="modal-header"><h2 class="modal-title h5" id="demand-create-title">สร้างใบสั่งผลิตจากคำขอ</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
            <div class="modal-body">
                <div class="alert alert-info"><div><strong id="demand-wo-quantity"></strong> · คลังเบิก/รับผลิต: <strong>{{ request()->attributes->get('selectedWarehouse')?->code ?? '—' }}</strong></div><div>เริ่มได้ตั้งแต่: <strong id="demand-wo-earliest"></strong></div><div class="mt-2"><strong>ข้อกำหนดจากลูกค้า:</strong> <span id="demand-wo-spec"></span></div></div>
                <p class="small text-secondary">สร้าง WO เต็มจำนวนรายการแม้มีสต็อกอยู่แล้ว · หมายเหตุงานผลิตแก้ไขได้ แต่ข้อกำหนดลูกค้าต้นฉบับจะยังคงอยู่</p>
                <div class="row g-3">
                    <div class="col-12"><label class="form-label" for="demand-wo-bom">Active BOM <span class="text-danger">*</span></label><select id="demand-wo-bom" name="bom_revision_id" class="form-select" required></select><div class="invalid-feedback" data-error-for="bom_revision_id"></div></div>
                    <div class="col-6"><label class="form-label" for="demand-wo-start-date">วันเริ่มแผน <span class="text-danger">*</span></label><input id="demand-wo-start-date" name="planned_start_date" class="form-control" type="date" required><div class="invalid-feedback" data-error-for="planned_start_date"></div></div>
                    <div class="col-6"><label class="form-label" for="demand-wo-finish-date">วันจบแผน (ถ้ามี)</label><input id="demand-wo-finish-date" name="planned_finish_date" class="form-control" type="date"><div class="invalid-feedback" data-error-for="planned_finish_date"></div></div>
                    <div class="col-6"><label class="form-label" for="demand-wo-start-time">เวลาเริ่มตามแผน</label><input id="demand-wo-start-time" name="planned_start_at" class="form-control" type="datetime-local"><div class="invalid-feedback" data-error-for="planned_start_at"></div></div>
                    <div class="col-6"><label class="form-label" for="demand-wo-finish-time">เวลาจบตามแผน</label><input id="demand-wo-finish-time" name="planned_finish_at" class="form-control" type="datetime-local"><div class="invalid-feedback" data-error-for="planned_finish_at"></div><div class="form-text">กรอกเวลาเริ่มและจบทั้งคู่เพื่อแสดงใน Timeline</div></div>
                    <div class="col-12 col-md-6"><label class="form-label" for="demand-wo-customer-delivery">กำหนดส่งที่ลูกค้าต้องการ (จาก POS)</label><input id="demand-wo-customer-delivery" class="form-control" type="date" readonly></div>
                    <div class="col-12 col-md-6"><label class="form-label" for="demand-wo-delivery-at">กำหนดส่งตามแผน (วัน/เวลา ถ้ามี)</label><input id="demand-wo-delivery-at" name="required_delivery_at" class="form-control" type="datetime-local"><div class="invalid-feedback" data-error-for="required_delivery_at"></div><div class="form-text">กำหนดส่งลูกค้าแสดงแยกไว้ ไม่สมมติเวลาให้ลูกค้าโดยอัตโนมัติ</div></div>
                    <div class="col-12"><label class="form-label" for="demand-wo-notes">หมายเหตุสำหรับงานผลิต</label><textarea id="demand-wo-notes" name="notes" class="form-control" maxlength="2000" rows="3"></textarea><div class="invalid-feedback" data-error-for="notes"></div></div>
                </div>
                <div id="demand-wo-error" class="text-danger small mt-3" role="alert" hidden></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-app-soft" data-bs-dismiss="modal">กลับ</button><button type="submit" class="btn btn-app-primary"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบสั่งผลิต</button></div>
        </form>
    </div>
</div>
@endif
@endsection
@push('scripts')
<script>
$(function(){
    const esc = $.fn.dataTable.render.text().display;
    const table = $('#production-demand-table');
    const dt = table.DataTable({...window.erpDataTableDefaults, order:[[5,'asc']], buttons:[window.erpExcelButton(table)], ajax:{url:table.data('url'),data:function(d){d.readiness=$('#demand-readiness').val();d.date_from=$('#demand-date-from').val();d.date_to=$('#demand-date-to').val();}}, columns:[
        window.erpRowNumberColumn(),
        {data:'sales_order_label', name:'sales_orders.document_number', render:esc},
        {data:'item_label', name:'wms_items.code', render:esc},
        {data:'quantity_label', name:'sales_order_lines.quantity', className:'text-end', render:esc},
        {data:'production_specification', name:'sales_order_lines.production_specification', render:function(value,type,row){return esc(value || row.description || '—');}},
        {data:'required_delivery_date_label', name:'sales_order_lines.requested_delivery_date', render:esc},
        {data:'requested_start_date_label', name:'sales_order_lines.requested_start_date', render:esc},
        {data:'readiness_label', name:'readiness_label', orderable:false, render:function(value,type,row){if(type!=='display') return value;const status={READY:'app-status-success',NEEDS_BOM:'app-status-warning',INVALID:'app-status-neutral'}[row.readiness_code]||'app-status-neutral';return '<span class="badge '+status+'">'+esc(value)+'</span>'; }},
        {data:null, orderable:false, searchable:false, className:'text-nowrap', render:function(_,type,row){if(type!=='display') return '';return row.create_url ? '<button class="btn btn-sm btn-app-soft js-create-wo" type="button" title="สร้างใบสั่งผลิต" aria-label="สร้างใบสั่งผลิต" data-url="'+esc(row.create_url)+'" data-boms="'+esc(row.bom_options_url)+'" data-quantity="'+esc(row.quantity_label)+'" data-start="'+esc(row.requested_start_date || '')+'" data-delivery="'+esc(row.requested_delivery_date || '')+'" data-spec="'+esc(row.production_specification || row.description || '')+'"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้างใบสั่งผลิต</button>' : ''; }}
    ]});
    $('#demand-search').on('click',function(){dt.ajax.reload();});
    $('#demand-reset').on('click',function(){$('#demand-readiness,#demand-date-from,#demand-date-to').val('');$('#demand-readiness').trigger('change.select2');dt.search('').ajax.reload();});
    const form=$('#demand-create-form');
    const feedback=$('#demand-feedback');
    $(document).on('click','.js-create-wo',async function(){
        const btn=$(this).prop('disabled',true);
        feedback.prop('hidden',true);
        try {
            const {boms}=await $.get(btn.attr('data-boms'));
            if(!boms.length) throw new Error('ไม่มี Active BOM ที่พร้อมใช้งาน กรุณาตั้งค่า BOM ก่อนสร้าง WO');
            form[0].reset();
            form.attr('action',btn.attr('data-url'));
            form.find('.is-invalid').removeClass('is-invalid');
            $('#demand-wo-error').prop('hidden',true);
            $('#demand-wo-quantity').text('จำนวน '+btn.attr('data-quantity'));
            $('#demand-wo-customer-delivery').val(btn.attr('data-delivery'));
            $('#demand-wo-earliest').text(btn.attr('data-start') || 'ไม่ระบุ');
            $('#demand-wo-spec').text(btn.attr('data-spec') || 'ไม่มี');
            const bom=$('#demand-wo-bom').empty();
            boms.forEach(item=>bom.append(new Option(item.label,item.id)));
            const today=new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10);
            $('#demand-wo-start-date').val(btn.attr('data-start') > today ? btn.attr('data-start') : today);
            $('#demand-wo-notes').val(btn.attr('data-spec') || '');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('demand-create-modal')).show();
        } catch(xhr) {
            feedback.text(xhr.responseJSON?.message || xhr.message || 'โหลดข้อมูล BOM ไม่สำเร็จ กรุณาลองใหม่').prop('hidden',false);
        } finally { btn.prop('disabled',false); }
    });
    form.find('[name=planned_start_at],[name=planned_finish_at]').on('change',function(){
        if(this.value) form.find('[name='+this.name.replace('_at','_date')+']').val(this.value.slice(0,10));
    });
    form.on('submit',function(event){
        event.preventDefault();
        const button=form.find('[type=submit]').prop('disabled',true);
        form.find('.is-invalid').removeClass('is-invalid');
        $('#demand-wo-error').prop('hidden',true);
        $.post(form.attr('action'),form.serialize()).done(response=>window.location.assign(response.redirect)).fail(xhr=>{
            button.prop('disabled',false);
            const errors=xhr.responseJSON?.errors||{};
            Object.entries(errors).forEach(([name,messages])=>{
                form.find('[name='+name+']').addClass('is-invalid');
                form.find('[data-error-for='+name+']').text(messages[0]);
            });
            $('#demand-wo-error').text(xhr.responseJSON?.message || 'สร้างใบสั่งผลิตไม่สำเร็จ กรุณาลองใหม่').prop('hidden',false);
            form.find('.is-invalid').first().trigger('focus');
        });
    });
});
</script>
@endpush
