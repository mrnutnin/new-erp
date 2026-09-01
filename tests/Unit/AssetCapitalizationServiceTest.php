<?php

namespace Tests\Unit;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Asset\Controllers\AssetCapitalizationController;
use App\Modules\Asset\Models\AssetCapitalization;
use App\Modules\Asset\Models\AssetCapitalizationLine;
use App\Modules\Asset\Services\AssetCapitalizationService;
use PHPUnit\Framework\TestCase;

final class AssetCapitalizationServiceTest extends TestCase
{
    public function test_journal_lines_always_reclassify_each_snapshot_pair(): void
    {
        $service = new AssetCapitalizationService($this->createMock(JournalPostingService::class));
        $line = new AssetCapitalizationLine([
            'asset_id' => 55, 'asset_account_id' => 101, 'clearing_account_id' => 202, 'capitalized_cost' => '1250.50', 'description' => 'Laptop',
        ]);
        $method = new \ReflectionMethod($service, 'journalLines');
        $lines = $method->invoke($service, [$line]);

        self::assertSame(101, $lines[0]['account_id']);
        self::assertSame('ASSET', $lines[0]['subledger_type']);
        self::assertSame('55', $lines[0]['subledger_id']);
        self::assertSame('1250.50', $lines[0]['debit']);
        self::assertSame(202, $lines[1]['account_id']);
        self::assertSame('1250.50', $lines[1]['credit']);
    }

    public function test_value_event_keys_are_stable_and_reversal_is_distinct(): void
    {
        $service = new AssetCapitalizationService($this->createMock(JournalPostingService::class));
        $capitalization = (new AssetCapitalization)->setAttribute('id', 45);
        $line = (new AssetCapitalizationLine)->setAttribute('id', 9);
        $eventKey = new \ReflectionMethod($service, 'eventKey');
        $reversalKey = new \ReflectionMethod($service, 'reversalEventKey');

        self::assertSame($eventKey->invoke($service, $capitalization, $line), $eventKey->invoke($service, $capitalization, $line));
        self::assertNotSame($eventKey->invoke($service, $capitalization, $line), $reversalKey->invoke($service, $capitalization, $line));
        self::assertSame(64, strlen($eventKey->invoke($service, $capitalization, $line)));
    }

