<?php

namespace App\Modules\Pos\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class CreditLimitPolicy
{
    private function __construct() {}

    public static function assertWithinLimit(string $limit, string $exposure, string $invoiceAmount): void
    {
        $limit = BigDecimal::of($limit)->toScale(2, RoundingMode::HALF_UP);
        if ($limit->isLessThanOrEqualTo(0)) {
            return; // 0 means no credit ceiling configured.
        }

        $projected = BigDecimal::of($exposure)->plus(BigDecimal::of($invoiceAmount))->toScale(2, RoundingMode::HALF_UP);
        if ($projected->isGreaterThan($limit)) {
            throw new InvalidArgumentException("ยอดลูกหนี้รวม {$projected} เกินวงเงินเครดิต {$limit}");
        }
    }
}
