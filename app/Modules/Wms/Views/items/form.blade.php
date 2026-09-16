@extends('Wms::layout')
@section('title', 'สินค้า | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4"><h1 class="h3 mb-4">{{ $item->exists ? 'แก้ไข' : 'เพิ่ม' }}สินค้า</h1>
<form id="item-form" method="POST" enctype="multipart/form-data" action="{{ $item->exists ? route('wms.items.update', $item) : route('wms.items.store') }}">@csrf @if($item->exists) @method('PUT') @endif
<div class="card border-0 shadow-sm"><div class="card-body p-4 row g-3">
<div class="col-md-3"><label class="form-label">รหัส</label><input class="form-control" name="code" value="{{ old('code', $item->code) }}" required></div>
<div class="col-md-5"><label class="form-label">ชื่อ</label><input class="form-control" name="name" value="{{ old('name', $item->name) }}" required></div>
<div class="col-md-4"><label class="form-label">หมวดสินค้า</label><select class="form-select js-category" name="category_id" data-url="{{ route('wms.items.category-options') }}" required>@if($selectedCategory)<option value="{{ $selectedCategory->id }}" selected>{{ $selectedCategory->code }} · {{ $selectedCategory->name }}</option>@endif</select></div>
<div class="col-md-3"><label class="form-label">ประเภท</label><select class="form-select" name="item_type"><option value="GOODS" @selected(old('item_type', $item->item_type) === 'GOODS')>สินค้า</option><option value="SERVICE" @selected(old('item_type', $item->item_type) === 'SERVICE')>บริการ</option></select></div>
<div class="col-md-3"><label class="form-label">หน่วยหลัก</label><select class="form-select js-uom" name="base_uom_id" data-url="{{ route('wms.items.uom-options') }}" required>@if($selectedUom)<option value="{{ $selectedUom->id }}" selected>{{ $selectedUom->code }} · {{ $selectedUom->name }}</option>@endif</select><input type="hidden" name="base_uom" value="{{ old('base_uom', $item->base_uom ?: 'EA') }}"></div>
<div class="col-md-3 form-check mt-5"><input class="form-check-input" type="checkbox" name="is_stock_item" value="1" @checked(old('is_stock_item', $item->is_stock_item))><label class="form-check-label">ติดตามสต็อก</label></div><div class="col-md-3 form-check mt-5"><input class="form-check-input" id="is_asset_capitalizable" type="checkbox" name="is_asset_capitalizable" value="1" @checked(old('is_asset_capitalizable', $item->is_asset_capitalizable))><label class="form-check-label" for="is_asset_capitalizable">รับรู้เป็นสินทรัพย์ได้</label></div><div class="col-md-3 form-check mt-5"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active ?? true))><label class="form-check-label">ใช้งาน</label></div>
<div class="col-md-4"><label class="form-label" for="default_asset_category_id">หมวดสินทรัพย์เริ่มต้น <span class="text-danger js-asset-required">*</span></label><select class="form-select js-asset-category" id="default_asset_category_id" name="default_asset_category_id" data-url="{{ route('wms.items.asset-category-options') }}"><option value="">ไม่กำหนด</option>@if($selectedAssetCategory)<option value="{{ $selectedAssetCategory->id }}" selected>{{ $selectedAssetCategory->code }} · {{ $selectedAssetCategory->name }}</option>@endif</select><div class="form-text">ใช้คัดกรองบรรทัด Purchase Invoice ที่เลือกมารับรู้สินทรัพย์</div></div>
@foreach([['inventory_account_id', 'บัญชีสินค้าคงเหลือ', 'ASSET', false], ['sales_account_id', 'บัญชีรายได้', 'REVENUE', true], ['cogs_account_id', 'บัญชีต้นทุน', 'COGS', false]] as [$field, $label, $type, $required])<div class="col-md-4"><label class="form-label">{{ $label }}</label><select class="form-select js-account" name="{{ $field }}" data-type="{{ $type }}" @required($required)><option value="">{{ $required ? 'เลือกบัญชี' : 'ไม่กำหนด' }}</option>@if($a = $accounts->get($item->{$field}))<option value="{{ $a->id }}" selected>{{ $a->code }} · {{ $a->name }}</option>@endif</select></div>@endforeach
<div class="col-12"><hr class="my-2"><h2 class="h5 mb-1">รูปภาพสินค้า</h2><p class="small text-secondary mb-2">กำหนดหน้าปก 1 ภาพ และเพิ่มภาพประกอบได้ไม่เกิน 5 ภาพ</p></div>
<div class="col-12 col-lg-6">
    <x-platform::file-uploader
        name="cover_image"
        label="ภาพหน้าปก"
        accept="image/jpeg,image/png,image/webp"
        max-file-size="5MB"
        help="เลือกได้ 1 ภาพ · JPG, PNG หรือ WEBP ขนาดไม่เกิน 5 MB"
        :image-preview="true"
        :preview-url="$item->exists && $item->cover_image_path ? route('wms.items.cover-image', $item) : null"
        preview-alt="ภาพหน้าปกสินค้าปัจจุบัน"
    />
    @if ($item->exists && $item->cover_image_path)
        <div class="form-check mt-2">
            <input class="form-check-input" id="remove_cover_image" name="remove_cover_image" type="checkbox" value="1">
            <label class="form-check-label text-danger" for="remove_cover_image">ลบภาพหน้าปกปัจจุบัน</label>
        </div>
    @endif
