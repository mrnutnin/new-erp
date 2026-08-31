<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Pos\Models\SalesCommissionPlan;
use Illuminate\Database\Seeder;

/** Optional local fixture: php artisan db:seed --class=PosSalesCommissionMockupSeeder */
final class PosSalesCommissionMockupSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $branch = Branch::query()->where('is_active', true)->orderBy('id')->firstOrFail();

        foreach ([
            ['COMM-POSTED-3-MOCK', 'คอมมิชชั่นยอดขาย 3% (Mockup)', 'POSTED_SALE', '3.0000', true],
            ['COMM-COLLECTED-2-MOCK', 'คอมมิชชั่นยอดรับชำระ 2% (Mockup)', 'COLLECTED_RECEIPT', '2.0000', false],
            ['COMM-GP-5-MOCK', 'คอมมิชชั่นกำไรขั้นต้น 5% (Mockup)', 'GROSS_PROFIT', '5.0000', false],
        ] as [$code, $name, $basis, $rate, $isActive]) {
            $plan = SalesCommissionPlan::query()->withTrashed()->updateOrCreate(['code' => $code], [
                'name' => $name, 'basis' => $basis, 'rate' => $rate, 'is_active' => $isActive,
                'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            $plan->restore();
            $plan->assignments()->updateOrCreate(['user_id' => $user->id, 'branch_id' => $branch->id]);
        }
    }
}
