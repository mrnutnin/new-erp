<?php

namespace App\Modules\Wms\Requests;

use App\Models\Party;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Party|null $supplier */
        $supplier = $this->route('supplier');

        return [
            'code' => [$supplier ? 'required' : 'nullable', 'string', 'max:30', ...($supplier ? [Rule::unique('parties')->ignore($supplier)] : [])],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['COMPANY', 'INDIVIDUAL'])],
            'tax_id' => ['nullable', 'digits:13', ...($supplier ? [Rule::unique('parties', 'tax_id')->where(fn ($query) => $query->where('branch_code', $this->input('branch_code', '00000')))->ignore($supplier)] : [])],
            'branch_code' => ['required', 'digits:5'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'payment_term_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_payment_terms', 'id')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $query
                        ->where('is_active', true)
                        ->when($supplier?->supplierRole?->payment_term_id, fn ($query, $paymentTermId) => $query->orWhere('id', $paymentTermId)))),
            ],
            'credit_limit' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $taxId = preg_replace('/\D+/', '', (string) $this->input('tax_id'));
        $branchCode = preg_replace('/\D+/', '', (string) $this->input('branch_code', '00000'));

        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->input('code'))),
            'tax_id' => $taxId !== '' ? $taxId : null,
            'branch_code' => $branchCode !== '' ? $branchCode : '00000',
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route('supplier') || $validator->errors()->hasAny(['code', 'tax_id', 'branch_code'])) {
                return;
            }

            $codeMatch = Party::query()->withTrashed()->with('supplierRole')->where('code', $this->input('code'))->first();
            $taxMatch = $this->filled('tax_id')
                ? Party::query()->withTrashed()->where('tax_id', $this->input('tax_id'))->where('branch_code', $this->input('branch_code'))->first()
                : null;

            if ($taxMatch && (! $codeMatch || ! $taxMatch->is($codeMatch))) {
                $validator->errors()->add('tax_id', 'เลขผู้เสียภาษีและสาขานี้เป็นของคู่ค้ารหัสอื่น');
            } elseif ($codeMatch && $codeMatch->tax_id && $this->filled('tax_id') && ($codeMatch->tax_id !== $this->input('tax_id') || $codeMatch->branch_code !== $this->input('branch_code'))) {
                $validator->errors()->add('tax_id', 'ข้อมูลภาษีไม่ตรงกับคู่ค้ารหัสนี้');
            } elseif ($codeMatch?->supplierRole) {
                $validator->errors()->add('code', 'คู่ค้านี้มีบทบาท Supplier อยู่แล้ว');
            }
        }];
    }
}
