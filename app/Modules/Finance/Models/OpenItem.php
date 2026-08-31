<?php

namespace App\Modules\Finance\Models;

use App\Models\Party;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpenItem extends Model
{
    protected $table = 'finance_open_items';

    protected $fillable = [
        'warehouse_id', 'ledger_type', 'party_type', 'party_id', 'account_id', 'journal_entry_line_id',
        'document_type', 'document_number', 'document_date', 'posting_date', 'due_date', 'balance_side', 'original_amount',
        'tax_code_id', 'tax_kind', 'tax_rate', 'tax_base', 'tax_amount', 'tax_point_date', 'withholding_tax_code_id', 'withholding_rate', 'withholding_base', 'withholding_amount',
    ];

    protected function casts(): array
    {
        return [
            'party_id' => 'integer',
            'document_date' => 'date',
            'posting_date' => 'date',
            'due_date' => 'date',
            'original_amount' => 'decimal:2',
            'tax_code_id' => 'integer', 'tax_rate' => 'decimal:4', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'tax_point_date' => 'date', 'withholding_tax_code_id' => 'integer', 'withholding_rate' => 'decimal:4', 'withholding_base' => 'decimal:2', 'withholding_amount' => 'decimal:2',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function journalEntryLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class);
    }

    public function debitAllocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'debit_open_item_id');
    }

    public function creditAllocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'credit_open_item_id');
    }

    public function taxRealizations(): HasMany
    {
        return $this->hasMany(TaxRealization::class);
    }
}
