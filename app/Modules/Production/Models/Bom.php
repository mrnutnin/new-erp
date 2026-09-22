<?php

namespace App\Modules\Production\Models;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Bom extends Model
{
    use SoftDeletes;

    protected $table = 'production_boms';

    protected $fillable = ['branch_id', 'code', 'name', 'finished_item_id', 'base_uom_id', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['branch_id' => 'integer', 'finished_item_id' => 'integer', 'base_uom_id' => 'integer', 'is_active' => 'boolean', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function branch() { return $this->belongsTo(Branch::class); }
    public function finishedItem() { return $this->belongsTo(Item::class, 'finished_item_id'); }
    public function baseUom() { return $this->belongsTo(Uom::class, 'base_uom_id'); }
    public function revisions() { return $this->hasMany(BomRevision::class, 'bom_id')->orderByDesc('revision_number'); }
    public function activeRevision() { return $this->hasOne(BomRevision::class, 'bom_id')->where('status', 'ACTIVE'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
