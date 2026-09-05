<?php

namespace App\Modules\Platform\Support;

use Illuminate\Validation\ValidationException;

final class DocumentTemplateVersionContract
{
    public static function publish(array $source): array
    {
        if ((int) ($source['template_id'] ?? 0) < 1 || (int) ($source['version'] ?? 0) < 1) {
            throw ValidationException::withMessages(['version' => 'Template version identity ไม่ครบ']);
        }
        if (($source['status'] ?? null) !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Publish ได้เฉพาะ Template version แบบ DRAFT']);
        }
        $definition = $source['definition'] ?? null;
        if (! is_array($definition) || ! isset($definition['sections']) || ! is_array($definition['sections'])) {
            throw ValidationException::withMessages(['definition' => 'Template definition ต้องมี sections เป็น array']);
        }

        return ['template_id' => (int) $source['template_id'], 'version' => (int) $source['version'], 'status' => 'PUBLISHED', 'published' => true];
    }
}
