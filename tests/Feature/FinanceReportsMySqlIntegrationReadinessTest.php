<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinanceReportsMySqlIntegrationReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    public function test_cash_position_aggregate_reads_posted_bank_and_cash_journal_lines(): void
    {
        $rows = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'POSTED')
            ->whereIn('l.subledger_type', ['BANK', 'CASH'])
            ->whereNotNull('l.subledger_id')
            ->select('l.subledger_type', 'l.subledger_id', DB::raw('SUM(l.debit - l.credit) as balance_amount'))
            ->groupBy('l.subledger_type', 'l.subledger_id')
            ->limit(100)
            ->get();

        foreach ($rows as $row) {
            $this->assertContains($row->subledger_type, ['BANK', 'CASH']);
            $this->assertNotEmpty($row->subledger_id);
            $this->assertIsNumeric($row->balance_amount);
        }
    }

    public function test_expected_cashflow_aggregate_reads_open_items_after_active_allocations(): void
    {
        $asOf = today()->toDateString();
        $allocated = DB::table('finance_allocations')->selectRaw('debit_open_item_id AS open_item_id, amount')->where('allocation_date', '<=', $asOf)->where(fn ($q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))->unionAll(DB::table('finance_allocations')->selectRaw('credit_open_item_id AS open_item_id, amount')->where('allocation_date', '<=', $asOf)->where(fn ($q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf)));
        $allocated = DB::query()->fromSub($allocated, 'allocation_rows')->select('open_item_id')->selectRaw('SUM(amount) AS allocated_amount')->groupBy('open_item_id');
        $rows = DB::table('finance_open_items as oi')->leftJoinSub($allocated, 'a', 'a.open_item_id', '=', 'oi.id')->where('oi.posting_date', '<=', $asOf)->whereRaw('oi.original_amount - COALESCE(a.allocated_amount, 0) > 0')->select('oi.ledger_type')->selectRaw('COUNT(*) as item_count')->selectRaw('SUM(oi.original_amount - COALESCE(a.allocated_amount, 0)) as outstanding_amount')->groupBy('oi.ledger_type')->get();

        foreach ($rows as $row) {
            $this->assertContains($row->ledger_type, ['AR', 'AP']);
            $this->assertGreaterThan(0, (int) $row->item_count);
            $this->assertGreaterThan(0, (float) $row->outstanding_amount);
        }
    }

    public function test_settlement_allocation_report_rows_keep_source_and_allocation_linkage(): void
    {
        $rows = DB::table('finance_settlement_allocation_intents as i')
            ->join('finance_settlements as s', 's.id', '=', 'i.settlement_id')
            ->join('finance_bank_accounts as ba', 'ba.id', '=', 's.bank_account_id')
            ->join('warehouses as w', 'w.id', '=', 'ba.warehouse_id')
            ->join('parties as p', 'p.id', '=', 's.party_id')
            ->leftJoin('finance_open_items as oi', 'oi.id', '=', 'i.open_item_id')
            ->leftJoin('finance_allocations as a', 'a.id', '=', 'i.allocation_id')
            ->whereNull('s.deleted_at')
            ->select(['i.id', 'i.amount', 's.document_type', 's.status', 'w.id as warehouse_id', 'p.id as party_id', 'oi.id as open_item_id', 'a.id as allocation_id'])
            ->limit(100)
            ->get();

        foreach ($rows as $row) {
            $this->assertContains($row->document_type, ['RECEIPT', 'PAYMENT']);
            $this->assertGreaterThan(0, (float) $row->amount);
            $this->assertGreaterThan(0, (int) $row->warehouse_id);
            $this->assertGreaterThan(0, (int) $row->party_id);
            $this->assertNotNull($row->open_item_id);
            if ($row->allocation_id !== null) {
                $this->assertGreaterThan(0, (int) $row->allocation_id);
            }
        }
    }
}
