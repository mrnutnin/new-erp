<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\ThaiBahtText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThaiBahtTextTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            ['0', 'ศูนย์บาทถ้วน'],
            ['1', 'หนึ่งบาทถ้วน'],
            ['21.25', 'ยี่สิบเอ็ดบาทยี่สิบห้าสตางค์'],
            ['101.00', 'หนึ่งร้อยเอ็ดบาทถ้วน'],
            ['1000000.00', 'หนึ่งล้านบาทถ้วน'],
            ['1000001.01', 'หนึ่งล้านหนึ่งบาทหนึ่งสตางค์'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_converts_money_to_thai_text(string $amount, string $expected): void
    {
        self::assertSame($expected, ThaiBahtText::convert($amount));
    }
}
