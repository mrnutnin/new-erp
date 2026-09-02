<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Support\PostingEvent;
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
            'event_code' => strtolower(trim((string) $this->input('event_code'))),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $mappings = app(AccountMappingService::class);
        $mapping = $this->route('accountMapping');
        $legacyUpdate = $mapping && $mapping->event_code === null;
        $uniqueRole = Rule::unique('accounting_account_mappings', 'key')->ignore($mapping);
        $legacyUpdate ? $uniqueRole->whereNull('event_code') : $uniqueRole->where('event_code', $this->input('event_code'));

        return [
            'event_code' => $legacyUpdate ? ['nullable', 'prohibited'] : ['required', Rule::in(PostingEvent::codes())],
            'key' => ['required', 'string', 'max:80', $uniqueRole],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)->where('is_postable', true))],
            'is_active' => ['required', 'boolean'],
            'reason' => [$legacyUpdate ? 'nullable' : 'required', 'string', 'min:10', 'max:500'],
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

            if ($mapping?->event_code !== null || ! $mapping) {
                try {
                    $mappings->assertEventRole($this->input('event_code'), $this->input('key'));
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }

                    return;
                }
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

    public function mappingValues(): array
    {
        $values = $this->safe()->only(['event_code', 'key', 'account_id', 'is_active']);
        if (($values['event_code'] ?? null) === '') {
            unset($values['event_code']);
        }

        return $values;
    }
}
