<?php

namespace App\Modules\Pos\Requests;

use App\Models\CompanySetting;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))->map(function (array $line): array {
            $line['uom_id'] = ($line['uom_id'] ?? '') === '' ? null : $line['uom_id'];
            $line['unit_price'] = ($line['unit_price'] ?? '') === '' ? null : $line['unit_price'];
            $line['base_unit_price'] = ($line['base_unit_price'] ?? '') === '' ? null : $line['base_unit_price'];
            $line['discount_percent'] = ($line['discount_percent'] ?? '') === '' ? null : $line['discount_percent'];

            return $line;
        })->values()->all();

        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
            'currency' => mb_strtoupper(trim((string) ($this->input('currency') ?: 'THB'))),
            'customer_group_code' => $this->filled('customer_group_code') ? trim((string) $this->input('customer_group_code')) : null,
            'is_active' => (bool) $this->input('is_active', true),
            'application_scope' => mb_strtoupper(trim((string) ($this->input('application_scope') ?: 'LINE'))),
            'stackable' => (bool) $this->input('stackable', false),
            'bill_discount_amount' => $this->filled('bill_discount_amount') ? $this->input('bill_discount_amount') : null,
            'bill_discount_percent' => $this->filled('bill_discount_percent') ? $this->input('bill_discount_percent') : null,
            'campaign_budget_amount' => $this->filled('campaign_budget_amount') ? $this->input('campaign_budget_amount') : null,
            'campaign_target_sales_amount' => $this->filled('campaign_target_sales_amount') ? $this->input('campaign_target_sales_amount') : null,
            'campaign_target_gross_profit_amount' => $this->filled('campaign_target_gross_profit_amount') ? $this->input('campaign_target_gross_profit_amount') : null,
            'campaign_owner_id' => $this->filled('campaign_owner_id') ? $this->input('campaign_owner_id') : null,
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        $promotion = $this->route('promotion');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('pos_promotions', 'code')->ignore($promotion)],
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'customer_group_code' => ['nullable', 'string', 'max:50', Rule::exists('pos_customer_groups', 'code')->where(fn ($query) => $query->where('company_setting_id', (int) (CompanySetting::query()->value('id') ?: 1))->where('is_active', true)->whereNull('deleted_at'))],
            'priority' => ['required', 'integer', 'min:0', 'max:999999'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
            'application_scope' => ['required', Rule::in(['LINE', 'DOCUMENT'])],
            'stackable' => ['required', 'boolean'],
            'bill_discount_amount' => ['nullable', ...WmsDecimal::rule(), 'min:0'],
            'bill_discount_percent' => ['nullable', ...WmsDecimal::rule(), 'min:0', 'max:100'],
            'campaign_budget_amount' => ['nullable', ...WmsDecimal::rule(), 'min:0'],
            'campaign_target_sales_amount' => ['nullable', ...WmsDecimal::rule(), 'min:0'],
            'campaign_target_gross_profit_amount' => ['nullable', ...WmsDecimal::rule()],
            'campaign_owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
            'lines' => ['nullable', 'array'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('wms_items', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
            'lines.*.uom_id' => ['nullable', 'integer', Rule::exists('wms_uoms', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
            'lines.*.minimum_quantity' => ['required', ...WmsDecimal::rule(), 'min:0'],
            'lines.*.unit_price' => ['nullable', ...WmsDecimal::rule(), 'min:0'],
            'lines.*.base_unit_price' => ['nullable', ...WmsDecimal::rule(), 'min:0'],
            'lines.*.discount_percent' => ['nullable', ...WmsDecimal::rule(), 'min:0', 'max:100'],
            'lines.*.is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $scope = $this->input('application_scope');
            $amount = $this->input('bill_discount_amount');
            $percent = $this->input('bill_discount_percent');
            if ($scope === 'DOCUMENT') {
                if (($amount === null) === ($percent === null)) {
                    $validator->errors()->add('bill_discount_amount', 'โปรโมชั่นท้ายบิลต้องระบุยอดลดหรือส่วนลดเปอร์เซ็นต์เพียงอย่างเดียว');
                }
                if (filled($this->input('lines'))) {
                    $validator->errors()->add('lines', 'โปรโมชั่นท้ายบิลไม่สามารถกำหนดเงื่อนไขสินค้าได้');
                }

                return;
            }

            if (! filled($this->input('lines'))) {
                $validator->errors()->add('lines', 'โปรโมชั่นต่อรายการต้องมีเงื่อนไขสินค้าอย่างน้อยหนึ่งรายการ');
            }
            if ($amount !== null || $percent !== null) {
                $validator->errors()->add('bill_discount_amount', 'โปรโมชั่นต่อรายการไม่สามารถกำหนดส่วนลดท้ายบิลได้');
            }

            $seen = [];
            foreach ((array) $this->input('lines', []) as $index => $line) {
                $hasPrice = $line['unit_price'] !== null && $line['unit_price'] !== '';
                $hasBasePrice = $line['base_unit_price'] !== null && $line['base_unit_price'] !== '';
                $hasDiscount = $line['discount_percent'] !== null && $line['discount_percent'] !== '';
                $fixedPrice = $hasPrice && ! $hasBasePrice && ! $hasDiscount;
                $percentageDiscount = ! $hasPrice && $hasBasePrice && $hasDiscount;
                if (! $fixedPrice && ! $percentageDiscount) {
                    $validator->errors()->add("lines.{$index}.unit_price", 'ระบุราคาโปรโมชั่น หรือราคาตั้งต้นพร้อมส่วนลดอย่างใดอย่างหนึ่งเท่านั้น');
                }

                $key = ($line['item_id'] ?? '').'|'.($line['uom_id'] ?? 'generic').'|'.($line['minimum_quantity'] ?? '0');
                if (isset($seen[$key])) {
                    $validator->errors()->add("lines.{$index}.item_id", 'รายการสินค้า หน่วย และจำนวนขั้นต่ำซ้ำกันในโปรโมชั่นเดียวกัน');
                }
                $seen[$key] = true;
            }
        });
    }
}
