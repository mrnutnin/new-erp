<?php

namespace App\Modules\Pos\Requests;

use App\Models\Party;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? mb_strtoupper(trim((string) $this->input('code'))) : null,
            'tax_id' => $this->filled('tax_id') ? preg_replace('/\D+/', '', (string) $this->input('tax_id')) : null,
            'branch_code' => $this->filled('branch_code') ? trim((string) $this->input('branch_code')) : null,
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
        ]);
    }

    public function rules(): array
    {
        /** @var Party|null $customer */
        $customer = $this->route('customer');

        return [
            'code' => array_values(array_filter([$customer ? 'required' : 'nullable', 'string', 'max:30', $customer ? Rule::unique('parties', 'code')->ignore($customer) : null])),
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['COMPANY', 'INDIVIDUAL'])],
            'tax_id' => array_values(array_filter([
                'nullable', 'digits:13',
                $customer ? Rule::unique('parties', 'tax_id')->where(fn ($query) => $query->where('branch_code', $this->input('branch_code', '00000')))->ignore($customer) : null,
            ])),
            'branch_code' => ['required', 'digits:5'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'payment_term_id' => [
                'nullable', 'integer',
                Rule::exists('finance_payment_terms', 'id')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $query
                        ->where('is_active', true)
                        ->when($customer?->customerRole?->payment_term_id, fn ($query, $id) => $query->orWhere('id', $id)))),
            ],
            'credit_limit' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'is_active' => ['required', 'boolean'],
            'customer_group_id' => ['nullable', 'integer', Rule::exists('pos_customer_groups', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))],
            'addresses' => ['nullable', 'array', 'max:100'],
            'addresses.*.id' => ['nullable', 'integer'],
            'addresses.*.address_type' => ['required', Rule::in(['BILLING', 'SHIPPING'])],
            'addresses.*.label' => ['nullable', 'string', 'max:100'],
            'addresses.*.recipient_name' => ['nullable', 'string', 'max:255'],
            'addresses.*.address_line' => ['nullable', 'string', 'max:2000'],
            'addresses.*.district' => ['nullable', 'string', 'max:100'],
            'addresses.*.amphoe' => ['nullable', 'string', 'max:100'],
            'addresses.*.province' => ['nullable', 'string', 'max:100'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:10'],
            'addresses.*.phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
