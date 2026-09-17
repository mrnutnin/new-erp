<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\ProductInterest;
use App\Modules\Crm\Services\ProductInterestCommercialService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductInterestController extends Controller
{
    public function data(Request $request,Opportunity $opportunity,ProductInterestCommercialService $commercialService):JsonResponse
    {
        $this->scope($request,$opportunity);$items=$opportunity->productInterests()->with(['item:id,code,name','uom:id,code,name'])->paginate(8);$commercial=$commercialService->forInterests($opportunity,$items->getCollection());
        return response()->json(['html'=>view('Crm::opportunities._product-interests',compact('items','opportunity','commercial'))->render(),'pagination'=>$items->hasPages()?$items->onEachSide(1)->links('pagination::bootstrap-5')->render():'','total'=>$items->total()]);
    }
    public function itemOptions(Request $request):JsonResponse
    {
        $q=trim((string)$request->input('q'));$rows=Item::query()->with('baseUom:id,code,name')->where('is_active',true)->when($q,fn(Builder $x)=>$x->where(fn(Builder $y)=>$y->where('code','like',"%{$q}%")->orWhere('name','like',"%{$q}%")))->orderBy('code')->forPage(max(1,$request->integer('page',1)),31)->get(['id','code','name','base_uom_id']);
        return response()->json(['results'=>$rows->take(30)->map(fn(Item $item)=>['id'=>$item->id,'text'=>$item->code.' · '.$item->name,'uom_id'=>$item->base_uom_id,'uom_label'=>trim(($item->baseUom?->code??'').' · '.($item->baseUom?->name??''),' ·')])->values(),'pagination'=>['more'=>$rows->count()>30]]);
    }
    public function store(Request $request,Opportunity $opportunity,AuditLogger $audit):JsonResponse{return $this->save($request,$opportunity,new ProductInterest,$audit);}
    public function update(Request $request,Opportunity $opportunity,ProductInterest $productInterest,AuditLogger $audit):JsonResponse{return $this->save($request,$opportunity,$productInterest,$audit);}
    public function destroy(Request $request,Opportunity $opportunity,ProductInterest $productInterest,AuditLogger $audit):JsonResponse
    {
        $this->editable($request,$opportunity);$this->interest($opportunity,$productInterest);DB::transaction(function()use($request,$productInterest,$audit){$row=ProductInterest::query()->lockForUpdate()->findOrFail($productInterest->id);$before=$row->toArray();$row->delete();$audit->record('crm.product-interest.deleted',$row,$before,[],$request->user(),$request);});return response()->json(['status'=>true,'msg'=>'ลบสินค้าที่สนใจแล้ว']);
    }
    private function save(Request $request,Opportunity $opportunity,ProductInterest $interest,AuditLogger $audit):JsonResponse
    {
        $this->editable($request,$opportunity);if($interest->exists)$this->interest($opportunity,$interest);$decimal=WmsDecimal::rule();$data=$request->validate(['item_id'=>['required','integer','exists:wms_items,id'],'uom_id'=>['required','integer','exists:wms_uoms,id'],'quantity'=>array_merge(['required'],$decimal,['gt:0']),'target_unit_price'=>array_merge(['nullable'],$decimal,['gte:0']),'budget_amount'=>array_merge(['nullable'],$decimal,['gte:0']),'requirements'=>['nullable','string','max:2000']]);$item=Item::query()->where('is_active',true)->find($data['item_id']);if(!$item||(int)$item->base_uom_id!==(int)$data['uom_id'])throw ValidationException::withMessages(['item_id'=>'สินค้าไม่พร้อมใช้งานหรือหน่วยไม่ตรงกับหน่วยหลัก']);$data['requirements']=trim((string)($data['requirements']??''))?:null;
        DB::transaction(function()use($request,$opportunity,$interest,$data,$audit){$before=$interest->exists?$interest->toArray():[];$interest->fill([...$data,'opportunity_id'=>$opportunity->id,'updated_by'=>$request->user()->id]);if(!$interest->exists)$interest->created_by=$request->user()->id;$interest->save();$audit->record($before?'crm.product-interest.updated':'crm.product-interest.created',$interest,$before,$interest->toArray(),$request->user(),$request);});return response()->json(['status'=>true,'msg'=>$interest->wasRecentlyCreated?'เพิ่มสินค้าที่สนใจแล้ว':'อัปเดตสินค้าที่สนใจแล้ว']);
    }
    private function editable(Request $request,Opportunity $opportunity):void{$this->scope($request,$opportunity);abort_if(in_array($opportunity->stage,['WON','LOST'],true),422,'Opportunity ที่ปิดแล้วแก้ไขสินค้าไม่ได้');}
    private function scope(Request $request,Opportunity $opportunity):void{abort_unless((int)$opportunity->branch_id===(int)$request->attributes->get('selectedBranch')->id,404);}
    private function interest(Opportunity $opportunity,ProductInterest $interest):void{abort_unless((int)$interest->opportunity_id===(int)$opportunity->id,404);}
}
