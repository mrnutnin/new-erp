<?php

namespace Database\Seeders;

use App\Models\Party;
use App\Models\PartyAddress;
use App\Models\PartyRole;
use App\Models\User;
use App\Modules\Finance\Models\PaymentTerm;
use Illuminate\Database\Seeder;

class PosCustomerAddressMockupSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $customer = Party::query()->withTrashed()->firstOrNew(['code' => 'CUST-ADDRESS-MOCK']);
        $customer->fill([
            'name' => 'บริษัท ทดสอบหลายที่อยู่ จำกัด',
            'type' => 'COMPANY',
            'tax_id' => '0105569001234',
            'branch_code' => '00000',
            'contact_name' => 'คุณสมชาย ทดสอบ',
            'phone' => '02-123-4567',
            'email' => 'address.mock@example.test',
            'address' => '99/9 ถนนสุขุมวิท แขวงคลองตันเหนือ เขตวัฒนา กรุงเทพมหานคร 10110',
            'is_active' => true,
            'created_by' => $customer->created_by ?: $user->id,
            'updated_by' => $user->id,
        ]);
        $customer->save();
        $customer->restore();

        PartyRole::query()->updateOrCreate(['party_id' => $customer->id, 'role' => 'CUSTOMER'], [
            'payment_term_id' => PaymentTerm::query()->where('is_active', true)->orderBy('credit_days')->value('id'),
            'credit_limit' => 500000,
            'is_active' => true,
        ]);

        foreach ([
            ['BILLING', 'สำนักงานใหญ่', 'ฝ่ายบัญชี', '99/9 ถนนสุขุมวิท', 'คลองตันเหนือ', 'วัฒนา', 'กรุงเทพมหานคร', '10110', '02-123-4567', true],
            ['BILLING', 'สาขาเชียงใหม่', 'ฝ่ายบัญชีเชียงใหม่', '88 ถนนนิมมานเหมินท์', 'สุเทพ', 'เมืองเชียงใหม่', 'เชียงใหม่', '50200', '053-123-456', false],
            ['BILLING', 'สาขาขอนแก่น', 'ฝ่ายบัญชีขอนแก่น', '77/7 ถนนมิตรภาพ', 'ในเมือง', 'เมืองขอนแก่น', 'ขอนแก่น', '40000', '043-123-456', false],
            ['SHIPPING', 'คลังบางนา', 'คุณวิชัย คลังบางนา', '55/12 ถนนบางนา-ตราด กม.7', 'บางแก้ว', 'บางพลี', 'สมุทรปราการ', '10540', '02-234-5678', true],
            ['SHIPPING', 'คลังรังสิต', 'คุณอรทัย คลังรังสิต', '88/8 ถนนพหลโยธิน', 'ประชาธิปัตย์', 'ธัญบุรี', 'ปทุมธานี', '12130', '02-345-6789', false],
            ['SHIPPING', 'หน้างานภูเก็ต', 'คุณกิตติ หน้างานภูเก็ต', '12/34 ถนนเจ้าฟ้าตะวันออก', 'วิชิต', 'เมืองภูเก็ต', 'ภูเก็ต', '83000', '076-123-456', false],
        ] as [$type, $label, $recipient, $line, $district, $amphoe, $province, $postalCode, $phone, $isDefault]) {
            $address = PartyAddress::query()->withTrashed()->updateOrCreate([
                'party_id' => $customer->id,
                'address_type' => $type,
                'label' => $label,
            ], [
                'recipient_name' => $recipient,
                'address_line' => $line,
                'district' => $district,
                'amphoe' => $amphoe,
                'province' => $province,
                'postal_code' => $postalCode,
                'is_default' => $isDefault,
                'is_active' => true,
                'phone' => $phone,
            ]);
            $address->restore();
        }
    }
}
