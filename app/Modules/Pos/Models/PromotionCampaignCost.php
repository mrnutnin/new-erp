<?php

namespace App\Modules\Pos\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PromotionCampaignCost extends Model
{
    protected $table = 'pos_promotion_campaign_costs';

    protected $fillable = ['promotion_id', 'branch_id', 'cost_date', 'amount', 'reference', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['promotion_id' => 'integer', 'branch_id' => 'integer', 'cost_date' => 'date', 'amount' => 'decimal:2', 'created_by' => 'integer'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
