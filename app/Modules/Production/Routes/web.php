<?php

use App\Modules\Production\Controllers\BomController;
use App\Modules\Production\Controllers\EntryController;
use App\Modules\Production\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'program:production', 'capability:production', 'warehouse'])
    ->prefix('production')
    ->name('production.')
    ->group(function (): void {
        Route::get('/', EntryController::class)
            ->middleware('permission:production.dashboard.view')
            ->name('index');

        Route::get('/demand', [OrderController::class, 'demand'])->middleware('permission:production.orders.view')->name('demand.index');
        Route::get('/demand/data', [OrderController::class, 'demandData'])->middleware('permission:production.orders.view')->name('demand.data');
        Route::post('/demand/{line}/orders', [OrderController::class, 'storeFromDemand'])->middleware('permission:production.orders.create')->name('orders.store-from-demand');
        Route::get('/shop-floor', [OrderController::class, 'shopFloor'])->middleware('permission:production.shop_floor.use')->name('shop-floor.index');
        Route::get('/shop-floor/issues', [OrderController::class, 'shopFloorIssues'])->middleware('permission:production.shop_floor.use')->name('shop-floor.issues');
        Route::get('/shop-floor/issues/data', [OrderController::class, 'shopFloorIssuesData'])->middleware('permission:production.shop_floor.use')->name('shop-floor.issues.data');
        Route::post('/shop-floor/{order}/issues', [OrderController::class, 'reportShopFloorIssue'])->middleware('permission:production.shop_floor.use')->name('shop-floor.issues.report');
        Route::post('/shop-floor/issues/{issue}/resolve', [OrderController::class, 'resolveShopFloorIssue'])->middleware('permission:production.shop_floor.use')->name('shop-floor.issues.resolve');
        Route::get('/shop-floor/{order}', [OrderController::class, 'shopFloorShow'])->middleware('permission:production.shop_floor.use')->name('shop-floor.show');
        Route::get('/shop-floor/{order}/items/{item}/cover-image', [OrderController::class, 'shopFloorItemImage'])->middleware('permission:production.shop_floor.use')->name('shop-floor.item-image');
        Route::get('/planning', [OrderController::class, 'planning'])->middleware('permission:production.orders.view')->name('planning.index');
        Route::get('/planning/options/{type}', [OrderController::class, 'planningOptions'])->middleware('permission:production.orders.view')->name('planning.options');
        Route::get('/reports/material-movements', [OrderController::class, 'materialMovements'])->middleware('permission:production.reports.view')->name('reports.material-movements');
        Route::get('/reports/material-movements/data', [OrderController::class, 'materialMovementsData'])->middleware('permission:production.reports.view')->name('reports.material-movements.data');
        Route::get('/reports', [OrderController::class, 'reports'])->middleware('permission:production.reports.view')->name('reports.index');
        Route::get('/reports/data', [OrderController::class, 'reportsData'])->middleware('permission:production.reports.view')->name('reports.data');
        Route::get('/reports/cost', [OrderController::class, 'costReports'])->middleware('permission:production.reports.view')->name('reports.cost');
        Route::get('/reports/cost/data', [OrderController::class, 'costReportsData'])->middleware('permission:production.reports.view')->name('reports.cost.data');
        Route::get('/orders', [OrderController::class, 'index'])->middleware('permission:production.orders.view')->name('orders.index');
        Route::get('/orders/data', [OrderController::class, 'data'])->middleware('permission:production.orders.view')->name('orders.data');
        Route::get('/orders/create', [OrderController::class, 'create'])->middleware('permission:production.orders.create')->name('orders.create');
        Route::post('/orders', [OrderController::class, 'store'])->middleware('permission:production.orders.create')->name('orders.store');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('permission:production.orders.view')->name('orders.show');
        Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])->middleware('permission:production.orders.update')->name('orders.edit');
        Route::put('/orders/{order}', [OrderController::class, 'update'])->middleware('permission:production.orders.update')->name('orders.update');
        Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->middleware('permission:production.orders.delete')->name('orders.destroy');
        Route::get('/orders/{order}/journal-preview/{journalEntry}', [OrderController::class, 'journalPreview'])->middleware('permission:production.orders.gl.view')->name('orders.journal-preview');
        Route::post('/orders/{order}/release', [OrderController::class, 'release'])->middleware('permission:production.orders.release')->name('orders.release');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('permission:production.orders.cancel')->name('orders.cancel');
        Route::post('/orders/{order}/recoverable-scrap-receipt', [OrderController::class, 'createRecoverableScrapReceipt'])->middleware('permission:production.shop_floor.use|production.execution.prepare')->name('orders.recoverable-scrap-receipt');
        Route::delete('/orders/{order}/recoverable-scrap-receipts/{document}', [OrderController::class, 'deleteRecoverableScrapReceipt'])->middleware('permission:production.shop_floor.use|production.execution.prepare')->name('orders.recoverable-scrap-receipts.destroy');
        Route::post('/orders/{order}/recoverable-scrap-receipts/{document}/approve', [OrderController::class, 'approveRecoverableScrapReceipt'])->middleware('permission:production.shop_floor.use|production.execution.prepare')->name('orders.recoverable-scrap-receipts.approve');
        Route::post('/orders/{order}/recoverable-scrap-receipts/{document}/post', [OrderController::class, 'postRecoverableScrapReceipt'])->middleware('permission:production.shop_floor.use|production.execution.post')->name('orders.recoverable-scrap-receipts.post');
        Route::post('/orders/{order}/recoverable-scrap-receipts/{document}/reverse', [OrderController::class, 'reverseRecoverableScrapReceipt'])->middleware('permission:production.shop_floor.use|production.execution.reverse')->name('orders.recoverable-scrap-receipts.reverse');
        Route::post('/orders/{order}/non-recoverable-scrap', [OrderController::class, 'reportNonRecoverableScrap'])->middleware('permission:production.shop_floor.use|production.execution.prepare')->name('orders.non-recoverable-scrap');
        Route::post('/orders/{order}/finished-receipt', [OrderController::class, 'createFinishedReceipt'])->middleware('permission:production.shop_floor.use|production.execution.prepare')->name('orders.finished-receipt');
        Route::post('/orders/{order}/finished-receipts/{document}/approve', [OrderController::class, 'approveFinishedReceipt'])->middleware('permission:production.shop_floor.use|production.execution.prepare')->name('orders.finished-receipts.approve');
        Route::post('/orders/{order}/finished-receipts/{document}/post', [OrderController::class, 'postFinishedReceipt'])->middleware('permission:production.shop_floor.use|production.execution.post')->name('orders.finished-receipts.post');
        Route::post('/orders/{order}/material-return', [OrderController::class, 'createMaterialReturn'])->middleware('permission:production.shop_floor.use|production.execution.prepare')->name('orders.material-return');
        Route::post('/orders/{order}/material-returns/{document}/approve', [OrderController::class, 'approveMaterialReturn'])->middleware('permission:production.shop_floor.use|production.execution.prepare')->name('orders.material-returns.approve');
        Route::post('/orders/{order}/material-returns/{document}/post', [OrderController::class, 'postMaterialReturn'])->middleware('permission:production.shop_floor.use|production.execution.post')->name('orders.material-returns.post');
        Route::post('/orders/{order}/material-returns/{document}/reverse', [OrderController::class, 'reverseMaterialReturn'])->middleware('permission:production.shop_floor.use|production.execution.reverse')->name('orders.material-returns.reverse');
        Route::post('/shop-floor/{order}/start', [OrderController::class, 'startFromShopFloor'])->middleware('permission:production.shop_floor.use|production.orders.start')->name('shop-floor.start');
        Route::post('/shop-floor/{order}/hold', [OrderController::class, 'hold'])->middleware('permission:production.orders.hold')->name('shop-floor.hold');
        Route::post('/shop-floor/{order}/resume', [OrderController::class, 'resume'])->middleware('permission:production.orders.hold')->name('shop-floor.resume');
        Route::post('/shop-floor/{order}/operations', [OrderController::class, 'addOperation'])->middleware('permission:production.routing.create')->name('shop-floor.operation.store');
        Route::put('/shop-floor/{order}/operations/{operation}', [OrderController::class, 'updateOperation'])->middleware('permission:production.routing.update')->name('shop-floor.operation.update');
        Route::delete('/shop-floor/{order}/operations/{operation}', [OrderController::class, 'deleteOperation'])->middleware('permission:production.routing.delete')->name('shop-floor.operation.delete');
        Route::post('/shop-floor/{order}/operations/{operation}/start', [OrderController::class, 'startOperation'])->middleware('permission:production.operations.execute')->name('shop-floor.operation.start');
        Route::post('/shop-floor/{order}/operations/{operation}/complete', [OrderController::class, 'completeOperation'])->middleware('permission:production.operations.execute')->name('shop-floor.operation.complete');
        Route::post('/orders/{order}/reserve-materials', [OrderController::class, 'reserveMaterials'])->middleware('permission:production.shop_floor.use|production.execution.prepare')->name('orders.reserve-materials');
        Route::post('/orders/{order}/material-issue', [OrderController::class, 'createMaterialIssue'])->middleware('permission:production.shop_floor.use|production.execution.prepare')->name('orders.material-issue');
        Route::post('/orders/{order}/material-issues/{document}/approve', [OrderController::class, 'approveMaterialIssue'])->middleware('permission:production.shop_floor.use|production.execution.approve')->name('orders.material-issues.approve');
        Route::post('/orders/{order}/material-issues/{document}/post', [OrderController::class, 'postMaterialIssue'])->middleware('permission:production.shop_floor.use|production.execution.post')->name('orders.material-issues.post');
        Route::post('/orders/{order}/material-issues/{document}/reverse', [OrderController::class, 'reverseMaterialIssue'])->middleware('permission:production.shop_floor.use|production.execution.reverse')->name('orders.material-issues.reverse');

        Route::get('/boms', [BomController::class, 'index'])->middleware('permission:production.boms.view')->name('boms.index');
        Route::get('/boms/data', [BomController::class, 'data'])->middleware('permission:production.boms.view')->name('boms.data');
        Route::get('/boms/item-options', [BomController::class, 'itemOptions'])->middleware('permission:production.boms.view')->name('boms.item-options');
        Route::get('/boms/create', [BomController::class, 'create'])->middleware('permission:production.boms.create')->name('boms.create');
        Route::post('/boms', [BomController::class, 'store'])->middleware('permission:production.boms.create')->name('boms.store');
        Route::get('/boms/{bom}', [BomController::class, 'show'])->middleware('permission:production.boms.view')->name('boms.show');
        Route::get('/boms/{bom}/revisions/{revision}/edit', [BomController::class, 'edit'])->middleware('permission:production.boms.update')->name('boms.revisions.edit');
        Route::put('/boms/{bom}/revisions/{revision}', [BomController::class, 'update'])->middleware('permission:production.boms.update')->name('boms.revisions.update');
        Route::delete('/boms/{bom}/revisions/{revision}', [BomController::class, 'destroy'])->middleware('permission:production.boms.delete')->name('boms.destroy');
        Route::post('/boms/{bom}/revisions/{revision}/copy', [BomController::class, 'copy'])->middleware('permission:production.boms.create')->name('boms.revisions.copy');
        Route::post('/boms/{bom}/revisions/{revision}/activate', [BomController::class, 'activate'])->middleware('permission:production.boms.activate')->name('boms.revisions.activate');
        Route::post('/boms/{bom}/revisions/{revision}/deactivate', [BomController::class, 'deactivate'])->middleware('permission:production.boms.deactivate')->name('boms.revisions.deactivate');
    });
