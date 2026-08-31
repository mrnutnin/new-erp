<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveDocumentSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_type' => strtoupper(trim($this->string('document_type')->toString())),
            'name' => trim($this->string('name')->toString()),
            'prefix' => strtoupper(trim($this->string('prefix')->toString())),
            'number_format' => strtoupper(trim($this->string('number_format')->toString())),
            'is_active' => $this->boolean('is_active'),
            'number_reuse_policy' => strtoupper(trim($this->string('number_reuse_policy')->toString() ?: 'NEVER_REUSE')),
        ]);
    }

    public function rules(): array
    {
        $sequence = $this->route('documentSequence');

        return [
            'document_type' => ['required', 'in:RECEIPT,PAYMENT,SALES_INVOICE,SALES_CREDIT_NOTE,PURCHASE_INVOICE,PURCHASE_CREDIT_NOTE,PURCHASE_ORDER,INVENTORY_ADJUSTMENT,INVENTORY_ISSUE,INVENTORY_RETURN,SALES_RFQ,SALES_INTAKE,SALES_QUOTATION,SALES_ORDER,PHYSICAL_SALE_HS,PHYSICAL_SALE_IV,SALES_RETURN,CUSTOMER,SUPPLIER,ADVANCE_DEPOSIT_AI,PURCHASE_REQUISITION,GOODS_RECEIPT,WMS_TRANSFER,STOCK_COUNT', Rule::unique('finance_document_sequences')->withoutTrashed()->whereNull('warehouse_id')->ignore($sequence)],
            'name' => ['required', 'string', 'max:255'],
            'prefix' => ['required', 'string', 'max:20'],
            'number_format' => ['required', 'string', 'max:80'],
            'reset_rule' => ['required', 'in:NEVER,YEARLY,MONTHLY'],
            'next_number' => [$sequence ? 'nullable' : 'required', 'integer', 'min:1', 'max:999999999999'],
            'is_active' => ['required', 'boolean'],
            'number_reuse_policy' => ['required', 'in:NEVER_REUSE,REUSE_DELETED_DRAFT_ONLY'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $format = $this->input('number_format');
            preg_match_all('/\{NUMBER:(\d+)\}/', (string) $format, $matches);

            if (count($matches[0]) !== 1 || (int) ($matches[1][0] ?? 0) < 1 || (int) ($matches[1][0] ?? 0) > 12) {
                $validator->errors()->add('number_format', 'รูปแบบต้องมี {NUMBER:n} เพียงหนึ่งครั้ง โดย n อยู่ระหว่าง 1–12');

                return;
            }

            $remaining = preg_replace('/\{(?:PREFIX|BRANCH|YY|YYMM|YYYY|MM|NUMBER:\d+)\}/', '', (string) $format);
            if (str_contains((string) $remaining, '{') || str_contains((string) $remaining, '}')) {
                $validator->errors()->add('number_format', 'รองรับเฉพาะ {PREFIX}, {BRANCH}, {YY}, {YYMM}, {YYYY}, {MM} และ {NUMBER:n}');

                return;
            }

            $example = preg_replace_callback('/\{NUMBER:(\d+)\}/', fn (array $match) => str_repeat('9', (int) $match[1]), (string) $format);
            $example = strtr((string) $example, [
                '{PREFIX}' => (string) $this->input('prefix'),
                '{BRANCH}' => 'BKK',
                '{YY}' => '26',
                '{YYMM}' => '2612',
                '{YYYY}' => '2026',
                '{MM}' => '12',
            ]);
            if (mb_strlen($example) > 40) {
                $validator->errors()->add('number_format', 'เลขเอกสารที่ได้ต้องยาวไม่เกิน 40 ตัวอักษร');
            }
        }];
    }
}
