<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Pos\Models\PhysicalSale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvanceDepositApplication extends Model
{
    protected $table = 'finance_advance_deposit_applications';

    protected $fillable = [
        'advance_deposit_id', 'open_item_id', 'physical_sale_id', 'application_date', 'amount', 'source_type', 'source_id', 'journal_entry_id', 'reversal_journal_entry_id',
        'idempotency_key', 'application_hash', 'created_by', 'reversed_by', 'reversed_at', 'reversal_date',
        'reversal_reason', 'reversal_key',
    ];

    protected function casts(): array
    {
        return ['application_date' => 'date', 'amount' => 'decimal:2', 'reversed_at' => 'datetime', 'reversal_date' => 'date'];
    }

    public function advanceDeposit(): BelongsTo
    {
        return $this->belongsTo(AdvanceDeposit::class);
    }

    public function openItem(): BelongsTo
    {
        return $this->belongsTo(OpenItem::class);
    }

    public function physicalSale(): BelongsTo
    {
        return $this->belongsTo(PhysicalSale::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
