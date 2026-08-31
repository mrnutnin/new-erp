<?php

namespace App\Modules\Pos\Requests;

use App\Models\CompanySetting;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\UomConversion;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))->map(function (array $line): array {
            $line['item_id'] = $line['item_id'] ?? null;
            $line['uom_id'] = ($line['uom_id'] ?? '') === '' ? null : $line['uom_id'];
            $line['minimum_quantity'] = $line['minimum_quantity'] ?? '0';
            $line['discount_percent'] = $line['discount_percent'] ?? '0';
            $line['is_active'] = (bool) ($line['is_active'] ?? true);

            return $line;
        })->values()->all();

        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
            'currency' => mb_strtoupper(trim((string) ($this->input('currency') ?: 'THB'))),
            'customer_group_code' => $this->filled('customer_group_code') ? trim((string) $this->input('customer_group_code')) : null,
            'priority' => $this->input('priority', 0),
            'is_active' => (bool) $this->input('is_active', true),
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        $priceList = $this->route('priceList');
        $branchId = (int) $this->attributes->get('selectedBranch')->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('pos_price_lists', 'code')->where('branch_id', $branchId)->ignore($priceList)],
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'customer_group_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::exists('pos_customer_groups', 'code')->where(fn ($query) => $query
                    ->where('company_setting_id', (int) (CompanySetting::query()->value('id') ?: 1))
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'priority' => ['required', 'integer', 'min:0', 'max:999999'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('wms_items', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))],
            'lines.*.uom_id' => ['nullable', 'integer', Rule::exists('wms_uoms', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))],
            'lines.*.minimum_quantity' => ['required', ...WmsDecimal::rule(), 'min:0'],
            'lines.*.unit_price' => ['required', ...WmsDecimal::rule(), 'min:0'],
            'lines.*.discount_percent' => ['required', ...WmsDecimal::rule(), 'min:0', 'max:100'],
            'lines.*.effective_from' => ['nullable', 'date'],
            'lines.*.effective_to' => ['nullable', 'date', 'after_or_equal:lines.*.effective_from'],
            'lines.*.is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $lines = array_values((array) $this->input('lines', []));
            foreach ($lines as $index => $line) {
                if (! empty($line['uom_id']) && ! $this->uomIsAvailableForItem($line)) {
                    $validator->errors()->add("lines.{$index}.uom_id", 'หน่วยต้องเป็นหน่วยหลักของสินค้า หรือมี Conversion ที่ใช้ได้ในช่วงวันที่ราคา');
                }
                foreach (array_slice($lines, 0, $index) as $previous) {
                    if ($this->sameTier($line, $previous) && $this->datesOverlap($line, $previous)) {
                        $validator->errors()->add("lines.{$index}.item_id", 'รายการสินค้า หน่วย และจำนวนขั้นต่ำซ้ำกันในช่วงวันที่มีผล');
                    }
                }
            }
        });
    }

    private function sameTier(array $left, array $right): bool
    {
        return (string) ($left['item_id'] ?? '') === (string) ($right['item_id'] ?? '')
            && (string) ($left['uom_id'] ?? '') === (string) ($right['uom_id'] ?? '')
            && (string) ($left['minimum_quantity'] ?? '0') === (string) ($right['minimum_quantity'] ?? '0');
    }

    private function datesOverlap(array $left, array $right): bool
    {
        [$leftFrom, $leftTo] = $this->effectiveRange($left);
        [$rightFrom, $rightTo] = $this->effectiveRange($right);

        return ($leftTo === null || $rightFrom === null || $leftTo >= $rightFrom)
            && ($rightTo === null || $leftFrom === null || $rightTo >= $leftFrom);
    }

    private function uomIsAvailableForItem(array $line): bool
    {
        $item = Item::query()->find((int) ($line['item_id'] ?? 0));
        if (! $item || (int) $item->base_uom_id === (int) $line['uom_id']) {
            return (bool) $item;
        }
        [$from, $to] = $this->effectiveRange($line);

        return UomConversion::query()->where('from_uom_id', $line['uom_id'])->where('to_uom_id', $item->base_uom_id)
            ->when($to !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $to)))
            ->when($from !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $from)))
            ->exists();
    }

    private function effectiveRange(array $line): array
    {
        $from = $this->maxDate($this->input('effective_from'), $line['effective_from'] ?? null);
        $to = $this->minDate($this->input('effective_to'), $line['effective_to'] ?? null);

        return [$from, $to];
    }

    private function maxDate(?string $left, ?string $right): ?string
    {
        return $left === null || $left === '' ? $right : ($right === null || $right === '' ? $left : max($left, $right));
    }

    private function minDate(?string $left, ?string $right): ?string
    {
        return $left === null || $left === '' ? $right : ($right === null || $right === '' ? $left : min($left, $right));
    }
}
