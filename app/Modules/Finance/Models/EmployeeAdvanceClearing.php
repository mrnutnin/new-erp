<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Finance\Models\PettyCashAttachment;

class EmployeeAdvanceClearing extends Model
{
    use SoftDeletes;
    protected $table = 'finance_employee_advance_clearings';
    protected $fillable = ['branch_id', 'warehouse_id', 'employee_advance_id', 'document_number', 'document_date', 'description', 'is_final', 'expense_amount', 'vat_amount', 'wht_amount', 'net_expense_amount', 'refund_amount', 'additional_amount', 'status', 'journal_entry_id', 'reversal_journal_entry_id', 'idempotency_key', 'reversal_key', 'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'reversed_by', 'reversed_at', 'reversal_reason', 'voided_by', 'voided_at', 'void_reason'];
    protected function casts(): array { return ['document_date' => 'date', 'is_final' => 'boolean', 'expense_amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'wht_amount' => 'decimal:2', 'net_expense_amount' => 'decimal:2', 'refund_amount' => 'decimal:2', 'additional_amount' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'voided_at' => 'datetime']; }
    public function advance(): BelongsTo { return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function lines(): HasMany { return $this->hasMany(EmployeeAdvanceClearingLine::class, 'clearing_id')->orderBy('line_number'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function reversalJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function attachments(): HasMany { return $this->hasMany(PettyCashAttachment::class, 'subject_id')->where('subject_type', 'EMPLOYEE_ADVANCE_CLEARING'); }
}
