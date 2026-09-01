<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDisposalLine extends Model
{
    protected $fillable = ['asset_disposal_id', 'asset_id', 'original_status', 'cost', 'accumulated_depreciation', 'accumulated_impairment', 'carrying_amount', 'proceeds', 'gain_loss'];
    protected function casts(): array { return ['cost' => 'decimal:2', 'accumulated_depreciation' => 'decimal:2', 'accumulated_impairment' => 'decimal:2', 'carrying_amount' => 'decimal:2', 'proceeds' => 'decimal:2', 'gain_loss' => 'decimal:2']; }
    public function disposal(): BelongsTo { return $this->belongsTo(AssetDisposal::class, 'asset_disposal_id'); }
    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
}
