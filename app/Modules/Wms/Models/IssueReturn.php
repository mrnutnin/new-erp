<?php

namespace App\Modules\Wms\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class IssueReturn extends Model
{
    use HasDocumentBranch, SoftDeletes;

    protected $table = 'wms_issue_returns';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d', 'reversed_by' => 'integer', 'reversed_at' => 'datetime', 'reversal_revision' => 'integer'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(IssueDocument::class, 'issue_document_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IssueReturnLine::class, 'return_id')->orderBy('line_number');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DocumentPhoto::class, 'document_id')->where('document_type', 'ISSUE_RETURN')->orderBy('id');
    }
}
