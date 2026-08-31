<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\CreditLimitPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CreditLimitPolicyTest extends TestCase
{
    public function test_zero_limit_means_unlimited(): void
    {
        CreditLimitPolicy::assertWithinLimit('0.00', '999999.00', '1.00');
        $this->addToAssertionCount(1);
    }

    public function test_projected_exposure_over_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CreditLimitPolicy::assertWithinLimit('1000.00', '900.00', '100.01');
    }
}
