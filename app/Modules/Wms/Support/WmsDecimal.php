<?php

namespace App\Modules\Wms\Support;

use App\Modules\Settings\Services\GlobalSettings;

/**
 * Presentation/input precision for WMS.
 *
 * Inventory costing keeps its internal decimal precision (8 places) so that
 * AVG/FIFO and unit conversions remain exact.  User-facing WMS values use the
 * company's configured decimal setting.
 */
final class WmsDecimal
{
    public static function places(): int
    {
        return max(0, min(4, (int) app(GlobalSettings::class)->value('tax_decimal_places')));
    }

    public static function format(mixed $value, ?int $places = null): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, $places ?? self::places(), '.', ',');
    }

    public static function rule(string $field = 'numeric'): array
    {
        $places = self::places();

        return [$field, 'decimal:0,'.$places];
    }
}
