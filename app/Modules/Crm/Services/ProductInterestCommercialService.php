<?php

namespace App\Modules\Crm\Services;

use App\Models\CompanySetting;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Pos\Services\PriceListResolver;
use App\Modules\Pos\Services\PromotionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductInterestCommercialService
{
    public function __construct(private readonly PriceListResolver $prices,private readonly PromotionResolver $promotions){}

    public function forInterests(Opportunity $opportunity,Collection $interests):array
    {
        if($interests->isEmpty())return [];$group=$this->customerGroupCode($opportunity);$date=now('Asia/Bangkok');$branchId=(int)$opportunity->branch_id;
        $stock=DB::table('wms_stock_balances as balance')->join('warehouses as warehouse','warehouse.id','=','balance.warehouse_id')->where('warehouse.branch_id',$branchId)->where('warehouse.is_active',true)->whereNull('warehouse.deleted_at')->whereIn('balance.item_id',$interests->pluck('item_id'))->selectRaw('balance.item_id,balance.uom_id,COALESCE(SUM(balance.available),0) available')->groupBy('balance.item_id','balance.uom_id')->get()->keyBy(fn($row)=>$row->item_id.':'.$row->uom_id);
        return $interests->mapWithKeys(function($interest)use($branchId,$group,$date,$stock){$quantity=(string)$interest->quantity;$price=$this->prices->resolve($branchId,(int)$interest->item_id,(int)$interest->uom_id,$group,$date,$quantity,'THB');$promotion=$this->promotions->resolve((int)$interest->item_id,(int)$interest->uom_id,$group,$date,$quantity,'THB');return [$interest->id=>['price'=>$price,'promotion'=>$promotion,'available'=>(string)($stock->get($interest->item_id.':'.$interest->uom_id)?->available??'0'),'as_of'=>$date->format('d/m/Y H:i')]];})->all();
    }

    private function customerGroupCode(Opportunity $opportunity):?string
    {
        if(!$opportunity->party_id)return null;$companyId=CompanySetting::query()->orderByDesc('id')->value('id');return $opportunity->party->customerGroups()->where('pos_customer_groups.company_setting_id',$companyId)->where('pos_customer_groups.is_active',true)->orderBy('pos_customer_groups.code')->value('pos_customer_groups.code');
    }
}
