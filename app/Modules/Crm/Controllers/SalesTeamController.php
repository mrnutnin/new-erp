<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\SalesTeam;
use App\Modules\Crm\Requests\SaveSalesTeamRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SalesTeamController extends Controller
{
    public function index(Request $request): View
    {
        $teams=SalesTeam::query()->where('branch_id',$this->branchId($request))->with(['manager:id,name,employee_code','members:id,name,employee_code'])->withCount('members')->orderBy('name')->get();
        return view('Crm::teams.index',compact('teams'));
    }

    public function create(): View { return view('Crm::teams.form',['salesTeam'=>new SalesTeam(['is_active'=>true])]); }

    public function store(SaveSalesTeamRequest $request, AuditLogger $audit): JsonResponse
    {
        $team=DB::transaction(function()use($request,$audit){$members=$this->members($request);$this->assertAvailable($members,$this->branchId($request));$team=SalesTeam::create(['branch_id'=>$this->branchId($request),'name'=>$request->validated('name'),'manager_id'=>$request->integer('manager_id'),'is_active'=>$request->boolean('is_active',true),'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id]);$team->members()->sync($members);$audit->record('crm.sales-team.created',$team,[],[...$team->toArray(),'member_ids'=>$members],$request->user(),$request);return $team;});
        return response()->json(['status'=>true,'msg'=>'สร้างทีมขายแล้ว','redirect'=>route('crm.teams.index')]);
    }

    public function edit(Request $request, SalesTeam $salesTeam): View { $this->scope($request,$salesTeam);return view('Crm::teams.form',['salesTeam'=>$salesTeam->load('manager:id,name,employee_code','members:id,name,employee_code')]); }

    public function update(SaveSalesTeamRequest $request, SalesTeam $salesTeam, AuditLogger $audit): JsonResponse
    {
        $this->scope($request,$salesTeam);DB::transaction(function()use($request,$salesTeam,$audit){$team=SalesTeam::lockForUpdate()->findOrFail($salesTeam->id);$members=$this->members($request);$this->assertAvailable($members,$team->branch_id,$team->id);$before=[...$team->toArray(),'member_ids'=>$team->members()->pluck('users.id')->all()];$team->update(['name'=>$request->validated('name'),'manager_id'=>$request->integer('manager_id'),'is_active'=>$request->boolean('is_active'),'updated_by'=>$request->user()->id]);$team->members()->sync($members);$audit->record('crm.sales-team.updated',$team,$before,[...$team->fresh()->toArray(),'member_ids'=>$members],$request->user(),$request);});
        return response()->json(['status'=>true,'msg'=>'บันทึกทีมขายแล้ว','redirect'=>route('crm.teams.index')]);
    }

    private function members(SaveSalesTeamRequest $request): array { return collect($request->validated('member_ids',[]))->push($request->integer('manager_id'))->map(fn($id)=>(int)$id)->unique()->values()->all(); }
    private function assertAvailable(array $ids,int $branchId,?int $except=null): void { $names=DB::table('crm_sales_team_members as member')->join('crm_sales_teams as team','team.id','=','member.team_id')->join('users','users.id','=','member.user_id')->whereIn('member.user_id',$ids)->where('team.branch_id',$branchId)->whereNull('team.deleted_at')->when($except,fn($q)=>$q->where('team.id','<>',$except))->pluck('users.name');if($names->isNotEmpty())throw ValidationException::withMessages(['member_ids'=>'ผู้ใช้อยู่ในทีมอื่นของสาขานี้แล้ว: '.$names->join(', ')]); }
    private function scope(Request $request,SalesTeam $team):void { abort_unless((int)$team->branch_id===$this->branchId($request),404); }
    private function branchId(Request $request):int { return (int)$request->attributes->get('selectedBranch')->id; }
}
