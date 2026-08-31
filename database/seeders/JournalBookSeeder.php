<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\JournalBook;
use Illuminate\Database\Seeder;

class JournalBookSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['code' => 'PJ', 'name' => 'สมุดรายวันซื้อ', 'type' => 'PURCHASE', 'sequence_prefix' => 'PJ', 'sort_order' => 1],
            ['code' => 'SJ', 'name' => 'สมุดรายวันขาย', 'type' => 'SALES', 'sequence_prefix' => 'SJ', 'sort_order' => 2],
            ['code' => 'CR', 'name' => 'สมุดรายวันรับ', 'type' => 'RECEIPT', 'sequence_prefix' => 'CR', 'sort_order' => 3],
            ['code' => 'CP', 'name' => 'สมุดรายวันจ่าย', 'type' => 'PAYMENT', 'sequence_prefix' => 'CP', 'sort_order' => 4],
            ['code' => 'GJ', 'name' => 'สมุดรายวันทั่วไป', 'type' => 'GENERAL', 'sequence_prefix' => 'GJ', 'sort_order' => 5],
        ])->each(function (array $attributes) {
            $book = JournalBook::query()->firstOrNew(['code' => $attributes['code']]);
            $book->fill([...$attributes, 'is_system' => true]);

            if (! $book->exists) {
                $book->is_active = true;
            }

            $book->save();
        });
    }
}
