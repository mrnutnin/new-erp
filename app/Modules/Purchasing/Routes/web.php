<?php

use App\Modules\Purchasing\Controllers\EntryController;
use App\Modules\Purchasing\Controllers\PurchaseDocumentController;
use App\Modules\Purchasing\Controllers\PurchaseDocumentPdfController;
use App\Modules\Purchasing\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Controllers\PurchaseReceiptController;
use App\Modules\Purchasing\Controllers\PurchaseRequisitionController;
use App\Modules\Purchasing\Controllers\OperationalReportController;
use App\Modules\Purchasing\Controllers\LandedCostController;
use App\Modules\Purchasing\Controllers\SupplierController;
use App\Modules\Purchasing\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

/*
 * Canonical Purchasing surface. Purchasing owns the purchasing URL and
 * permission boundary; inventory/cost services remain a WMS integration seam.
 */
Route::middleware(['auth', 'program:purchasing', 'warehouse'])
    ->prefix('purchasing')
    ->name('purchasing.')
    ->group(function (): void {
        Route::get('/', EntryController::class)->middleware('permission:purchasing.dashboard.view')->name('index');
        Route::get('/dashboard/data/{section}', [EntryController::class, 'data'])->middleware('permission:purchasing.dashboard.view')->name('dashboard.data');
        Route::get('/workflow', [WorkflowController::class, 'index'])->name('workflow.index');
        Route::get('/reports', [OperationalReportController::class, 'index'])->middleware('permission:purchasing.reports.view')->name('reports.index');

        Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('permission:purchasing.suppliers.view')->name('suppliers.index');
        Route::get('/suppliers/data', [SupplierController::class, 'data'])->middleware('permission:purchasing.suppliers.view')->name('suppliers.data');
        Route::get('/suppliers/options', [SupplierController::class, 'options'])->middleware('permission:purchasing.suppliers.view')->name('suppliers.options');
        Route::get('/suppliers/create', [SupplierController::class, 'create'])->middleware('permission:purchasing.suppliers.create')->name('suppliers.create');
        Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('permission:purchasing.suppliers.create')->name('suppliers.store');
        Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->middleware('permission:purchasing.suppliers.update')->name('suppliers.edit');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:purchasing.suppliers.update')->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:purchasing.suppliers.delete')->name('suppliers.destroy');

        Route::get('/purchase-requisitions', [PurchaseRequisitionController::class, 'index'])->middleware('permission:purchasing.purchase-requisitions.view')->name('purchase-requisitions.index');
        Route::get('/purchase-requisitions/data', [PurchaseRequisitionController::class, 'data'])->middleware('permission:purchasing.purchase-requisitions.view')->name('purchase-requisitions.data');
        Route::get('/purchase-requisitions/supplier-options', [PurchaseRequisitionController::class, 'supplierOptions'])->middleware('permission:purchasing.purchase-requisitions.view')->name('purchase-requisitions.supplier-options');
        Route::get('/purchase-requisitions/item-options', [PurchaseRequisitionController::class, 'itemOptions'])->middleware('permission:purchasing.purchase-requisitions.view')->name('purchase-requisitions.item-options');
        Route::get('/purchase-requisitions/uom-options', [PurchaseRequisitionController::class, 'uomOptions'])->middleware('permission:purchasing.purchase-requisitions.view')->name('purchase-requisitions.uom-options');
        Route::get('/purchase-requisitions/create', [PurchaseRequisitionController::class, 'create'])->middleware('permission:purchasing.purchase-requisitions.create')->name('purchase-requisitions.create');
        Route::post('/purchase-requisitions', [PurchaseRequisitionController::class, 'store'])->middleware('permission:purchasing.purchase-requisitions.create')->name('purchase-requisitions.store');
        Route::get('/purchase-requisitions/{purchaseRequisition}/edit', [PurchaseRequisitionController::class, 'edit'])->middleware('permission:purchasing.purchase-requisitions.update')->name('purchase-requisitions.edit');
        Route::get('/purchase-requisitions/{purchaseRequisition}/pdf', [PurchaseDocumentPdfController::class, 'requisition'])->middleware('permission:purchasing.purchase-requisitions.print')->name('purchase-requisitions.pdf');
        Route::put('/purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'update'])->middleware('permission:purchasing.purchase-requisitions.update')->name('purchase-requisitions.update');
        Route::delete('/purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'destroy'])->middleware('permission:purchasing.purchase-requisitions.delete')->name('purchase-requisitions.destroy');
        Route::post('/purchase-requisitions/{purchaseRequisition}/submit', [PurchaseRequisitionController::class, 'submit'])->middleware('permission:purchasing.purchase-requisitions.submit')->name('purchase-requisitions.submit');
        Route::post('/purchase-requisitions/{purchaseRequisition}/approve', [PurchaseRequisitionController::class, 'approve'])->middleware('permission:purchasing.purchase-requisitions.approve')->name('purchase-requisitions.approve');
        Route::post('/purchase-requisitions/{purchaseRequisition}/reject', [PurchaseRequisitionController::class, 'reject'])->middleware('permission:purchasing.purchase-requisitions.reject')->name('purchase-requisitions.reject');
        Route::post('/purchase-requisitions/{purchaseRequisition}/void', [PurchaseRequisitionController::class, 'void'])->middleware('permission:purchasing.purchase-requisitions.void')->name('purchase-requisitions.void');
        Route::post('/purchase-requisitions/{purchaseRequisition}/create-po', [PurchaseRequisitionController::class, 'createPurchaseOrder'])->middleware('permission:purchasing.purchase-requisitions.create-po')->name('purchase-requisitions.create-po');

        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchasing.purchase-orders.view')->name('purchase-orders.index');
        Route::get('/purchase-orders/data', [PurchaseOrderController::class, 'data'])->middleware('permission:purchasing.purchase-orders.view')->name('purchase-orders.data');
        Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->middleware('permission:purchasing.purchase-orders.create')->name('purchase-orders.create');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchasing.purchase-orders.create')->name('purchase-orders.store');
        Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchasing.purchase-orders.view')->name('purchase-orders.show');
        Route::get('/purchase-orders/{purchaseOrder}/pdf', [PurchaseDocumentPdfController::class, 'order'])->middleware('permission:purchasing.purchase-orders.print')->name('purchase-orders.pdf');
        Route::get('/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->middleware('permission:purchasing.purchase-orders.update')->name('purchase-orders.edit');
        Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchasing.purchase-orders.update')->name('purchase-orders.update');
        Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->middleware('permission:purchasing.purchase-orders.delete')->name('purchase-orders.destroy');
        Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchasing.purchase-orders.approve')->name('purchase-orders.approve');
        Route::post('/purchase-orders/{purchaseOrder}/void', [PurchaseOrderController::class, 'void'])->middleware('permission:purchasing.purchase-orders.void')->name('purchase-orders.void');

        Route::get('/purchase-receipts', [PurchaseReceiptController::class, 'index'])->middleware('permission:purchasing.purchase-receipts.view')->name('purchase-receipts.index');
        Route::get('/purchase-receipts/data', [PurchaseReceiptController::class, 'data'])->middleware('permission:purchasing.purchase-receipts.view')->name('purchase-receipts.data');
        Route::get('/purchase-receipts/supplier-options', [PurchaseReceiptController::class, 'supplierOptions'])->middleware('permission:purchasing.purchase-receipts.view')->name('purchase-receipts.supplier-options');
        Route::get('/purchase-receipts/create', [PurchaseReceiptController::class, 'create'])->middleware('permission:purchasing.purchase-receipts.create')->name('purchase-receipts.create');
        Route::get('/purchase-receipts/{purchaseReceipt}/edit', [PurchaseReceiptController::class, 'edit'])->middleware('permission:purchasing.purchase-receipts.update')->name('purchase-receipts.edit');
        Route::get('/purchase-receipts/{purchaseReceipt}/pdf', [PurchaseDocumentPdfController::class, 'receipt'])->middleware('permission:purchasing.purchase-receipts.print')->name('purchase-receipts.pdf');
        Route::get('/purchase-receipts/purchase-options', [PurchaseReceiptController::class, 'purchaseOptions'])->middleware('permission:purchasing.purchase-receipts.create')->name('purchase-receipts.purchase-options');
        Route::get('/purchase-receipts/line-options', [PurchaseReceiptController::class, 'lineOptions'])->middleware('permission:purchasing.purchase-receipts.create')->name('purchase-receipts.line-options');
        Route::post('/purchase-receipts', [PurchaseReceiptController::class, 'store'])->middleware('permission:purchasing.purchase-receipts.create')->name('purchase-receipts.store');
        Route::put('/purchase-receipts/{purchaseReceipt}', [PurchaseReceiptController::class, 'update'])->middleware('permission:purchasing.purchase-receipts.update')->name('purchase-receipts.update');
        Route::post('/purchase-receipts/{purchaseReceipt}/approve', [PurchaseReceiptController::class, 'approve'])->middleware('permission:purchasing.purchase-receipts.approve')->name('purchase-receipts.approve');
        Route::post('/purchase-receipts/{purchaseReceipt}/void', [PurchaseReceiptController::class, 'void'])->middleware('permission:purchasing.purchase-receipts.void')->name('purchase-receipts.void');

        Route::get('/landed-costs', [LandedCostController::class, 'index'])->middleware('permission:purchasing.landed-costs.view')->name('landed-costs.index');
        Route::get('/landed-costs/data', [LandedCostController::class, 'data'])->middleware('permission:purchasing.landed-costs.view')->name('landed-costs.data');
        Route::get('/landed-costs/create', [LandedCostController::class, 'create'])->middleware('permission:purchasing.landed-costs.create')->name('landed-costs.create');
        Route::post('/landed-costs', [LandedCostController::class, 'store'])->middleware('permission:purchasing.landed-costs.create')->name('landed-costs.store');
        Route::get('/landed-costs/{landedCost}', [LandedCostController::class, 'show'])->middleware('permission:purchasing.landed-costs.view')->name('landed-costs.show');
        Route::post('/landed-costs/{landedCost}/submit', [LandedCostController::class, 'submit'])->middleware('permission:purchasing.landed-costs.submit')->name('landed-costs.submit');
        Route::post('/landed-costs/{landedCost}/approve', [LandedCostController::class, 'approve'])->middleware('permission:purchasing.landed-costs.approve')->name('landed-costs.approve');
        Route::post('/landed-costs/{landedCost}/post', [LandedCostController::class, 'post'])->middleware('permission:purchasing.landed-costs.post')->name('landed-costs.post');
        Route::post('/landed-costs/{landedCost}/void', [LandedCostController::class, 'void'])->middleware('permission:purchasing.landed-costs.void')->name('landed-costs.void');

        Route::get('/purchase-documents', [PurchaseDocumentController::class, 'index'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.index');
        Route::get('/purchase-documents/data', [PurchaseDocumentController::class, 'data'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.data');
        // Keep every AP option/matching endpoint on the canonical Purchasing
        // surface. The controller remains shared during extraction, but links
        // and AJAX calls no longer need to cross the WMS route boundary.
        Route::get('/purchase-documents/supplier-options', [PurchaseDocumentController::class, 'supplierOptions'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.supplier-options');
        Route::get('/purchase-documents/account-options', [PurchaseDocumentController::class, 'accountOptions'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.account-options');
        Route::get('/purchase-documents/item-options', [PurchaseDocumentController::class, 'itemOptions'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.item-options');
        Route::get('/purchase-documents/uom-options', [PurchaseDocumentController::class, 'uomOptions'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.uom-options');
        Route::get('/purchase-documents/tax-code-options', [PurchaseDocumentController::class, 'taxCodeOptions'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.tax-code-options');
        Route::get('/purchase-documents/withholding-tax-code-options', [PurchaseDocumentController::class, 'withholdingTaxCodeOptions'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.withholding-tax-code-options');
        Route::get('/purchase-documents/original-options', [PurchaseDocumentController::class, 'originalOptions'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.original-options');
        Route::get('/purchase-documents/purchase-order-line-options', [PurchaseDocumentController::class, 'purchaseOrderLineOptions'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.purchase-order-line-options');
        Route::get('/purchase-documents/goods-receipt-line-options', [PurchaseDocumentController::class, 'goodsReceiptLineOptions'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.goods-receipt-line-options');
        Route::get('/purchase-documents/goods-receipt-options', [PurchaseDocumentController::class, 'goodsReceiptOptions'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.goods-receipt-options');
        Route::get('/purchase-documents/goods-receipt-lines', [PurchaseDocumentController::class, 'goodsReceiptLines'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.goods-receipt-lines');
        Route::get('/purchase-documents/create', [PurchaseDocumentController::class, 'create'])->middleware('permission:purchasing.purchase-documents.create')->name('purchase-documents.create');
        Route::post('/purchase-documents', [PurchaseDocumentController::class, 'store'])->middleware('permission:purchasing.purchase-documents.create')->name('purchase-documents.store');
        Route::get('/purchase-documents/{purchaseDocument}', [PurchaseDocumentController::class, 'show'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.show');
        Route::get('/purchase-documents/{purchaseDocument}/pdf', [PurchaseDocumentPdfController::class, 'purchase'])->middleware('permission:purchasing.purchase-documents.print')->name('purchase-documents.pdf');
        Route::get('/purchase-documents/{purchaseDocument}/edit', [PurchaseDocumentController::class, 'edit'])->middleware('permission:purchasing.purchase-documents.update')->name('purchase-documents.edit');
        Route::put('/purchase-documents/{purchaseDocument}', [PurchaseDocumentController::class, 'update'])->middleware('permission:purchasing.purchase-documents.update')->name('purchase-documents.update');
        Route::delete('/purchase-documents/{purchaseDocument}', [PurchaseDocumentController::class, 'destroy'])->middleware('permission:purchasing.purchase-documents.delete')->name('purchase-documents.destroy');
        Route::post('/purchase-documents/{purchaseDocument}/approve', [PurchaseDocumentController::class, 'approve'])->middleware('permission:purchasing.purchase-documents.approve')->name('purchase-documents.approve');
        Route::get('/purchase-documents/{purchaseDocument}/three-way-match', [PurchaseDocumentController::class, 'threeWayMatch'])->middleware('permission:purchasing.purchase-documents.view')->name('purchase-documents.three-way-match');
        Route::post('/purchase-documents/{purchaseDocument}/variance-approve', [PurchaseDocumentController::class, 'varianceApprove'])->middleware('permission:purchasing.purchase-documents.approve')->name('purchase-documents.variance-approve');
        Route::post('/purchase-documents/{purchaseDocument}/variance-reject', [PurchaseDocumentController::class, 'varianceReject'])->middleware('permission:purchasing.purchase-documents.approve')->name('purchase-documents.variance-reject');
        Route::post('/purchase-documents/{purchaseDocument}/variance-recover', [PurchaseDocumentController::class, 'varianceRecover'])->middleware('permission:purchasing.purchase-documents.update')->name('purchase-documents.variance-recover');
        Route::post('/purchase-documents/{purchaseDocument}/post', [PurchaseDocumentController::class, 'post'])->middleware('permission:purchasing.purchase-documents.post')->name('purchase-documents.post');
        Route::post('/purchase-documents/{purchaseDocument}/inventory-post', [PurchaseDocumentController::class, 'inventoryPost'])->middleware('permission:purchasing.purchase-documents.inventory-post')->name('purchase-documents.inventory-post');
        Route::post('/purchase-documents/{purchaseDocument}/inventory-reverse', [PurchaseDocumentController::class, 'inventoryReverse'])->middleware('permission:purchasing.purchase-documents.inventory-reverse')->name('purchase-documents.inventory-reverse');
        Route::post('/purchase-documents/{purchaseDocument}/credit-inventory-reverse', [PurchaseDocumentController::class, 'creditInventoryReverse'])->middleware('permission:purchasing.purchase-documents.inventory-reverse')->name('purchase-documents.credit-inventory-reverse');
        Route::post('/purchase-documents/{purchaseDocument}/void', [PurchaseDocumentController::class, 'void'])->middleware('permission:purchasing.purchase-documents.void')->name('purchase-documents.void');
    });
