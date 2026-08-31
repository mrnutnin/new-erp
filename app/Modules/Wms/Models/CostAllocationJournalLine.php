<?php

namespace App\Modules\Wms\Models;

use App\Modules\Accounting\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CostAllocationJournalLine extends Model
{
    protected $table = 'wms_cost_allocation_journal_lines';

    protected $fillable = ['allocation_id', 'journal_entry_line_id', 'revision', 'identity_key'];

    protected function casts(): array
    {
        return ['allocation_id' => 'integer', 'journal_entry_line_id' => 'integer', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Cost allocation Journal linkage is immutable. Create a new revision.'));
        static::deleting(fn (): never => throw new LogicException('Cost allocation Journal linkage cannot be deleted.'));
    }

    public function allocation()
    {
        return $this->belongsTo(CostAllocation::class, 'allocation_id');
    }

    public function journalEntryLine()
    {
        return $this->belongsTo(JournalEntryLine::class, 'journal_entry_line_id');
    }
}
