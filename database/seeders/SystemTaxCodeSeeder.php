<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Database\Seeder;

class SystemTaxCodeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::definitions() as $code => $values) {
            $taxCode = TaxCode::query()->withTrashed()->firstOrNew(['code' => $code]);
            $taxCode->fill([...$values, 'is_active' => true]);
            $taxCode->deleted_at = null;
            $taxCode->save();
        }
    }

    /** @return array<string, array{name:string, kind:string, rate:int}> */
    public static function definitions(): array
    {
        return [
            'NONE' => ['name' => 'ไม่คิด VAT', 'kind' => 'NONE_VAT', 'rate' => 0],
            'VAT7-OUT' => ['name' => 'VAT ขาย 7%', 'kind' => 'VAT_OUT', 'rate' => 7],
            'VAT7-IN' => ['name' => 'VAT ซื้อ 7%', 'kind' => 'VAT_IN', 'rate' => 7],
            'WHT3' => ['name' => 'หัก ณ ที่จ่าย 3%', 'kind' => 'WHT', 'rate' => 3],
        ];
    }
}
