<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $fillable = ['account_id', 'tax_code_id', 'subledger_type', 'subledger_id', 'line_number', 'description', 'debit', 'credit', 'tax_base', 'tax_amount', 'tax_point_date', 'tax_settlement_date'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'tax_point_date' => 'date', 'tax_settlement_date' => 'date'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
