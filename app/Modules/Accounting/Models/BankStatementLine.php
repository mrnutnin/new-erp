<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementLine extends Model
{
    protected $table = 'accounting_bank_statement_lines';

    protected $fillable = ['bank_statement_id', 'line_number', 'transaction_date', 'description', 'reference', 'amount', 'running_balance', 'status', 'matched_journal_entry_line_id'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'amount' => 'decimal:2', 'running_balance' => 'decimal:2'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function matchedJournalLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class, 'matched_journal_entry_line_id');
    }
}
