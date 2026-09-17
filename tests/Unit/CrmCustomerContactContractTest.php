<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmCustomerContactContractTest extends TestCase
{
    #[Test]
    public function customer_contacts_have_reversible_schema_model_and_installer_guard(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_17_150000_create_party_contacts.php'));
        $party = file_get_contents(base_path('app/Models/Party.php'));
        $schema = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        self::assertStringContainsString("Schema::create('party_contacts'", $migration);
        self::assertStringContainsString("Schema::dropIfExists('party_contacts')", $migration);
        self::assertStringContainsString("'decision_role'", $migration);
        self::assertStringContainsString('public function contacts(): HasMany', $party);
        self::assertStringContainsString("'party_contacts' =>", $schema);
    }

    #[Test]
    public function contact_crud_is_validated_scoped_audited_and_available_from_customer_360(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Crm/Controllers/ContactController.php'));
        $request = file_get_contents(base_path('app/Modules/Crm/Requests/SavePartyContactRequest.php'));
        $routes = file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Crm/Views/customers/show.blade.php'));

        self::assertStringContainsString("required_without_all:email,line_id", $request);
        self::assertStringContainsString("['DECISION_MAKER', 'INFLUENCER', 'USER', 'GATEKEEPER', 'OTHER']", $request);
        self::assertStringContainsString('(int) $contact->party_id === (int) $customer->id', $controller);
        self::assertStringContainsString("crm.customer-contact.created", $controller);
        self::assertStringContainsString("Route::post('/customers/{customer}/contacts'", $routes);
        self::assertStringContainsString('id="contact-form"', $show);
        self::assertStringContainsString('js-delete-contact', $show);
    }
}
