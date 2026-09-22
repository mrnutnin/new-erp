<?php

namespace App\Modules\Production\Requests;

use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveBomRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code'))), 'name' => trim((string) $this->input('name')), 'notes' => trim((string) $this->input('notes'))]);
    }

    public function rules(): array
    {
        $creating = ! $this->route('revision');

        return [
            'code' => [$creating ? 'required' : 'nullable', 'string', 'max:50'],
            'name' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'finished_item_id' => [$creating ? 'required' : 'nullable', 'integer', Rule::exists('wms_items', 'id')->where(fn ($query) => $query->where('is_active', true)->where('is_stock_item', true))],
            'base_uom_id' => [$creating ? 'required' : 'nullable', 'integer', 'exists:wms_uoms,id'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.component_item_id' => ['required', 'integer', Rule::exists('wms_items', 'id')->where(fn ($query) => $query->where('is_active', true)->where('is_stock_item', true)), 'distinct'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:wms_uoms,id'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,'.WmsDecimal::places(), 'gt:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
            'lines.*.substitutes' => ['nullable', 'array', 'max:10'],
            'lines.*.substitutes.*.substitute_item_id' => ['required', 'integer', Rule::exists('wms_items', 'id')->where(fn ($query) => $query->where('is_active', true)->where('is_stock_item', true))],
            'lines.*.substitutes.*.uom_id' => ['required', 'integer', 'exists:wms_uoms,id'],
            'lines.*.substitutes.*.quantity_factor' => ['required', 'numeric', 'gt:0'],
            'lines.*.substitutes.*.priority' => ['required', 'integer', 'min:1', 'max:100'],
            'lines.*.substitutes.*.notes' => ['nullable', 'string', 'max:500'],
            'operations' => ['nullable', 'array', 'max:50'],
            'operations.*.name' => ['required', 'string', 'max:150'],
            'operations.*.planned_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'operations.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
