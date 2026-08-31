<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\PostingEvent;
use App\Modules\Accounting\Support\PostingIdentity;
use DomainException;
use PHPUnit\Framework\TestCase;

class PostingContractTest extends TestCase
{
    public function test_identity_is_stable_and_payload_changes_are_detected(): void
    {
        $key = PostingIdentity::key('WMS', 'supplier_invoice.inventory', 'INV-1001');

        $this->assertSame($key, PostingIdentity::key('WMS', 'supplier_invoice.inventory', 'INV-1001'));
        $this->assertNotSame($key, PostingIdentity::key('WMS', 'supplier_invoice.inventory', 'INV-1002'));
        $this->assertNotSame(PostingIdentity::fingerprint(['amount' => '100.00']), PostingIdentity::fingerprint(['amount' => '101.00']));
        $this->assertSame(
            PostingIdentity::fingerprint(['source' => ['type' => 'WMS', 'id' => '1'], 'amount' => '100.00']),
            PostingIdentity::fingerprint(['amount' => '100.00', 'source' => ['id' => '1', 'type' => 'WMS']]),
        );
        $this->assertSame('PURCHASE', PostingEvent::bookType('supplier_invoice.inventory'));
        $this->assertSame('GENERAL', PostingEvent::bookType('inventory_adjustment'));
    }

    public function test_unknown_events_are_rejected(): void
    {
        $this->expectException(DomainException::class);
        PostingEvent::bookType('custom.formula');
    }
}
