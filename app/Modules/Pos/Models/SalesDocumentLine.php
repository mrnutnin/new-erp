<?php

namespace App\Modules\Pos\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDocumentLine extends Model
{
    protected $fillable = ['line_number', 'description', 'item_id', 'uom_id', 'stock_uom_id', 'uom_factor', 'conversion_snapshot', 'price_snapshot', 'quantity', 'unit', 'unit_price', 'discount_amount', 'revenue_account_id', 'tax_code_id', 'tax_rate', 'tax_base', 'tax_amount', 'line_total', 'withholding_tax_code_id', 'withholding_rate', 'withholding_base', 'withholding_amount'];

    protected function casts(): array
    {
        return ['item_id' => 'integer', 'uom_id' => 'integer', 'stock_uom_id' => 'integer', 'uom_factor' => 'decimal:8', 'conversion_snapshot' => 'array', 'price_snapshot' => 'array', 'quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount_amount' => 'decimal:2', 'tax_rate' => 'decimal:4', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2', 'withholding_tax_code_id' => 'integer', 'withholding_rate' => 'decimal:4', 'withholding_base' => 'decimal:2', 'withholding_amount' => 'decimal:2'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function withholdingTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'withholding_tax_code_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function stockUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }
}
