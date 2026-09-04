<?php

namespace App\Modules\Purchasing\Requests;

use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Services\PurchaseReturnEligibilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason')),
            'lines' => $this->input('lines', []),
        ]);
    }

    public function rules(): array
    {
        return [
            'goods_receipt_id' => ['required', 'integer', 'min:1', 'exists:goods_receipts,id'],
            'purchase_document_id' => ['nullable', 'integer', 'min:1', 'exists:purchase_documents,id'],
            'return_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.goods_receipt_line_id' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('goods_receipt_lines', 'id')],
            'lines.*.purchase_document_line_id' => ['nullable', 'integer', 'min:1', 'exists:purchase_document_lines,id'],
            'lines.*.purchase_quantity' => ['required', 'numeric', 'decimal:0,8', 'gt:0'],
            'lines.*.reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $receiptId = (int) $this->integer('goods_receipt_id');
            $eligibility = app(PurchaseReturnEligibilityService::class);
            foreach ($this->input('lines', []) as $index => $line) {
                $receiptLine = GoodsReceiptLine::query()->with('goodsReceipt')->find((int) ($line['goods_receipt_line_id'] ?? 0));
                if (! $receiptLine || (int) $receiptLine->goods_receipt_id !== $receiptId) {
                    $validator->errors()->add("lines.{$index}.goods_receipt_line_id", 'รายการต้องอยู่ใน Goods Receipt ที่เลือก');
                    continue;
                }
                try {
                    $eligibility->assertLineQuantityAllowed($receiptLine, (string) $line['purchase_quantity']);
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add("lines.{$index}.{$field}", $message);
                        }
                    }
                }
            }
        }];
    }
}
