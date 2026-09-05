<?php

namespace App\Modules\Platform\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DocumentTemplateVersion extends Model
{
    protected $table = 'platform_document_template_versions';
    protected $fillable = ['template_id', 'version', 'status', 'schema_version', 'definition', 'published_by', 'published_at'];
    protected function casts(): array { return ['version' => 'integer', 'definition' => 'array', 'published_at' => 'datetime']; }

    public function template() { return $this->belongsTo(DocumentTemplate::class, 'template_id'); }
    public function publishedBy() { return $this->belongsTo(User::class, 'published_by'); }
}
