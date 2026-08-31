<?php

namespace Tests\Unit;

use App\Modules\Finance\Support\PaymentDueDate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentDueDateTest extends TestCase
{
    public function test_due_on_date_adds_credit_days(): void
    {
        $this->assertSame('2026-09-19', PaymentDueDate::calculate('2026-08-20', 'DUE_ON_DATE', 30));
    }

    public function test_end_of_month_adds_days_before_moving_to_month_end(): void
    {
        $this->assertSame('2024-02-29', PaymentDueDate::calculate('2024-01-30', 'END_OF_MONTH', 5));
        $this->assertSame('2026-08-31', PaymentDueDate::calculate('2026-08-20', 'END_OF_MONTH', 0));
    }

    #[DataProvider('invalidInputs')]
    public function test_it_rejects_invalid_inputs(string $date, string $rule, mixed $days): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentDueDate::calculate($date, $rule, $days);
    }

    public static function invalidInputs(): array
    {
        return [
            'invalid calendar date' => ['2026-02-29', 'DUE_ON_DATE', 0],
            'wrong date format' => ['20/08/2026', 'DUE_ON_DATE', 0],
            'year zero' => ['0000-08-20', 'DUE_ON_DATE', 0],
            'unknown rule' => ['2026-08-20', 'NET_DAYS', 0],
            'negative days' => ['2026-08-20', 'DUE_ON_DATE', -1],
            'too many days' => ['2026-08-20', 'DUE_ON_DATE', 3651],
            'numeric string days' => ['2026-08-20', 'DUE_ON_DATE', '30'],
        ];
    }
}
