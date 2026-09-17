<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CustomerAssignment;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\SalesTeam;
use App\Modules\Crm\Requests\TransferCustomerOwnershipRequest;
use App\Modules\Crm\Services\CrmNotificationService;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class OwnershipTransferController extends Controller
{
    private const OPEN_STAGES=['NEW','CONTACTED','QUALIFIED','PROPOSAL','NEGOTIATION'];

    public function index(Request $request):View
    {
        return view('Crm::ownership-transfers.index',['teams'=>SalesTeam::query()->where('branch_id',$this->branchId($request))->where('is_active',true)->orderBy('name')->get(['id','name'])]);
    }

    public function preview(TransferCustomerOwnershipRequest $request):JsonResponse
    {
        [$assignments,$opportunities,$activities]=$this->queries($request,$request->validated());
        return response()->json(['counts'=>['customers'=>(clone $assignments)->distinct('party_id')->count('party_id'),'opportunities'=>(clone $opportunities)->count(),'activities'=>(clone $activities)->count()],'customers'=>(clone $assignments)->join('parties','parties.id','=','crm_customer_assignments.party_id')->select('parties.id','parties.code','parties.name')->distinct()->orderBy('parties.name')->limit(10)->get()]);
    }

    public function store(TransferCustomerOwnershipRequest $request,AuditLogger $audit,CrmNotificationService $notifications):JsonResponse
    {
        $data=$request->validated();$branchId=$this->branchId($request);$result=DB::transaction(function()use($request,$audit,$data,$branchId){$users=User::withTrashed()->whereIn('id',[$data['source_user_id'],$data['target_user_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');$source=$users[(int)$data['source_user_id']]??null;$target=$users[(int)$data['target_user_id']]??null;if(!$source||!$target||$target->trashed()||!$target->is_active)throw ValidationException::withMessages(['target_user_id'=>'ผู้รับโอนต้องเป็นผู้ใช้งานที่เปิดใช้งาน']);[$assignments,$opportunities,$activities]=$this->queries($request,$data);$teamIds=(clone $assignments)->whereNotNull('team_id')->distinct()->pluck('team_id');if($teamIds->isNotEmpty()){SalesTeam::query()->whereIn('id',$teamIds)->orderBy('id')->lockForUpdate()->get();$memberTeamIds=DB::table('crm_sales_team_members')->where('user_id',$target->id)->whereIn('team_id',$teamIds)->pluck('team_id');if($memberTeamIds->count()!==$teamIds->count())throw ValidationException::withMessages(['target_user_id'=>'ผู้รับโอนต้องเป็นสมาชิกของทุกทีมที่ได้รับลูกค้า']);}$counts=['customers'=>(clone $assignments)->distinct('party_id')->count('party_id'),'opportunities'=>(clone $opportunities)->count(),'activities'=>(clone $activities)->count()];$fingerprint=hash('sha256',implode(':',[$source->id,$target->id,$branchId,$data['scope'],...array_values($counts),(clone $assignments)->max('updated_at'),(clone $opportunities)->max('updated_at'),(clone $activities)->max('updated_at')]));if($counts['customers'])(clone $assignments)->update(['owner_id'=>DB::raw('CASE WHEN owner_id = '.(int)$source->id.' THEN '.(int)$target->id.' ELSE owner_id END'),'backup_owner_id'=>DB::raw('CASE WHEN owner_id = '.(int)$source->id.' AND backup_owner_id = '.(int)$target->id.' THEN NULL WHEN backup_owner_id = '.(int)$source->id.' AND owner_id = '.(int)$target->id.' THEN NULL WHEN backup_owner_id = '.(int)$source->id.' THEN '.(int)$target->id.' ELSE backup_owner_id END'),'updated_by'=>$request->user()->id,'updated_at'=>now()]);if($counts['opportunities'])(clone $opportunities)->update(['owner_id'=>$target->id,'updated_by'=>$request->user()->id,'updated_at'=>now()]);if($counts['activities'])(clone $activities)->update(['assigned_to'=>$target->id,'updated_at'=>now()]);$audit->record('crm.customer.ownership-transferred',$source,[],['target_user_id'=>$target->id,'branch_id'=>$branchId,'scope'=>$data['scope'],'team_id'=>$data['team_id']??null,'customer_ids'=>$data['scope']==='SELECTED'?$data['customer_ids']:null,'reason'=>$data['reason'],'counts'=>$counts],$request->user(),$request);return compact('source','target','counts','fingerprint');});
        if(array_sum($result['counts'])>0)DB::afterCommit(fn()=>$notifications->sendOwnershipTransfer($result['target']->id,$branchId,$result['source']->name,$result['counts'],$result['fingerprint']));
        return response()->json(['status'=>true,'msg'=>'โอนผู้ดูแลและงานเรียบร้อยแล้ว','counts'=>$result['counts']]);
    }

    public function userOptions(Request $request):JsonResponse
    {
        $search=trim((string)$request->input('q'));$rows=User::withTrashed()->whereHas('branches',fn(Builder $query)=>$query->where('branches.id',$this->branchId($request)))->when($request->boolean('active_only'),fn(Builder $query)=>$query->where('is_active',true)->whereNull('deleted_at'))->when($search,fn(Builder $query)=>$query->where(fn(Builder $query)=>$query->where('name','like',"%{$search}%")->orWhere('employee_code','like',"%{$search}%")->orWhere('username','like',"%{$search}%")))->orderByDesc('is_active')->orderBy('name')->forPage(max(1,$request->integer('page',1)),31)->get(['id','name','employee_code','position','is_active']);return response()->json(['results'=>$rows->take(30)->map(fn(User $user)=>['id'=>$user->id,'text'=>($user->employee_code?$user->employee_code.' · ':'').$user->name.($user->is_active&&!$user->trashed()?'':' · ปิดใช้งาน')])->values(),'pagination'=>['more'=>$rows->count()>30]]);
    }

    public function customerOptions(Request $request):JsonResponse
    {
        $sourceId=$request->validate(['source_user_id'=>['required','integer'],'q'=>['nullable','string','max:100']])['source_user_id'];$search=trim((string)$request->input('q'));$rows=Party::query()->whereHas('customerRole')->whereHas('customerAssignments',fn(Builder $query)=>$query->where('branch_id',$this->branchId($request))->where(fn(Builder $query)=>$query->where('owner_id',$sourceId)->orWhere('backup_owner_id',$sourceId)))->when($search,fn(Builder $query)=>$query->where(fn(Builder $query)=>$query->where('code','like',"%{$search}%")->orWhere('name','like',"%{$search}%")))->orderBy('name')->forPage(max(1,$request->integer('page',1)),31)->get(['id','code','name']);return response()->json(['results'=>$rows->take(30)->map(fn(Party $party)=>['id'=>$party->id,'text'=>$party->code.' · '.$party->name])->values(),'pagination'=>['more'=>$rows->count()>30]]);
    }

    private function queries(Request $request,array $data):array
    {
        $branchId=$this->branchId($request);$sourceId=(int)$data['source_user_id'];$assignments=CustomerAssignment::query()->where('branch_id',$branchId)->where(fn(Builder $query)=>$query->where('owner_id',$sourceId)->orWhere('backup_owner_id',$sourceId));if($data['scope']==='TEAM')$assignments->where('team_id',$data['team_id']);if($data['scope']==='SELECTED')$assignments->whereIn('party_id',$data['customer_ids']);$partyIds=(clone $assignments)->select('party_id');$opportunities=Opportunity::query()->where('branch_id',$branchId)->where('owner_id',$sourceId)->whereIn('stage',self::OPEN_STAGES);if($data['scope']!=='ALL')$opportunities->whereIn('party_id',clone $partyIds);$activities=Activity::query()->where('assigned_to',$sourceId)->whereNull('completed_at')->whereHas('opportunity',fn(Builder $query)=>$query->where('branch_id',$branchId)->when($data['scope']!=='ALL',fn(Builder $query)=>$query->whereIn('party_id',clone $partyIds)));return [$assignments,$opportunities,$activities];
    }
    private function branchId(Request $request):int{return (int)$request->attributes->get('selectedBranch')->id;}
}
