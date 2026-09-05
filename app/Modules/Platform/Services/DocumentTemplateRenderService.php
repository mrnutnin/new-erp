<?php

namespace App\Modules\Platform\Services;

use App\Models\CompanySetting;
use App\Modules\Platform\Models\DocumentTemplateVersion;
use App\Modules\Platform\Support\NormalizedDocumentPayloadContract;

final class DocumentTemplateRenderService
{
    public function render(string $documentType, array $definition, array $payload): string
    {
        $normalized = NormalizedDocumentPayloadContract::normalize($documentType, $payload);
        return view('Platform::document-templates.render', ['definition' => $definition, 'payload' => $normalized])->render();
    }

    public function renderDefault(CompanySetting $company, string $documentType, array $payload): ?string
    {
        $version = app(DocumentTemplateService::class)->resolveDefault($company, $documentType);
        return $version ? $this->render($documentType, $version->definition, $payload) : null;
    }
}
