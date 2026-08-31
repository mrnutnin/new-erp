<?php

namespace App\Modules\Wms\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class IssueDocument extends Model
{
    use HasDocumentBranch, SoftDeletes;

    protected $table = 'wms_issue_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(IssueLine::class, 'document_id')->orderBy('line_number');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
