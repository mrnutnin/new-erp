<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\EffectiveDocumentDateResolver;
use PHPUnit\Framework\TestCase;

final class EffectiveDocumentDateResolverTest extends TestCase
{
    private EffectiveDocumentDateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new EffectiveDocumentDateResolver;
    }

    public function test_matching_document_movement_and_allocation_dates_are_ready(): void
    {
        $result = $this->resolver->assess('2026-09-01', '2026-09-01 18:00:00', '2026-09-01', 'INVENTORY_ADJUSTMENT_DOCUMENT');

        self::assertTrue($result['ready']);
        self::assertSame('2026-09-01', $result['effective_date']);
        self::assertSame([], $result['blockers']);
    }

    public function test_movement_or_allocation_date_difference_is_blocked(): void
    {
        $result = $this->resolver->assess('2026-09-01', '2026-09-07', '2026-09-01', 'INVENTORY_ADJUSTMENT_DOCUMENT');

        self::assertFalse($result['ready']);
        self::assertSame(['DATE_MISMATCH'], $result['blockers']);
        self::assertSame('2026-09-07', $result['movement_date']);
    }

    public function test_missing_document_authority_is_not_guessed_from_ledger_dates(): void
    {
        $result = $this->resolver->assess(null, '2026-09-01', '2026-09-01', null);

        self::assertFalse($result['ready']);
        self::assertSame(['DATE_AUTHORITY_UNRESOLVED'], $result['blockers']);
        self::assertNull($result['effective_date']);
    }
}
