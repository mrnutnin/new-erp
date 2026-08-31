<?php

namespace App\Modules\Pos\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    use HasDocumentBranch;

    protected $fillable = ['warehouse_id', 'branch_id', 'sales_quotation_id', 'sales_rfq_id', 'source_sales_intake_id', 'party_id', 'document_number', 'party_code', 'party_name', 'party_tax_id', 'party_branch_code', 'party_address', 'document_date', 'valid_until', 'status', 'subtotal', 'discount_amount', 'promotion_snapshot', 'promotion_discount_amount', 'total_amount', 'description', 'created_by', 'updated_by', 'confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at', 'cancel_reason'];

    protected function casts(): array
    {
        return ['document_date' => 'date', 'valid_until' => 'date', 'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'promotion_snapshot' => 'array', 'promotion_discount_amount' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('line_number');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(SalesRfq::class, 'sales_rfq_id');
    }

    public function sourceIntake(): BelongsTo
    {
        return $this->belongsTo(SalesIntake::class, 'source_sales_intake_id');
    }

    public function physicalSales(): HasMany
    {
        return $this->hasMany(PhysicalSale::class, 'source_id')->where('source_type', 'SALES_ORDER');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
