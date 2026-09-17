<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Crm\Models\SalesTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class KanbanController extends Controller
{
    public function index(Request $request): View
    {
        $filters=$this->filters($request,false);
        return view('Crm::opportunities.kanban',['stages'=>Opportunity::STAGES,'filters'=>$filters,'selectedOwner'=>$filters['owner_id']?User::find($filters['owner_id']):null,'teams'=>SalesTeam::query()->where('branch_id',$this->branchId($request))->where('is_active',true)->orderBy('name')->get(['id','name'])]);
    }

    public function data(Request $request): JsonResponse
    {
        $filters=$this->filters($request,true);$stage=$filters['stage'];
        $items=Opportunity::query()->with(['party:id,code,name','owner:id,name','salesIntake:id,document_number'])->where('branch_id',$this->branchId($request))->where('stage',$stage)
            ->when($filters['owner_id'],fn(Builder $q,int $id)=>$q->where('owner_id',$id))
            ->when($filters['team_id'],function(Builder $q,int $id)use($request){$team=SalesTeam::query()->where('branch_id',$this->branchId($request))->where('is_active',true)->findOrFail($id);$q->whereIn('owner_id',$team->members()->select('users.id'));})
            ->when($filters['value_min']!==null,fn(Builder $q,$value)=>$q->where('expected_value','>=',$value))->when($filters['value_max']!==null,fn(Builder $q,$value)=>$q->where('expected_value','<=',$value))
            ->when($filters['close_month'],function(Builder $q,string $month){[$year,$number]=explode('-',$month);$q->whereYear('expected_close_date',$year)->whereMonth('expected_close_date',$number);})
            ->when($filters['q'],function(Builder $q,string $search){$q->where(fn(Builder $q)=>$q->where('title','like',"%{$search}%")->orWhere('contact_name','like',"%{$search}%")->orWhereHas('party',fn(Builder $party)=>$party->where('code','like',"%{$search}%")->orWhere('name','like',"%{$search}%"))->orWhereHas('owner',fn(Builder $owner)=>$owner->where('name','like',"%{$search}%")));})
            ->orderByRaw('next_action_at IS NULL')->orderBy('next_action_at')->orderByDesc('id')->paginate(10);
        $canUpdate=$request->user()->hasPermission('crm.opportunities.update');
        return response()->json(['html'=>view('Crm::opportunities._kanban-cards',compact('items','canUpdate'))->render(),'total'=>$items->total(),'from'=>$items->firstItem(),'to'=>$items->lastItem(),'next_page'=>$items->hasMorePages()?$items->currentPage()+1:null]);
    }

    public function transition(Request $request, Opportunity $opportunity, AuditLogger $audit): JsonResponse
    {
        abort_unless((int)$opportunity->branch_id===$this->branchId($request),404);
        $data=$request->validate(['stage'=>['required',Rule::in(array_keys(Opportunity::STAGES))],'lost_reason'=>[Rule::requiredIf(fn()=> $request->input('stage')==='LOST'),'nullable','string','min:5','max:1000'],'won_value'=>[Rule::requiredIf(fn()=> $request->input('stage')==='WON'),'nullable','numeric','gt:0','decimal:0,2'],'won_date'=>[Rule::requiredIf(fn()=> $request->input('stage')==='WON'),'nullable','date_format:Y-m-d','before_or_equal:today']]);
        DB::transaction(function()use($request,$opportunity,$data,$audit):void{$opportunity=Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);abort_unless((int)$opportunity->branch_id===$this->branchId($request),404);if(in_array($opportunity->stage,['WON','LOST'],true))throw ValidationException::withMessages(['stage'=>'Opportunity ที่ปิดแล้วไม่สามารถลากเปลี่ยนขั้นตอนได้']);if($opportunity->stage===$data['stage'])return;$before=$opportunity->toArray();$values=['stage'=>$data['stage'],'probability'=>Opportunity::STAGES[$data['stage']]['probability'],'updated_by'=>$request->user()->id,'won_at'=>null,'lost_at'=>null,'lost_reason'=>null];if($data['stage']==='LOST'){$values['lost_reason']=trim($data['lost_reason']);$values['lost_at']=now();}elseif($data['stage']==='WON'){$values['expected_value']=$data['won_value'];$values['expected_close_date']=$data['won_date'];$values['won_at']=$data['won_date'].' '.now()->format('H:i:s');}$opportunity->update($values);$audit->record('crm.opportunity.updated',$opportunity,$before,$opportunity->fresh()->toArray(),$request->user(),$request);});
        return response()->json(['status'=>true,'msg'=>'เปลี่ยนขั้นตอน Opportunity แล้ว']);
    }

    private function filters(Request $request,bool $requireStage):array
    {
        $rules=['stage'=>[$requireStage?'required':'nullable',Rule::in(array_keys(Opportunity::STAGES))],'q'=>['nullable','string','max:100'],'owner_id'=>['nullable','integer',Rule::exists('users','id')->where('is_active',true)],'team_id'=>['nullable','integer','exists:crm_sales_teams,id'],'value_min'=>['nullable','numeric','min:0'],'value_max'=>['nullable','numeric','gte:value_min'],'close_month'=>['nullable','date_format:Y-m']];$v=$request->validate($rules);
        return ['stage'=>$v['stage']??null,'q'=>trim((string)($v['q']??'')),'owner_id'=>isset($v['owner_id'])?(int)$v['owner_id']:null,'team_id'=>isset($v['team_id'])?(int)$v['team_id']:null,'value_min'=>$v['value_min']??null,'value_max'=>$v['value_max']??null,'close_month'=>$v['close_month']??null];
    }
    private function branchId(Request $request):int{return (int)$request->attributes->get('selectedBranch')->id;}
}
