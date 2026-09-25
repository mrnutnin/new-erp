<?php

namespace Tests\Unit;

use Tests\TestCase;

class InstallerDatabasePreparationCommandTest extends TestCase
{
    public function test_web_creates_background_prepare_job_and_exposes_progress_for_the_installer_ui(): void
    {
        $command = file_get_contents(base_path('routes/console.php'));
        $controller = file_get_contents(base_path('app/Modules/Installer/Controllers/SetupController.php'));
        $job = file_get_contents(base_path('app/Modules/Installer/Jobs/PrepareDatabaseJob.php'));
        $service = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));
        $routes = file_get_contents(base_path('routes/web.php'));
        $queueConfig = file_get_contents(base_path('config/queue.php'));
        $view = file_get_contents(base_path('app/Modules/Installer/Views/setup/index.blade.php'));

        self::assertStringContainsString("Artisan::command('erp:setup:prepare-database'", $command);
        self::assertStringContainsString('app(DatabasePreparationService::class)->prepare()', $command);
        self::assertStringContainsString('Queue::connection', $controller);
        self::assertStringContainsString('function_exists(\'proc_open\')', $controller);
        self::assertStringContainsString('push(new PrepareDatabaseJob($jobId))', $controller);
        self::assertStringContainsString("'background' => [\n            'driver' => 'background'", $queueConfig);
        self::assertStringContainsString('PrepareDatabaseJob($jobId)', $controller);
        self::assertStringContainsString("Route::get('/setup/prepare-database/status'", $routes);
        self::assertStringContainsString('$preparation->prepare($this->jobId)', $job);
        self::assertStringContainsString('MigrationEnded::class', $service);
        self::assertStringContainsString("'progress' => \$progress", $service);
        self::assertStringContainsString("asset('css/app.css')", $view);
        self::assertStringContainsString('btn btn-app-primary', $view);
        self::assertStringContainsString('Initialize System Defaults → Company Information / Default Organization / Administrator → Seed Master Data UAT', $view);
        self::assertStringContainsString('data-status-url=', $view);
        self::assertStringContainsString('Seed Master Data UAT', $view);
        self::assertStringContainsString('poll(payload.job_id, startedAt)', $view);
    }
}
