<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Pos\Models\CommissionPayoutBatch;
use App\Modules\Pos\Models\CommissionRecord;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesCommissionPlan;
use App\Modules\Pos\Services\CommissionPayoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Rollback-only proof that commission payout uses its event mapping and captures provenance. */
final class SalesCommissionPayoutMySqlIntegrationReadinessTest extends TestCase
{
    public function test_commission_payout_posts_mapping_provenance_and_is_idempotent(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $bank = BankAccount::query()->with('warehouse')->where('is_active', true)->whereNotNull('account_id')->orderBy('id')->first();
        $plan = SalesCommissionPlan::query()->orderBy('id')->first();
        $sale = $bank ? PhysicalSale::query()->where('warehouse_id', $bank->warehouse_id)->orderBy('id')->first() : null;
        $period = FiscalPeriod::query()->where('status', 'OPEN')->orderBy('start_date')->first();

        if (! $actor || ! $bank?->warehouse || ! $plan || ! $sale || ! $period || ! JournalBook::query()->where('type', 'PAYMENT')->where('is_active', true)->exists()) {
            $this->markTestSkipped('ต้องมี User, Bank, Commission Plan, Physical Sale และงวด/สมุด PAYMENT ที่เปิดอยู่ใน local MySQL');
        }
        if (AccountMapping::query()->where('event_code', 'sales_commission_payout')->where('key', 'COMMISSION_EXPENSE')->where('is_active', true)->count() !== 1) {
            $this->markTestSkipped('ต้องตั้งค่า sales_commission_payout / COMMISSION_EXPENSE ให้พร้อมก่อน');
        }

        DB::beginTransaction();
        try {
            $date = $period->start_date->format('Y-m-d');
            $suffix = strtoupper(Str::random(12));
            // Use an isolated recipient so unrelated pending negative adjustments in
            // the local mock dataset do not invalidate this success-path proof.
            $recipient = User::factory()->create([
                'primary_branch_id' => $bank->warehouse->branch_id,
            ]);
            $record = CommissionRecord::query()->create([
                'commission_plan_id' => $plan->id,
                'recipient_user_id' => $recipient->id,
                'warehouse_id' => $bank->warehouse_id,
                'branch_id' => $bank->warehouse->branch_id,
                'physical_sale_id' => $sale->id,
                'physical_sale_line_id' => null,
                'source_type' => 'INTEGRATION_TEST',
                'source_id' => $suffix,
                'base_amount' => '100.00',
                'rate_percent' => '10.0000',
                'commission_amount' => '10.00',
                'status' => 'APPROVED',
                'calculated_at' => now(),
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'snapshot' => ['test' => 'sales_commission_payout'],
                'idempotency_key' => 'test:commission-payout:'.$suffix,
            ]);
            $batch = CommissionPayoutBatch::query()->create([
                'document_number' => 'CP-TEST-'.$suffix,
                'branch_id' => $bank->warehouse->branch_id,
                'warehouse_id' => $bank->warehouse_id,
                'recipient_user_id' => $recipient->id,
                'bank_account_id' => $bank->id,
                'currency_code' => 'THB',
                'document_date' => $date,
                'total_amount' => '10.00',
                'status' => 'DRAFT',
                'created_by' => $actor->id,
            ]);
            $batch->lines()->create(['commission_record_id' => $record->id, 'amount' => '10.00']);

            $posted = app(CommissionPayoutService::class)->post($batch, $actor);
            $journal = $posted->journalEntry()->with('lines')->firstOrFail();
            $accounts = collect($journal->posting_metadata['accounts'] ?? []);

            $this->assertSame('POSTED', $posted->status);
            $this->assertSame('PAID', $record->fresh()->status);
            $this->assertSame(['COMMISSION_EXPENSE', 'BANK_ACCOUNT'], $accounts->pluck('account_role')->all());
            $this->assertSame('MAPPING', $accounts->firstWhere('account_role', 'COMMISSION_EXPENSE')['source']);
            $this->assertSame('DOCUMENT', $accounts->firstWhere('account_role', 'BANK_ACCOUNT')['source']);
            $this->assertSame('10.00', $journal->lines->reduce(fn (string $total, $line): string => JournalBalance::add($total, $line->debit), '0.00'));
            $this->assertSame('10.00', $journal->lines->reduce(fn (string $total, $line): string => JournalBalance::add($total, $line->credit), '0.00'));

            $retry = app(CommissionPayoutService::class)->post($posted, $actor);
            $this->assertSame($posted->journal_entry_id, $retry->journal_entry_id);
        } finally {
            DB::rollBack();
        }
    }
}
