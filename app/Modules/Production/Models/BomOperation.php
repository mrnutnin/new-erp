<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

final class BomOperation extends Model
{
    protected $table = 'production_bom_operations';
    protected $fillable = ['bom_revision_id', 'sequence', 'name', 'planned_minutes', 'notes'];
    protected function casts(): array { return ['planned_minutes' => 'integer']; }
    public function revision() { return $this->belongsTo(BomRevision::class, 'bom_revision_id'); }
}