    public function test_service_uses_source_row_locks_and_accounting_idempotency_boundary(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationService::class))->getFileName());

        self::assertStringContainsString('PurchaseDocumentLine::query()', $source);
        self::assertStringContainsString('->lockForUpdate()', $source);
        self::assertStringContainsString('postForBranchWithinTransaction', $source);
        self::assertStringContainsString("'idempotency_key'", $source);
        self::assertStringContainsString("'status' => 'POSTED'", $source);
        self::assertStringContainsString("'status' => 'REVERSED'", $source);
    }

    public function test_purchase_source_is_split_by_ceiling_not_by_a_single_asset_unique_key(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationService::class))->getFileName());

        self::assertStringContainsString("->where('asset_capitalizations.status', 'POSTED')", $source);
        self::assertStringContainsString("->sum('asset_capitalization_lines.capitalized_cost')", $source);
        self::assertStringContainsString('->plus($requested)->isGreaterThan($this->decimal($source->net_amount))', $source);
        self::assertStringContainsString('lockAndAssertAllocationCeilings', $source);
    }

    public function test_only_posted_invoice_can_progress_and_reversal_uses_accounting_reversal(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationService::class))->getFileName());

        self::assertStringContainsString("->where('status', 'POSTED')", $source);
        self::assertStringContainsString('reverseWithinTransaction', $source);
        self::assertStringContainsString('\'reversal_of_event_id\' => $event->id', $source);
        self::assertStringContainsString("'event_type' => 'REVERSAL'", $source);
    }

    public function test_first_capitalization_establishes_value_and_reversal_restores_the_draft_estimate(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationService::class))->getFileName());

        self::assertStringContainsString('\'original_cost\' => $cost->__toString()', $source);
        self::assertStringContainsString('\'book_cost\' => $cost->__toString()', $source);
        self::assertStringContainsString('$restore = $capitalizationHistory?->old_values ?? []', $source);
        self::assertStringContainsString('\'original_cost\' => $this->decimal($restore[\'original_cost\'] ?? 0)->__toString()', $source);
    }

    public function test_non_capitalizable_purchase_item_requires_a_permitted_reasoned_exception(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationService::class))->getFileName());

        self::assertStringContainsString('! $capitalization->is_manual_exception || ! $actor->hasPermission(\'asset.capitalizations.exception\')', $source);
        self::assertStringContainsString('\'manual_exception_reason\' => [\'nullable\', \'string\', \'min:10\', \'max:500\'', $source);
    }

    public function test_manual_reclass_uses_the_same_accounting_capitalization_lifecycle(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationService::class))->getFileName());

        self::assertStringContainsString('PURCHASE_DOCUMENT,OPENING,MANUAL_RECLASS', $source);
        self::assertStringContainsString('required_if:source_type,MANUAL_RECLASS', $source);
        self::assertStringContainsString('$capitalization->source_type === \'OPENING\' ? null', $source);
        self::assertStringContainsString('$capitalization->source_type !== \'OPENING\' && ! $line->clearing_account_id', $source);
    }

    public function test_capitalization_rejects_a_control_account_as_its_credit_account(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationService::class))->getFileName());

        self::assertStringContainsString("->whereNull('control_account_type')->exists()", $source);
        self::assertStringContainsString('บัญชีเครดิตต้องเป็นบัญชีย่อยที่ลงรายการได้ ไม่ใช่บัญชีคุม', $source);
    }

    public function test_only_a_draft_capitalization_can_be_soft_deleted(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationController::class))->getFileName());

        self::assertStringContainsString("if (\$capitalization->status !== 'DRAFT')", $source);
        self::assertStringContainsString('$capitalization->delete()', $source);
        self::assertStringContainsString('asset.capitalization.deleted', $source);
    }

    public function test_capitalization_table_exposes_delete_only_for_drafts(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationController::class))->getFileName());

        self::assertStringContainsString("\$row->status === 'DRAFT'", $source);
        self::assertStringContainsString("'delete_url'", $source);
    }

    public function test_submitted_or_approved_unposted_capitalization_can_be_voided(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationService::class))->getFileName());

        self::assertStringContainsString('public function void(AssetCapitalization', $source);
        self::assertStringContainsString("in_array(\$capitalization->status, ['SUBMITTED', 'APPROVED'], true)", $source);
        self::assertStringContainsString("'status' => 'VOID'", $source);
        self::assertStringContainsString("'void_reason' => \$reason", $source);
    }

    public function test_post_uses_the_shared_open_period_and_balanced_journal_contract(): void
    {
        $source = file_get_contents((new \ReflectionClass(JournalPostingService::class))->getFileName());

        self::assertStringContainsString("->where('status', 'OPEN')", $source);
        self::assertStringContainsString("'วันที่ Post ต้องอยู่ในงวดบัญชีที่เปิดอยู่'", $source);
        self::assertStringContainsString('JournalBalance::totals($posting[\'lines\'])', $source);
        self::assertStringContainsString("'ยอดรวมเดบิตและเครดิตต้องเท่ากันและมากกว่าศูนย์'", $source);
    }

    public function test_capitalization_blocks_cost_below_the_category_threshold(): void
    {
        $source = file_get_contents((new \ReflectionClass(AssetCapitalizationService::class))->getFileName());

        self::assertStringContainsString('isLessThan($this->decimal($asset->category->capitalization_threshold))', $source);
        self::assertStringContainsString('ต่ำกว่าเกณฑ์ของหมวดสินทรัพย์', $source);
    }
}
