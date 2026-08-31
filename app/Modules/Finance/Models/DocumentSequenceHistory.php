<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DocumentSequenceHistory extends Model
{
    protected $table = 'finance_document_sequence_histories';

    protected $fillable = ['document_sequence_id', 'source_type', 'source_id', 'document_number', 'document_date', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'document_date' => 'date'];
    }

    public function sequence()
    {
        return $this->belongsTo(DocumentSequence::class, 'document_sequence_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
