<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmContactPermissionContractTest extends TestCase
{
    #[Test]
    public function contact_permission_metadata_has_reversible_schema_and_installer_guard(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_17_160000_add_contact_permissions_to_party_contacts.php'));
        $schema = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));
        $model = file_get_contents(base_path('app/Models/PartyContact.php'));

        self::assertStringContainsString("'contact_permission_status'", $migration);
        self::assertStringContainsString("dropConstrainedForeignId('permission_recorded_by')", $migration);
        self::assertStringContainsString("'allow_phone'", $schema);
        self::assertStringContainsString("'permission_recorded_at'", $schema);
        self::assertStringContainsString("'permission_recorded_by'", $model);
    }

    #[Test]
    public function allowed_contact_requires_a_lawful_basis_and_channel_while_blocked_clears_channels(): void
    {
        $request = file_get_contents(base_path('app/Modules/Crm/Requests/SavePartyContactRequest.php'));
        $controller = file_get_contents(base_path('app/Modules/Crm/Controllers/ContactController.php'));
        $show = file_get_contents(base_path('app/Modules/Crm/Views/customers/show.blade.php'));

        self::assertStringContainsString("['UNKNOWN', 'ALLOWED', 'BLOCKED']", $request);
        self::assertStringContainsString("Rule::requiredIf", $request);
        self::assertStringContainsString('กรุณาเลือกช่องทางที่อนุญาตให้ติดต่ออย่างน้อย 1 ช่องทาง', $request);
        self::assertStringContainsString("\$allowed && \$request->boolean('allow_phone')", $controller);
        self::assertStringContainsString("'permission_recorded_by' => \$changed ? \$request->user()->id", $controller);
        self::assertStringContainsString('ไม่ใช่คำรับรองการปฏิบัติตาม PDPA', $show);
        self::assertStringContainsString('ไม่อนุญาตให้ติดต่อ', $show);
    }
}
