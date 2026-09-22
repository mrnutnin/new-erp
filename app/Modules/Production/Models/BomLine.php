<?php

namespace App\Modules\Production\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class BomLine extends Model
{
    protected $table = 'production_bom_lines';

    protected $fillable = ['bom_revision_id', 'line_number', 'component_item_id', 'uom_id', 'quantity', 'notes'];

    protected function casts(): array
    {
        return ['bom_revision_id' => 'integer', 'line_number' => 'integer', 'component_item_id' => 'integer', 'uom_id' => 'integer', 'quantity' => 'decimal:8'];
    }

    protected static function booted(): void
    {
        $mutable = function (self $line): void {
            if ($line->revision()->where('status', '!=', 'DRAFT')->exists()) {
                throw new LogicException('Active or inactive BOM lines are immutable. Copy a new revision instead.');
            }
        };
        static::updating($mutable);
        static::deleting($mutable);
    }

    public function revision() { return $this->belongsTo(BomRevision::class, 'bom_revision_id'); }
    public function componentItem() { return $this->belongsTo(Item::class, 'component_item_id'); }
    public function uom() { return $this->belongsTo(Uom::class, 'uom_id'); }
    public function substitutes() { return $this->hasMany(BomLineSubstitute::class, 'bom_line_id')->orderBy('priority'); }
}
