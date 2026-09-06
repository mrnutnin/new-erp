<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('programs')) {
            return;
        }

        $now = now();
        $dashboardId = DB::table('programs')->where('code', 'dashboard')->value('id');

        if ($dashboardId === null) {
            $dashboardId = DB::table('programs')->insertGetId([
                'code' => 'dashboard',
                'name' => 'Dashboard',
                'description' => 'ภาพรวมองค์กรสำหรับผู้บริหาร',
                'requires_branch' => false,
                'requires_warehouse' => false,
                'entry_route' => 'dashboard',
                'is_enabled' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('programs')->where('id', $dashboardId)->update([
                'name' => 'Dashboard',
                'description' => 'ภาพรวมองค์กรสำหรับผู้บริหาร',
                'requires_branch' => false,
                'requires_warehouse' => false,
                'entry_route' => 'dashboard',
                'is_enabled' => true,
                'sort_order' => 0,
                'deleted_at' => null,
                'updated_at' => $now,
            ]);
        }

        DB::table('programs')->where('code', 'logistics')->update([
            'is_enabled' => false,
            'updated_at' => $now,
        ]);

        if (Schema::hasTable('program_user') && Schema::hasTable('users')) {
            $userIds = DB::table('users')->whereNull('deleted_at')->pluck('id');
            foreach ($userIds as $userId) {
                DB::table('program_user')->insertOrIgnore([
                    'program_id' => $dashboardId,
                    'user_id' => $userId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('programs')) {
            return;
        }

        $dashboardId = DB::table('programs')->where('code', 'dashboard')->value('id');
        if ($dashboardId !== null && Schema::hasTable('program_user')) {
            DB::table('program_user')->where('program_id', $dashboardId)->delete();
        }

        DB::table('programs')->where('code', 'dashboard')->delete();
        DB::table('programs')->where('code', 'logistics')->update([
            'is_enabled' => true,
            'updated_at' => now(),
        ]);
    }
};
