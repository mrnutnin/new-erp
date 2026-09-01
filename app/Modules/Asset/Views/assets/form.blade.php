@extends('Asset::layout')

@section('title', ($asset->exists ? 'แก้ไข' : 'เพิ่ม').'ทะเบียนสินทรัพย์ | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="mb-4">
            <p class="eyebrow mb-2">ASSET / REGISTER</p>
            <h1 class="h3 mb-2">{{ $asset->exists ? 'แก้ไข' : 'เพิ่ม' }}ทะเบียนสินทรัพย์</h1>
            <p class="text-secondary mb-0">ทะเบียนนี้อยู่ภายใต้สาขาที่เลือก และแก้ไขได้เฉพาะสถานะร่าง</p>
        </div>

        <div class="card border-0 shadow-sm"><div class="card-body p-4 p-md-5">
            <form id="asset-form" method="post" action="{{ $asset->exists ? route('asset.assets.update', $asset) : route('asset.assets.store') }}" novalidate>
                @csrf
                @if ($asset->exists) @method('PUT') @endif

                @if ($asset->exists)
                    <div class="alert alert-secondary d-flex align-items-center gap-2" role="status"><i class="bx bx-hash" aria-hidden="true"></i><span>เลขทะเบียน: <strong>{{ $asset->asset_number }}</strong> · สถานะ: <strong>{{ $asset->status }}</strong></span></div>
                @endif

                <h2 class="h5 mb-3">ข้อมูลทะเบียน</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-4"><label class="form-label" for="registration_date">วันที่ขึ้นทะเบียน <span class="text-danger">*</span></label><input class="form-control" type="date" id="registration_date" name="registration_date" value="{{ old('registration_date', $asset->registration_date?->format('Y-m-d')) }}" required><div class="form-text">ใช้กำหนดเลขทะเบียนสินทรัพย์ แยกจากวันซื้อและวันพร้อมใช้งาน</div><div class="invalid-feedback" data-error-for="registration_date"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="asset_category_id">หมวดสินทรัพย์ <span class="text-danger">*</span></label><select class="form-select" id="asset_category_id" name="asset_category_id" required><option value="">เลือกหมวด</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('asset_category_id', $asset->asset_category_id) === (string) $category->id)>{{ $category->code }} · {{ $category->name }}</option>@endforeach</select><div class="invalid-feedback" data-error-for="asset_category_id"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="name">ชื่อสินทรัพย์ <span class="text-danger">*</span></label><input class="form-control" id="name" name="name" maxlength="255" value="{{ old('name', $asset->name) }}" required><div class="invalid-feedback" data-error-for="name"></div></div>
                    <div class="col-12"><label class="form-label" for="description">รายละเอียด</label><textarea class="form-control" id="description" name="description" rows="2">{{ old('description', $asset->description) }}</textarea><div class="invalid-feedback" data-error-for="description"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="tag_number">หมายเลขแท็ก</label><input class="form-control" id="tag_number" name="tag_number" maxlength="100" value="{{ old('tag_number', $asset->tag_number) }}"><div class="invalid-feedback" data-error-for="tag_number"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="barcode_value">บาร์โค้ด</label><input class="form-control" id="barcode_value" name="barcode_value" maxlength="100" value="{{ old('barcode_value', $asset->barcode_value) }}"><div class="invalid-feedback" data-error-for="barcode_value"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="parent_asset_id">สินทรัพย์หลัก</label><select class="form-select" id="parent_asset_id" name="parent_asset_id"><option value="">ไม่มี</option>@foreach($parents as $parent)<option value="{{ $parent->id }}" @selected((string) old('parent_asset_id', $asset->parent_asset_id) === (string) $parent->id)>{{ $parent->asset_number }} · {{ $parent->name }}</option>@endforeach</select><div class="invalid-feedback" data-error-for="parent_asset_id"></div></div>
                </div>

                <hr class="my-4"><h2 class="h5 mb-3">การจัดเก็บและผู้ดูแล</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-4"><label class="form-label" for="warehouse_id">คลัง</label><select class="form-select js-asset-option" id="warehouse_id" name="warehouse_id" data-type="warehouse"><option value="">ไม่กำหนด</option>@if($asset->warehouse)<option value="{{ $asset->warehouse_id }}" selected>{{ $asset->warehouse->code }} · {{ $asset->warehouse->name }}</option>@endif</select><div class="invalid-feedback" data-error-for="warehouse_id"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="location_id">สถานที่สินทรัพย์</label><select class="form-select" id="location_id" name="location_id"><option value="">ไม่กำหนด</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) old('location_id', $asset->location_id) === (string) $location->id)>{{ $location->code }} · {{ $location->name }}</option>@endforeach</select><div class="invalid-feedback" data-error-for="location_id"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="custodian_user_id">ผู้ดูแล</label><select class="form-select js-asset-option" id="custodian_user_id" name="custodian_user_id" data-type="custodian"><option value="">ไม่กำหนด</option>@if($asset->custodian)<option value="{{ $asset->custodian_user_id }}" selected>{{ $asset->custodian->employee_code }} · {{ $asset->custodian->name }}</option>@endif</select><div class="invalid-feedback" data-error-for="custodian_user_id"></div></div>
                </div>

                <hr class="my-4"><h2 class="h5 mb-3">รายละเอียดสินค้าและการได้มา</h2>
                <div class="row g-3">
                    @foreach (['brand' => 'ยี่ห้อ', 'model' => 'รุ่น', 'serial_number' => 'Serial number', 'manufacturer' => 'ผู้ผลิต'] as $field => $label)
                        <div class="col-12 col-md-3"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-control" id="{{ $field }}" name="{{ $field }}" maxlength="255" value="{{ old($field, $asset->{$field}) }}"><div class="invalid-feedback" data-error-for="{{ $field }}"></div></div>
                    @endforeach
                    <div class="col-12 col-md-4"><label class="form-label" for="acquisition_date">วันที่ซื้อ <span class="text-danger">*</span></label><input class="form-control" type="date" id="acquisition_date" name="acquisition_date" value="{{ old('acquisition_date', $asset->acquisition_date?->format('Y-m-d')) }}" required><div class="invalid-feedback" data-error-for="acquisition_date"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="placed_in_service_date">วันที่พร้อมใช้งาน</label><input class="form-control" type="date" id="placed_in_service_date" name="placed_in_service_date" value="{{ old('placed_in_service_date', $asset->placed_in_service_date?->format('Y-m-d')) }}"><div class="invalid-feedback" data-error-for="placed_in_service_date"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="supplier_id">ผู้ขาย</label><select class="form-select js-asset-option" id="supplier_id" name="supplier_id" data-type="supplier"><option value="">ไม่กำหนด</option>@if($asset->supplier)<option value="{{ $asset->supplier_id }}" selected>{{ $asset->supplier->code }} · {{ $asset->supplier->name }}</option>@endif</select><div class="invalid-feedback" data-error-for="supplier_id"></div></div>
                </div>

                <hr class="my-4"><h2 class="h5 mb-3">มูลค่า ประกัน และค่าเสื่อม</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-4"><label class="form-label" for="original_cost">ต้นทุนคาดการณ์ก่อนรับรู้ <span class="text-danger">*</span></label><input class="form-control" type="number" min="0" step="0.01" id="original_cost" name="original_cost" value="{{ old('original_cost', $asset->original_cost) }}" required><div class="form-text">เมื่อ Post ใบรับรู้ ระบบจะใช้ยอด Capitalization จริงเป็นต้นทุนทางบัญชี</div><div class="invalid-feedback" data-error-for="original_cost"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="currency_code">สกุลเงิน <span class="text-danger">*</span></label><input class="form-control text-uppercase" id="currency_code" name="currency_code" minlength="3" maxlength="3" value="{{ old('currency_code', $asset->currency_code) }}" required><div class="invalid-feedback" data-error-for="currency_code"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="exchange_rate">อัตราแลกเปลี่ยน <span class="text-danger">*</span></label><input class="form-control" type="number" min="0.000001" step="0.000001" id="exchange_rate" name="exchange_rate" value="{{ old('exchange_rate', $asset->exchange_rate) }}" required><div class="invalid-feedback" data-error-for="exchange_rate"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="warranty_end_date">สิ้นสุดประกันสินค้า</label><input class="form-control" type="date" id="warranty_end_date" name="warranty_end_date" value="{{ old('warranty_end_date', $asset->warranty_end_date?->format('Y-m-d')) }}"><div class="invalid-feedback" data-error-for="warranty_end_date"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="insurance_policy_number">เลขที่กรมธรรม์</label><input class="form-control" id="insurance_policy_number" name="insurance_policy_number" maxlength="255" value="{{ old('insurance_policy_number', $asset->insurance_policy_number) }}"><div class="invalid-feedback" data-error-for="insurance_policy_number"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="insurance_end_date">สิ้นสุดประกันภัย</label><input class="form-control" type="date" id="insurance_end_date" name="insurance_end_date" value="{{ old('insurance_end_date', $asset->insurance_end_date?->format('Y-m-d')) }}"><div class="invalid-feedback" data-error-for="insurance_end_date"></div></div>
                    <div class="col-12 col-md-6"><input type="hidden" name="is_depreciation_suspended" value="0"><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="is_depreciation_suspended" name="is_depreciation_suspended" value="1" @checked(old('is_depreciation_suspended', $asset->is_depreciation_suspended))><label class="form-check-label" for="is_depreciation_suspended">ระงับการคิดค่าเสื่อม</label></div><div class="invalid-feedback" data-error-for="is_depreciation_suspended"></div></div>
                    <div class="col-12 col-md-6"><label class="form-label" for="status_reason">เหตุผลประกอบ</label><input class="form-control" id="status_reason" name="status_reason" maxlength="500" value="{{ old('status_reason', $asset->status_reason) }}"><div class="invalid-feedback" data-error-for="status_reason"></div></div>
                </div>

                <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mt-4">
                    <a class="btn btn-outline-dark" href="{{ route('asset.assets.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับ</a>
                    <div class="d-flex gap-2">
                        @if ($asset->exists && $asset->status === 'DRAFT' && auth()->user()->hasPermission('asset.capitalizations.create'))
                            <a class="btn btn-outline-dark" href="{{ route('asset.capitalizations.create', ['source_type' => 'MANUAL_RECLASS', 'asset_id' => $asset->id]) }}"><i class="bx bx-book-add me-1" aria-hidden="true"></i>ตั้งทุนและลงบัญชี</a>
                        @endif
                        @if ($asset->exists && $asset->status === 'DRAFT' && auth()->user()->hasPermission('asset.register.update'))
                            <button class="btn btn-outline-danger js-delete-asset" type="button" data-url="{{ route('asset.assets.destroy', $asset) }}"><i class="bx bx-trash me-1" aria-hidden="true"></i>ลบ</button>
                        @endif
                        @if (! $asset->exists || ($asset->status === 'DRAFT' && auth()->user()->hasPermission('asset.register.update')))
                            <button class="btn btn-dark" type="submit" data-busy-text="กำลังบันทึก..."><i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกทะเบียนสินทรัพย์</button>
                        @endif
                    </div>
                </div>
            </form>
        </div></div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    $('.js-asset-option').select2({width:'100%',allowClear:true,placeholder:'ไม่กำหนด',ajax:{url:@json(route('asset.assets.options')),dataType:'json',delay:250,data:function(params){return {type:$(this).data('type'),q:params.term||'',page:params.page||1};},processResults:function(data){return data;}}});
    window.erpAjaxForm({form:'#asset-form',redirect:@json(! $asset->exists),reload:false});
    window.erpAjaxDelete({button:'.js-delete-asset',redirect:@json(route('asset.assets.index')),confirm:'ยืนยันการลบสินทรัพย์ร่างนี้หรือไม่?'});
});
</script>
@endpush
