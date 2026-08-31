<?php

namespace App\Modules\Finance\Requests;

use App\Modules\Accounting\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $warehouseId = $this->attributes->get('selectedWarehouse')->id;
        $bankAccount = $this->route('bankAccount');
        $accountNumber = Rule::unique('finance_bank_accounts', 'account_number')->where(fn ($query) => $query->where('warehouse_id', $warehouseId))->ignore($bankAccount);

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('finance_bank_accounts')->where(fn ($query) => $query->where('warehouse_id', $warehouseId))->ignore($bankAccount)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:CASH,BANK,CREDIT_CARD,CHEQUE'],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where(fn ($query) => $query->whereIn('control_account_type', ['CASH', 'BANK', 'CREDIT_CARD', 'CHEQUE'])->where('is_active', true)->where('is_postable', true))],
            'bank_name' => ['nullable', 'required_if:type,BANK', 'string', 'max:255'],
            'account_number' => ['nullable', 'required_if:type,BANK', 'string', 'max:50', $accountNumber],
            'currency_code' => ['required', 'string', 'size:3'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('account_id')) {
                return;
            }

            $controlType = Account::query()->whereKey($this->integer('account_id'))->value('control_account_type');

            if ($controlType !== $this->input('type')) {
                $validator->errors()->add('account_id', 'บัญชีคุม GL ต้องตรงกับประเภทช่องทางรับเงิน');
            }
        }];
    }
}
