<?php

use App\Modules\Platform\Controllers\AuthController;
use App\Modules\Platform\Controllers\ContextController;
use App\Modules\Platform\Controllers\DashboardController;
use App\Modules\Platform\Controllers\EntryController;
use App\Modules\Platform\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', EntryController::class)->name('entry');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/select-program', [ContextController::class, 'programs'])->name('programs.index');
    Route::post('/select-program', [ContextController::class, 'storeProgram'])->name('programs.store');
    Route::get('/handoff/finance/settlements/create', [ContextController::class, 'handoffToFinanceSettlement'])->name('context.finance-settlement.handoff');

    Route::middleware('program')->group(function () {
        Route::get('/select-branch', [ContextController::class, 'branches'])->name('branches.index');
        Route::post('/select-branch', [ContextController::class, 'storeBranch'])->name('branches.store');
        Route::get('/select-warehouse', [ContextController::class, 'warehouses'])->name('warehouses.index');

        Route::get('/dashboard', DashboardController::class)
            ->middleware('warehouse')
            ->name('dashboard');
    });
});
