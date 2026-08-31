<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountMappingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SaveAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'key' => strtoupper(trim((string) $this->input('key'))),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $mappings = app(AccountMappingService::class);
        $mapping = $this->route('accountMapping');

        return [
            'key' => ['required', Rule::in($mappings->keys()), Rule::unique('accounting_account_mappings', 'key')->ignore($mapping)],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)->where('is_postable', true))],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        $mappings = app(AccountMappingService::class);

        return [function (Validator $validator) use ($mappings): void {
            if ($validator->errors()->hasAny(['key', 'account_id'])) {
                return;
            }

            $mapping = $this->route('accountMapping');
            if ($mapping && $mapping->key !== $this->input('key')) {
                $validator->errors()->add('key', 'ไม่สามารถเปลี่ยนประเภท Account Mapping ได้');

                return;
            }

            $account = Account::query()->with('type')->find($this->integer('account_id'));
            if ($account) {
                try {
                    $mappings->assertCompatible($this->input('key'), $account);
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            }
        }];
    }
}
