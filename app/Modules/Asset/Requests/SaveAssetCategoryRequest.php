<?php

namespace App\Modules\Asset\Requests;

use App\Modules\Accounting\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveAssetCategoryRequest extends FormRequest
{
    private const ACCOUNT_FIELDS = [
        'asset_account_id', 'accumulated_depreciation_account_id', 'depreciation_expense_account_id',
        'accumulated_impairment_account_id', 'impairment_loss_account_id', 'disposal_gain_account_id', 'disposal_loss_account_id', 'disposal_clearing_account_id',
    ];

    private const ACCOUNT_TYPE_BY_FIELD = [
        'asset_account_id' => 'ASSET',
        'accumulated_depreciation_account_id' => 'ASSET',
        'depreciation_expense_account_id' => 'EXPENSE',
        'accumulated_impairment_account_id' => 'ASSET',
        'impairment_loss_account_id' => 'EXPENSE',
        'disposal_gain_account_id' => 'REVENUE',
        'disposal_loss_account_id' => 'EXPENSE',
        'disposal_clearing_account_id' => 'ASSET',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim($this->string('code')->toString())),
            'name' => trim($this->string('name')->toString()),
            'description' => $this->filled('description') ? trim($this->string('description')->toString()) : null,
            'is_depreciable' => $this->boolean('is_depreciable'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $category = $this->route('assetCategory') ?? $this->route('category');
        $account = Rule::exists('accounts', 'id')->where(fn ($query) => $query
            ->whereNull('deleted_at')->where('is_active', true)->where('is_postable', true));

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('asset_categories', 'code')->ignore($category)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_depreciable' => ['required', 'boolean'],
            'capitalization_threshold' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'book_method' => ['required', 'in:STRAIGHT_LINE'],
            'book_useful_life_months' => ['nullable', 'required_if:is_depreciable,1', 'integer', 'min:1', 'max:1200'],
            'book_residual_value_percent' => ['required', 'numeric', 'decimal:0,4', 'between:0,100'],
            'tax_method' => ['required', 'in:STRAIGHT_LINE'],
            'tax_useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'tax_rate_percent' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,100'],
            'tax_cost_cap' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'asset_account_id' => ['required', 'integer', $account],
            'accumulated_depreciation_account_id' => ['nullable', 'required_if:is_depreciable,1', 'integer', $account],
            'depreciation_expense_account_id' => ['nullable', 'required_if:is_depreciable,1', 'integer', $account],
            'accumulated_impairment_account_id' => ['nullable', 'integer', $account],
            'impairment_loss_account_id' => ['nullable', 'integer', $account],
            'disposal_gain_account_id' => ['nullable', 'integer', $account],
            'disposal_loss_account_id' => ['nullable', 'integer', $account],
            'disposal_clearing_account_id' => ['nullable', 'integer', $account],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (self::ACCOUNT_FIELDS as $field) {
                if (! $this->filled($field) || $validator->errors()->has($field)) {
                    continue;
                }

                $account = Account::query()->with('type')->find($this->integer($field));
                if ($account?->type?->code !== self::ACCOUNT_TYPE_BY_FIELD[$field]) {
                    $validator->errors()->add($field, 'ประเภทบัญชี GL ไม่รองรับช่องนี้');
                }

                if ($field === 'asset_account_id' && $account?->control_account_type !== 'FIXED_ASSET') {
                    $validator->errors()->add($field, 'บัญชีสินทรัพย์ต้องเป็นบัญชีคุม FIXED_ASSET');
                }
            }
        }];
    }
}
