<?php

namespace Database\Seeders;

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Finance\Services\WhtRealizationLedgerService;
use Illuminate\Database\Seeder;

class WithholdingExportMockupSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'HQ-WH')->firstOrFail();
        $expense = Account::query()->where('code', '51000')->firstOrFail();
        $payable = Account::query()->where('code', '21000')->firstOrFail();
        $bank = Account::query()->where('code', '11000')->firstOrFail();
        $wht = TaxCode::query()->where('code', 'WHT3')->firstOrFail();
        $posting = app(JournalPostingService::class);
        $openItems = app(OpenItemService::class);
        $realizations = app(WhtRealizationLedgerService::class);

        foreach ([['SUP-WHT-IND-001', 'ผู้รับเงินบุคคลธรรมดา Mockup', 'INDIVIDUAL', '1100000000001', 'PND3'], ['SUP-WHT-COM-001', 'บริษัทผู้รับเงิน Mockup จำกัด', 'COMPANY', '0105559999999', 'PND53']] as [$code, $name, $type, $taxId, $form]) {
            $party = Party::query()->updateOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'tax_id' => $taxId, 'branch_code' => '00000', 'is_active' => true, 'created_by' => $user->id, 'updated_by' => $user->id]);
            PartyRole::query()->updateOrCreate(['party_id' => $party->id, 'role' => 'SUPPLIER'], ['is_active' => true]);
            $source = 'WHT-EXPORT-'.$form;
            $invoice = $posting->post(['source_type' => 'PURCHASING', 'source_id' => $source.'-INVOICE', 'event_code' => 'supplier_invoice.expense', 'entry_date' => '2026-09-02', 'document_date' => '2026-09-02', 'source_reference' => $source.'-BILL', 'description' => 'Mock WHT '.$form.' invoice', 'lines' => [['account_id' => $expense->id, 'debit' => 1000, 'credit' => 0, 'description' => 'ค่าบริการ Mock'], ['account_id' => $payable->id, 'debit' => 0, 'credit' => 1000, 'subledger_type' => 'SUPPLIER', 'subledger_id' => $party->code, 'description' => 'เจ้าหนี้ Mock']]], $warehouse, $user);
            $invoiceLine = $invoice->lines()->where('account_id', $payable->id)->firstOrFail();
            $openInvoice = $openItems->recordFromJournalLine($invoiceLine, ['document_type' => 'INVOICE', 'document_number' => $source.'-BILL', 'due_date' => '2026-09-30']);
            $openInvoice->update(['party_id' => $party->id, 'withholding_tax_code_id' => $wht->id, 'withholding_rate' => 3, 'withholding_base' => 1000, 'withholding_amount' => 30]);
            $payment = $posting->post(['source_type' => 'FINANCE', 'source_id' => $source.'-PAYMENT', 'event_code' => 'supplier_payment', 'entry_date' => '2026-09-02', 'document_date' => '2026-09-02', 'source_reference' => $source.'-PAY', 'description' => 'Mock WHT '.$form.' payment', 'lines' => [['account_id' => $payable->id, 'debit' => 1000, 'credit' => 0, 'subledger_type' => 'SUPPLIER', 'subledger_id' => $party->code], ['account_id' => $bank->id, 'debit' => 0, 'credit' => 970, 'subledger_type' => 'BANK', 'subledger_id' => 'BANK-HQ'], ['account_id' => Account::query()->where('code', '21400')->firstOrFail()->id, 'debit' => 0, 'credit' => 30, 'subledger_type' => 'TAX', 'subledger_id' => $wht->code]]], $warehouse, $user);
            $paymentLine = $payment->lines()->where('account_id', $payable->id)->firstOrFail();
            $openPayment = $openItems->recordFromJournalLine($paymentLine, ['document_type' => 'PAYMENT', 'document_number' => $source.'-PAY', 'due_date' => null]);
            $settlement = Settlement::query()->updateOrCreate(['document_number' => $source.'-SETTLEMENT'], ['document_type' => 'PAYMENT', 'document_date' => '2026-09-02', 'settlement_date' => '2026-09-02', 'party_type' => 'SUPPLIER', 'party_id' => $party->id, 'gross_amount' => 1000, 'withholding_amount' => 30, 'net_amount' => 970, 'status' => 'POSTED', 'description' => 'Mock settlement '.$form, 'created_by' => $user->id]);
            $allocation = Allocation::query()->firstOrCreate(['source_id' => $source.'-ALLOCATION'], ['debit_open_item_id' => $openPayment->id, 'credit_open_item_id' => $openInvoice->id, 'allocation_date' => '2026-09-02', 'amount' => 1000, 'source_type' => 'MOCKUP', 'idempotency_key' => hash('sha256', $source), 'allocation_hash' => hash('sha256', $source.'-hash'), 'created_by' => $user->id]);
            $realizations->record($allocation, $settlement, $user);
        }
    }
}
