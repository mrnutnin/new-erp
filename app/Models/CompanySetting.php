<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySetting extends Model
{
    protected $fillable = [
        'company_name',
        'company_address',
        'logo_path',
        'tax_id',
        'locale',
        'timezone',
        'base_currency',
        'date_format',
        'business_profile',
        'production_enabled',
        'accounting_profile',
        'inventory_costing_method',
        'allow_negative_stock',
        'negative_stock_cost_method',
        'fiscal_year_start_month',
        'default_vat_rate',
        'default_withholding_tax_rate',
        'tax_decimal_places',
        'manual_discount_approval_threshold',
        'document_sequence_reset',
        'posting_sla_minutes',
        'recost_sla_minutes',
        'audit_retention_days',
        'file_retention_days',
        'effective_from',
        'settings_version',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'allow_negative_stock' => 'boolean',
            'production_enabled' => 'boolean',
            'default_vat_rate' => 'decimal:2',
            'default_withholding_tax_rate' => 'decimal:2',
            'tax_decimal_places' => 'integer',
            'manual_discount_approval_threshold' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
