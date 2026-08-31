<?php

namespace App\Modules\Pos\Models;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Models\BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommissionPayoutBatch extends Model
{
    protected $table = 'pos_sales_commission_payout_batches';

    protected $fillable = ['payment_batch_id', 'document_number', 'branch_id', 'warehouse_id', 'recipient_user_id', 'bank_account_id', 'currency_code', 'document_date', 'total_amount', 'status', 'journal_entry_id', 'created_by', 'posted_by', 'posted_at', 'voided_by', 'voided_at', 'void_reason', 'reversed_by', 'reversed_at', 'reversal_reason'];

    protected function casts(): array
    {
        return ['payment_batch_id' => 'integer', 'branch_id' => 'integer', 'warehouse_id' => 'integer', 'recipient_user_id' => 'integer', 'bank_account_id' => 'integer', 'journal_entry_id' => 'integer', 'total_amount' => 'decimal:2', 'document_date' => 'date', 'posted_at' => 'datetime', 'voided_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(CommissionPaymentBatch::class, 'payment_batch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommissionPayoutLine::class, 'payout_batch_id');
    }
}
