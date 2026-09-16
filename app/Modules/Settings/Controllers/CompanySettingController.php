<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\FileStorageService;
use App\Modules\Settings\Requests\UpdateCompanySettingRequest;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Settings\Support\SettingRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CompanySettingController extends Controller
{
    public function edit(GlobalSettings $globalSettings, SettingRegistry $registry): View
    {
        $setting = $globalSettings->current();
        $readiness = collect(SettingRegistry::REQUIRED)->mapWithKeys(fn (array $keys, string $module) => [
            $module => collect($globalSettings->missingFor($module))->map(fn (string $key) => $registry->definition($key)['name'])->all(),
        ]);

        return view('Settings::company.edit', compact('setting', 'readiness'));
    }

    public function logo(GlobalSettings $globalSettings, FileStorageService $storage): StreamedResponse
    {
        $setting = $globalSettings->current();
        abort_unless($setting->logo_path, 404);
        $disk = $setting->logo_disk ?: 'public';
        $filesystem = Storage::disk($disk);
        abort_unless($filesystem->exists($setting->logo_path), 404);
        $mimeType = $filesystem->mimeType($setting->logo_path) ?: 'image/png';

        return $storage->inline($disk, $setting->logo_path, 'company-logo', $mimeType);
    }

    public function update(UpdateCompanySettingRequest $request, AuditLogger $audit, GlobalSettings $globalSettings, FileStorageService $storage): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();
        $logo = $request->file('logo');
        $values = Arr::except($validated, ['change_reason', 'logo']);
        $storedLogo = null;
        if ($logo) {
            $storedLogo = $storage->store($logo, 'company-logo', (string) ($validated['tax_id'] ?: 'company'));
            $values['logo_disk'] = $storedLogo['disk'];
            $values['logo_path'] = $storedLogo['path'];
        }
        $oldVersion = null;
        $oldLogoDisk = null;
        $oldLogoPath = null;

        try {
            DB::transaction(function () use ($audit, $request, $validated, $values, &$oldVersion, &$oldLogoDisk, &$oldLogoPath) {
                $setting = CompanySetting::query()->lockForUpdate()->findOrFail(1);
                $oldVersion = $setting->settings_version;
                $oldLogoDisk = $setting->logo_disk ?: 'public';
                $oldLogoPath = $setting->logo_path;
                $before = $setting->only(array_keys($values));

                $setting->update([
                    ...$values,
                    'settings_version' => $oldVersion + 1,
                    'updated_by' => $request->user()->id,
                ]);

                DB::table('company_setting_versions')->insert([
                    'company_setting_id' => $setting->id,
                    'version' => $setting->settings_version,
                    'effective_from' => $setting->effective_from,
                    'values' => json_encode($setting->only(array_keys(SettingRegistry::DEFINITIONS)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'change_reason' => $validated['change_reason'],
                    'changed_by' => $request->user()->id,
                    'created_at' => now(),
                ]);

                $audit->record('settings.company.updated', $setting, $before, [
                    ...$values,
                    'settings_version' => $setting->settings_version,
                    'change_reason' => $validated['change_reason'],
                ], $request->user(), $request);
            });
        } catch (Throwable $exception) {
            if ($storedLogo) {
                try {
                    $storage->delete($storedLogo['disk'], $storedLogo['path']);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            throw $exception;
        }

        if ($oldLogoPath && isset($values['logo_path']) && $oldLogoPath !== $values['logo_path']) {
            try {
                $storage->delete($oldLogoDisk, $oldLogoPath);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        DB::afterCommit(fn () => $globalSettings->forget($oldVersion));

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'msg' => 'บันทึก Global Setting แล้ว',
            ]);
        }

        return redirect()->route('settings.company.edit')->with('success', 'บันทึก Global Setting แล้ว');
    }
}
