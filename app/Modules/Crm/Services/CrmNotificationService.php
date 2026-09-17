<?php

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Notifications\CrmWorkNotification;
use App\Modules\Pos\Models\PhysicalSale;
use Illuminate\Support\Facades\DB;

final class CrmNotificationService
{
    public function __construct(private readonly OpportunityRiskService $risk){}
    public function sendOpportunityWon(int $opportunityId,int $saleId):int
    {
        $opportunity=Opportunity::query()->with('owner:id,name,is_active')->find($opportunityId);$sale=PhysicalSale::query()->find($saleId);
        if(!$opportunity?->owner?->is_active||!$sale)return 0;
        return $this->deliver("crm:won:sale:{$saleId}",$opportunity->owner,'OPPORTUNITY_WON',null,$opportunity->branch_id,['kind'=>'OPPORTUNITY_WON','title'=>'ปิดการขายสำเร็จ','message'=>$opportunity->title.' · '.$sale->document_number.' ลงบัญชีแล้ว','url'=>route('crm.opportunities.show',$opportunity),'branch_id'=>$opportunity->branch_id]);
    }

    public function sendOpportunitySaleCancelled(int $opportunityId,int $saleId,int $revision):int
    {
        $opportunity=Opportunity::query()->with('owner:id,name,is_active')->find($opportunityId);$sale=PhysicalSale::query()->find($saleId);
        if(!$opportunity?->owner?->is_active||!$sale)return 0;
        return $this->deliver("crm:sale-cancelled:{$saleId}:{$revision}",$opportunity->owner,'SALE_CANCELLED',null,$opportunity->branch_id,['kind'=>'SALE_CANCELLED','title'=>'เอกสารขายถูกยกเลิก','message'=>$sale->document_number.' ถูกยกเลิก กรุณาตรวจสอบ Opportunity '.$opportunity->title,'url'=>route('crm.opportunities.show',$opportunity),'branch_id'=>$opportunity->branch_id]);
    }

    public function sendOwnershipTransfer(int $targetUserId,int $branchId,string $sourceName,array $counts,string $fingerprint):int
    {
        $target=User::query()->where('is_active',true)->find($targetUserId);if(!$target)return 0;
        return $this->deliver('crm:ownership-transfer:'.$fingerprint,$target,'OWNERSHIP_TRANSFER',null,$branchId,['kind'=>'OWNERSHIP_TRANSFER','title'=>'ได้รับโอนลูกค้าและงาน CRM','message'=>'รับโอนจาก '.$sourceName.' · ลูกค้า '.$counts['customers'].' · Opportunity '.$counts['opportunities'].' · กิจกรรม '.$counts['activities'],'url'=>route('crm.my-work.index'),'branch_id'=>$branchId]);
    }

    public function sendRiskAlerts():int
    {
        $cutoff=now()->subDays($this->risk->staleDays());$rows=$this->risk->query()->join('users','users.id','=','crm_opportunities.owner_id')->where('users.is_active',true)->whereNull('users.deleted_at')->selectRaw('crm_opportunities.owner_id, crm_opportunities.branch_id, COUNT(*) risk_count, SUM(crm_opportunities.next_action_at IS NULL) no_action_count, SUM(crm_opportunities.next_action_at < ?) overdue_action_count, SUM(crm_opportunities.expected_close_date < ?) delayed_count, SUM(crm_opportunities.updated_at < ?) stale_count',[now(),today()->toDateString(),$cutoff])->groupBy('crm_opportunities.owner_id','crm_opportunities.branch_id')->get();$users=User::query()->whereIn('id',$rows->pluck('owner_id'))->get()->keyBy('id');$sent=0;foreach($rows as $row){$user=$users->get($row->owner_id);if(!$user)continue;$message='เสี่ยง '.(int)$row->risk_count.' ดีล · ไม่มี Next action '.(int)$row->no_action_count.' · เกินกำหนด '.(int)$row->overdue_action_count.' · เลื่อนปิด '.(int)$row->delayed_count.' · ไม่เคลื่อนไหว '.(int)$row->stale_count;$sent+=$this->deliver('crm:risk:'.today()->toDateString().':'.$row->branch_id.':'.$row->owner_id,$user,'OPPORTUNITY_RISK',null,(int)$row->branch_id,['kind'=>'OPPORTUNITY_RISK','title'=>'Opportunity ต้องตรวจสอบ','message'=>$message,'url'=>route('crm.forecast.index',['owner_id'=>$row->owner_id]),'branch_id'=>(int)$row->branch_id]);}return $sent;
    }

