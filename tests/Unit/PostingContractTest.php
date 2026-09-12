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
        $this->assertSame(['INVENTORY', 'ACCOUNTS_PAYABLE'], PostingEvent::roles('supplier_invoice.inventory'));
        $this->assertSame('NO_GL', PostingEvent::contract('asset.branch_transfer')['status']);
        $this->assertSame('DEFERRED', PostingEvent::contract('expense_payment')['status']);
        $this->assertSame(['COGS', 'INVENTORY'], PostingEvent::roles('inventory.revaluation.cogs'));
        $this->assertSame(['ISSUE_EXPENSE', 'INVENTORY'], PostingEvent::roles('inventory.revaluation.issue_expense'));
        $this->assertSame(['WIP', 'INVENTORY'], PostingEvent::roles('production.revaluation.wip'));
        $this->assertSame(['FINISHED_GOODS', 'WIP'], PostingEvent::roles('production.revaluation.finished_goods'));
        $this->assertSame(['PURCHASE_RETURN_VARIANCE', 'INVENTORY'], PostingEvent::roles('purchasing.revaluation.return_cost'));
        $this->assertSame(['ISSUE_EXPENSE', 'INVENTORY'], PostingEvent::roles('inventory.issue'));
        $this->assertSame(['INVENTORY', 'ISSUE_EXPENSE'], PostingEvent::roles('inventory.issue_return'));
        $this->assertSame('LIVE', PostingEvent::contract('production.material_issue')['status']);
        $this->assertSame(['INVENTORY', 'WIP'], PostingEvent::roles('production.material_return'));
    }

    public function test_unknown_events_are_rejected(): void
    {
        $this->expectException(DomainException::class);
        PostingEvent::bookType('custom.formula');
    }
}
