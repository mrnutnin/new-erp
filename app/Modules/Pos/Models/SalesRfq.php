<?php

namespace App\Modules\Pos\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesRfq extends Model
{
    use HasDocumentBranch;

    protected $fillable = [
        'warehouse_id', 'branch_id', 'document_number', 'party_id', 'source_sales_intake_id', 'party_code', 'party_name',
        'party_tax_id', 'party_branch_code', 'party_address', 'document_date',
        'valid_until', 'status', 'subtotal', 'discount_amount', 'promotion_snapshot', 'promotion_discount_amount', 'total_amount', 'description', 'sent_by', 'sent_at', 'closed_by',
        'closed_at', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'reviewed_by', 'reviewed_at', 'review_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'promotion_snapshot' => 'array',
            'promotion_discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesRfqLine::class)->orderBy('line_number');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function sourceIntake(): BelongsTo
    {
        return $this->belongsTo(SalesIntake::class, 'source_sales_intake_id');
    }

    public function quotation(): HasOne
    {
        return $this->hasOne(SalesQuotation::class, 'sales_rfq_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(SalesOrder::class, 'sales_rfq_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
