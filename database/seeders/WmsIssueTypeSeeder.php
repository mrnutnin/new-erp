<?php

namespace Database\Seeders;

use App\Modules\Wms\Models\IssueType;
use Illuminate\Database\Seeder;

/** Installs recommended organization-wide issue types. */
final class WmsIssueTypeSeeder extends Seeder
{
    /** @return array<int, array{code:string,name:string,description:string}> */
    public static function definitions(): array
    {
        return [
            ['code' => 'GENERAL', 'name' => 'เบิกทั่วไป', 'description' => 'เบิกสินค้าเพื่อใช้งานทั่วไป'],
            ['code' => 'PROJECT', 'name' => 'เบิกโครงการ', 'description' => 'เบิกสินค้าเพื่อใช้งานในโครงการ'],
            ['code' => 'PRODUCTION', 'name' => 'เบิกเข้าผลิต', 'description' => 'เบิกวัตถุดิบสำหรับ Manual Production'],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $definition) {
            $type = IssueType::withTrashed()->firstOrNew([
                'warehouse_id' => null,
                'code' => $definition['code'],
            ]);

            if (! $type->exists) {
                $type->fill([
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_active' => true,
                ]);
            }

            $type->deleted_at = null;
            $type->save();
        }
    }
}
