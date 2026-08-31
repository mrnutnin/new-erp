<?php

namespace App\Modules\Pos\Requests;

use App\Modules\Pos\Models\PhysicalSale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PostPhysicalSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'withholding_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where(fn ($query) => $query->where('kind', 'WHT')->where('is_active', true))],
            'withholding_base' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'tenders' => ['nullable', 'array', 'min:1', 'max:20'], 'tenders.*.bank_account_id' => ['required_with:tenders', 'integer', 'min:1'], 'tenders.*.amount' => ['required_with:tenders', 'numeric', 'decimal:0,2', 'gt:0'], 'tenders.*.reference' => ['nullable', 'string', 'max:100'],
            'advance_allocations' => ['nullable', 'array', 'max:20'], 'advance_allocations.*.advance_deposit_id' => ['required_with:advance_allocations', 'integer', 'min:1'], 'advance_allocations.*.amount' => ['required_with:advance_allocations', 'numeric', 'decimal:0,2', 'gt:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $sale = $this->route('physicalSale');
            if ($validator->errors()->isEmpty() && $sale instanceof PhysicalSale
                && $this->input('posting_date') < $sale->document_date->format('Y-m-d')) {
                $validator->errors()->add('posting_date', 'วันที่ Post ต้องไม่ก่อนวันที่เอกสาร');
            }
            if ($validator->errors()->isEmpty() && $sale instanceof PhysicalSale && $sale->document_type === 'HS' && (float) $sale->total_amount > 0 && $this->input('tenders') === null && $this->input('advance_allocations') === null) {
                $validator->errors()->add('tenders', 'ขายสดต้องระบุช่องทางรับเงินก่อนยืนยันขาย');
            }
        }];
    }
}
