<?php

namespace App\Modules\Pos\Models;

use App\Models\Party;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class BillingNote extends Model
{
    use SoftDeletes;

    protected $table = 'pos_billing_notes';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['document_date' => 'date', 'due_date' => 'date', 'total_amount' => 'decimal:2', 'issued_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function lines(): HasMany { return $this->hasMany(BillingNoteLine::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function issuedBy(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
}
