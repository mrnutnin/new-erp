<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\User;
use App\Modules\Crm\Models\CustomerAssignment;
use App\Modules\Crm\Models\SalesTeam;
use App\Modules\Crm\Requests\SaveCustomerAssignmentRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerAssignmentController extends Controller
{
    public function update(SaveCustomerAssignmentRequest $request,Party $customer,AuditLogger $audit):JsonResponse
    {
        abort_unless($customer->customerRole()->exists(),404);$data=$request->validated();$branchId=$this->branchId($request);
        $assignment=DB::transaction(function()use($request,$customer,$audit,$data,$branchId){Party::query()->lockForUpdate()->findOrFail($customer->id);if($teamId=$data['team_id']??null){$team=SalesTeam::query()->where('branch_id',$branchId)->whereKey($teamId)->lockForUpdate()->firstOrFail();$memberIds=$team->members()->pluck('users.id');foreach(array_filter([(int)$data['owner_id'],(int)($data['backup_owner_id']??0)]) as $userId)if(!$memberIds->contains($userId))throw ValidationException::withMessages(['team_id'=>'เจ้าของลูกค้าและผู้แทนสำรองต้องเป็นสมาชิกทีมที่เลือก']);}$assignment=CustomerAssignment::query()->where('party_id',$customer->id)->where('branch_id',$branchId)->lockForUpdate()->first();$before=$assignment?->only(['owner_id','team_id','territory','backup_owner_id'])??[];$assignment??=new CustomerAssignment(['party_id'=>$customer->id,'branch_id'=>$branchId,'created_by'=>$request->user()->id]);$assignment->fill([...$data,'updated_by'=>$request->user()->id])->save();$audit->record('crm.customer.assignment-updated',$assignment,$before,$assignment->only(['owner_id','team_id','territory','backup_owner_id']),$request->user(),$request);return $assignment;});
        return response()->json(['status'=>true,'msg'=>'บันทึกผู้ดูแลลูกค้าแล้ว','assignment_id'=>$assignment->id]);
    }

    public function userOptions(Request $request):JsonResponse
    {
        $search=trim((string)$request->input('q'));$rows=User::query()->where('is_active',true)->whereHas('branches',fn(Builder $query)=>$query->where('branches.id',$this->branchId($request)))->when($search,fn(Builder $query)=>$query->where(fn(Builder $query)=>$query->where('name','like',"%{$search}%")->orWhere('employee_code','like',"%{$search}%")->orWhere('username','like',"%{$search}%")))->orderBy('name')->forPage(max(1,$request->integer('page',1)),31)->get(['id','name','employee_code','position']);
        return response()->json(['results'=>$rows->take(30)->map(fn(User $user)=>['id'=>$user->id,'text'=>($user->employee_code?$user->employee_code.' · ':'').$user->name,'position'=>$user->position])->values(),'pagination'=>['more'=>$rows->count()>30]]);
    }

    private function branchId(Request $request):int{return (int)$request->attributes->get('selectedBranch')->id;}
}
