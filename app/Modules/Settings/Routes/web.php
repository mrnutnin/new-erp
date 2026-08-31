<?php

use App\Modules\Finance\Controllers\DocumentSequenceController;
use App\Modules\Settings\Controllers\AuditLogController;
use App\Modules\Settings\Controllers\BranchController;
use App\Modules\Settings\Controllers\CompanySettingController;
use App\Modules\Settings\Controllers\EntryController;
use App\Modules\Settings\Controllers\RoleController;
use App\Modules\Settings\Controllers\UserController;
use App\Modules\Settings\Controllers\WarehouseController;
use App\Modules\Settings\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'program:settings'])
    ->prefix('settings')
    ->name('settings.')
    ->group(function () {
        Route::get('/', EntryController::class)->name('index');
        Route::get('/workflow', [WorkflowController::class, 'index'])->name('workflow.index');

        Route::get('/company', [CompanySettingController::class, 'edit'])
            ->middleware('permission:settings.company.view')->name('company.edit');
        Route::put('/company', [CompanySettingController::class, 'update'])
            ->middleware('permission:settings.company.update')->name('company.update');

        // Permission names remain under finance during the transition so existing roles keep access.
        Route::get('/document-sequences', [DocumentSequenceController::class, 'index'])
            ->middleware('permission:finance.document-sequences.view')->name('document-sequences.index');
        Route::get('/document-sequences/data', [DocumentSequenceController::class, 'data'])
            ->middleware('permission:finance.document-sequences.view')->name('document-sequences.data');
        Route::get('/document-sequences/{documentSequence}/edit', [DocumentSequenceController::class, 'edit'])
            ->middleware('permission:finance.document-sequences.update')->name('document-sequences.edit');
        Route::put('/document-sequences/{documentSequence}', [DocumentSequenceController::class, 'update'])
            ->middleware('permission:finance.document-sequences.update')->name('document-sequences.update');

        Route::get('/users', [UserController::class, 'index'])
            ->middleware('permission:settings.users.view')->name('users.index');
        Route::get('/users/data', [UserController::class, 'data'])
            ->middleware('permission:settings.users.view')->name('users.data');
        Route::get('/users/export', [UserController::class, 'export'])
            ->middleware('permission:settings.users.view')->name('users.export');
        Route::get('/users/create', [UserController::class, 'create'])
            ->middleware('permission:settings.users.create')->name('users.create');
        Route::post('/users', [UserController::class, 'store'])
            ->middleware('permission:settings.users.create')->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])
            ->middleware('permission:settings.users.update')->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])
            ->middleware('permission:settings.users.update')->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])
            ->middleware('permission:settings.users.delete')->name('users.destroy');

        Route::get('/branches', [BranchController::class, 'index'])
            ->middleware('permission:settings.branches.view')->name('branches.index');
        Route::get('/branches/data', [BranchController::class, 'data'])
            ->middleware('permission:settings.branches.view')->name('branches.data');
        Route::get('/branches/export', [BranchController::class, 'export'])
            ->middleware('permission:settings.branches.view')->name('branches.export');
        Route::get('/branches/create', [BranchController::class, 'create'])
            ->middleware('permission:settings.branches.create')->name('branches.create');
        Route::post('/branches', [BranchController::class, 'store'])
            ->middleware('permission:settings.branches.create')->name('branches.store');
        Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])
            ->middleware('permission:settings.branches.update')->name('branches.edit');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])
            ->middleware('permission:settings.branches.update')->name('branches.update');
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])
            ->middleware('permission:settings.branches.delete')->name('branches.destroy');

        Route::get('/warehouses', [WarehouseController::class, 'index'])
            ->middleware('permission:settings.warehouses.view')->name('warehouses.index');
        Route::get('/warehouses/data', [WarehouseController::class, 'data'])
            ->middleware('permission:settings.warehouses.view')->name('warehouses.data');
        Route::get('/warehouses/export', [WarehouseController::class, 'export'])
            ->middleware('permission:settings.warehouses.view')->name('warehouses.export');
        Route::get('/warehouses/create', [WarehouseController::class, 'create'])
            ->middleware('permission:settings.warehouses.create')->name('warehouses.create');
        Route::post('/warehouses', [WarehouseController::class, 'store'])
            ->middleware('permission:settings.warehouses.create')->name('warehouses.store');
        Route::get('/warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])
            ->middleware('permission:settings.warehouses.update')->name('warehouses.edit');
        Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])
            ->middleware('permission:settings.warehouses.update')->name('warehouses.update');
        Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])
            ->middleware('permission:settings.warehouses.delete')->name('warehouses.destroy');

        Route::get('/roles', [RoleController::class, 'index'])
            ->middleware('permission:settings.roles.view')->name('roles.index');
        Route::get('/roles/data', [RoleController::class, 'data'])
            ->middleware('permission:settings.roles.view')->name('roles.data');
        Route::get('/roles/export', [RoleController::class, 'export'])
            ->middleware('permission:settings.roles.view')->name('roles.export');
        Route::get('/roles/create', [RoleController::class, 'create'])
            ->middleware('permission:settings.roles.manage')->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])
            ->middleware('permission:settings.roles.manage')->name('roles.store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])
            ->middleware('permission:settings.roles.manage')->name('roles.edit');
        Route::put('/roles/{role}', [RoleController::class, 'update'])
            ->middleware('permission:settings.roles.manage')->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
            ->middleware('permission:settings.roles.delete')->name('roles.destroy');

        Route::get('/audit', [AuditLogController::class, 'index'])
            ->middleware('permission:settings.audit.view')->name('audit.index');
        Route::get('/audit/data', [AuditLogController::class, 'data'])
            ->middleware('permission:settings.audit.view')->name('audit.data');
        Route::get('/audit/export', [AuditLogController::class, 'export'])
            ->middleware('permission:settings.audit.view')->name('audit.export');
    });
