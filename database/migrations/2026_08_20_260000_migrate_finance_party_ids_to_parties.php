<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_open_items', function (Blueprint $table) {
            $table->unsignedBigInteger('party_master_id')->nullable()->after('party_type');
        });
        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->unsignedBigInteger('party_master_id')->nullable()->after('party_type');
        });

        $allowed = [
            'CUSTOMER|CUST-001' => ['code' => 'CUST-001', 'name' => 'ลูกค้าตัวอย่าง', 'role' => 'CUSTOMER'],
            'SUPPLIER|SUP-001' => ['code' => 'SUP-001', 'name' => 'Supplier ตัวอย่าง', 'role' => 'SUPPLIER'],
        ];
        $legacy = DB::table('finance_open_items')->select(['party_type', 'party_id'])
            ->union(DB::table('finance_settlements')->select(['party_type', 'party_id'])->whereNotNull('party_id'))
            ->distinct()
            ->get();

        foreach ($legacy as $row) {
            $key = "{$row->party_type}|{$row->party_id}";
            if (! isset($allowed[$key])) {
                throw new RuntimeException("Finance party mapping is not defined for {$key}");
            }
        }

        foreach ($allowed as $key => $identity) {
            if (! $legacy->contains(fn ($row) => "{$row->party_type}|{$row->party_id}" === $key)) {
                continue;
            }

            $partyId = DB::table('parties')->where('code', $identity['code'])->value('id');
            if (! $partyId) {
                $partyId = DB::table('parties')->insertGetId([
                    'code' => $identity['code'],
                    'name' => $identity['name'],
                    'type' => 'COMPANY',
                    'branch_code' => '00000',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('party_roles')->insertOrIgnore([
                'party_id' => $partyId,
                'role' => $identity['role'],
                'credit_limit' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (['finance_open_items', 'finance_settlements'] as $table) {
                DB::table($table)
                    ->where('party_type', $identity['role'])
                    ->where('party_id', $identity['code'])
                    ->update(['party_master_id' => $partyId]);
            }
        }

        if (DB::table('finance_open_items')->whereNull('party_master_id')->exists()
            || DB::table('finance_settlements')->whereNotNull('party_id')->whereNull('party_master_id')->exists()) {
            throw new RuntimeException('Finance party IDs were not fully reconciled');
        }

        Schema::table('finance_open_items', function (Blueprint $table) {
            $table->dropIndex('finance_open_items_aging_idx');
            $table->dropColumn('party_id');
        });
        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->dropColumn('party_id');
        });
        Schema::table('finance_open_items', function (Blueprint $table) {
            $table->renameColumn('party_master_id', 'party_id');
        });
        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->renameColumn('party_master_id', 'party_id');
        });
        Schema::table('finance_open_items', function (Blueprint $table) {
            $table->unsignedBigInteger('party_id')->nullable(false)->change();
            $table->foreign('party_id')->references('id')->on('parties')->restrictOnDelete();
            $table->index(['warehouse_id', 'ledger_type', 'party_type', 'party_id', 'due_date'], 'finance_open_items_aging_idx');
        });
        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->foreign('party_id')->references('id')->on('parties')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_open_items', function (Blueprint $table) {
            $table->dropForeign(['party_id']);
            $table->dropIndex('finance_open_items_aging_idx');
            $table->renameColumn('party_id', 'party_master_id');
        });
        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->dropForeign(['party_id']);
            $table->renameColumn('party_id', 'party_master_id');
        });
        Schema::table('finance_open_items', function (Blueprint $table) {
            $table->string('party_id', 100)->nullable();
        });
        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->string('party_id', 100)->nullable();
        });

        foreach (DB::table('parties')->get(['id', 'code']) as $party) {
            DB::table('finance_open_items')->where('party_master_id', $party->id)->update(['party_id' => $party->code]);
            DB::table('finance_settlements')->where('party_master_id', $party->id)->update(['party_id' => $party->code]);
        }

        Schema::table('finance_open_items', function (Blueprint $table) {
            $table->dropColumn('party_master_id');
            $table->string('party_id', 100)->nullable(false)->change();
            $table->index(['warehouse_id', 'ledger_type', 'party_type', 'party_id', 'due_date'], 'finance_open_items_aging_idx');
        });
        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->dropColumn('party_master_id');
        });
    }
};
