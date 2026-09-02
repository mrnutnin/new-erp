<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Modules\Accounting\Services\JournalEntryWriter;
use App\Modules\Accounting\Services\JournalPostingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JournalPostingMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['journal_entry_lines', 'journal_entries', 'accounts', 'fiscal_periods', 'journal_books', 'company_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency')->nullable();
        });
        Schema::create('journal_books', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('sequence_prefix');
            $table->boolean('is_active');
        });
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status');
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active');
            $table->boolean('is_postable');
            $table->string('control_account_type')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_book_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedInteger('sequence_number');
            $table->string('entry_number');
            $table->date('entry_date');
            $table->date('document_date')->nullable();
            $table->string('source_type');
            $table->string('source_event')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_reference')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('posting_hash')->nullable();
            $table->json('posting_metadata')->nullable();
            $table->string('description');
            $table->string('currency_code');
            $table->decimal('exchange_rate', 18, 6);
            $table->string('status');
            $table->unsignedBigInteger('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('validation_reason')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('posting_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedSmallInteger('line_number');
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->string('subledger_type')->nullable();
            $table->string('subledger_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('debit', 18, 2);
            $table->decimal('credit', 18, 2);
            $table->decimal('tax_base', 18, 2)->nullable();
            $table->decimal('tax_amount', 18, 2)->nullable();
            $table->date('tax_point_date')->nullable();
            $table->date('tax_settlement_date')->nullable();
            $table->timestamps();
        });
        DB::table('journal_books')->insert(['type' => 'SALES', 'sequence_prefix' => 'SJ', 'is_active' => true]);
        DB::table('fiscal_periods')->insert(['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'OPEN']);
        DB::table('accounts')->insert([
            ['id' => 10, 'is_active' => true, 'is_postable' => true],
            ['id' => 20, 'is_active' => true, 'is_postable' => true],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['journal_entry_lines', 'journal_entries', 'accounts', 'fiscal_periods', 'journal_books', 'company_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_metadata_is_canonicalized_persisted_and_part_of_idempotency_hash(): void
    {
        $service = new JournalPostingService(new JournalEntryWriter);
        $branch = new Branch;
        $branch->id = 1;
        $payload = [
            'source_type' => 'POS', 'source_id' => '1', 'source_reference' => 'SI-001', 'event_code' => 'sales_invoice',
            'entry_date' => '2026-09-15', 'document_date' => '2026-09-15', 'description' => 'ขายสินค้า',
            'lines' => [
                ['account_id' => 10, 'debit' => '100.00', 'credit' => '0.00'],
                ['account_id' => 20, 'debit' => '0.00', 'credit' => '100.00'],
            ],
            'posting_metadata' => [
                'event_code' => 'sales_invoice', 'contract_version' => 1,
                'accounts' => [
                    ['source' => 'MAPPING', 'mapping_version' => 1, 'account_id' => 20, 'account_role' => 'SALES_REVENUE', 'mapping_id' => 8],
                    ['source' => 'MAPPING', 'mapping_version' => 1, 'account_id' => 10, 'account_role' => 'ACCOUNTS_RECEIVABLE', 'mapping_id' => 7],
                ],
            ],
        ];

        $posted = $service->postForBranch($payload, $branch);
        $retried = $service->postForBranch($payload, $branch);

        self::assertSame($posted->id, $retried->id);
        self::assertSame('sales_invoice', $posted->posting_metadata['event_code']);
        self::assertSame(1, $posted->posting_metadata['contract_version']);

        $payload['posting_metadata']['accounts'][0]['mapping_version'] = 2;
        $this->expectException(ValidationException::class);
        $service->postForBranch($payload, $branch);
    }
}
