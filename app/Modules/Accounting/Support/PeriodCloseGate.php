<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PeriodCloseGate
{
    public static function failures(FiscalPeriod $period): array
    {
        $failures = [];
        $pending = DB::table('journal_entries')->whereIn('status', ['DRAFT', 'VALIDATED'])->whereBetween('entry_date', [$period->start_date, $period->end_date])->count();
        if ($pending > 0) {
            $failures[] = "มี Journal ที่ยังไม่ POSTED {$pending} รายการในงวด";
        }

        $unbalanced = DB::table('journal_entries as entries')
            ->leftJoin('journal_entry_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
            ->where('entries.status', 'POSTED')
            ->whereBetween('entries.entry_date', [$period->start_date, $period->end_date])
            ->select('entries.id')
            ->groupBy('entries.id')
            ->havingRaw('ABS(COALESCE(SUM(lines.debit), 0) - COALESCE(SUM(lines.credit), 0)) > 0.005')
            ->count();
        if ($unbalanced > 0) {
            $failures[] = "พบ Journal ที่ยอดไม่สมดุล {$unbalanced} รายการ — ไปที่ Accounting > Journal Entries ตรวจบรรทัด; รายการ POSTED ให้ทำ Reversal/รายการแก้ไข ห้ามแก้ทับประวัติเดิม";
        }

        self::appendInventoryFailures($failures, $period);
        self::appendAssetFailures($failures, $period);
        self::appendOperationalPostingFailures($failures, $period);

        return $failures;
    }

    /**
     * Asset documents are subledger work in progress until they are posted (or
     * explicitly cancelled/reversed). Keep this gate schema-aware so an
     * installation can migrate Accounting before Asset without breaking close.
     */
    private static function appendAssetFailures(array &$failures, FiscalPeriod $period): void
    {
        if (Schema::hasTable('asset_depreciation_runs')) {
            $pendingRuns = DB::table('asset_depreciation_runs')
                ->whereBetween('run_through_date', [$period->start_date, $period->end_date])
                ->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'FAILED'])
                ->count();
            if ($pendingRuns > 0) {
                $failures[] = "มี Asset depreciation run ที่ยังไม่ Post {$pendingRuns} รายการ — ไปที่ Asset > ค่าเสื่อมราคา ตรวจอนุมัติ/Post หรือยกเลิกก่อนปิดงวด";
            }

            $unlinkedPostedRuns = DB::table('asset_depreciation_runs')
                ->whereBetween('run_through_date', [$period->start_date, $period->end_date])
                ->where('book_type', 'BOOK')
                ->where('status', 'POSTED')->whereNull('journal_entry_id')->count();
            if ($unlinkedPostedRuns > 0) {
                $failures[] = "มี Asset depreciation run ที่สถานะ POSTED แต่ยังไม่ผูก Journal {$unlinkedPostedRuns} รายการ — ตรวจ Asset > ค่าเสื่อมราคา และ Journal linkage ก่อนปิดงวด";
            }
        }

        self::appendAssetDocumentFailures($failures, $period, 'asset_impairments', 'assessment_date', 'Impairment', 'ด้อยค่า');
        self::appendAssetDocumentFailures($failures, $period, 'asset_disposals', 'disposal_date', 'Disposal', 'จำหน่าย/ตัดออก');
    }

    private static function appendAssetDocumentFailures(
        array &$failures,
        FiscalPeriod $period,
        string $table,
        string $dateColumn,
        string $label,
        string $menuLabel,
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        $documents = DB::table($table)->whereBetween($dateColumn, [$period->start_date, $period->end_date]);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $documents->whereNull('deleted_at');
        }

        $pending = (clone $documents)
            ->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count();
        if ($pending > 0) {
            $failures[] = "มีเอกสาร Asset {$label} ที่ยังไม่ Post {$pending} รายการ — ไปที่ Asset > {$menuLabel} ตรวจอนุมัติ/Post หรือยกเลิกก่อนปิดงวด";
        }

        $unlinked = (clone $documents)
            ->where('status', 'POSTED')->whereNull('journal_entry_id')->count();
        if ($unlinked > 0) {
            $failures[] = "มีเอกสาร Asset {$label} สถานะ POSTED แต่ยังไม่ผูก Journal {$unlinked} รายการ — ตรวจเอกสารและ Journal linkage ก่อนปิดงวด";
        }
    }

    /**
     * These are operational documents that own a live GL event. A draft or
     * approved record inside the period must be resolved before a final lock;
     * this is deliberately a read-only gate, while each posting service keeps
     * its own status and mapping validation when the user eventually posts.
     */
    private static function appendOperationalPostingFailures(array &$failures, FiscalPeriod $period): void
    {
        foreach ([
            ['asset_capitalizations', 'document_date', ['DRAFT', 'SUBMITTED', 'APPROVED'], 'Asset รับรู้/เพิ่มมูลค่า'],
            ['sales_documents', 'document_date', ['DRAFT', 'APPROVED'], 'POS ใบแจ้งหนี้/ใบลดหนี้'],
            ['pos_physical_sales', 'document_date', ['DRAFT'], 'POS HS/IV'],
            ['pos_sales_returns', 'document_date', ['DRAFT'], 'POS ใบรับคืนสินค้า'],
            ['finance_settlements', 'settlement_date', ['DRAFT', 'APPROVED'], 'Finance รับ/จ่ายชำระ'],
            ['finance_advance_deposits', 'document_date', ['DRAFT'], 'Finance เงินล่วงหน้า/มัดจำ'],
            ['pos_sales_commission_payout_batches', 'document_date', ['DRAFT'], 'Finance จ่ายคอมมิชชั่น'],
            ['purchase_documents', 'document_date', ['DRAFT', 'APPROVED'], 'Purchasing ใบซื้อ/ใบลดหนี้'],
            ['wms_inventory_adjustment_documents', 'document_date', ['DRAFT', 'APPROVED'], 'WMS ปรับปรุงสต็อก'],
        ] as [$table, $dateColumn, $pendingStatuses, $label]) {
            self::appendOperationalDocumentFailures($failures, $period, $table, $dateColumn, $pendingStatuses, $label);
        }
    }

    /** @param list<string> $pendingStatuses */
    private static function appendOperationalDocumentFailures(
        array &$failures,
        FiscalPeriod $period,
        string $table,
        string $dateColumn,
        array $pendingStatuses,
        string $label,
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        $documents = DB::table($table)->whereBetween($dateColumn, [$period->start_date, $period->end_date]);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $documents->whereNull('deleted_at');
        }

        $pending = (clone $documents)->whereIn('status', $pendingStatuses)->count();
        if ($pending > 0) {
            $failures[] = "มี {$label} ที่ยังไม่ Post {$pending} รายการ — ตรวจอนุมัติ/Post หรือยกเลิกเอกสารก่อน Lock งวด";
        }

        if (! Schema::hasColumn($table, 'journal_entry_id')) {
            return;
        }

        $unlinked = (clone $documents)->where('status', 'POSTED')->whereNull('journal_entry_id')->count();
        if ($unlinked > 0) {
            $failures[] = "มี {$label} สถานะ POSTED แต่ยังไม่ผูก Journal {$unlinked} รายการ — ตรวจ Journal linkage และทำ correction/reversal ตาม workflow ก่อน Lock งวด";
        }
    }

    private static function appendInventoryFailures(array &$failures, FiscalPeriod $period): void
    {
        if (! Schema::hasTable('wms_cost_allocations')) {
            return;
        }

        $allocations = DB::table('wms_cost_allocations')
            ->whereBetween('wms_cost_allocations.business_date', [$period->start_date, $period->end_date])
            ->where('wms_cost_allocations.status', '!=', 'REVERSED');

        $pending = (clone $allocations)->where(function ($query): void {
            $query->where('cost_status', 'PENDING')->orWhereIn('status', ['PENDING', 'REQUIRES_RECOST']);
        })->count();
        if ($pending > 0) {
            $failures[] = "มี Inventory cost allocation ที่ยังไม่ Final/Recost {$pending} รายการ — ไปที่ WMS > Stock Valuation ตรวจ pending cost และทำ Recost/Retry ก่อนปิดงวด";
        }

        $unlinked = (clone $allocations)->whereNull('journal_entry_id')->count();
        if ($unlinked > 0) {
            $failures[] = "มี Inventory cost allocation ที่ยังไม่ผูก Journal {$unlinked} รายการ — ไปที่ WMS > Inventory → GL Preflight ตรวจ source/posting แล้วแก้ที่เอกสารต้นทาง ห้าม link ด้วยมือ";
        }

        if (! Schema::hasTable('wms_cost_allocation_journal_lines')) {
            if ((clone $allocations)->exists()) {
                $failures[] = 'ยังไม่มีหลักฐาน Inventory allocation → Journal line สำหรับงวดนี้ — ไปที่ WMS > Inventory → GL Preflight ตรวจ migration/lineage ก่อนปิดงวด';
            }
        } else {
            $missingProof = (clone $allocations)
                ->leftJoin('wms_cost_allocation_journal_lines as links', 'links.allocation_id', '=', 'wms_cost_allocations.id')
                ->whereNull('links.id')
                ->count('wms_cost_allocations.id');
            if ($missingProof > 0) {
                $failures[] = "มี Inventory allocation ที่ไม่มี Journal line proof {$missingProof} รายการ — ไปที่ WMS > Inventory → GL Preflight แก้ source/posting และสร้าง revision ตาม contract ห้ามแก้ Journal POSTED";
            }

            $mismatched = (clone $allocations)
                ->join('wms_cost_allocation_journal_lines as links', 'links.allocation_id', '=', 'wms_cost_allocations.id')
                ->leftJoin('journal_entry_lines as lines', 'lines.id', '=', 'links.journal_entry_line_id')
                ->leftJoin('journal_entries as linked_entries', 'linked_entries.id', '=', 'lines.journal_entry_id')
                ->where(function ($query) use ($period): void {
                    $query->whereNull('lines.id')
                        ->orWhereNull('linked_entries.id')
                        ->orWhere('linked_entries.status', '!=', 'POSTED')
                        ->orWhere('linked_entries.entry_date', '>', $period->end_date)
                        ->orWhereColumn('linked_entries.warehouse_id', '!=', 'wms_cost_allocations.warehouse_id')
                        ->orWhereNull('wms_cost_allocations.journal_entry_id')
                        ->orWhereColumn('lines.journal_entry_id', '!=', 'wms_cost_allocations.journal_entry_id')
                        ->orWhereColumn('links.revision', '!=', 'wms_cost_allocations.revision')
                        ->orWhereNull('links.identity_key');
                })->distinct()->count('wms_cost_allocations.id');
            if ($mismatched > 0) {
                $failures[] = "มี Inventory allocation ที่ Journal line proof ไม่ตรง revision/Journal {$mismatched} รายการ — ไปที่ WMS > Inventory → GL Preflight ตรวจ warehouse, revision และ source แล้วใช้ reversal/correction";
            }

            $orphanGl = DB::table('journal_entry_lines as lines')
                ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
                ->join('accounts', 'accounts.id', '=', 'lines.account_id')
                ->leftJoin('wms_cost_allocation_journal_lines as links', 'links.journal_entry_line_id', '=', 'lines.id')
                ->leftJoin('wms_cost_allocations as allocations', 'allocations.id', '=', 'links.allocation_id')
                ->where('entries.status', 'POSTED')
                ->whereBetween('entries.entry_date', [$period->start_date, $period->end_date])
                ->where('accounts.control_account_type', 'INVENTORY')
                ->where('lines.subledger_type', 'ITEM')
                ->whereNull('allocations.id')
                ->count('lines.id');
            if ($orphanGl > 0) {
                $failures[] = "มี Inventory GL line ที่ไม่มี cost allocation linkage {$orphanGl} รายการ — ไปที่ Accounting > Reconciliation และ WMS > Inventory → GL Preflight ตรวจ source mapping; ห้ามปรับยอดให้ตรงด้วยมือ";
            }

            $difference = (clone $allocations)
                ->join('wms_cost_allocation_journal_lines as links', 'links.allocation_id', '=', 'wms_cost_allocations.id')
                ->join('journal_entry_lines as lines', 'lines.id', '=', 'links.journal_entry_line_id')
                ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
                ->join('accounts', 'accounts.id', '=', 'lines.account_id')
                ->where('entries.status', 'POSTED')
                ->where('entries.entry_date', '<=', $period->end_date)
                ->whereColumn('entries.warehouse_id', 'wms_cost_allocations.warehouse_id')
                ->where('accounts.control_account_type', 'INVENTORY')
                ->select('wms_cost_allocations.id')
                ->groupBy('wms_cost_allocations.id', 'wms_cost_allocations.value', 'wms_cost_allocations.direction')
                ->havingRaw('ABS(wms_cost_allocations.value - ABS(COALESCE(SUM(lines.debit - lines.credit), 0))) > 0.005')
                ->count();
            if ($difference > 0) {
                $failures[] = "พบผลต่าง Inventory allocation กับ Inventory GL {$difference} รายการ — ไปที่ Accounting > Reconciliation ตรวจ allocation/GL และทำ Recost หรือ reversal ตามงวดที่เปิด";
            }
        }

        if (Schema::hasTable('wms_cost_recalculation_requests')) {
            $recost = DB::table('wms_cost_recalculation_requests as requests')
                ->join('wms_stock_movements as movements', 'movements.id', '=', 'requests.trigger_movement_id')
                ->where('movements.business_date', '<=', $period->end_date)
                ->whereIn('requests.status', ['PENDING', 'PROCESSING', 'FAILED', 'STALE'])
                ->count('requests.id');
            if ($recost > 0) {
                $failures[] = "มี Recost request ที่ยังไม่สำเร็จ {$recost} รายการ — ไปที่ WMS > Recost Queue ตรวจสาเหตุและ Retry; หากเป็นงวดปิดให้ทำ adjustment ในงวดเปิด ห้ามแก้ Journal เดิม";
            }
        }
    }
}
