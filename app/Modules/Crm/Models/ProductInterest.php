<?php

namespace App\Modules\Crm\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductInterest extends Model
{
    protected $table='crm_product_interests';
    protected $fillable=['opportunity_id','item_id','uom_id','quantity','target_unit_price','budget_amount','requirements','created_by','updated_by'];
    protected function casts():array{return ['opportunity_id'=>'integer','item_id'=>'integer','uom_id'=>'integer','quantity'=>'decimal:6','target_unit_price'=>'decimal:4','budget_amount'=>'decimal:4'];}
    public function opportunity():BelongsTo{return $this->belongsTo(Opportunity::class);}
    public function item():BelongsTo{return $this->belongsTo(Item::class);}
    public function uom():BelongsTo{return $this->belongsTo(Uom::class);}
}
