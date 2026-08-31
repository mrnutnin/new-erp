<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaxRealization extends Model
{
    protected $table = 'finance_tax_realizations';

    protected $fillable = [
        'allocation_id', 'settlement_id', 'open_item_id', 'tax_kind', 'tax_code_id',
        'deferred_account_id', 'actual_account_id', 'tax_base', 'tax_amount',
        'tax_point_date', 'settlement_date', 'created_by',
    ];

    protected function casts(): array
    {
        return ['tax_code_id' => 'integer', 'deferred_account_id' => 'integer', 'actual_account_id' => 'integer', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'tax_point_date' => 'date', 'settlement_date' => 'date'];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function openItem(): BelongsTo
    {
        return $this->belongsTo(OpenItem::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function deferredAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deferred_account_id');
    }

    public function actualAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'actual_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
