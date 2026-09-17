<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Services\SalesForecastService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class ForecastController extends Controller
{
    public function index(Request $request,SalesForecastService $forecast):View
    {
        $filters=$this->filters($request);return view('Crm::forecast.index',['filters'=>$filters,'teams'=>$forecast->teams($request),'selectedOwner'=>$filters['owner_id']?User::find($filters['owner_id']):null]);
    }
    public function ownerOptions(Request $request,SalesForecastService $forecast):JsonResponse
    {
        $search=trim((string)$request->input('q'));$teams=$forecast->teams($request);if($request->filled('team_id')){$team=$teams->firstWhere('id',$request->integer('team_id'));abort_unless($team,403);$allowed=$team->members()->pluck('users.id');}else{$allowed=$request->user()->hasPermission('crm.team-work.view-all')?null:$teams->flatMap(fn($team)=>$team->members()->pluck('users.id'))->push($request->user()->id)->unique();}$rows=User::query()->where('is_active',true)->whereHas('branches',fn(Builder $query)=>$query->where('branches.id',$request->attributes->get('selectedBranch')->id))->when($allowed!==null,fn(Builder $query)=>$query->whereIn('id',$allowed))->when($search,fn(Builder $query)=>$query->where(fn(Builder $query)=>$query->where('name','like',"%{$search}%")->orWhere('employee_code','like',"%{$search}%")))->orderBy('name')->forPage(max(1,$request->integer('page',1)),31)->get(['id','name','employee_code']);return response()->json(['results'=>$rows->take(30)->map(fn(User $user)=>['id'=>$user->id,'text'=>($user->employee_code?$user->employee_code.' · ':'').$user->name])->values(),'pagination'=>['more'=>$rows->count()>30]]);
    }
    public function data(Request $request,SalesForecastService $forecast):JsonResponse
    {
        $snapshot=$forecast->snapshot($request,$this->filters($request));return response()->json(['summary'=>$snapshot['summary'],'performance'=>$snapshot['performance'],'insights'=>$snapshot['insights'],'risks'=>$snapshot['risks'],'stages'=>$snapshot['stages']->map(fn($row)=>['stage'=>$row->stage,'opportunity_count'=>(int)$row->opportunity_count,'pipeline'=>(float)$row->pipeline,'forecast'=>(float)$row->forecast])->values(),'html'=>view('Crm::forecast._cards',['rows'=>$snapshot['paginator']])->render(),'pagination'=>$snapshot['paginator']->hasPages()?$snapshot['paginator']->onEachSide(1)->links('pagination::bootstrap-5')->render():'','from'=>$snapshot['paginator']->firstItem(),'to'=>$snapshot['paginator']->lastItem(),'total'=>$snapshot['paginator']->total()]);
    }
    private function filters(Request $request):array
    {
        $data=$request->validate(['month'=>['nullable','date_format:Y-m'],'team_id'=>['nullable','integer','exists:crm_sales_teams,id'],'owner_id'=>['nullable','integer',Rule::exists('users','id')],'page'=>['nullable','integer','min:1']]);return ['month'=>$data['month']??now()->format('Y-m'),'team_id'=>isset($data['team_id'])?(int)$data['team_id']:null,'owner_id'=>isset($data['owner_id'])?(int)$data['owner_id']:null,'page'=>(int)($data['page']??1)];
    }
}
