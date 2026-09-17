<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveCustomerAssignmentRequest extends FormRequest
{
    public function authorize():bool{return true;}
    protected function prepareForValidation():void{$this->merge(['territory'=>trim((string)$this->input('territory'))?:null]);}
    public function rules():array
    {
        $branchId=(int)$this->attributes->get('selectedBranch')->id;
        $branchUser=fn($query)=>$query->where('is_active',true)->whereExists(fn($query)=>$query->from('user_branch')->whereColumn('user_branch.user_id','users.id')->where('user_branch.branch_id',$branchId));
        return [
            'owner_id'=>['required','integer',Rule::exists('users','id')->where($branchUser)],
            'team_id'=>['nullable','integer',Rule::exists('crm_sales_teams','id')->where(fn($query)=>$query->where('branch_id',$branchId)->where('is_active',true)->whereNull('deleted_at'))],
            'territory'=>['nullable','string','max:100'],
            'backup_owner_id'=>['nullable','integer','different:owner_id',Rule::exists('users','id')->where($branchUser)],
        ];
    }
    public function attributes():array{return ['owner_id'=>'เจ้าของลูกค้าหลัก','team_id'=>'ทีมขาย','territory'=>'เขตการขาย','backup_owner_id'=>'ผู้แทนสำรอง'];}
}
