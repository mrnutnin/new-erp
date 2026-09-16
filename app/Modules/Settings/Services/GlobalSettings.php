<?php

namespace App\Modules\Settings\Services;

use App\Models\CompanySetting;
use App\Modules\Settings\Support\SettingRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GlobalSettings
{
    private const VERSION_CACHE_KEY = 'global-settings.version';

    public function __construct(private readonly SettingRegistry $registry) {}

    public function current(): CompanySetting
    {
        CompanySetting::query()->firstOrCreate(['id' => 1], [
            'company_name' => config('app.name'),
            'effective_from' => now()->toDateString(),
        ]);

        $version = Cache::rememberForever(self::VERSION_CACHE_KEY, fn () => (int) CompanySetting::query()->whereKey(1)->value('settings_version'));

        return Cache::rememberForever("global-settings.v{$version}", fn () => CompanySetting::query()->findOrFail(1));
    }

    public function value(string $key): mixed
    {
        $definition = $this->registry->definition($key);

        return $this->current()->getAttribute($key) ?? $definition['default'];
    }

    public function missingFor(string $module): array
    {
        return $this->registry->missing($module, $this->current()->getAttributes());
    }

    public function logoDataUri(): ?string
    {
        $setting = $this->current();
        if (! $setting->logo_path) {
            return null;
        }

        try {
            $storage = Storage::disk($setting->logo_disk ?: 'public');
            if (! $storage->exists($setting->logo_path)) {
                return null;
            }

            $mimeType = $storage->mimeType($setting->logo_path) ?: 'image/png';

            return 'data:'.$mimeType.';base64,'.base64_encode($storage->get($setting->logo_path));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function forget(int $version): void
    {
        Cache::forget(self::VERSION_CACHE_KEY);
        Cache::forget("global-settings.v{$version}");
    }
}
