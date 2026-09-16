<?php

namespace App\Modules\Accounting\Support;

final class ThaiBahtText
{
    /** @var array<int, string> */
    private const DIGITS = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];

    /** @var array<int, string> */
    private const PLACES = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];

    public static function convert(string|int|float $amount): string
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$baht, $satang] = explode('.', $normalized);
        $baht = ltrim($baht, '0') ?: '0';
        $text = self::integer($baht).'บาท';

        return $satang === '00' ? $text.'ถ้วน' : $text.self::integer(ltrim($satang, '0') ?: '0').'สตางค์';
    }

    private static function integer(string $number): string
    {
        $number = ltrim($number, '0') ?: '0';
        if ($number === '0') {
            return 'ศูนย์';
        }

        if (strlen($number) > 6) {
            $head = substr($number, 0, -6);
            $tail = substr($number, -6);

            return self::integer($head).'ล้าน'.($tail === '000000' ? '' : self::integer($tail));
        }

        $result = '';
        $length = strlen($number);
        foreach (str_split($number) as $index => $character) {
            $digit = (int) $character;
            $place = $length - $index - 1;
            if ($digit === 0) {
                continue;
            }
            if ($place === 1 && $digit === 1) {
                $result .= 'สิบ';
            } elseif ($place === 1 && $digit === 2) {
                $result .= 'ยี่สิบ';
            } elseif ($place === 0 && $digit === 1 && $length > 1) {
                $result .= 'เอ็ด';
            } else {
                $result .= self::DIGITS[$digit].self::PLACES[$place];
            }
        }

        return $result;
    }
}