    public function sendDueReminders(?int $minutes = null): int
    {
        $now=now();$window=$minutes??1440;$default=max(1,(int)config('erp.crm.reminder_minutes',30));$sent=0;
        Activity::query()->with(['assignee:id,name,is_active','opportunity:id,branch_id,title'])->whereNull('completed_at')->whereNotNull('due_at')->whereBetween('due_at',[$now,$now->copy()->addMinutes($window)])->orderBy('id')->chunkById(100,function($activities)use(&$sent,$minutes,$default,$now){$preferences=DB::table('crm_notification_preferences')->whereIn('user_id',$activities->pluck('assigned_to')->filter()->unique())->get()->keyBy('user_id');foreach($activities as $activity){if(!$activity->assignee?->is_active||!$activity->opportunity)continue;$preference=$preferences->get($activity->assigned_to);if($minutes===null&&$preference&&!(bool)$preference->reminder_enabled)continue;$lead=$minutes??(int)($preference->reminder_minutes??$default);if($activity->due_at->gt($now->copy()->addMinutes($lead)))continue;$key='crm:due:'.$activity->id.':'.$activity->due_at->timestamp;$sent+=$this->deliver($key,$activity->assignee,'DUE_REMINDER',$activity->id,$activity->opportunity->branch_id,['kind'=>'DUE_REMINDER','title'=>'งานใกล้ครบกำหนด','message'=>$activity->subject.' · '.$activity->due_at->format('d/m/Y H:i'),'url'=>route('crm.opportunities.show',$activity->opportunity_id).'#activity-form','branch_id'=>$activity->opportunity->branch_id]);}});
        return $sent;
    }

    public function sendDailyDigests(): int
    {
        $rows=DB::table('crm_activities as activity')->join('crm_opportunities as opportunity','opportunity.id','=','activity.opportunity_id')->join('users','users.id','=','activity.assigned_to')->whereNull('opportunity.deleted_at')->whereNull('activity.completed_at')->where('users.is_active',true)
            ->where(fn($query)=>$query->where('activity.due_at','<',now())->orWhereDate('activity.due_at',today()))
            ->selectRaw('activity.assigned_to user_id, opportunity.branch_id, SUM(CASE WHEN activity.due_at < ? THEN 1 ELSE 0 END) overdue_count, SUM(CASE WHEN DATE(activity.due_at) = ? THEN 1 ELSE 0 END) today_count',[now(),today()->toDateString()])->groupBy('activity.assigned_to','opportunity.branch_id')->get();
        $users=User::query()->whereIn('id',$rows->pluck('user_id'))->get()->keyBy('id');$sent=0;
        foreach($rows as $row){$user=$users->get($row->user_id);if(!$user)continue;$key='crm:digest:'.today()->toDateString().':'.$row->branch_id.':'.$row->user_id;$sent+=$this->deliver($key,$user,'DAILY_DIGEST',null,(int)$row->branch_id,['kind'=>'DAILY_DIGEST','title'=>'สรุปงาน CRM ประจำวัน','message'=>'วันนี้ '.(int)$row->today_count.' งาน · เกินกำหนด '.(int)$row->overdue_count.' งาน','url'=>route('crm.my-work.index',['view'=>'TODAY']),'branch_id'=>(int)$row->branch_id]);}
        $teams=DB::table('crm_sales_teams as team')->join('crm_sales_team_members as member','member.team_id','=','team.id')->join('crm_activities as activity','activity.assigned_to','=','member.user_id')->join('crm_opportunities as opportunity','opportunity.id','=','activity.opportunity_id')->join('users as manager','manager.id','=','team.manager_id')->whereNull('team.deleted_at')->where('team.is_active',true)->where('manager.is_active',true)->whereNull('opportunity.deleted_at')->whereNull('activity.completed_at')->whereColumn('opportunity.branch_id','team.branch_id')->where(fn($query)=>$query->where('activity.due_at','<',now())->orWhereDate('activity.due_at',today()))->selectRaw('team.id team_id, team.name team_name, team.manager_id, team.branch_id, SUM(CASE WHEN activity.due_at < ? THEN 1 ELSE 0 END) overdue_count, SUM(CASE WHEN DATE(activity.due_at) = ? THEN 1 ELSE 0 END) today_count',[now(),today()->toDateString()])->groupBy('team.id','team.name','team.manager_id','team.branch_id')->get();
        $managers=User::query()->whereIn('id',$teams->pluck('manager_id'))->get()->keyBy('id');foreach($teams as $team){$manager=$managers->get($team->manager_id);if(!$manager)continue;$key='crm:team-digest:'.today()->toDateString().':'.$team->team_id;$sent+=$this->deliver($key,$manager,'TEAM_DAILY_DIGEST',null,(int)$team->branch_id,['kind'=>'TEAM_DAILY_DIGEST','title'=>'สรุปงานทีม '.$team->team_name,'message'=>'วันนี้ '.(int)$team->today_count.' งาน · เกินกำหนด '.(int)$team->overdue_count.' งาน','url'=>route('crm.team-work.index',['team_id'=>$team->team_id]),'branch_id'=>(int)$team->branch_id]);}
        return $sent;
    }

    private function deliver(string $key,User $user,string $kind,?int $activityId,?int $branchId,array $payload):int
    {
        $inserted=DB::table('crm_notification_deliveries')->insertOrIgnore(['idempotency_key'=>$key,'user_id'=>$user->id,'kind'=>$kind,'activity_id'=>$activityId,'branch_id'=>$branchId,'created_at'=>now()]);
        if(!$inserted)return 0;
        try{$user->notify(new CrmWorkNotification($payload));}catch(\Throwable $exception){DB::table('crm_notification_deliveries')->where('idempotency_key',$key)->delete();throw $exception;}
        return 1;
    }
}
