<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Support\JournalBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'description' => trim($this->string('description')->toString()),
            'source_reference' => trim($this->string('source_reference')->toString()) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'source_reference' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => [
                'bail', 'required', 'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->where('is_postable', true)
                    ->whereNull('control_account_type')
                    ->whereNull('deleted_at')),
            ],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.debit' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'lines.*.credit' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'lines.*.tax_code_id' => [
                'nullable', 'integer',
                Rule::exists('tax_codes', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at')),
            ],
            'lines.*.tax_base' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'lines.*.tax_point_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.tax_settlement_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $lines = $this->input('lines', []);

            if (! is_array($lines)) {
                return;
            }

            foreach ($lines as $index => $line) {
                $debit = (float) ($line['debit'] ?? 0);
                $credit = (float) ($line['credit'] ?? 0);

                if (($debit > 0) === ($credit > 0)) {
                    $validator->errors()->add("lines.{$index}.debit", 'แต่ละบรรทัดต้องมีจำนวนเงินด้านเดบิตหรือเครดิตเพียงด้านเดียว');
                }
            }

            $totals = JournalBalance::totals($lines);
            if ($totals['debit'] <= 0 || $totals['debit'] !== $totals['credit']) {
                $validator->errors()->add('lines', 'ยอดรวมเดบิตและเครดิตต้องเท่ากันและมากกว่าศูนย์');
            }
        }];
    }
}