</div>
<div class="col-12 col-lg-6">
    <x-platform::file-uploader
        name="additional_images"
        label="ภาพเพิ่มเติม"
        accept="image/jpeg,image/png,image/webp"
        max-file-size="5MB"
        :max-files="5"
        help="เลือกพร้อมกันได้หลายภาพ · ภาพเดิมและภาพใหม่รวมกันไม่เกิน 5 ภาพ"
        :image-preview="true"
        :multiple="true"
    />
</div>
@if ($item->exists && count($item->additional_images ?? []))
    <div class="col-12">
        <p class="form-label mb-2">ภาพเพิ่มเติมปัจจุบัน ({{ count($item->additional_images) }}/5)</p>
        <div class="item-image-gallery">
            @foreach ($item->additional_images as $index => $image)
                <label class="item-image-gallery__item" for="remove_additional_image_{{ $index }}">
                    <img src="{{ route('wms.items.additional-image', [$item, 'index' => $index]) }}" alt="ภาพเพิ่มเติม {{ $index + 1 }} ของ {{ $item->name }}">
                    <span class="form-check">
                        <input class="form-check-input" id="remove_additional_image_{{ $index }}" name="remove_additional_images[]" type="checkbox" value="{{ $index }}">
                        <span class="form-check-label text-danger">ลบภาพนี้</span>
                    </span>
                </label>
            @endforeach
        </div>
    </div>
@endif
</div><div class="p-4"><button class="btn btn-dark" type="submit">บันทึก</button></div></div></form></div>
@endsection
@push('scripts')<script>$(function(){$('.js-account').each(function(){var s=$(this);s.select2({width:'100%',theme:'bootstrap-5',ajax:{url:'{{route('wms.items.account-options')}}',delay:250,data:function(p){return{q:p.term||'',page:p.page||1,type:s.data('type')};},processResults:function(d){return d;}}})});$('.js-category,.js-uom,.js-asset-category').each(function(){var s=$(this);s.select2({width:'100%',theme:'bootstrap-5',placeholder:'ค้นหารหัสหรือชื่อ',allowClear:true,ajax:{url:s.data('url'),delay:250,data:function(p){return{q:p.term||'',page:p.page||1};},processResults:function(d){return d;}}})});function assetPolicy(){var enabled=$('#is_asset_capitalizable').is(':checked');$('#default_asset_category_id').prop('required',enabled).prop('disabled',!enabled);$('.js-asset-required').toggle(enabled);}$('#is_asset_capitalizable').on('change',assetPolicy);assetPolicy();window.erpAjaxForm({form:'#item-form',redirect:true})});</script>@endpush
