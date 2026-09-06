<?php

namespace App\Modules\Platform\Services;

use App\Modules\Settings\Services\GlobalSettings;
use InvalidArgumentException;

final class ModuleCapability
{
    public const PRODUCTION = 'production';

    public const ASSET = 'asset';

    public const LOGISTICS = 'logistics';

    public function __construct(private readonly GlobalSettings $settings) {}

    public function businessProfile(): string
    {
        $profile = $this->settings->value('business_profile');

        return in_array($profile, ['TRADING', 'MANUFACTURING'], true) ? $profile : 'TRADING';
    }

    public function isEnabled(string $module): bool
    {
        return match ($module) {
            self::PRODUCTION => $this->businessProfile() === 'MANUFACTURING'
                && $this->settings->value('production_enabled') === true,
            self::ASSET => $this->settings->value('asset_enabled') === true,
            default => throw new InvalidArgumentException("Unknown module capability [{$module}]."),
        };
    }

    public function isProgramAvailable(string $program): bool
    {
        return match ($program) {
            self::PRODUCTION => $this->isEnabled(self::PRODUCTION),
            self::ASSET => $this->isEnabled(self::ASSET),
            // Logistics is intentionally hidden while the MVP scope is limited.
            self::LOGISTICS => false,
            default => true,
        };
    }
}
