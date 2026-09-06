<?php

use App\Modules\Installer\Controllers\SetupController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\Facades\Route;

// The installer must work before the first migration creates database sessions.
Route::withoutMiddleware([VerifyCsrfToken::class, StartSession::class, ShareErrorsFromSession::class])->group(function (): void {
    Route::get('/setup', [SetupController::class, 'index'])->name('installer.index');
    Route::post('/setup/prepare-database', [SetupController::class, 'prepareDatabase'])->name('installer.prepare-database');
    Route::post('/setup/initialize-defaults', [SetupController::class, 'initializeDefaults'])->name('installer.initialize-defaults');
    Route::post('/setup/company', [SetupController::class, 'saveCompany'])->name('installer.company');
    Route::post('/setup/modules', [SetupController::class, 'selectModules'])->name('installer.modules');
    Route::post('/setup/organization', [SetupController::class, 'ensureOrganization'])->name('installer.organization');
    Route::post('/setup/administrator', [SetupController::class, 'createAdministrator'])->name('installer.administrator');
    Route::post('/setup/validate', [SetupController::class, 'validateSetup'])->name('installer.validate');
    Route::post('/setup/go-live', [SetupController::class, 'goLive'])->name('installer.go-live');
    Route::post('/setup/apply-system-updates', [SetupController::class, 'applySystemUpdates'])->name('installer.apply-system-updates');
    Route::post('/setup/reset-default-version-markers', [SetupController::class, 'resetDefaultVersionMarkers'])->name('installer.reset-default-version-markers');
    Route::post('/setup/import/opening-balance', [SetupController::class, 'importOpeningBalance'])->name('installer.import.opening-balance');
    Route::post('/setup/import/opening-balance/commit', [SetupController::class, 'commitOpeningBalance'])->name('installer.import.opening-balance.commit');
    Route::post('/setup/import/party', [SetupController::class, 'importParty'])->name('installer.import.party');
    Route::post('/setup/import/party/commit', [SetupController::class, 'commitParty'])->name('installer.import.party.commit');
    Route::post('/setup/import/items', [SetupController::class, 'importItems'])->name('installer.import.items');
    Route::post('/setup/import/items/commit', [SetupController::class, 'commitItems'])->name('installer.import.items.commit');
    Route::post('/setup/import/employees', [SetupController::class, 'importEmployees'])->name('installer.import.employees');
    Route::post('/setup/import/employees/commit', [SetupController::class, 'commitEmployees'])->name('installer.import.employees.commit');
    Route::post('/setup/import/open-items', [SetupController::class, 'importOpenItems'])->name('installer.import.open-items');
    Route::post('/setup/import/open-items/commit', [SetupController::class, 'commitOpenItems'])->name('installer.import.open-items.commit');
    Route::get('/setup/import/errors', [SetupController::class, 'importErrors'])->name('installer.import.errors');
});
