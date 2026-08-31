<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentSequenceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('finance_document_sequence_counters');
        Schema::dropIfExists('finance_document_sequences');
        Schema::dropIfExists('branches');

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('finance_document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type');
            $table->string('name');
            $table->string('prefix');
            $table->string('number_format');
            $table->string('reset_rule');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->string('last_reset_key', 12)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('number_reuse_policy')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('finance_document_sequence_counters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_sequence_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->string('last_reset_key', 12)->nullable();
            $table->timestamps();
            $table->unique(['document_sequence_id', 'branch_id']);
        });
    }

    public function test_it_formats_supported_document_number_tokens(): void
    {
        $sequence = new DocumentSequence([
            'prefix' => 'RC',
            'number_format' => '{PREFIX}-{YYYY}{MM}-{NUMBER:6}',
        ]);

        $number = (new DocumentSequenceService)->format($sequence, CarbonImmutable::parse('2026-08-20'), 42);

        $this->assertSame('RC-202608-000042', $number);
    }

    public function test_it_formats_branch_and_two_digit_year_from_document_date(): void
    {
        $sequence = new DocumentSequence([
            'prefix' => 'IV',
            'number_format' => 'IV{BRANCH}{YYMM}{NUMBER:6}',
        ]);

        $number = (new DocumentSequenceService)->format($sequence, CarbonImmutable::parse('2026-08-31'), 1, 'bkk');

        $this->assertSame('IVBKK2608000001', $number);
    }

    public function test_branch_counters_are_independent_and_reset_using_document_date(): void
    {
        $sequence = DocumentSequence::query()->create([
            'document_type' => 'INVOICE', 'name' => 'Invoice', 'prefix' => 'IV',
            'number_format' => 'IV{BRANCH}{YYMM}{NUMBER:3}', 'reset_rule' => 'MONTHLY',
            'next_number' => 1, 'is_active' => true,
        ]);
        $bangkok = Branch::query()->create(['code' => 'BKK', 'name' => 'Bangkok', 'is_active' => true]);
        $chiangMai = Branch::query()->create(['code' => 'CNX', 'name' => 'Chiang Mai', 'is_active' => true]);
        $service = new DocumentSequenceService;

        $this->assertSame('IVBKK2608001', $service->issueForBranch($sequence, $bangkok, CarbonImmutable::parse('2026-08-31')));
        $this->assertSame('IVCNX2608001', $service->issueForBranch($sequence, $chiangMai, CarbonImmutable::parse('2026-08-31')));
        $this->assertSame('IVBKK2608002', $service->issueForBranch($sequence, $bangkok, CarbonImmutable::parse('2026-08-31')));
        $this->assertSame('IVBKK2609001', $service->issueForBranch($sequence, $bangkok, CarbonImmutable::parse('2026-09-01')));
    }

    public function test_initial_key_keeps_starting_number_and_newer_period_resets(): void
    {
        $service = new DocumentSequenceService;

        $this->assertSame([250, '202608'], $service->resolveCounter('MONTHLY', null, '202608', 250));
        $this->assertSame([1, '202609'], $service->resolveCounter('MONTHLY', '202608', '202609', 251));
        $this->assertSame([250, 'NEVER'], $service->resolveCounter('NEVER', null, 'NEVER', 250));
    }

    public function test_older_period_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new DocumentSequenceService)->resolveCounter('MONTHLY', '202608', '202607', 20);
    }

    public function test_yearly_reset_starts_at_one_only_when_the_requested_period_advances(): void
    {
        $service = new DocumentSequenceService;

        $this->assertSame([1, '2027'], $service->resolveCounter('YEARLY', '2026', '2027', 999));
        $this->assertSame([999, '2026'], $service->resolveCounter('YEARLY', '2026', '2026', 999));
    }

    public function test_never_reset_does_not_rewind_for_a_date_change(): void
    {
        $this->assertSame([42, 'NEVER'], (new DocumentSequenceService)->resolveCounter('NEVER', 'NEVER', 'NEVER', 42));
    }
}
