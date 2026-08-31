<?php

namespace Database\Seeders;

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Pos\Models\SalesOrder;
use Illuminate\Database\Seeder;

class PosPaymentMethodMockupSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'HQ-WH')->firstOrFail();
        $assetTypeId = AccountType::query()->where('code', 'ASSET')->value('id');

        $accounts = collect([
            ['code' => '11001', 'name' => 'เงินสดหน้าร้าน Mockup', 'type' => 'CASH'],
            ['code' => '11210', 'name' => 'ลูกหนี้บัตรเครดิต Mockup', 'type' => 'CREDIT_CARD'],
            ['code' => '11310', 'name' => 'เช็ครับ Mockup', 'type' => 'CHEQUE'],
        ])->mapWithKeys(function (array $row) use ($assetTypeId, $user): array {
            $account = Account::query()->updateOrCreate(['code' => $row['code']], [
                'account_type_id' => $assetTypeId,
                'name' => $row['name'],
                'level' => 1,
                'normal_balance' => 'DEBIT',
                'statement_section' => 'BALANCE_SHEET',
                'reporting_profile' => 'PAE',
                'control_account_type' => $row['type'],
                'is_postable' => true,
                'is_active' => true,
                'updated_by' => $user->id,
            ]);

            return [$row['type'] => $account];
        });
        $accounts['BANK'] = Account::query()->where('code', '11111')->firstOrFail();

        foreach ([
            ['code' => 'CASH-POS-MOCK', 'name' => 'เงินสดหน้าร้าน (Mockup)', 'type' => 'CASH', 'account' => 'CASH', 'bank_name' => null, 'number' => null],
            ['code' => 'BANK-HQ', 'name' => 'โอนผ่านธนาคาร (Mockup)', 'type' => 'BANK', 'account' => 'BANK', 'bank_name' => 'ธนาคารตัวอย่าง', 'number' => '123-4-56789-0'],
            ['code' => 'CARD-POS-MOCK', 'name' => 'บัตรเครดิต (Mockup)', 'type' => 'CREDIT_CARD', 'account' => 'CREDIT_CARD', 'bank_name' => 'ผู้ให้บริการบัตรตัวอย่าง', 'number' => 'MERCHANT-MOCK'],
            ['code' => 'CHEQUE-POS-MOCK', 'name' => 'เช็ค (Mockup)', 'type' => 'CHEQUE', 'account' => 'CHEQUE', 'bank_name' => null, 'number' => null],
        ] as $method) {
            BankAccount::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'code' => $method['code']], [
                'account_id' => $accounts[$method['account']]->id,
                'type' => $method['type'],
                'name' => $method['name'],
                'bank_name' => $method['bank_name'],
                'account_number' => $method['number'],
                'currency_code' => 'THB',
                'is_active' => true,
                'created_by' => $user->id,
            ]);
        }

        $customer = Party::query()->updateOrCreate(['code' => 'CUST-PAYMENT-MOCK'], [
            'name' => 'ลูกค้าทดสอบรับชำระหลายช่องทาง',
            'type' => 'COMPANY',
            'branch_code' => '00000',
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $cod = PaymentTerm::query()->where('code', 'COD')->firstOrFail();
        PartyRole::query()->updateOrCreate(['party_id' => $customer->id, 'role' => 'CUSTOMER'], [
            'payment_term_id' => $cod->id,
            'credit_limit' => 0,
            'is_active' => true,
        ]);
        $source = SalesOrder::query()->with('lines')->where('warehouse_id', $warehouse->id)->where('status', 'CONFIRMED')->latest('id')->firstOrFail();
        $order = SalesOrder::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'document_number' => 'SO-PAYMENT-MOCK'], [
            'sales_quotation_id' => null,
            'sales_rfq_id' => null,
            'source_sales_intake_id' => null,
            'party_id' => $customer->id,
            'party_code' => $customer->code,
            'party_name' => $customer->name,
            'party_tax_id' => $customer->tax_id,
            'party_branch_code' => $customer->branch_code,
            'party_address' => $customer->address,
            'document_date' => today(),
            'valid_until' => today()->addDays(30),
            'status' => 'CONFIRMED',
            'subtotal' => $source->subtotal,
            'discount_amount' => $source->discount_amount,
            'total_amount' => $source->total_amount,
            'description' => 'Mock ใบสั่งขายสำหรับทดสอบรับชำระหลายช่องทาง',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
            'cancelled_by' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
        ]);
        $order->lines()->delete();
        foreach ($source->lines as $line) {
            $order->lines()->create([
                'source_quotation_line_id' => null,
                'source_rfq_line_id' => null,
                'source_sales_intake_line_id' => null,
                'line_number' => $line->line_number,
                'item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'line_total' => $line->line_total,
                'item_snapshot' => $line->item_snapshot,
                'uom_snapshot' => $line->uom_snapshot,
            ]);
        }
    }
}
