<?php

namespace Tests\Unit;

use App\Support\PartyNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PartyNameNormalizerTest extends TestCase
{
    #[Test]
    #[DataProvider('equivalentNames')]
    public function legal_entity_variants_share_the_same_search_key(string $first,string $second):void
    {
        self::assertSame(PartyNameNormalizer::normalize($first),PartyNameNormalizer::normalize($second));
    }

    public static function equivalentNames():array
    {
        return [
            ['บริษัท อเล็กเซียซอฟต์ จำกัด','อเล็กเซียซอฟต์'],
            ['ห้างหุ้นส่วนจำกัด มิ้นท์ ERP','หจก. มิ้นท์-ERP'],
            ['Alexia Soft Co., Ltd.','alexia-soft company limited'],
        ];
    }

    #[Test]
    public function materially_different_names_remain_different():void
    {
        self::assertNotSame(PartyNameNormalizer::normalize('บริษัท อัลฟ่า จำกัด'),PartyNameNormalizer::normalize('บริษัท เบต้า จำกัด'));
    }
}
