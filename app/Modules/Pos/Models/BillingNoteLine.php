<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BillingNoteLine extends Model
{
    protected $table = 'pos_billing_note_lines';
    protected $guarded = [];

    protected function casts(): array { return ['amount' => 'decimal:2']; }
    public function billingNote(): BelongsTo { return $this->belongsTo(BillingNote::class); }
    public function salesDocument(): BelongsTo { return $this->belongsTo(SalesDocument::class); }
    public function physicalSale(): BelongsTo { return $this->belongsTo(PhysicalSale::class); }
}
