<?php

namespace App\Modules\Finance\Models;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;

class DocumentSequenceCounter extends Model
{
    protected $table = 'finance_document_sequence_counters';

    protected $fillable = ['document_sequence_id', 'branch_id', 'next_number', 'last_reset_key'];

    protected function casts(): array
    {
        return ['document_sequence_id' => 'integer', 'branch_id' => 'integer', 'next_number' => 'integer'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function sequence()
    {
        return $this->belongsTo(DocumentSequence::class, 'document_sequence_id');
    }
}
