<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransferCustomerOwnershipRequest extends FormRequest
{
    public function authorize():bool{return true;}
    protected function prepareForValidation():void{$this->merge(['reason'=>trim((string)$this->input('reason'))]);}
    public function rules():array
    {
        $branchId=(int)$this->attributes->get('selectedBranch')->id;
        $branchUser=fn($query)=>$query->whereExists(fn($query)=>$query->from('user_branch')->whereColumn('user_branch.user_id','users.id')->where('user_branch.branch_id',$branchId));
        return [
            'source_user_id'=>['required','integer',Rule::exists('users','id')->where($branchUser)],
            'target_user_id'=>['required','integer','different:source_user_id',Rule::exists('users','id')->where(fn($query)=>$branchUser($query)->where('is_active',true)->whereNull('deleted_at'))],
            'scope'=>['required',Rule::in(['ALL','TEAM','SELECTED'])],
            'team_id'=>['nullable','required_if:scope,TEAM','integer',Rule::exists('crm_sales_teams','id')->where(fn($query)=>$query->where('branch_id',$branchId)->where('is_active',true)->whereNull('deleted_at'))],
            'customer_ids'=>['nullable','required_if:scope,SELECTED','array','max:100'],'customer_ids.*'=>['integer','distinct','exists:parties,id'],
            'reason'=>['required','string','max:500'],
        ];
    }
}
