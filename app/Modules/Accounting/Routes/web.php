<?php

use App\Modules\Accounting\Controllers\AccountController;
use App\Modules\Accounting\Controllers\AccountImportController;
use App\Modules\Accounting\Controllers\AccountingReportController;
use App\Modules\Accounting\Controllers\AccountMappingController;
use App\Modules\Accounting\Controllers\EntryController;
use App\Modules\Accounting\Controllers\FiscalPeriodController;
use App\Modules\Accounting\Controllers\FiscalYearController;
use App\Modules\Accounting\Controllers\JournalBookController;
use App\Modules\Accounting\Controllers\JournalEntryController;
use App\Modules\Accounting\Controllers\TaxCodeController;
use App\Modules\Accounting\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'warehouse', 'permission:accounting.journal-entries.view'])
    ->prefix('accounting')
    ->name('accounting.')
    ->group(function () {
        Route::get('/journal-preview/{journalEntry}', [JournalEntryController::class, 'preview'])->name('journal-preview.show');
    });

Route::middleware(['auth', 'program:accounting', 'warehouse'])
    ->prefix('accounting')
    ->name('accounting.')
    ->group(function () {
        Route::get('/', EntryController::class)->name('index');
        Route::get('/workflow', [WorkflowController::class, 'index'])->name('workflow.index');

        Route::get('/accounts', [AccountController::class, 'index'])->middleware('permission:accounting.accounts.view')->name('accounts.index');
        Route::get('/accounts/parent-options', [AccountController::class, 'parentOptions'])->middleware('permission:accounting.accounts.view')->name('accounts.parent-options');
        Route::get('/accounts/data', [AccountController::class, 'data'])->middleware('permission:accounting.accounts.view')->name('accounts.data');
        Route::get('/accounts/export', [AccountController::class, 'export'])->middleware('permission:accounting.accounts.view')->name('accounts.export');
        Route::get('/accounts/create', [AccountController::class, 'create'])->middleware('permission:accounting.accounts.create')->name('accounts.create');
        Route::post('/accounts', [AccountController::class, 'store'])->middleware('permission:accounting.accounts.create')->name('accounts.store');
        Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])->middleware('permission:accounting.accounts.update')->name('accounts.edit');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])->middleware('permission:accounting.accounts.update')->name('accounts.update');
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->middleware('permission:accounting.accounts.delete')->name('accounts.destroy');
        Route::get('/account-import', [AccountImportController::class, 'create'])->middleware('permission:accounting.accounts.import')->name('account-import.create');
        Route::get('/account-import/template', [AccountImportController::class, 'template'])->middleware('permission:accounting.accounts.import')->name('account-import.template');
        Route::post('/account-import', [AccountImportController::class, 'stage'])->middleware('permission:accounting.accounts.import')->name('account-import.stage');
        Route::get('/account-import/{batch}', [AccountImportController::class, 'show'])->middleware('permission:accounting.accounts.import')->name('account-import.show');
        Route::get('/account-import/{batch}/errors', [AccountImportController::class, 'errors'])->middleware('permission:accounting.accounts.import')->name('account-import.errors');
        Route::put('/account-import/{batch}/commit', [AccountImportController::class, 'commit'])->middleware('permission:accounting.accounts.import.commit')->name('account-import.commit');
        Route::get('/account-mappings', [AccountMappingController::class, 'index'])->middleware('permission:accounting.account-mappings.view')->name('account-mappings.index');
        Route::get('/account-mappings/data', [AccountMappingController::class, 'data'])->middleware('permission:accounting.account-mappings.view')->name('account-mappings.data');
        Route::get('/account-mappings/account-options', [AccountMappingController::class, 'accountOptions'])->middleware('permission:accounting.account-mappings.view')->name('account-mappings.account-options');
        Route::get('/account-mappings/create', [AccountMappingController::class, 'create'])->middleware('permission:accounting.account-mappings.create')->name('account-mappings.create');
        Route::post('/account-mappings', [AccountMappingController::class, 'store'])->middleware('permission:accounting.account-mappings.create')->name('account-mappings.store');
        Route::get('/account-mappings/{accountMapping}/edit', [AccountMappingController::class, 'edit'])->middleware('permission:accounting.account-mappings.update')->name('account-mappings.edit');
        Route::put('/account-mappings/{accountMapping}', [AccountMappingController::class, 'update'])->middleware('permission:accounting.account-mappings.update')->name('account-mappings.update');
        Route::get('/tax-codes', [TaxCodeController::class, 'index'])->middleware('permission:accounting.tax-codes.view')->name('tax-codes.index');
        Route::get('/tax-codes/data', [TaxCodeController::class, 'data'])->middleware('permission:accounting.tax-codes.view')->name('tax-codes.data');
        Route::get('/tax-codes/create', [TaxCodeController::class, 'create'])->middleware('permission:accounting.tax-codes.create')->name('tax-codes.create');
        Route::post('/tax-codes', [TaxCodeController::class, 'store'])->middleware('permission:accounting.tax-codes.create')->name('tax-codes.store');
        Route::get('/tax-codes/{taxCode}/edit', [TaxCodeController::class, 'edit'])->middleware('permission:accounting.tax-codes.update')->name('tax-codes.edit');
        Route::put('/tax-codes/{taxCode}', [TaxCodeController::class, 'update'])->middleware('permission:accounting.tax-codes.update')->name('tax-codes.update');
        Route::delete('/tax-codes/{taxCode}', [TaxCodeController::class, 'destroy'])->middleware('permission:accounting.tax-codes.delete')->name('tax-codes.destroy');

        Route::get('/fiscal-years', [FiscalYearController::class, 'index'])->middleware('permission:accounting.periods.view')->name('fiscal-years.index');
        Route::get('/fiscal-years/data', [FiscalYearController::class, 'data'])->middleware('permission:accounting.periods.view')->name('fiscal-years.data');
        Route::get('/fiscal-years/export', [FiscalYearController::class, 'export'])->middleware('permission:accounting.periods.view')->name('fiscal-years.export');
        Route::get('/fiscal-years/create', [FiscalYearController::class, 'create'])->middleware('permission:accounting.periods.create')->name('fiscal-years.create');
        Route::post('/fiscal-years', [FiscalYearController::class, 'store'])->middleware('permission:accounting.periods.create')->name('fiscal-years.store');
        Route::get('/fiscal-years/{fiscalYear}', [FiscalYearController::class, 'show'])->middleware('permission:accounting.periods.view')->name('fiscal-years.show');

        Route::put('/fiscal-periods/{fiscalPeriod}/soft-close', [FiscalPeriodController::class, 'softClose'])->middleware('permission:accounting.periods.close')->name('fiscal-periods.soft-close');
        Route::put('/fiscal-periods/{fiscalPeriod}/reopen', [FiscalPeriodController::class, 'reopen'])->middleware('permission:accounting.periods.reopen')->name('fiscal-periods.reopen');
        Route::put('/fiscal-periods/{fiscalPeriod}/lock', [FiscalPeriodController::class, 'lock'])->middleware('permission:accounting.periods.lock')->name('fiscal-periods.lock');

        Route::get('/journal-books', [JournalBookController::class, 'index'])->middleware('permission:accounting.journal-books.view')->name('journal-books.index');
        Route::put('/journal-books', [JournalBookController::class, 'update'])->middleware('permission:accounting.journal-books.update')->name('journal-books.update');

        Route::get('/reports/trial-balance', [AccountingReportController::class, 'trialBalanceIndex'])->middleware('permission:accounting.reports.view')->name('reports.trial-balance.index');
        Route::get('/reports/trial-balance/data', [AccountingReportController::class, 'trialBalanceData'])->middleware('permission:accounting.reports.view')->name('reports.trial-balance.data');
        Route::get('/reports/trial-balance/export', [AccountingReportController::class, 'trialBalanceExport'])->middleware('permission:accounting.reports.view')->name('reports.trial-balance.export');
        Route::get('/reports/general-ledger', [AccountingReportController::class, 'generalLedgerIndex'])->middleware('permission:accounting.reports.view')->name('reports.general-ledger.index');
        Route::get('/reports/general-ledger/account-options', [AccountingReportController::class, 'generalLedgerAccountOptions'])->middleware('permission:accounting.reports.view')->name('reports.general-ledger.account-options');
        Route::get('/reports/general-ledger/data', [AccountingReportController::class, 'generalLedgerData'])->middleware('permission:accounting.reports.view')->name('reports.general-ledger.data');
        Route::get('/reports/general-ledger/export', [AccountingReportController::class, 'generalLedgerExport'])->middleware('permission:accounting.reports.view')->name('reports.general-ledger.export');
        Route::get('/reports/profit-loss', [AccountingReportController::class, 'profitLossIndex'])->middleware('permission:accounting.reports.view')->name('reports.profit-loss.index');
        Route::get('/reports/profit-loss/data', [AccountingReportController::class, 'profitLossData'])->middleware('permission:accounting.reports.view')->name('reports.profit-loss.data');
        Route::get('/reports/profit-loss/export', [AccountingReportController::class, 'profitLossExport'])->middleware('permission:accounting.reports.view')->name('reports.profit-loss.export');
        Route::get('/reports/comparative-income', [AccountingReportController::class, 'comparativeIncomeIndex'])->middleware('permission:accounting.reports.comparative-income.view')->name('reports.comparative-income.index');
        Route::get('/reports/comparative-income/data', [AccountingReportController::class, 'comparativeIncomeData'])->middleware('permission:accounting.reports.comparative-income.view')->name('reports.comparative-income.data');
        Route::get('/reports/comparative-income/export', [AccountingReportController::class, 'comparativeIncomeExport'])->middleware('permission:accounting.reports.comparative-income.view')->name('reports.comparative-income.export');
        Route::get('/reports/balance-sheet', [AccountingReportController::class, 'balanceSheetIndex'])->middleware('permission:accounting.reports.view')->name('reports.balance-sheet.index');
        Route::get('/reports/balance-sheet/data', [AccountingReportController::class, 'balanceSheetData'])->middleware('permission:accounting.reports.view')->name('reports.balance-sheet.data');
        Route::get('/reports/balance-sheet/export', [AccountingReportController::class, 'balanceSheetExport'])->middleware('permission:accounting.reports.view')->name('reports.balance-sheet.export');
        Route::get('/reports/tax', [AccountingReportController::class, 'taxReportIndex'])->middleware('permission:accounting.reports.view')->name('reports.tax.index');
        Route::get('/reports/tax/data', [AccountingReportController::class, 'taxReportData'])->middleware('permission:accounting.reports.view')->name('reports.tax.data');
        Route::get('/reports/tax/export', [AccountingReportController::class, 'taxReportExport'])->middleware('permission:accounting.reports.view')->name('reports.tax.export');
        Route::get('/reports/withholding-expense', [AccountingReportController::class, 'withholdingExpenseIndex'])->middleware('permission:accounting.reports.withholding-expense.view')->name('reports.withholding-expense.index');
        Route::get('/reports/withholding-expense/data', [AccountingReportController::class, 'withholdingData'])->middleware('permission:accounting.reports.withholding-expense.view')->name('reports.withholding-expense.data');
        Route::get('/reports/withholding-received', [AccountingReportController::class, 'withholdingReceivedIndex'])->middleware('permission:accounting.reports.withholding-received.view')->name('reports.withholding-received.index');
        Route::get('/reports/withholding-received/data', [AccountingReportController::class, 'withholdingData'])->middleware('permission:accounting.reports.withholding-received.view')->name('reports.withholding-received.data');
        Route::get('/reports/reconciliation', [AccountingReportController::class, 'reconciliationIndex'])->middleware('permission:accounting.reports.view')->name('reports.reconciliation.index');
        Route::get('/reports/reconciliation/data', [AccountingReportController::class, 'reconciliationData'])->middleware('permission:accounting.reports.view')->name('reports.reconciliation.data');
        Route::get('/reports/reconciliation/export', [AccountingReportController::class, 'reconciliationExport'])->middleware('permission:accounting.reports.view')->name('reports.reconciliation.export');

        Route::get('/journal-entries', [JournalEntryController::class, 'index'])->middleware('permission:accounting.journal-entries.view')->name('journal-entries.index');
        Route::get('/journal-entries/account-options', [JournalEntryController::class, 'accountOptions'])->middleware('permission:accounting.accounts.view')->name('journal-entries.account-options');
        Route::get('/journal-entries/data', [JournalEntryController::class, 'data'])->middleware('permission:accounting.journal-entries.view')->name('journal-entries.data');
        Route::get('/journal-entries/export', [JournalEntryController::class, 'export'])->middleware('permission:accounting.journal-entries.view')->name('journal-entries.export');
        Route::get('/journal-entries/create', [JournalEntryController::class, 'create'])->middleware('permission:accounting.journal-entries.create')->name('journal-entries.create');
        Route::post('/journal-entries', [JournalEntryController::class, 'store'])->middleware('permission:accounting.journal-entries.create')->name('journal-entries.store');
        Route::put('/journal-entries/{journalEntry}/submit', [JournalEntryController::class, 'submit'])->middleware('permission:accounting.journal-entries.submit')->name('journal-entries.submit');
        Route::put('/journal-entries/{journalEntry}/approve', [JournalEntryController::class, 'approve'])->middleware('permission:accounting.journal-entries.approve')->name('journal-entries.approve');
        Route::put('/journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->middleware('permission:accounting.journal-entries.reverse')->name('journal-entries.reverse');
        Route::get('/journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])->middleware('permission:accounting.journal-entries.view')->name('journal-entries.show');
        Route::get('/journal-entries/{journalEntry}/edit', [JournalEntryController::class, 'edit'])->middleware('permission:accounting.journal-entries.update')->name('journal-entries.edit');
        Route::put('/journal-entries/{journalEntry}', [JournalEntryController::class, 'update'])->middleware('permission:accounting.journal-entries.update')->name('journal-entries.update');
    });
