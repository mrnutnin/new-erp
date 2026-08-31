<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentSequence extends Model
{
    use SoftDeletes;

    protected $table = 'finance_document_sequences';

    protected $fillable = ['warehouse_id', 'document_type', 'name', 'prefix', 'number_format', 'reset_rule', 'next_number', 'last_reset_key', 'is_active', 'number_reuse_policy', 'created_by'];

    protected function casts(): array
    {
        return ['next_number' => 'integer', 'is_active' => 'boolean'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function numberHistory()
    {
        return $this->hasMany(DocumentSequenceHistory::class);
    }

    public function branchCounters()
    {
        return $this->hasMany(DocumentSequenceCounter::class);
    }
}
