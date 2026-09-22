<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class BomRevision extends Model
{
    protected $table = 'production_bom_revisions';

    protected $fillable = ['bom_id', 'revision_number', 'status', 'effective_from', 'effective_to', 'notes', 'activated_at', 'activated_by', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['bom_id' => 'integer', 'revision_number' => 'integer', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'activated_at' => 'datetime', 'activated_by' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if ($revision->getOriginal('status') === 'ACTIVE' && $revision->isDirty(array_diff(array_keys($revision->getDirty()), ['status', 'effective_to', 'updated_by', 'updated_at']))) {
                throw new LogicException('Active BOM revision is immutable. Copy a new revision instead.');
            }
        });
        static::deleting(function (self $revision): void {
            if ($revision->status !== 'DRAFT') {
                throw new LogicException('Only a draft BOM revision can be deleted.');
            }
        });
    }

    public function bom() { return $this->belongsTo(Bom::class, 'bom_id'); }
    public function lines() { return $this->hasMany(BomLine::class, 'bom_revision_id')->orderBy('line_number'); }
    public function operations() { return $this->hasMany(BomOperation::class, 'bom_revision_id')->orderBy('sequence'); }
    public function activator() { return $this->belongsTo(User::class, 'activated_by'); }
}
