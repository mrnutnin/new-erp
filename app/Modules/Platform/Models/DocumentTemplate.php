<?php

namespace App\Modules\Platform\Models;

use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'platform_document_templates';
    protected $fillable = ['company_setting_id', 'document_type', 'name', 'is_default', 'status', 'created_by', 'updated_by'];
    protected function casts(): array { return ['is_default' => 'boolean']; }

    public function companySetting() { return $this->belongsTo(CompanySetting::class); }
    public function versions() { return $this->hasMany(DocumentTemplateVersion::class, 'template_id'); }
}
