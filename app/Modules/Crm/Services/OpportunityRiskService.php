<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;

final class OpportunityRiskService
{
    private const OPEN_STAGES=['NEW','CONTACTED','QUALIFIED','PROPOSAL','NEGOTIATION'];

    public function query(?int $branchId=null,?array $ownerIds=null):Builder
    {
        $cutoff=now()->subDays($this->staleDays());
        return Opportunity::query()->whereIn('crm_opportunities.stage',self::OPEN_STAGES)->when($branchId,fn(Builder $query)=>$query->where('crm_opportunities.branch_id',$branchId))->when($ownerIds!==null,fn(Builder $query)=>$query->whereIn('crm_opportunities.owner_id',$ownerIds))->where(fn(Builder $query)=>$query->whereNull('crm_opportunities.next_action_at')->orWhere('crm_opportunities.next_action_at','<',now())->orWhereDate('crm_opportunities.expected_close_date','<',today())->orWhere('crm_opportunities.updated_at','<',$cutoff));
    }

    public function reasons(Opportunity $opportunity):array
    {
        $reasons=[];if(!$opportunity->next_action_at)$reasons[]='ไม่มี Next action';elseif($opportunity->next_action_at->isPast())$reasons[]='Next action เกินกำหนด';if($opportunity->expected_close_date?->isPast())$reasons[]='เลยวันที่คาดปิด';if($opportunity->updated_at->lt(now()->subDays($this->staleDays())))$reasons[]='ไม่มีความเคลื่อนไหว '.$this->staleDays().' วัน';return $reasons;
    }

    public function staleDays():int{return max(1,(int)config('erp.crm.stale_opportunity_days',14));}
}
