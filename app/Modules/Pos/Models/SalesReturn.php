<?php

namespace App\Modules\Pos\Models;

use App\Models\AuditLog;
use App\Models\Concerns\HasDocumentBranch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Models\BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    use HasDocumentBranch;

    protected $table = 'pos_sales_returns';

    protected $fillable = ['warehouse_id', 'branch_id', 'physical_sale_id', 'document_number', 'document_date', 'posting_date', 'reason', 'party_code', 'party_name', 'party_address', 'total_amount', 'journal_entry_id', 'cogs_journal_entry_id', 'refund_bank_account_id', 'refund_amount', 'status', 'posted_by', 'posted_at', 'void_reason', 'reversal_key', 'reversal_revision', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['physical_sale_id' => 'integer', 'journal_entry_id' => 'integer', 'cogs_journal_entry_id' => 'integer', 'refund_bank_account_id' => 'integer', 'reversal_revision' => 'integer', 'document_date' => 'date', 'posting_date' => 'date', 'posted_at' => 'datetime', 'total_amount' => 'decimal:2', 'refund_amount' => 'decimal:2'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PhysicalSale::class, 'physical_sale_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class)->orderBy('line_number');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function cogsJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'cogs_journal_entry_id');
    }

    public function refundBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'refund_bank_account_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'subject_id')->where('subject_type', self::class);
    }
}
