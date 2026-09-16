<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class AccountingJournalVoucherPdfContractTest extends TestCase
{
    public function test_journal_voucher_pdf_is_permissioned_and_uses_the_shared_renderer(): void
    {
        $route = app('router')->getRoutes()->getByName('accounting.journal-entries.pdf');
        $controller = file_get_contents(base_path('app/Modules/Accounting/Controllers/JournalEntryController.php'));
        $view = file_get_contents(base_path('app/Modules/Accounting/Views/pdf/journal-voucher.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Accounting/Views/journal-entries/show.blade.php'));
        $index = file_get_contents(base_path('app/Modules/Accounting/Views/journal-entries/index.blade.php'));
        $rbac = file_get_contents(base_path('database/seeders/RbacSeeder.php'));

        self::assertNotNull($route);
        self::assertContains('permission:accounting.journal-entries.print', $route->gatherMiddleware());
        self::assertStringContainsString("renderView('Accounting::pdf.journal-voucher'", $controller);
        self::assertStringContainsString('ensureWarehouseScope($request, $journalEntry)', $controller);
        self::assertStringContainsString('application/pdf', $controller);
        self::assertStringContainsString('ใบสำคัญการลงบัญชี', $view);
        self::assertStringContainsString('JOURNAL VOUCHER', $view);
        self::assertStringContainsString('pdf-watermark', $view);
        self::assertStringContainsString('pdf-signatures', $view);
        self::assertStringContainsString('target="_blank"', $show);
        self::assertStringContainsString('row.print_url', $index);
        self::assertStringContainsString("'accounting.journal-entries.print'", $rbac);
    }

    public function test_journal_voucher_renders_thai_a4_pdf_with_balanced_totals(): void
    {
        $entry = (new JournalEntry)->forceFill(['entry_number' => 'JV-202609-000001', 'entry_date' => Carbon::parse('2026-09-16'), 'document_date' => Carbon::parse('2026-09-16'), 'description' => 'บันทึกรายการทดสอบ', 'status' => 'DRAFT', 'source_type' => 'MANUAL', 'currency_code' => 'THB', 'exchange_rate' => '1.000000']);
        $entry->setRelation('branch', (new Branch)->forceFill(['code' => 'HQ', 'name' => 'สำนักงานใหญ่']));
        $entry->setRelation('warehouse', (new Warehouse)->forceFill(['code' => 'HQ-WH', 'name' => 'คลังสำนักงานใหญ่']));
        $entry->setRelation('book', (new JournalBook)->forceFill(['code' => 'GJ', 'name' => 'สมุดรายวันทั่วไป']));
        $period = (new FiscalPeriod)->forceFill(['period_number' => 9]);
        $period->setRelation('fiscalYear', (new FiscalYear)->forceFill(['name' => '2569']));
        $entry->setRelation('period', $period);
        $entry->setRelation('createdBy', (new User)->forceFill(['name' => 'ผู้จัดทำทดสอบ']));
        foreach (['validatedBy', 'postedBy', 'reversedBy', 'reversalOf', 'reversal'] as $relation) {
            $entry->setRelation($relation, null);
        }
        $lines = collect([
            ['line_number' => 1, 'description' => 'เดบิตทดสอบ', 'debit' => '1000.00', 'credit' => '0.00', 'account' => ['code' => '110100', 'name' => 'เงินสด']],
            ['line_number' => 2, 'description' => 'เครดิตทดสอบ', 'debit' => '0.00', 'credit' => '1000.00', 'account' => ['code' => '410100', 'name' => 'รายได้']],
        ])->map(function (array $values): JournalEntryLine {
            $account = $values['account'];
            unset($values['account']);
            $line = (new JournalEntryLine)->forceFill($values);
            $line->setRelation('account', (new Account)->forceFill($account));
            $line->setRelation('taxCode', null);

            return $line;
        });
        $entry->setRelation('lines', $lines);
        $debitTotal = BigDecimal::of('1000.00');
        $creditTotal = BigDecimal::of('1000.00');
        $html = view('Accounting::pdf.journal-voucher', ['journalEntry' => $entry, 'logo' => null, 'companyName' => 'บริษัททดสอบ จำกัด', 'companyAddress' => 'กรุงเทพมหานคร', 'companyTaxId' => '0100000000001', 'companyTaxBranchCode' => '00000', 'dateFormat' => 'd/m/Y', 'decimalPlaces' => 2, 'debitTotal' => $debitTotal, 'creditTotal' => $creditTotal, 'difference' => $debitTotal->minus($creditTotal)])->render();

        self::assertStringContainsString('ใบสำคัญการลงบัญชี', $html);
        self::assertStringContainsString('1,000.00', $html);
        self::assertStringStartsWith('%PDF-', app(DocumentPdfRenderer::class)->render($html));
    }
}
