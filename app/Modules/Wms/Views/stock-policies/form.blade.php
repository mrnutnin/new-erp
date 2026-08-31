@extends('Wms::layout')
@section('title','ตั้งค่า Min/Max Stock | WMS')
@section('content')
<div class="container-fluid px-3 px-lg-4 py-4">
    <p class="eyebrow mb-2">WMS / POLICY</p>
    <h1 class="h3 mb-1">{{$policy->exists?'แก้ไข':'เพิ่ม'}}นโยบาย Min/Max Stock</h1>
    <p class="text-secondary mb-4">คลังที่เลือก: {{$warehouse->code}} · {{$warehouse->name}}</p>
    <form id="stock-policy-form" method="POST" action="{{$policy->exists?route('wms.stock-policies.update',$policy):route('wms.stock-policies.store')}}">
        @csrf @if($policy->exists) @method('PUT') @endif
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 row g-3">
                <div class="col-12">
                    <label class="form-label">สินค้า (ถ้าไม่เลือก = ค่าเริ่มต้นทั้งคลัง)</label>
                    <select class="form-select js-stock-policy-item" name="item_id" data-url="{{route('wms.stock-policies.item-options')}}" data-placeholder="ค้นหาสินค้า">
                        <option value="">ค้นหาสินค้า</option>
                        @if($policy->item)<option value="{{$policy->item_id}}" selected>{{$policy->item->code}} · {{$policy->item->name}}</option>@endif
                    </select>
                    <div class="form-text">นโยบายผูกกับสินค้าและคลังที่เลือก เพื่อให้การแจ้งเตือนตรงกับ Stock จริง</div>
                    <div class="invalid-feedback" data-error-for="item_id"></div>
                </div>
                @php($quantityStep = 1 / (10 ** \App\Modules\Wms\Support\WmsDecimal::places()))
                <div class="col-md-4"><label class="form-label">ปริมาณขั้นต่ำ (Min)</label><input class="form-control" name="min_quantity" type="number" min="0" step="{{$quantityStep}}" value="{{old('min_quantity',$policy->min_quantity ?? 0)}}" required><div class="invalid-feedback" data-error-for="min_quantity"></div></div>
                <div class="col-md-4"><label class="form-label">ปริมาณสูงสุด (Max)</label><input class="form-control" name="max_quantity" type="number" min="0" step="{{$quantityStep}}" value="{{old('max_quantity',$policy->max_quantity ?? 0)}}" required><div class="invalid-feedback" data-error-for="max_quantity"></div></div>
                <div class="col-md-4"><label class="form-label">จำนวนที่เติมเมื่อถึง Min</label><input class="form-control" name="reorder_quantity" type="number" min="0" step="{{$quantityStep}}" value="{{old('reorder_quantity',$policy->reorder_quantity ?? 0)}}" required><div class="invalid-feedback" data-error-for="reorder_quantity"></div></div>
                <div class="col-12 form-check"><input class="form-check-input" name="is_active" type="checkbox" value="1" @checked(old('is_active',$policy->is_active ?? true))><label class="form-check-label">เปิดใช้งานนโยบายนี้</label></div>
            </div>
            <div class="p-4"><button class="btn btn-dark" type="submit">บันทึก</button> <a class="btn btn-outline-secondary" href="{{route('wms.stock-policies.index')}}">ยกเลิก</a></div>
        </div>
    </form>
</div>
@endsection
@push('scripts')
<script>
$(function(){
    var item = $('.js-stock-policy-item');
    item.select2({theme:'bootstrap-5', width:'100%', allowClear:true, placeholder:item.data('placeholder'), ajax:{url:item.data('url'), dataType:'json', delay:250, data:function(params){return {q:params.term || '', page:params.page || 1};}, processResults:function(data, params){params.page=params.page || 1; return data;}}});
    window.erpAjaxForm({form:'#stock-policy-form', redirect:true});
});
</script>
@endpush
