<?php

namespace App\Modules\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use SoftDeletes;

    protected $table = 'pos_promotions';

    protected $fillable = [
        'code', 'name', 'application_scope', 'stackable', 'currency', 'customer_group_code',
        'bill_discount_amount', 'bill_discount_percent', 'priority', 'campaign_budget_amount', 'campaign_target_sales_amount', 'campaign_target_gross_profit_amount', 'campaign_owner_id',
        'effective_from', 'effective_to', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'stackable' => 'boolean',
            'bill_discount_amount' => 'decimal:4',
            'bill_discount_percent' => 'decimal:4',
            'priority' => 'integer',
            'campaign_budget_amount' => 'decimal:2',
            'campaign_target_sales_amount' => 'decimal:2',
            'campaign_target_gross_profit_amount' => 'decimal:2',
            'campaign_owner_id' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function campaignOwner()
    {
        return $this->belongsTo(User::class, 'campaign_owner_id');
    }

    public function campaignCosts(): HasMany
    {
        return $this->hasMany(PromotionCampaignCost::class);
    }
}
