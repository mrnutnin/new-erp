<?php

namespace App\Modules\Platform\Services;

use App\Modules\Settings\Services\GlobalSettings;
use InvalidArgumentException;

final class ModuleCapability
{
    public const PRODUCTION = 'production';

    public function __construct(private readonly GlobalSettings $settings) {}

    public function businessProfile(): string
    {
        $profile = $this->settings->value('business_profile');

        return in_array($profile, ['TRADING', 'MANUFACTURING'], true) ? $profile : 'TRADING';
    }

    public function isEnabled(string $module): bool
    {
        if ($module !== self::PRODUCTION) {
            throw new InvalidArgumentException("Unknown module capability [{$module}].");
        }

        return $this->businessProfile() === 'MANUFACTURING'
            && $this->settings->value('production_enabled') === true;
    }

    public function isProgramAvailable(string $program): bool
    {
        return $program === self::PRODUCTION ? $this->isEnabled(self::PRODUCTION) : true;
    }
}
