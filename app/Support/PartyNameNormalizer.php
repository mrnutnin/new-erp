<?php

namespace App\Support;

final class PartyNameNormalizer
{
    public static function normalize(?string $name):string
    {
        $value=mb_strtolower(trim((string)$name));
        if(class_exists(\Normalizer::class))$value=\Normalizer::normalize($value,\Normalizer::FORM_C)?:$value;
        $value=preg_replace('/\b(public\s+company\s+limited|company|corporation|incorporated|limited|corp|co|ltd|inc)\b/u','',$value)??$value;
        $value=str_replace(['ห้างหุ้นส่วนจำกัด','บริษัทมหาชนจำกัด','บริษัท','มหาชน','จำกัด','หจก','บจก'], '',$value);
        return preg_replace('/[^\p{L}\p{M}\p{N}]+/u','',$value)??'';
    }
}
