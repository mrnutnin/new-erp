<?php

namespace Tests\Unit;

use App\Modules\Finance\Support\OpenItemBalance;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OpenItemBalanceTest extends TestCase
{
    public function test_it_derives_remaining_status_signed_amount_and_aging_bucket_without_float_math(): void
    {
        $this->assertSame('70.05', OpenItemBalance::remaining('100.10', '30.05'));
        $this->assertSame('OPEN', OpenItemBalance::status('100.10', '0'));
        $this->assertSame('PARTIAL', OpenItemBalance::status('100.10', '30.05'));
        $this->assertSame('CLOSED', OpenItemBalance::status('100.10', '100.10'));
        $this->assertSame('100.10', OpenItemBalance::signed('DEBIT', '100.10'));
        $this->assertSame('-100.10', OpenItemBalance::signed('CREDIT', '100.10'));
        $this->assertSame('CURRENT', OpenItemBalance::agingBucket('2026-08-20', '2026-08-20'));
        $this->assertSame('1_30', OpenItemBalance::agingBucket('2026-08-01', '2026-08-20'));
        $this->assertSame('31_60', OpenItemBalance::agingBucket('2026-07-01', '2026-08-20'));
        $this->assertSame('61_90', OpenItemBalance::agingBucket('2026-06-01', '2026-08-20'));
        $this->assertSame('OVER_90', OpenItemBalance::agingBucket('2026-01-01', '2026-08-20'));

        OpenItemBalance::assertAllocationFitsTimeline('100.00', '2026-08-01', '20.00', [
            ['allocation_date' => '2026-08-01', 'amount' => '80.00', 'reversal_date' => '2026-08-04'],
            ['allocation_date' => '2026-08-05', 'amount' => '80.00'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        OpenItemBalance::remaining('100.00', '100.01');
    }

    public function test_it_rejects_a_backdated_allocation_that_overallocates_at_a_future_point(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OpenItemBalance::assertAllocationFitsTimeline('100.00', '2026-08-01', '30.00', [
            ['allocation_date' => '2026-08-05', 'amount' => '80.00'],
        ]);
    }
}
