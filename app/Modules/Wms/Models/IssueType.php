<?php

namespace App\Modules\Wms\Models;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IssueType extends Model
{
    use SoftDeletes;

    protected $table = 'wms_issue_types';

    protected $fillable = ['warehouse_id', 'code', 'name', 'description', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['warehouse_id' => 'integer', 'is_active' => 'boolean'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
