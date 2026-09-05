<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\InternalTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InternalTransferMySqlIntegrationReadinessTest extends TestCase
{
    public function test_internal_transfer_posts_balanced_gl_and_reverses_original_journal(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $warehouse = $actor?->warehouses()->where('warehouses.is_active', true)->with('branch')->orderBy('warehouses.id')->first();
        $accounts = $warehouse ? BankAccount::query()->where('warehouse_id', $warehouse->id)->where('is_active', true)->where('currency_code', 'THB')->with('account')->whereHas('account', fn ($q) => $q->where('is_active', true)->where('is_postable', true))->orderBy('id')->take(2)->get() : collect();
        $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'INTERNAL_TRANSFER')->where('is_active', true)->first();
        if (! $actor || ! $warehouse || $accounts->count() < 2 || ! $sequence || ! JournalBook::query()->where('type', 'GENERAL')->where('is_active', true)->exists()) {
            $this->markTestSkipped('ต้องมี User, Warehouse, บัญชี THB ที่ลงบัญชีได้ 2 บัญชี, sequence INTERNAL_TRANSFER และสมุด GENERAL');
        }

        DB::beginTransaction();
        try {
            $request = Request::create('/finance/internal-transfers', 'POST');
            $service = app(InternalTransferService::class);
            $transfer = $service->create(['document_date' => today()->toDateString(), 'source_bank_account_id' => $accounts[0]->id, 'destination_bank_account_id' => $accounts[1]->id, 'amount' => '125.00', 'description' => 'Integration internal transfer'], $warehouse, $sequence, $actor, $request);
            $transfer = $service->transition($transfer, $warehouse, 'SUBMIT', $actor, $request);
            $transfer = $service->transition($transfer, $warehouse, 'APPROVE', $actor, $request);
            $posted = $service->post($transfer, $warehouse, $actor, $request)->load('journalEntry.lines');
            $journal = $posted->journalEntry;

            self::assertSame('POSTED', $posted->status);
            self::assertSame('125.00', number_format((float) $journal->lines->sum('debit'), 2, '.', ''));
            self::assertSame('125.00', number_format((float) $journal->lines->sum('credit'), 2, '.', ''));
            self::assertSame([$accounts[1]->account_id, $accounts[0]->account_id], $journal->lines->pluck('account_id')->all());
            self::assertSame(['TRANSFER_SOURCE', 'TRANSFER_DESTINATION'], collect($journal->posting_metadata['accounts'])->pluck('account_role')->all());

            $reversed = $service->reverse($posted, $warehouse, today()->toDateString(), 'Integration reversal test', $actor, $request)->load('reversalJournalEntry.lines');
            self::assertSame('REVERSED', $reversed->status);
            self::assertSame('POSTED', $reversed->reversalJournalEntry->status);
            self::assertSame('125.00', number_format((float) $reversed->reversalJournalEntry->lines->sum('debit'), 2, '.', ''));
            self::assertSame('125.00', number_format((float) $reversed->reversalJournalEntry->lines->sum('credit'), 2, '.', ''));
        } finally {
            DB::rollBack();
        }
    }
}
