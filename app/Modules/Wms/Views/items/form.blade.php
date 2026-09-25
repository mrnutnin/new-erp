@extends('Wms::layout')
@section('title', 'สินค้า | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <header class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-1">WMS / MASTER DATA</p>
            <h1 class="h3 mb-1">{{ $item->exists ? 'แก้ไขสินค้า' : 'เพิ่มสินค้า' }}</h1>
            <p class="text-secondary mb-0">กำหนดข้อมูลสินค้า การติดตามสต็อก บัญชี และรูปภาพ</p>
        </div>
        <a class="btn btn-app-soft" href="{{ route('wms.items.index') }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับหน้ารายการ</a>
    </header>

    <form id="item-form" method="POST" enctype="multipart/form-data" action="{{ $item->exists ? route('wms.items.update', $item) : route('wms.items.store') }}">
        @csrf
        @if($item->exists) @method('PUT') @endif

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3"><h2 class="h5 mb-1">ข้อมูลสินค้า</h2><p class="small text-secondary mb-0">รหัส ชื่อ หมวดหมู่ และหน่วยนับหลัก</p></div>
                <div class="row g-3">
                    <div class="col-12 col-md-4"><label class="form-label" for="item-code">รหัส <span class="text-danger">*</span></label><input id="item-code" class="form-control" name="code" value="{{ old('code', $item->code) }}" required>@error('code')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
                    <div class="col-12 col-md-8"><label class="form-label" for="item-name">ชื่อสินค้า <span class="text-danger">*</span></label><input id="item-name" class="form-control" name="name" value="{{ old('name', $item->name) }}" required>@error('name')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
                    <div class="col-12 col-md-6"><label class="form-label" for="item-category">หมวดสินค้า <span class="text-danger">*</span></label><select id="item-category" class="form-select js-category" name="category_id" data-url="{{ route('wms.items.category-options') }}" required>@if($selectedCategory)<option value="{{ $selectedCategory->id }}" selected>{{ $selectedCategory->code }} · {{ $selectedCategory->name }}</option>@endif</select>@error('category_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
                    <div class="col-12 col-md-3"><label class="form-label" for="item-type">ประเภท <span class="text-danger">*</span></label><select id="item-type" class="form-select" name="item_type" required><option value="GOODS" @selected(old('item_type', $item->item_type) === 'GOODS')>สินค้า</option><option value="SERVICE" @selected(old('item_type', $item->item_type) === 'SERVICE')>บริการ</option></select>@error('item_type')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
                    <div class="col-12 col-md-3"><label class="form-label" for="item-uom">หน่วยหลัก <span class="text-danger">*</span></label><select id="item-uom" class="form-select js-uom" name="base_uom_id" data-url="{{ route('wms.items.uom-options') }}" required>@if($selectedUom)<option value="{{ $selectedUom->id }}" selected>{{ $selectedUom->code }} · {{ $selectedUom->name }}</option>@endif</select><input type="hidden" name="base_uom" value="{{ old('base_uom', $item->base_uom ?: 'EA') }}">@error('base_uom_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
                </div>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3"><h2 class="h5 mb-1">การใช้งานและสต็อก</h2><p class="small text-secondary mb-0">เลือกคุณสมบัติที่เกี่ยวข้องกับสินค้า</p></div>
                <div class="row g-3">
                    <div class="col-12 col-md-4"><div class="form-check border rounded p-3 h-100"><input class="form-check-input" id="is_stock_item" type="checkbox" name="is_stock_item" value="1" @checked(old('is_stock_item', $item->is_stock_item))><label class="form-check-label fw-semibold" for="is_stock_item">ติดตามสต็อก</label><div class="form-text">ใช้กับสินค้าที่มีการรับเข้า เบิก หรือขายจากคลัง</div></div></div>
                    <div class="col-12 col-md-4"><div class="form-check border rounded p-3 h-100"><input class="form-check-input" id="is_asset_capitalizable" type="checkbox" name="is_asset_capitalizable" value="1" @checked(old('is_asset_capitalizable', $item->is_asset_capitalizable))><label class="form-check-label fw-semibold" for="is_asset_capitalizable">รับรู้เป็นสินทรัพย์ได้</label><div class="form-text">ใช้คัดกรองรายการที่นำไปรับรู้เป็นสินทรัพย์</div></div></div>
                    <div class="col-12 col-md-4"><div class="form-check border rounded p-3 h-100"><input class="form-check-input" id="is_active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active ?? true))><label class="form-check-label fw-semibold" for="is_active">เปิดใช้งาน</label><div class="form-text">ปิดใช้งานเพื่อไม่นำไปเลือกในเอกสารใหม่</div></div></div>
                    @if($productionEnabled)
                    <div class="col-12 col-lg-6">
                        <div class="border rounded p-3 h-100">
                            <div class="form-check"><input class="form-check-input" id="can_manufacture" type="checkbox" name="can_manufacture" value="1" @checked(old('can_manufacture', $item->can_manufacture ?? false))><label class="form-check-label fw-semibold" for="can_manufacture">สั่งผลิตได้</label></div>
                            <div class="form-text">เฉพาะสินค้าที่ติดตามสต็อก เปิดให้สร้างใบสั่งผลิตจากใบสั่งขายหลังยืนยัน โดยไม่สร้างอัตโนมัติ</div>
                            <div class="text-danger small" data-error-for="can_manufacture" role="alert">@error('can_manufacture'){{ $message }}@enderror</div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="border rounded p-3 h-100">
                            <div class="form-check"><input class="form-check-input" id="can_receive_production_scrap" type="checkbox" name="can_receive_production_scrap" value="1" @checked(old('can_receive_production_scrap', $item->can_receive_production_scrap ?? false))><label class="form-check-label fw-semibold" for="can_receive_production_scrap">รับเป็นสินค้าเศษผลิตได้</label></div>
                            <div class="form-text">ใช้เลือกสินค้า GOODS สำหรับรับเศษผลิตเข้าคลัง</div>
                            <div class="text-danger small" data-error-for="can_receive_production_scrap" role="alert">@error('can_receive_production_scrap'){{ $message }}@enderror</div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3"><h2 class="h5 mb-1">การกำหนดบัญชี</h2><p class="small text-secondary mb-0">ระบุบัญชีที่ใช้บันทึกรายการของสินค้า</p></div>
                <div class="row g-3">
                    @foreach([['inventory_account_id', 'บัญชีสินค้าคงเหลือ', 'ASSET', false], ['sales_account_id', 'บัญชีรายได้', 'REVENUE', true], ['cogs_account_id', 'บัญชีต้นทุน', 'COGS', false]] as [$field, $label, $type, $required])
                        <div class="col-12 col-md-4"><label class="form-label" for="{{ $field }}">{{ $label }} @if($required)<span class="text-danger">*</span>@endif</label><select id="{{ $field }}" class="form-select js-account" name="{{ $field }}" data-type="{{ $type }}" @required($required)><option value="">{{ $required ? 'เลือกบัญชี' : 'ไม่กำหนด' }}</option>@if($a = $accounts->get($item->{$field}))<option value="{{ $a->id }}" selected>{{ $a->code }} · {{ $a->name }}</option>@endif</select>@error($field)<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
                    @endforeach
                    <div class="col-12 col-md-6"><label class="form-label" for="default_asset_category_id">หมวดสินทรัพย์เริ่มต้น <span class="text-danger js-asset-required">*</span></label><select class="form-select js-asset-category" id="default_asset_category_id" name="default_asset_category_id" data-url="{{ route('wms.items.asset-category-options') }}"><option value="">ไม่กำหนด</option>@if($selectedAssetCategory)<option value="{{ $selectedAssetCategory->id }}" selected>{{ $selectedAssetCategory->code }} · {{ $selectedAssetCategory->name }}</option>@endif</select><div class="form-text">ใช้คัดกรองบรรทัด Purchase Invoice ที่เลือกมารับรู้สินทรัพย์</div>@error('default_asset_category_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
                </div>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3"><h2 class="h5 mb-1">รูปภาพสินค้า</h2><p class="small text-secondary mb-0">กำหนดภาพหน้าปก 1 ภาพ และภาพประกอบรวมไม่เกิน 5 ภาพ</p></div>
                <div class="row g-4">
                    <div class="col-12 col-lg-6">
                        <x-platform::file-uploader name="cover_image" label="ภาพหน้าปก" accept="image/jpeg,image/png,image/webp" :multiple="false" :max-files="1" max-file-size="5MB" help="เลือกได้ 1 ภาพ · JPG, PNG หรือ WEBP ขนาดไม่เกิน 5 MB" :image-preview="true" :preview-url="$item->exists && $item->cover_image_path ? route('wms.items.cover-image', $item) : null" preview-alt="ภาพหน้าปกสินค้าปัจจุบัน" />
                        @if ($item->exists && $item->cover_image_path)
                            <div class="form-check mt-2"><input class="form-check-input" id="remove_cover_image" name="remove_cover_image" type="checkbox" value="1"><label class="form-check-label text-danger" for="remove_cover_image">ลบภาพหน้าปกปัจจุบัน</label></div>
                        @endif
                    </div>
                    <div class="col-12 col-lg-6">
                        <x-platform::file-uploader name="additional_images" label="ภาพเพิ่มเติม" accept="image/jpeg,image/png,image/webp" max-file-size="5MB" :max-files="5" help="เลือกได้หลายภาพ · ภาพเดิมและภาพใหม่รวมกันไม่เกิน 5 ภาพ" :image-preview="true" :multiple="true" />
                    </div>
                    @if ($item->exists && count($item->additional_images ?? []))
                        <div class="col-12">
                            <p class="form-label mb-2">ภาพเพิ่มเติมปัจจุบัน ({{ count($item->additional_images) }}/5)</p>
                            <div class="item-image-gallery">
                                @foreach ($item->additional_images as $index => $image)
                                    <label class="item-image-gallery__item" for="remove_additional_image_{{ $index }}">
                                        <img src="{{ route('wms.items.additional-image', [$item, 'index' => $index]) }}" alt="ภาพเพิ่มเติม {{ $index + 1 }} ของ {{ $item->name }}">
                                        <span class="form-check"><input class="form-check-input" id="remove_additional_image_{{ $index }}" name="remove_additional_images[]" type="checkbox" value="{{ $index }}"><span class="form-check-label text-danger">ลบภาพนี้</span></span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <div class="d-flex flex-wrap justify-content-end gap-2 pb-3">
            <a class="btn btn-outline-secondary" href="{{ route('wms.items.index') }}">ยกเลิก</a>
            <button class="btn btn-app-primary" type="submit"><i class="bx bx-save me-1" aria-hidden="true"></i>{{ $item->exists ? 'บันทึกการแก้ไข' : 'บันทึกสินค้า' }}</button>
        </div>
    </form>
</div>
@endsection
@push('scripts')
<script>
$(function () {
    $('.js-account').each(function () {
        var select = $(this);
        select.select2({
            width: '100%', theme: 'bootstrap-5',
            ajax: {
                url: '{{ route('wms.items.account-options') }}', delay: 250,
                data: function (params) { return { q: params.term || '', page: params.page || 1, type: select.data('type') }; },
                processResults: function (data) { return data; }
            }
        });
    });
    $('.js-category,.js-uom,.js-asset-category').each(function () {
        var select = $(this);
        select.select2({
            width: '100%', theme: 'bootstrap-5', placeholder: 'ค้นหารหัสหรือชื่อ', allowClear: true,
            ajax: {
                url: select.data('url'), delay: 250,
                data: function (params) { return { q: params.term || '', page: params.page || 1 }; },
                processResults: function (data) { return data; }
            }
        });
    });
    function assetPolicy() {
        var enabled = $('#is_asset_capitalizable').is(':checked');
        $('#default_asset_category_id').prop('required', enabled).prop('disabled', !enabled);
        $('.js-asset-required').toggle(enabled);
    }
    $('#is_asset_capitalizable').on('change', assetPolicy);
    assetPolicy();
    window.erpAjaxForm({ form: '#item-form', redirect: true });
});
</script>
@endpush
