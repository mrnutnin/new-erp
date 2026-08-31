<?php

namespace App\Modules\Finance\Requests;

use App\Modules\Accounting\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveOtherCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim($this->string('code')->toString())),
            'name' => trim($this->string('name')->toString()),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:INCOME,EXPENSE'],
            'code' => ['required', 'string', 'max:30', Rule::unique('finance_other_categories')->where(fn ($query) => $query->where('kind', $this->input('kind')))->ignore($this->route('otherCategory'))],
            'name' => ['required', 'string', 'max:255'],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('statement_section', 'PROFIT_LOSS')->where('is_active', true)->where('is_postable', true))],
            'tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('account_id')) {
                return;
            }

            $normalBalance = Account::query()->whereKey($this->integer('account_id'))->value('normal_balance');
            $expected = $this->input('kind') === 'INCOME' ? 'CREDIT' : 'DEBIT';

            if ($normalBalance !== $expected) {
                $validator->errors()->add('account_id', 'บัญชี GL ต้องตรงกับประเภทรายได้หรือรายจ่าย');
            }
        }];
    }
}
