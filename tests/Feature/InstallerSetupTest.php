<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class InstallerSetupTest extends TestCase
{
    public function test_setup_requires_the_installation_token(): void
    {
        Config::set('erp.setup.enabled', true);
        Config::set('erp.setup.token', 'test-token');

        $this->get('/setup')
            ->assertOk()
            ->assertSee('ต้องใช้ Installation Key')
            ->assertDontSee('System Check');
    }

    public function test_authorized_setup_can_render_without_application_tables(): void
    {
        Config::set('erp.setup.enabled', true);
        Config::set('erp.setup.token', 'test-token');

        $this->get('/setup?token=test-token')
            ->assertOk()
            ->assertSee('System Check')
            ->assertSee('Database connection');
    }
}
