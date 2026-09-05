<?php

namespace App\Modules\Platform\Services;

use App\Models\CompanySetting;
use App\Models\User;
use App\Modules\Platform\Models\DocumentTemplate;
use App\Modules\Platform\Models\DocumentTemplateVersion;
use App\Modules\Platform\Support\DocumentTemplateVersionContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DocumentTemplateService
{
    public function createTemplate(CompanySetting $company, string $documentType, string $name, User $actor, bool $default = false): DocumentTemplate
    {
        $documentType = strtoupper(trim($documentType));
        $name = trim($name);
        if ($documentType === '' || $name === '') {
            throw ValidationException::withMessages(['name' => 'Template ต้องมีประเภทเอกสารและชื่อ']);
        }

        return DB::transaction(function () use ($company, $documentType, $name, $actor, $default): DocumentTemplate {
            if ($default) {
                DocumentTemplate::query()->where('company_setting_id', $company->id)->where('document_type', $documentType)->update(['is_default' => false]);
            }
            return DocumentTemplate::query()->create(['company_setting_id' => $company->id, 'document_type' => $documentType, 'name' => $name, 'is_default' => $default, 'status' => 'ACTIVE', 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        }, 3);
    }

    public function createVersion(DocumentTemplate $template, array $definition, User $actor): DocumentTemplateVersion
    {
        if (! isset($definition['sections']) || ! is_array($definition['sections'])) {
            throw ValidationException::withMessages(['definition' => 'Template definition ต้องมี sections เป็น array']);
        }

        return DB::transaction(function () use ($template, $definition, $actor): DocumentTemplateVersion {
            $locked = DocumentTemplate::query()->where('company_setting_id', $template->company_setting_id)->lockForUpdate()->findOrFail($template->id);
            $version = ((int) $locked->versions()->max('version')) + 1;
            return $locked->versions()->create(['version' => $version, 'status' => 'DRAFT', 'schema_version' => '1.0', 'definition' => $definition]);
        }, 3);
    }

    public function updateDraft(DocumentTemplateVersion $version, array $definition, User $actor): DocumentTemplateVersion
    {
        if (! isset($definition['sections']) || ! is_array($definition['sections'])) {
            throw ValidationException::withMessages(['definition' => 'Template definition ต้องมี sections เป็น array']);
        }

        return DB::transaction(function () use ($version, $definition): DocumentTemplateVersion {
            $locked = DocumentTemplateVersion::query()->with('template')->lockForUpdate()->findOrFail($version->id);
            if ($locked->status !== 'DRAFT') {
                throw ValidationException::withMessages(['version' => 'แก้ไขได้เฉพาะ Template Draft; Published ต้องสร้าง Version ใหม่']);
            }
            $locked->forceFill(['definition' => $definition])->save();
            return $locked->fresh('template');
        }, 3);
    }

    public function createNextVersion(DocumentTemplate $template, User $actor): DocumentTemplateVersion
    {
        return DB::transaction(function () use ($template): DocumentTemplateVersion {
            $locked = DocumentTemplate::query()->with(['versions' => fn ($query) => $query->latest('version')])->lockForUpdate()->findOrFail($template->id);
            $latest = $locked->versions->first();
            return $locked->versions()->create(['version' => ((int) ($latest?->version ?? 0)) + 1, 'status' => 'DRAFT', 'schema_version' => '1.0', 'definition' => $latest?->definition ?? ['sections' => []]]);
        }, 3);
    }

    public function publish(DocumentTemplateVersion $version, User $actor): DocumentTemplateVersion
    {
        return DB::transaction(function () use ($version, $actor): DocumentTemplateVersion {
            $locked = DocumentTemplateVersion::query()->with('template')->lockForUpdate()->findOrFail($version->id);
            DocumentTemplateVersionContract::publish(['template_id' => $locked->template_id, 'version' => $locked->version, 'status' => $locked->status, 'definition' => $locked->definition]);
            DocumentTemplateVersion::query()->where('template_id', $locked->template_id)->where('status', 'PUBLISHED')->update(['status' => 'RETIRED']);
            $locked->forceFill(['status' => 'PUBLISHED', 'published_by' => $actor->id, 'published_at' => now()])->save();

            return $locked->fresh('template');
        }, 3);
    }

    public function retire(DocumentTemplate $template, User $actor): DocumentTemplate
    {
        return DB::transaction(function () use ($template, $actor): DocumentTemplate {
            $locked = DocumentTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $locked->forceFill(['status' => 'ARCHIVED', 'is_default' => false, 'updated_by' => $actor->id])->save();
            $locked->versions()->where('status', 'PUBLISHED')->update(['status' => 'RETIRED']);
            return $locked->fresh('versions');
        }, 3);
    }

    public function resolveDefault(CompanySetting $company, string $documentType): ?DocumentTemplateVersion
    {
        $template = DocumentTemplate::query()->where('company_setting_id', $company->id)->where('document_type', strtoupper(trim($documentType)))->where('status', 'ACTIVE')->where('is_default', true)->first();
        return $template?->versions()->where('status', 'PUBLISHED')->latest('version')->first();
    }
}
