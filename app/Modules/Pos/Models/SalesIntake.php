<?php

namespace App\Modules\Pos\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesIntake extends Model
{
    use HasDocumentBranch;

    protected $fillable = [
        'warehouse_id', 'branch_id', 'document_number', 'party_id', 'party_code', 'party_name', 'party_tax_id', 'party_branch_code', 'party_address',
        'document_date', 'source', 'description', 'status', 'requires_rfq', 'created_by', 'updated_by', 'prepared_by', 'order_method',
        'delivery_method', 'appointment_date', 'tax_treatment', 'prices_include_vat', 'billing_address', 'shipping_address',
        'tax_decimal_places', 'subtotal', 'discount_amount', 'promotion_snapshot', 'promotion_discount_amount', 'tax_base', 'tax_amount', 'grand_total',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date', 'appointment_date' => 'date', 'requires_rfq' => 'boolean', 'prices_include_vat' => 'boolean',
            'subtotal' => 'decimal:4', 'discount_amount' => 'decimal:4', 'promotion_snapshot' => 'array', 'promotion_discount_amount' => 'decimal:4', 'tax_base' => 'decimal:4', 'tax_amount' => 'decimal:4', 'grand_total' => 'decimal:4',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesIntakeLine::class)->orderBy('line_number');
    }

    public function rfq(): HasOne
    {
        return $this->hasOne(SalesRfq::class, 'source_sales_intake_id');
    }

    public function quotation(): HasOne
    {
        return $this->hasOne(SalesQuotation::class, 'source_sales_intake_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(SalesOrder::class, 'source_sales_intake_id')->where('status', '!=', 'CANCELLED')->latest('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'source_sales_intake_id');
    }
}
