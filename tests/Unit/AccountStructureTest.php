<?php

namespace Tests\Unit;

use App\Modules\Accounting\Rules\AccountStructure;
use PHPUnit\Framework\TestCase;

class AccountStructureTest extends TestCase
{
    public function test_parent_must_be_active_summary_account_in_the_same_type(): void
    {
        $this->assertTrue(AccountStructure::parentIsValid(true, false, 1, 1));
        $this->assertFalse(AccountStructure::parentIsValid(false, false, 1, 1));
        $this->assertFalse(AccountStructure::parentIsValid(true, true, 1, 1));
        $this->assertFalse(AccountStructure::parentIsValid(true, false, 1, 2));
    }

    public function test_accounts_with_children_cannot_be_postable_deactivated_or_deleted(): void
    {
        $this->assertTrue(AccountStructure::canBePostable(0));
        $this->assertFalse(AccountStructure::canBePostable(1));
        $this->assertTrue(AccountStructure::canDeactivate(0));
        $this->assertFalse(AccountStructure::canDeactivate(1));
        $this->assertTrue(AccountStructure::canDelete(0));
        $this->assertFalse(AccountStructure::canDelete(1));
    }

    public function test_level_follows_parent(): void
    {
        $this->assertSame(1, AccountStructure::level(null));
        $this->assertSame(4, AccountStructure::level(3));
    }

    public function test_level_is_limited_to_one_through_five(): void
    {
        $this->assertTrue(AccountStructure::levelIsValid(1));
        $this->assertTrue(AccountStructure::levelIsValid(5));
        $this->assertFalse(AccountStructure::levelIsValid(6));
    }

    public function test_control_account_must_be_postable(): void
    {
        $this->assertTrue(AccountStructure::controlTypeIsValid(null, false));
        $this->assertTrue(AccountStructure::controlTypeIsValid('AR', true));
        $this->assertFalse(AccountStructure::controlTypeIsValid('AR', false));
    }
}
