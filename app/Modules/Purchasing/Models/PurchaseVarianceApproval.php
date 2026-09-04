<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Purchasing\Support\PurchaseThreeWayMatchPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseVarianceApproval extends Model
{
    protected $table = 'wms_purchase_variance_approvals';
    protected $fillable = ['purchase_document_id','status','revision','actor_id','acted_at','reason','policy_snapshot','match_snapshot','evidence_hash','recovery_hint'];
    protected function casts(): array { return ['acted_at'=>'datetime','policy_snapshot'=>'array','match_snapshot'=>'array','revision'=>'integer']; }
    public function document(): BelongsTo { return $this->belongsTo(PurchaseDocument::class, 'purchase_document_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
    public static function evidenceHash(array $match, PurchaseThreeWayMatchPolicy $policy): string { return hash('sha256', json_encode(['blockers'=>array_values($match['blockers'] ?? []),'lines'=>$match['lines'] ?? [],'policy'=>['quantity_tolerance'=>$policy->quantityTolerance,'price_tolerance'=>$policy->priceTolerance,'require_approval_on_variance'=>$policy->requireApprovalOnVariance,'block_on_variance'=>$policy->blockOnVariance]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }
}
