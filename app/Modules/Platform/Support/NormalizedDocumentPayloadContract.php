<?php

namespace App\Modules\Platform\Support;

use Illuminate\Validation\ValidationException;

final class NormalizedDocumentPayloadContract
{
    public static function normalize(string $documentType, array $payload): array
    {
        foreach (['company', 'document', 'lines', 'totals'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw ValidationException::withMessages([$key => "Document payload ต้องมี {$key}"]);
            }
        }
        if (! is_array($payload['company']) || ! is_array($payload['document']) || ! is_array($payload['lines']) || ! is_array($payload['totals'])) {
            throw ValidationException::withMessages(['payload' => 'Document payload มีโครงสร้างไม่ถูกต้อง']);
        }

        return ['document_type' => strtoupper(trim($documentType)), 'company' => $payload['company'], 'party' => is_array($payload['party'] ?? null) ? $payload['party'] : [], 'document' => $payload['document'], 'lines' => array_values($payload['lines']), 'totals' => $payload['totals'], 'signatures' => is_array($payload['signatures'] ?? null) ? $payload['signatures'] : []];
    }
}
