<?php

use App\Modules\Dashboard\Controllers\ExecutiveDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'permission:dashboard.executive.view'])->group(function () {
    Route::get('/dashboard', [ExecutiveDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/data', [ExecutiveDashboardController::class, 'data'])->name('dashboard.data');
});
