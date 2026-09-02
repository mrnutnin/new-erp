<?php

namespace Tests\Unit;

use App\Modules\Accounting\Controllers\AccountMappingController;
use App\Modules\Accounting\Requests\SaveAccountMappingRequest;
use PHPUnit\Framework\TestCase;

final class PostingConfigurationUiContractTest extends TestCase
{
    public function test_controller_scopes_new_configuration_by_event_role_and_uses_shared_account_constraint(): void
    {
        $source = file_get_contents((new \ReflectionClass(AccountMappingController::class))->getFileName());

        self::assertStringContainsString("'event_code' => ['nullable'", $source);
        self::assertStringContainsString('assertEventRole', $source);
        self::assertStringContainsString('applyCompatibleAccountConstraint', $source);
        self::assertStringContainsString("whereNull('accounting_account_mappings.event_code')", $source);
        self::assertStringContainsString("'reason' => \$request->input('reason')", $source);
    }

    public function test_request_uses_event_role_uniqueness_and_does_not_persist_a_blank_legacy_scope(): void
    {
        $source = file_get_contents((new \ReflectionClass(SaveAccountMappingRequest::class))->getFileName());

        self::assertStringContainsString("where('event_code', \$this->input('event_code'))", $source);
        self::assertStringContainsString("whereNull('event_code')", $source);
        self::assertStringContainsString('assertEventRole', $source);
        self::assertStringContainsString("unset(\$values['event_code'])", $source);
    }
}
