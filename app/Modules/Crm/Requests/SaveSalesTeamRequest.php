<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveSalesTeamRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    protected function prepareForValidation(): void { $this->merge(['name'=>trim((string)$this->input('name'))]); }
    public function rules(): array
    {
        $branchId=(int)$this->attributes->get('selectedBranch')->id;
        return [
            'name'=>['required','string','max:255',Rule::unique('crm_sales_teams','name')->where('branch_id',$branchId)->ignore($this->route('salesTeam'))],
            'manager_id'=>['required','integer',Rule::exists('users','id')->where('is_active',true)],
            'member_ids'=>['nullable','array','max:100'], 'member_ids.*'=>['integer','distinct',Rule::exists('users','id')->where('is_active',true)],
            'is_active'=>['nullable','boolean'],
        ];
    }
}
