<?php

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OpportunityPosLifecycleService
{
    public function __construct(private readonly AuditLogger $audit,private readonly CrmNotificationService $notifications){}

    public function markWon(PhysicalSale $sale,User $actor,Request $request):?Opportunity
    {
        $opportunity=$this->opportunity($sale,true);if(!$opportunity||in_array($opportunity->stage,['WON','LOST'],true))return $opportunity;
        $before=$opportunity->toArray();$opportunity->update(['stage'=>'WON','probability'=>100,'won_at'=>now(),'lost_at'=>null,'lost_reason'=>null,'updated_by'=>$actor->id]);
        $this->audit->record('crm.opportunity.won-from-pos',$opportunity,$before,$opportunity->fresh()->toArray(),$actor,$request);
        $opportunityId=$opportunity->id;$saleId=$sale->id;DB::afterCommit(fn()=> $this->notifications->sendOpportunityWon($opportunityId,$saleId));
        return $opportunity;
    }

    public function notifyCancellation(PhysicalSale $sale):void
    {
        $opportunity=$this->opportunity($sale);if(!$opportunity)return;$opportunityId=$opportunity->id;$saleId=$sale->id;$revision=(int)$sale->reversal_revision;
        DB::afterCommit(fn()=> $this->notifications->sendOpportunitySaleCancelled($opportunityId,$saleId,$revision));
    }

    private function opportunity(PhysicalSale $sale,bool $lock=false):?Opportunity
    {
        if($sale->source_type!=='SALES_ORDER')return null;
        $order=SalesOrder::query()->with(['sourceIntake:id','rfq:id,source_sales_intake_id','quotation:id,source_sales_intake_id,sales_rfq_id','quotation.rfq:id,source_sales_intake_id'])->find($sale->source_id);
        $intakeId=$order?->source_sales_intake_id??$order?->rfq?->source_sales_intake_id??$order?->quotation?->source_sales_intake_id??$order?->quotation?->rfq?->source_sales_intake_id;
        if(!$intakeId)return null;
        return Opportunity::query()->where('branch_id',$sale->branch_id)->where('party_id',$sale->party_id)->where('sales_intake_id',$intakeId)->when($lock,fn($query)=>$query->lockForUpdate())->first();
    }
}
