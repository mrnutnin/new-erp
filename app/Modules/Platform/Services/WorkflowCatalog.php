<?php

namespace App\Modules\Platform\Services;

final class WorkflowCatalog
{
    public static function for(string $program, ?ModuleCapability $capability = null): array
    {
        if ($program === 'settings') {
            return self::decorate([[
                'code' => 'company-setup', 'title' => 'เริ่มต้นบริษัท',
                'description' => 'ตั้งค่าข้อมูลกลางและสิทธิ์ก่อนเริ่มทำรายการ', 'duration' => 'ประมาณ 15 นาที',
                'steps' => [
                    ['label' => 'ข้อมูลบริษัท', 'route' => 'settings.company.edit', 'permission' => 'settings.company.view', 'effect' => 'กำหนดนโยบายบริษัท'],
                    ['label' => 'สาขา', 'route' => 'settings.branches.index', 'permission' => 'settings.branches.view', 'effect' => 'สร้างขอบเขตสาขา'],
                    ['label' => 'คลัง', 'route' => 'settings.warehouses.index', 'permission' => 'settings.warehouses.view', 'effect' => 'กำหนดคลังและ Warehouse context'],
                    ['label' => 'ผู้ใช้และสิทธิ์', 'route' => 'settings.users.index', 'permission' => 'settings.users.view', 'effect' => 'ควบคุมการเข้าถึงเมนู'],
                    ['label' => 'Global Settings', 'route' => 'settings.company.edit', 'permission' => 'settings.company.view', 'effect' => 'เปิด readiness ของระบบ'],
                ],
            ]], 'setup');
        }

        if ($program === 'finance') {
            return self::decorate([
                [
                    'code' => 'finance-posting-readiness', 'title' => 'ค่าเริ่มต้นการลงบัญชี', 'mode' => 'setup',
                    'description' => 'ตรวจ Account Mapping ที่ใช้เป็นค่าเริ่มต้นของเหตุการณ์รับ–จ่าย โดยเอกสารยังตรวจบัญชีเงินสด/ธนาคารและข้อมูลต้นทางซ้ำก่อน Post', 'duration' => 'ตรวจอัตโนมัติ',
                    'steps' => [
                        ['label' => 'รับชำระจากลูกค้า', 'route' => 'finance.settlements.index', 'permission' => 'finance.settlements.view', 'effect' => 'ตรวจบัญชีลูกหนี้และบัญชีเงินสด/ธนาคารที่รับชำระ', 'event_code' => 'customer_payment', 'mode' => 'setup'],
                        ['label' => 'รับเงินล่วงหน้า', 'route' => 'finance.advance-deposits.index', 'permission' => 'finance.advance-deposits.view', 'effect' => 'ตรวจบัญชีเงินล่วงหน้ารับและบัญชีเงินสด/ธนาคาร', 'event_code' => 'customer_advance', 'mode' => 'setup'],
                        ['label' => 'จ่ายชำระเจ้าหนี้', 'route' => 'finance.settlements.index', 'permission' => 'finance.settlements.view', 'effect' => 'ตรวจบัญชีเจ้าหนี้และบัญชีเงินสด/ธนาคารที่จ่าย', 'event_code' => 'supplier_payment', 'mode' => 'setup'],
                    ],
                ],
                [
                    'code' => 'finance-first-time-setup', 'title' => 'ตั้งค่าการเงินก่อนเริ่มใช้งาน', 'mode' => 'setup',
                    'description' => 'เตรียมข้อมูลกลางและบัญชีที่ใช้ซ้ำ เพื่อให้ทีมเล็กทำงานรับ–จ่ายได้โดยไม่ต้องตั้งค่าซ้ำทุกเอกสาร', 'duration' => 'ประมาณ 15 นาที',
                    'steps' => [
                        ['label' => 'บัญชีเงินสด / ธนาคาร', 'route' => 'finance.bank-accounts.index', 'permission' => 'finance.bank-accounts.view', 'effect' => 'ผูกบัญชีเงินสดและธนาคารกับ GL ที่ active/postable', 'mode' => 'setup', 'recovery_hint' => 'ถ้าบัญชี GL ไม่พบหรือใช้ไม่ได้ ให้แก้ที่ Accounting > Account Mapping/ผังบัญชีก่อน แล้วกลับมาตรวจใหม่; Approved/Posted ห้ามลบหรือแก้ทับ'],
                        ['label' => 'วงเงินสดย่อย', 'route' => 'finance.petty-cash-funds.index', 'permission' => 'finance.petty-cash.manage-funds', 'effect' => 'กำหนดชื่อวงเงิน วงเงินสูงสุด ผู้ดูแล และบัญชีเงินสดที่ใช้จริง', 'mode' => 'setup', 'recovery_hint' => 'ตรวจบัญชีเงินสดและคลังให้ถูกต้องก่อนสร้าง; วงเงินที่ถูกอ้างอิงแล้วให้ปิดใช้งานแทนการลบ'],
                        ['label' => 'เงื่อนไขการชำระเงิน', 'route' => 'finance.payment-terms.index', 'permission' => 'finance.payment-terms.view', 'effect' => 'กำหนดเครดิตและวันครบกำหนดให้เอกสารรับ–จ่าย', 'mode' => 'setup', 'recovery_hint' => 'แก้เงื่อนไขที่ master ก่อนสร้างเอกสารใหม่; เอกสารที่ Post แล้วห้ามแก้ทับ'],
                        ['label' => 'หมวดรายได้ / รายจ่ายอื่น', 'route' => 'finance.other-categories.index', 'permission' => 'finance.other-categories.view', 'effect' => 'กำหนดบัญชี GL สำหรับรายการนอกลูกหนี้/เจ้าหนี้', 'mode' => 'setup', 'recovery_hint' => 'ถ้า mapping ผิด ให้แก้ master และสร้าง Draft ใหม่ แทนการแก้ Journal ที่ Post แล้ว'],
                        ['label' => 'เลขที่และรูปแบบเอกสาร', 'route' => 'settings.document-sequences.index', 'permission' => 'finance.document-sequences.view', 'effect' => 'กำหนดรูปแบบเลขกลาง และเลขรันแยกตามประเภทเอกสารและสาขา', 'mode' => 'setup', 'recovery_hint' => 'เลขที่ออกแล้วห้าม reuse; หากวันที่ Draft เปลี่ยน ระบบต้องออกเลขตาม policy ใหม่'],
                        ['label' => 'ตรวจ Account Mapping / งวดบัญชี', 'route' => 'accounting.account-mappings.index', 'permission' => 'accounting.account-mappings.view', 'effect' => 'ยืนยันบัญชีคุมและงวดเปิดก่อนให้ผู้ใช้ Post', 'mode' => 'setup', 'recovery_hint' => 'แก้ mapping หรือเปิดงวดใน Accounting แล้วกลับมาเริ่มรายการใหม่; ห้ามข้าม preflight'],
                    ],
                ],
                [
                    'code' => 'record-to-cash', 'title' => 'งานประจำวัน: รับเงิน / จ่ายเงิน', 'mode' => 'daily',
                    'description' => 'ตรวจลูกหนี้–เจ้าหนี้ สร้าง Voucher/Settlement จัดสรร และตรวจรายงานหลัง Post', 'duration' => 'ตามรายการจริง',
                    'steps' => [
                        ['label' => 'ลูกหนี้ / เจ้าหนี้', 'route' => 'finance.receivables.open-items.index', 'permission' => 'finance.ar-open-items.view', 'effect' => 'ตรวจยอดคงค้างจากเอกสารที่ Post แล้ว', 'mode' => 'daily', 'recovery_hint' => 'ถ้ายอดไม่ตรง ให้ตรวจเอกสารต้นทางและรายการ allocation; ห้ามปรับยอด Open Item ด้วยมือ'],
                        ['label' => 'ใบขอจ่าย / ใบสำคัญจ่าย', 'route' => 'finance.payment-vouchers.index', 'permission' => 'finance.payment-vouchers.view', 'effect' => 'เตรียมรายการจ่ายและส่ง/อนุมัติตาม approval policy; ทีมเล็กทำครบได้เมื่อ policy อนุญาต', 'mode' => 'daily', 'recovery_hint' => 'Draft แก้ได้, Submitted/Approved ให้ใช้ transition หรือ Void ตามสิทธิ์; Posted ต้องใช้ reversal'],
                        ['label' => 'รับเงิน / จ่ายเงิน', 'route' => 'finance.settlements.index', 'permission' => 'finance.settlements.view', 'effect' => 'Post เงินและจัดสรร Open Item แบบ idempotent พร้อม VAT/WHT realization ตามเงื่อนไข', 'mode' => 'daily', 'recovery_hint' => 'ตรวจ Bank/Cash, วันที่ และยอดจัดสรรก่อน Post; ถ้า Post แล้วห้ามแก้ทับ ให้ทำ reversal/รายการแก้ไข'],
                        ['label' => 'เงินล่วงหน้า / เงินมัดจำ', 'route' => 'finance.advance-deposits.index', 'permission' => 'finance.advance-deposits.view', 'effect' => 'ตรวจรายการจาก Settlement ที่ลงบัญชีแล้ว และนำเงินล่วงหน้า/เงินมัดจำไปตัดกับเอกสารคู่ค้า', 'mode' => 'daily', 'recovery_hint' => 'เลือกเอกสารปลายทางให้ตรงคู่ค้า คลัง และ AR/AP; หาก Post แล้วห้ามแก้ทับ ให้ใช้ย้อนรายการพร้อมเหตุผลตามสิทธิ์'],
                        ['label' => 'Petty Cash', 'route' => 'finance.petty-cash.index', 'permission' => 'finance.petty-cash.view', 'effect' => 'สร้างใบสำคัญเงินสดย่อย ตรวจ VAT/WHT ส่งอนุมัติตาม approval policy สำหรับทีมเล็ก ลงบัญชี และย้อนรายการตามสิทธิ์', 'mode' => 'daily', 'recovery_hint' => 'Draft แก้ไขหรือลบได้, Submitted/Approved ใช้ transition หรือ Void, Posted ต้องใช้ reversal พร้อมเหตุผลและ audit'],
                        ['label' => 'เติมเงินสดย่อย', 'route' => 'finance.petty-cash-top-ups.index', 'permission' => 'finance.petty-cash-top-ups.view', 'effect' => 'โอนเงินจากบัญชีธนาคารเข้าวงเงินสดย่อย ตรวจ approval policy สำหรับทีมเล็ก และตรวจ GL หลัง Post', 'mode' => 'daily', 'recovery_hint' => 'ตรวจบัญชีต้นทาง วงเงิน และงวดบัญชีก่อน Post; Posted แล้วให้ใช้ reversal'],
                        ['label' => 'เคลียร์เงินสดย่อย', 'route' => 'finance.petty-cash-clearings.index', 'permission' => 'finance.petty-cash-clearings.view', 'effect' => 'เปรียบเทียบยอดตามทะเบียนกับเงินจริง บันทึกผลต่าง ตรวจ approval policy สำหรับทีมเล็ก และตรวจ GL', 'mode' => 'daily', 'recovery_hint' => 'ผลต่างต้องมีเหตุผล; หาก Post แล้วให้ใช้ reversal และตรวจเอกสารแนบประกอบ'],
                        ['label' => 'เงินทดรองพนักงาน', 'route' => 'finance.employee-advances.index', 'permission' => 'finance.employee-advances.view', 'effect' => 'เบิกเงินทดรอง เคลียร์ค่าใช้จ่าย คืนเงิน หรือจ่ายเพิ่มตาม approval policy สำหรับทีมเล็ก พร้อม VAT/WHT, GL และ audit', 'mode' => 'daily', 'recovery_hint' => 'Draft แก้ไขหรือลบได้, Submitted/Approved ใช้ transition หรือ Void, Posted ต้องใช้ reversal'],
                        ['label' => 'เคลียร์เงินทดรองพนักงาน', 'route' => 'finance.employee-advance-clearings.index', 'permission' => 'finance.employee-advance-clearings.view', 'effect' => 'บันทึกค่าใช้จ่ายจริง เงินคืน/เบิกเพิ่มตาม approval policy สำหรับทีมเล็ก แนบหลักฐาน และลงบัญชีตาม mapping', 'mode' => 'daily', 'recovery_hint' => 'ตรวจยอดเคลียร์ให้สัมพันธ์กับเงินทดรองและเอกสารแนบ; Posted แล้วให้ใช้ reversal'],
                        ['label' => 'โอนเงินภายใน', 'route' => 'finance.internal-transfers.index', 'permission' => 'finance.internal-transfers.view', 'effect' => 'โอนระหว่างบัญชีเงินสด/ธนาคารภายในสาขาตาม approval policy สำหรับทีมเล็ก ตรวจยอด GL และย้อนรายการได้', 'mode' => 'daily', 'recovery_hint' => 'บัญชีต้นทางและปลายทางต้องต่างกัน; Draft แก้ไขหรือลบได้ และ Posted ใช้ reversal'],
                        ['label' => 'Aging / รายงานธุรกรรม', 'route' => 'finance.receivables.aging.index', 'permission' => 'finance.ar-aging.view', 'effect' => 'ติดตามยอดคงค้างและตรวจรับ–จ่ายตามสาขา/คลัง', 'mode' => 'daily', 'recovery_hint' => 'ถ้ารายงานต่างจากยอด GL ให้หยุดปิดงวดและส่งรายการให้ Accounting ตรวจ reconciliation'],
                    ],
                ],
            ], 'daily');
        }

        if ($program === 'accounting') {
            return self::decorate([[
                'code' => 'record-to-report', 'title' => 'Record-to-Report',
                'description' => 'ตั้งค่าผังบัญชี บันทึก ตรวจสอบ และอ่านรายงานทางการเงิน', 'duration' => 'ประมาณ 20 นาที',
                'steps' => [
                    ['label' => 'ผังบัญชี', 'route' => 'accounting.accounts.index', 'permission' => 'accounting.accounts.view', 'effect' => 'เตรียมบัญชีระดับ 1–5 และบัญชี Postable', 'mode' => 'setup'],
                    ['label' => 'Account Mapping', 'route' => 'accounting.account-mappings.index', 'permission' => 'accounting.account-mappings.view', 'effect' => 'ผูกบัญชีควบคุมให้โมดูลปฏิบัติการ', 'mode' => 'setup'],
                    ['label' => 'ปีและงวดบัญชี', 'route' => 'accounting.fiscal-years.index', 'permission' => 'accounting.periods.view', 'effect' => 'กำหนดงวดที่เปิดให้บันทึก', 'mode' => 'setup'],
                    ['label' => 'รายการสมุดรายวัน', 'route' => 'accounting.journal-entries.index', 'permission' => 'accounting.journal-entries.view', 'effect' => 'ตรวจ Journal ที่ Validate และ Post ตาม approval policy; ทีมเล็กไม่ต้องสร้างผู้อนุมัติคนที่สองถ้า policy ไม่ได้กำหนด'],
                    ['label' => 'รายงานหลัก', 'route' => 'accounting.reports.trial-balance.index', 'permission' => 'accounting.reports.view', 'effect' => 'อ่าน GL, งบทดลอง และงบการเงินตามงวด'],
                ],
            ]], 'daily');
        }

        if ($program === 'pos') {
            return self::decorate([
                [
                    'code' => 'sales-posting-readiness', 'title' => 'ค่าเริ่มต้นการลงบัญชี', 'mode' => 'setup',
                    'description' => 'ตรวจ Account Mapping สำหรับการขายก่อนออก HS/IV โดยเอกสารยังตรวจ Tax Code และข้อมูลสินค้า/การชำระเงินซ้ำก่อน Post', 'duration' => 'ตรวจอัตโนมัติ',
                    'steps' => [
                        ['label' => 'ขายสด / ขายเชื่อ (HS/IV)', 'route' => 'pos.physical-sales.index', 'permission' => 'pos.physical-sales.view', 'effect' => 'ตรวจบัญชีรายได้ ภาษีขาย และลูกหนี้ตามค่าเริ่มต้น', 'event_code' => 'sales_invoice', 'mode' => 'setup'],
                    ],
                ],
                [
                    'code' => 'sales-setup', 'title' => '1. ตั้งค่าพร้อมขาย', 'mode' => 'setup',
                    'description' => 'ตั้งค่าข้อมูลที่มีผลต่อราคาและผลตอบแทนก่อนเริ่มออกเอกสารขาย', 'duration' => 'ก่อนเริ่มรอบขาย',
                    'steps' => [
                        ['label' => 'กลุ่มลูกค้า', 'route' => 'pos.customer-groups.index', 'permission' => 'pos.customer-groups.view', 'effect' => 'กำหนดกลุ่มที่ใช้เลือกราคาขายและเงื่อนไขการค้า', 'mode' => 'setup'],
                        ['label' => 'ลูกค้าและที่อยู่', 'route' => 'pos.customers.index', 'permission' => 'pos.customers.view', 'effect' => 'เตรียมผู้ซื้อ ที่อยู่ออกบิล/จัดส่ง และข้อมูลภาษี', 'mode' => 'setup'],
                        ['label' => 'รายการราคา', 'route' => 'pos.price-lists.index', 'permission' => 'pos.price-lists.view', 'effect' => 'กำหนดราคาตามสินค้า กลุ่มลูกค้า ช่วงเวลา และจำนวนขั้นต่ำ', 'mode' => 'setup'],
                        ['label' => 'โปรโมชั่น', 'route' => 'pos.promotions.index', 'permission' => 'pos.promotions.view', 'effect' => 'กำหนดส่วนลดหรือราคาโปรโมชัน ซึ่งมีลำดับก่อน Price List ตามนโยบายที่ตั้งไว้', 'mode' => 'setup'],
                        ['label' => 'แผนคอมมิชชั่นขาย', 'route' => 'pos.sales-commission-plans.index', 'permission' => 'pos.commission-plans.view', 'effect' => 'กำหนดผู้รับและอัตราคอมมิชชั่นก่อนเอกสารขายถูก Post', 'mode' => 'setup'],
                    ],
                ],
                [
                    'code' => 'sales-documents', 'title' => '2. วงจรเอกสารขาย', 'mode' => 'daily',
                    'description' => 'เลือกเส้นทางตามลักษณะการขาย: เริ่มรับข้อมูลเพื่อเสนอราคา/สั่งขาย หรือออก HS/IV โดยตรง', 'duration' => 'ต่อรายการขาย',
                    'steps' => [
                        ['label' => 'ใบรับข้อมูลเบื้องต้น', 'route' => 'pos.sales-intakes.index', 'permission' => 'pos.sales-intakes.view', 'effect' => 'รับความต้องการและรายละเอียดลูกค้า ก่อนแปลงเป็นเอกสารถัดไป', 'mode' => 'daily'],
                        ['label' => 'ใบขอราคา / ใบเสนอราคา', 'route' => 'pos.sales-rfqs.index', 'permission' => 'pos.sales-rfqs.view', 'effect' => 'ตรวจราคาและส่งข้อเสนอให้ลูกค้า แล้วแปลงเป็นใบสั่งขายเมื่อยอมรับ', 'mode' => 'daily'],
                        ['label' => 'ใบสั่งขาย', 'route' => 'pos.sales-orders.index', 'permission' => 'pos.sales-orders.view', 'effect' => 'ยืนยันคำสั่งซื้อ เป็นเอกสารต้นทางของการขายและส่งมอบ', 'mode' => 'daily'],
                        ['label' => 'ขายสด / ขายเชื่อ (HS/IV)', 'route' => 'pos.physical-sales.index', 'permission' => 'pos.physical-sales.view', 'effect' => 'ออกเอกสารขาย ตรวจคลังเฉพาะจุดสินค้า/ส่งมอบ และ Post ตามสิทธิ์', 'mode' => 'daily'],
                        ['label' => 'รับเงินหรือสร้างลูกหนี้', 'route' => 'pos.receipts.index', 'permission' => 'pos.receipts.view', 'effect' => 'รับชำระสำหรับขายสด หรือให้ IV ที่ Post แล้วส่งยอดเป็นลูกหนี้', 'mode' => 'daily'],
                    ],
                ],
                [
                    'code' => 'sales-aftercare', 'title' => '3. หลังการขายและลูกหนี้', 'mode' => 'daily',
                    'description' => 'ติดตามยอดคงค้าง รับมัดจำ และทำเอกสารแก้ไขโดยอ้างอิงรายการขายเดิม', 'duration' => 'เมื่อมีการชำระ/คืนสินค้า',
                    'steps' => [
                        ['label' => 'ลูกหนี้คงค้าง', 'route' => 'pos.receivables.index', 'permission' => 'pos.receivables.view', 'effect' => 'ติดตาม IV ที่ยังไม่ชำระและวันครบกำหนดของสาขาปัจจุบัน', 'mode' => 'daily'],
                        ['label' => 'เงินมัดจำ', 'route' => 'pos.advance-deposits.index', 'permission' => 'pos.advance-deposits.view', 'effect' => 'รับและนำเงินมัดจำไปตัดกับการขายที่เกี่ยวข้อง', 'mode' => 'daily'],
                        ['label' => 'ใบลดหนี้ / รับคืน', 'route' => 'pos.sales-returns.index', 'permission' => 'pos.sales-returns.view', 'effect' => 'อ้างอิง HS/IV เดิม ตรวจรายการคืน แล้ว Post เอกสารแก้ไขตามสิทธิ์', 'mode' => 'daily'],
                        ['label' => 'ตรวจสอบยอดขายและรับชำระ', 'route' => 'pos.sales-reports.reconciliation.index', 'permission' => 'pos.sales-reports.view', 'effect' => 'กระทบยอดขาย รับเงิน และลูกหนี้ก่อนสรุปรอบ', 'mode' => 'daily'],
                    ],
                ],
                [
                    'code' => 'sales-commission', 'title' => '4. คอมมิชชั่นขาย', 'mode' => 'daily',
                    'description' => 'POS คำนวณและตรวจสอบสิทธิ์ จากนั้นส่งเป็นชุดให้ Finance สร้างใบขอจ่ายและจ่ายเงินจริง', 'duration' => 'ปิดรอบคอมมิชชั่น',
                    'steps' => [
                        ['label' => 'ตรวจและอนุมัติรายการคอมมิชชั่น', 'route' => 'pos.sales-commissions.index', 'permission' => 'pos.sales-commissions.view', 'effect' => 'ตรวจบิลอ้างอิงและยอดคอมมิชชั่น แล้วอนุมัติหรือไม่อนุมัติเป็นรายรายการตาม approval policy; ทีมเล็กทำครบได้เมื่อ policy อนุญาต', 'mode' => 'daily'],
                        ['label' => 'สร้างชุดจ่าย', 'route' => 'pos.sales-commission-payment-batches.create', 'permission' => 'pos.sales-commissions.pay', 'effect' => 'เลือกช่วงวันที่และผู้รับ แล้วรวมรายการที่อนุมัติไว้ในชุดเดียวตาม approval policy; ทีมเล็กทำครบได้เมื่อ policy อนุญาต', 'mode' => 'daily'],
                        ['label' => 'ส่งชุดให้ฝ่ายการเงิน', 'route' => 'pos.sales-commissions.index', 'permission' => 'pos.sales-commissions.pay', 'effect' => 'ส่งชุดที่ตรวจแล้วให้ Finance ตรวจสอบ สร้างใบขอจ่าย และสร้างใบสำคัญจ่าย', 'mode' => 'daily'],
                    ],
                ],
                [
                    'code' => 'sales-performance', 'title' => '5. เป้าหมายและรายงานผู้บริหาร', 'mode' => 'daily',
                    'description' => 'ตั้งเป้า ดูผลงานจริง และใช้รายงานเพื่อปรับราคา โปรโมชั่น และการทำงานของทีมขาย', 'duration' => 'รายวัน / ปิดรอบ',
                    'steps' => [
                        ['label' => 'เป้ายอดขายสาขา', 'route' => 'pos.branch-sales-targets.index', 'permission' => 'pos.branch-sales-targets.view', 'effect' => 'กำหนดเป้ายอดขายและ GP ของสาขา', 'mode' => 'daily'],
                        ['label' => 'เป้ายอดขายพนักงาน', 'route' => 'pos.employee-sales-targets.index', 'permission' => 'pos.employee-sales-targets.view', 'effect' => 'กำหนดเป้ารายบุคคลเพื่อใช้วัดและกระตุ้นผลงาน', 'mode' => 'daily'],
                        ['label' => 'Dashboard และรายงานยอดขาย', 'route' => 'pos.index', 'permission' => 'pos.dashboard.view', 'effect' => 'ดูยอดขาย HS/IV/ลดหนี้ สินค้าขายดี เอกสารค้าง และคำเตือนลูกหนี้', 'mode' => 'daily'],
                        ['label' => 'รายงานผล Promotion / Campaign ROI', 'route' => 'pos.sales-reports.campaign-roi.index', 'permission' => 'pos.sales-reports.view', 'effect' => 'ตรวจยอดขาย GP ค่าใช้จ่าย และผลตอบแทนของ Campaign ก่อนตัดสินใจรอบถัดไป', 'mode' => 'daily'],
                    ],
                ],
            ], 'daily');
        }

        if ($program === 'wms') {
            return self::decorate([
                [
                    'code' => 'purchase-posting-readiness', 'title' => 'ค่าเริ่มต้นการลงบัญชี', 'mode' => 'setup',
                    'description' => 'ตรวจ Account Mapping สำหรับใบซื้อเชื่อ โดยเอกสารยังตรวจ Item/Category หรือบัญชีค่าใช้จ่ายที่เลือกซ้ำก่อน Post', 'duration' => 'ตรวจอัตโนมัติ',
                    'steps' => [
                        ['label' => 'ใบซื้อเชื่อสินค้า', 'route' => 'purchasing.purchase-documents.index', 'permission' => 'purchasing.purchase-documents.view', 'effect' => 'ตรวจบัญชีสินค้าคงคลังและเจ้าหนี้ตามค่าเริ่มต้น', 'event_code' => 'supplier_invoice.inventory', 'mode' => 'setup'],
                        ['label' => 'ใบซื้อเชื่อบริการ / ค่าใช้จ่าย', 'route' => 'purchasing.purchase-documents.index', 'permission' => 'purchasing.purchase-documents.view', 'effect' => 'ตรวจบัญชีค่าใช้จ่ายและเจ้าหนี้ตามค่าเริ่มต้น', 'event_code' => 'supplier_invoice.expense', 'mode' => 'setup'],
                    ],
                ],
                [
                    'code' => 'procure-to-pay', 'title' => 'Procure-to-Pay', 'mode' => 'daily',
                    'description' => 'ตั้งแต่ข้อมูล Supplier จนถึงรับสินค้า ใบซื้อเชื่อ ตั้งเจ้าหนี้ และการชำระเงิน', 'duration' => 'ประมาณ 20 นาที',
                    'decision_cards' => [
                        ['code' => 'service-expense-purchase', 'mode' => 'daily', 'title' => 'กรณีบริการ / ค่าใช้จ่าย', 'description' => 'รายการที่ไม่ใช่สินค้าคงคลังไม่ต้องผ่าน Goods Receipt ให้สร้าง Credit Purchase พร้อมบัญชีค่าใช้จ่าย แล้วส่งต่อ AP/Payment', 'route' => 'purchasing.purchase-documents.index', 'permission' => 'purchasing.purchase-documents.view', 'block_reason' => 'ตรวจว่าเป็นบริการหรือค่าใช้จ่ายจริง และเลือกบัญชี GL ให้ถูกต้องก่อน Post', 'recovery_hint' => 'ถ้าเลือกประเภทผิด ให้แก้เฉพาะ Draft; หาก Post แล้วห้ามแก้ทับ ให้ใช้ Void/Reverse หรือเอกสารแก้ไขตามสิทธิ์'],
                        ['code' => 'purchase-three-way-match', 'mode' => 'daily', 'title' => 'ตรวจ 3-way match ก่อน Post', 'description' => 'จับคู่ PO, Goods Receipt และ Credit Purchase ตาม Supplier, Warehouse, สินค้า/หน่วย, จำนวน และราคา/ต้นทุน; ตอนนี้เป็น read-only preflight ยังไม่เปิด variance approval หรือ Inventory Post จาก Receipt', 'route' => null, 'permission' => 'purchasing.purchase-documents.view', 'block_reason' => 'ถ้าจำนวนหรือมูลค่าไม่ตรง ให้หยุด Post และแก้เอกสารต้นทาง/จัดสรร receipt line ให้ครบก่อน; ห้ามใช้ Journal ปรับเพื่อบังคับให้ผ่าน', 'recovery_hint' => 'จำนวนต่าง: กลับไปตรวจ PO/Receipt และยอดรับคงเหลือ; ราคา/ต้นทุนต่าง: ตรวจใบเสนอราคาและใบซื้อเชื่อ แล้วส่งตาม policy variance. Draft แก้ได้, Approved Receipt ให้ Void พร้อมเหตุผล, Posted ห้ามแก้ทับให้ใช้ Reverse/Credit Note ตามสิทธิ์'],
                    ],
                    'steps' => [
                        ['label' => 'Supplier', 'route' => 'purchasing.suppliers.index', 'permission' => 'purchasing.suppliers.view', 'effect' => 'เตรียมคู่ค้า', 'recovery_hint' => 'ถ้า Supplier มีประวัติแล้ว ห้าม hard delete; ปิดใช้งานผ่านหน้าที่มี guard และ audit เพื่อเก็บประวัติไว้. Approved/Posted ห้ามลบ'],
                        ['label' => 'Purchase Requisition / ใบขอซื้อ', 'route' => 'purchasing.purchase-requisitions.index', 'permission' => 'purchasing.purchase-requisitions.view', 'effect' => 'บันทึกความต้องการซื้อ ตรวจสินค้า/หน่วย แล้วส่งอนุมัติตาม approval policy ก่อนทำ PO; ทีมเล็กทำครบได้เมื่อ policy อนุญาต', 'recovery_hint' => 'ถ้าข้อมูลผิด ให้แก้เฉพาะ Draft/รายการที่ถูกตีกลับ; รายการที่อนุมัติแล้วให้ยกเลิกพร้อมเหตุผล ห้ามแก้ทับประวัติ'],
                        ['label' => 'Purchase Order / ใบสั่งซื้อ', 'route' => 'purchasing.purchase-orders.index', 'permission' => 'purchasing.purchase-orders.view', 'effect' => 'ยืนยันรายการ ราคา หน่วย และเงื่อนไขที่อนุมัติจาก PR ตาม approval policy ก่อนส่งให้ Supplier; ทีมเล็กทำครบได้เมื่อ policy อนุญาต', 'recovery_hint' => 'ถ้า PO ยังเป็น Draft ให้แก้ได้; หาก Approved แล้วให้ Void ตามสิทธิ์และเหตุผลก่อนสร้างรายการใหม่ ห้ามแก้ทับประวัติ'],
                        ['label' => 'Goods Receipt / ตรวจรับสินค้า', 'route' => 'purchasing.purchase-receipts.index', 'permission' => 'purchasing.purchase-receipts.view', 'effect' => 'รับของจริง ตรวจจำนวน หน่วยแปลง และต้นทุนเบื้องต้นตาม PO; การรับสินค้าไม่สร้าง GL ซ้ำกับ Credit Purchase ใน MVP', 'recovery_hint' => 'ถ้ารับผิดให้แก้เฉพาะ Draft; Approved ให้ Void ตามสิทธิ์และตรวจยอดรับคงเหลือใหม่ ห้ามรับเกิน PO และห้ามสร้าง Stock/Cost/GL ซ้ำ'],
                        ['label' => 'Credit Purchase / ใบซื้อเชื่อ', 'route' => 'purchasing.purchase-documents.index', 'permission' => 'purchasing.purchase-documents.view', 'effect' => 'ตรวจ 3-way match กับ PO และ Goods Receipt แล้ว Post เพื่อสร้าง AP Open Item; สินค้าคงคลังลง Stock ผ่าน boundary ของ Purchase Document เท่านั้น', 'recovery_hint' => 'ถ้าจำนวนหรือราคาต่างจาก Receipt ให้หยุดตรวจ variance ก่อน Post; Draft แก้ได้, Posted ห้ามแก้ทับ ให้ใช้ Void/Reverse/Credit Note ตามสิทธิ์'],
                        ['label' => 'AP / Payment', 'route' => 'finance.settlements.index', 'permission' => 'finance.settlements.view', 'effect' => 'ตรวจ AP Open Item แล้วสร้าง/อนุมัติการจ่ายเงินตาม approval policy และเงื่อนไขการชำระเงิน; ทีมเล็กทำครบได้เมื่อ policy อนุญาต', 'recovery_hint' => 'ตรวจ Supplier, Bank/Cash, วันที่ และยอดจัดสรรก่อน Post; หากจ่ายแล้วห้ามแก้ทับ ให้ใช้ reversal และเหตุผลตามสิทธิ์'],
                    ],
                ],
                [
                    'code' => 'inventory-operations', 'title' => 'Inventory Operations', 'mode' => 'setup',
                    'description' => 'เตรียมข้อมูลสินค้า ต้นทุน และขั้นตอนรับ–จ่ายสินค้าในคลัง', 'duration' => 'ประมาณ 20 นาที',
                    'decision_cards' => [
                        ['code' => 'inventory-gl-mapping', 'mode' => 'setup', 'title' => '1. Inventory / COGS Mapping', 'description' => 'บัญชี Inventory และ COGS ต้องเป็นบัญชีที่ active/postable และ mapping ถูกประเภทก่อนเปิด posting', 'route' => null, 'permission' => 'wms.stock-valuation.view', 'event_code' => 'inventory.mapping', 'block_reason' => 'ไปตรวจ Account Mapping ใน Accounting ให้ครบก่อน', 'recovery_hint' => 'ถ้า mapping ผิด ให้แก้ mapping แล้วทำ preflight ใหม่; ห้ามเลือกบัญชีแรกหรือ hard-code account ID'],
                        ['code' => 'inventory-cost-policy', 'mode' => 'setup', 'title' => '2. AVG / FIFO Cost Policy', 'description' => 'บริษัทต้องใช้ costing policy เดียว และกำหนด negative stock/fallback ตามนโยบาย', 'route' => null, 'permission' => 'wms.stock-valuation.view', 'event_code' => 'inventory.cost_policy', 'block_reason' => 'ตรวจ Global Settings และยืนยัน policy ก่อน Post Stock', 'recovery_hint' => 'ถ้าเลือก policy ผิด ให้หยุด Post และแก้ setting ตาม effective-date policy ก่อนเริ่มรายการใหม่'],
                        ['code' => 'inventory-cost-preflight', 'mode' => 'daily', 'title' => '3. Pending / Unlinked Preflight', 'description' => 'ห้ามส่งต่อ Inventory→GL เมื่อยังมี pending cost หรือ allocation ที่ยังไม่ link', 'route' => 'wms.stock-valuation.index', 'permission' => 'wms.stock-valuation.view', 'event_code' => 'inventory.preflight', 'block_reason' => 'เปิด Stock Valuation เพื่อตรวจ Pending/RECOST และ allocation linkage', 'recovery_hint' => 'ตรวจเอกสารรับต้นทุนและรอ RECOST ให้เสร็จ; อย่าปิดงวดหรือยืนยัน GL จากยอด provisional'],
                        ['code' => 'inventory-gl-reconciliation', 'mode' => 'daily', 'title' => '4. Inventory / COGS Reconciliation', 'description' => 'ยอด allocation, Stock projection และ GL control account ต้อง reconcile ก่อนปิดงวด', 'route' => null, 'permission' => 'wms.stock-valuation.view', 'event_code' => 'reconciliation_zero_gate', 'block_reason' => 'ตรวจรายงาน reconciliation ใน Accounting เมื่อ source contract พร้อม', 'recovery_hint' => 'แยก mapping gap, pending recost, unlinked allocation และ rounding delta ก่อนแก้หรือ reverse'],
                        ['code' => 'inventory_purchase_event_wiring', 'mode' => 'setup', 'title' => 'Gate A · Purchase event wiring', 'description' => 'ตรวจว่า Receipt/Purchase source เชื่อม inventory event, Item และ UOM ครบก่อนสร้าง Cost Layer', 'route' => 'wms.stock-valuation.index', 'permission' => 'wms.stock-valuation.view', 'event_code' => 'inventory_purchase_event_wiring', 'block_reason' => 'ยังไม่เปิด Inventory Posting จน source contract และ purchase event wiring พร้อม', 'recovery_hint' => 'ผู้รับผิดชอบ Purchasing/WMS ตรวจ source document, Item, UOM และ source identity; แก้ที่เอกสารต้นทางก่อนขอ preflight ใหม่'],
                        ['code' => 'atomic_journal_movement_allocation_linkage', 'mode' => 'daily', 'title' => 'Gate B · Atomic Journal linkage', 'description' => 'ตรวจ Movement → Allocation → Journal line linkage ให้อยู่ใน transaction เดียวและทำซ้ำไม่ได้', 'route' => 'wms.stock-valuation.index', 'permission' => 'wms.stock-valuation.view', 'event_code' => 'atomic_journal_movement_allocation_linkage', 'block_reason' => 'ยังไม่มีปุ่ม Post เพราะ atomic movement/allocation/Journal linkage ยังไม่ผ่าน gate', 'recovery_hint' => 'ผู้รับผิดชอบ WMS/Accounting ตรวจ unlinked หรือ mismatched allocation และรอ source/posting contract; ห้าม link หรือแก้ Journal ด้วยมือ'],
                        ['code' => 'reconciliation_zero_gate', 'mode' => 'daily', 'title' => 'Gate C · Reconciliation zero', 'description' => 'ผลต่าง allocation กับ GL และ balance กับ allocation ต้องเป็นศูนย์ก่อนเปิด Posting', 'route' => 'wms.stock-valuation.index', 'permission' => 'wms.stock-valuation.view', 'event_code' => 'reconciliation_zero_gate', 'block_reason' => 'ยังไม่พร้อมเปิด Inventory Posting จน reconciliation difference เป็นศูนย์และ reversal gate ผ่าน', 'recovery_hint' => 'ผู้รับผิดชอบ Accounting/WMS แยก mapping gap, pending recost, rounding และ reversal ก่อน reconcile ใหม่; ห้ามปรับยอดเพื่อบังคับให้เป็นศูนย์'],
                    ],
                    'steps' => [
                        ['label' => 'Item / Category / UOM', 'route' => 'wms.items.index', 'permission' => 'wms.items.view', 'effect' => 'กำหนดสินค้า หน่วย และบัญชี GL', 'mode' => 'setup', 'recovery_hint' => 'ถ้า master มีประวัติแล้ว ห้าม hard delete; ปิดใช้งานหรือใช้ Soft Delete ผ่านหน้าที่มี guard และ audit แทน. Approved/Posted ห้ามลบ'],
                        ['label' => 'นโยบายต้นทุน AVG/FIFO', 'route' => null, 'permission' => 'wms.stock-valuation.view', 'effect' => 'กำหนดวิธีต้นทุนระดับบริษัทก่อน Post Stock', 'event_code' => 'inventory.cost_policy', 'block_reason' => 'ตรวจ Global Settings ให้เลือก AVG หรือ FIFO และกำหนดนโยบายสต็อกติดลบก่อนเริ่มงาน'],
                        ['label' => 'Inventory / COGS GL Mapping', 'route' => null, 'permission' => 'wms.stock-valuation.view', 'effect' => 'เตรียมบัญชีควบคุม Inventory และ COGS ก่อนเปิด posting', 'event_code' => 'inventory.mapping', 'mode' => 'setup', 'block_reason' => 'ตั้งค่าและตรวจ Account Mapping ใน Accounting ให้ครบก่อนเปิด Inventory→GL; ยังไม่มีหน้าตั้งค่าข้าม Module ใน WMS'],
                        ['label' => 'Opening Balance', 'route' => 'wms.opening-balances.index', 'permission' => 'wms.opening-balances.view', 'event_code' => 'wms.opening-balances', 'effect' => 'เตรียมยอดยกมาและต้นทุนตั้งต้นของสินค้า', 'mode' => 'setup', 'block_reason' => 'เตรียมยอดสินค้าและต้นทุนยกมาผ่าน Stock Ledger/Import ก่อนเริ่มรับ–จ่ายจริง', 'recovery_hint' => 'ตรวจยอดและหน่วยนับให้ตรงก่อนยืนยัน; หากพบความผิดพลาดหลัง Post ให้ใช้เอกสารปรับปรุงหรือ reversal ห้ามแก้ทับ ledger เดิม'],
                        ['label' => 'Receipt / รับสินค้า', 'route' => 'purchasing.purchase-receipts.index', 'permission' => 'purchasing.purchase-receipts.view', 'effect' => 'รับสินค้าจริง ตรวจจำนวน หน่วย และต้นทุนก่อนเข้าสู่ Stock', 'mode' => 'daily', 'recovery_hint' => 'ตรวจ PO และยอดรับคงเหลือก่อนยืนยัน; หากรับผิดให้ Void ตามสิทธิ์ ห้ามสร้าง Stock ซ้ำ'],
                        ['label' => 'Issue / เบิกสินค้า', 'route' => 'wms.issues.index', 'permission' => 'wms.issues.view', 'effect' => 'สร้างและลง Stock รายการจ่ายออกตามคลัง สินค้า และหน่วย', 'mode' => 'daily', 'recovery_hint' => 'Draft แก้หรือลบได้; Posted แล้วห้ามแก้ Stock Ledger ให้ใช้ reversal/เอกสารย้อนกลับตามสิทธิ์'],
                        ['label' => 'Transfer / โอนระหว่างคลัง', 'route' => 'wms.transfers.index', 'permission' => 'wms.transfers.view', 'effect' => 'ส่งออก รับเข้า และติดตามต้นทุนระหว่างคลังแบบมี lineage', 'mode' => 'daily', 'recovery_hint' => 'ตรวจคลังต้นทาง/ปลายทางและจำนวนก่อนส่ง; รายการเริ่มดำเนินการแล้วห้ามแก้ข้อมูลต้นฉบับ'],
                        ['label' => 'Stock Count / ตรวจนับ', 'route' => 'wms.stock-counts.index', 'permission' => 'wms.stock-counts.view', 'effect' => 'บันทึกผลตรวจนับและส่งอนุมัติตาม approval policy ก่อนสร้าง Adjustment; ทีมเล็กทำครบได้เมื่อ policy อนุญาต', 'mode' => 'daily', 'recovery_hint' => 'แก้ได้เฉพาะ Draft; หลังอนุมัติให้ใช้ Adjustment หรือ reversal ตามสิทธิ์'],
                        ['label' => 'Inventory Adjustment / ปรับปรุง', 'route' => 'wms.inventory-adjustments.index', 'permission' => 'wms.inventory-adjustments.view', 'effect' => 'ปรับปรุงส่วนต่างจากเหตุผลที่ตรวจสอบได้ และลง Stock/GL ตาม gate', 'mode' => 'daily', 'recovery_hint' => 'ต้องมีเหตุผลและ mapping ครบ; Posted แล้วใช้ reversal ห้ามแก้ยอดเดิมโดยตรง'],
                        ['label' => 'Inventory→GL Preflight', 'route' => 'wms.stock-valuation.index', 'permission' => 'wms.stock-valuation.view', 'event_code' => 'inventory.preflight', 'effect' => 'ตรวจ mapping, pending cost, allocation linkage และ period ก่อน posting', 'mode' => 'daily', 'block_reason' => 'ตรวจ preflight ให้ไม่มี mapping ที่ขาด, pending cost หรือ allocation ที่ยังไม่ link ก่อนส่งต่อ Accounting'],
                        ['label' => 'Valuation / RECOST', 'route' => 'wms.stock-valuation.index', 'permission' => 'wms.stock-valuation.view', 'effect' => 'ตรวจ current projection, historical valuation และรายการ RECOST หลังรับต้นทุนจริง', 'next_action' => 'เปิดมูลค่าสินค้าคงเหลือปัจจุบัน; Historical ให้เลือกวันที่ในแท็บ Historical valuation', 'limitation_note' => 'Historical valuation แสดง Final/Pending แยกกันและไม่ใช้ยอดปัจจุบันแทนประวัติ; รายการ Pending/Unlinked ต้องตรวจสอบก่อนปิดงวด', 'mode' => 'daily'],
                        ['code' => 'inventory-reconciliation-resolve', 'label' => 'GL Reconciliation / Resolve', 'route' => null, 'permission' => 'wms.stock-valuation.view', 'event_code' => 'inventory_reconciliation_resolve', 'effect' => 'ทบทวนผลต่าง Inventory/COGS กับ GL และแก้รายการค้างก่อนปิดงวด', 'mode' => 'daily', 'block_reason' => 'เปิดรายงาน reconciliation ใน Accounting เพื่อตรวจและแก้รายการค้างตาม source contract', 'recovery_hint' => 'ใช้ Accounting > Reports > Reconciliation ตรวจ mapping, allocation, Stock projection และ GL; ห้ามแก้ยอดเดิมโดยตรง ให้ใช้ correction/recost/reversal ตาม contract'],
                    ],
                ],
            ], 'daily');
        }

        if ($program === 'production') {
            if ($capability === null || ! $capability->isEnabled(ModuleCapability::PRODUCTION)) {
                return [];
            }

            return self::decorate([[
                'code' => 'plan-to-produce', 'title' => 'Plan-to-Produce',
                'description' => 'ลำดับการวางแผน ผลิต และรับสินค้าสำเร็จรูป', 'duration' => 'ตามรอบการผลิต',
                'steps' => [
                    ['label' => 'Item / BOM / BOQ', 'route' => null, 'permission' => 'production.bom.view', 'effect' => 'เตรียมโครงสร้างสินค้าและวัตถุดิบ', 'mode' => 'setup', 'block_reason' => 'Production workflow ยังไม่มีหน้าปลายทางใน MVP'],
                    ['label' => 'Work Order', 'route' => null, 'permission' => 'production.work-orders.view', 'effect' => 'สร้างและควบคุมคำสั่งผลิต', 'mode' => 'daily', 'block_reason' => 'รอ Production domain และ route ของ Work Order ก่อนเปิดใช้งาน'],
                    ['label' => 'Material Issue / WIP', 'route' => null, 'permission' => 'production.work-orders.view', 'effect' => 'ติดตามวัตถุดิบและต้นทุนระหว่างผลิต', 'mode' => 'daily', 'block_reason' => 'รอ Production domain และ route ของ Material Issue ก่อนเปิดใช้งาน'],
                    ['label' => 'Finished Goods / Cost', 'route' => null, 'permission' => 'production.work-orders.view', 'effect' => 'รับสินค้าสำเร็จรูปและส่งต้นทุนเข้าระบบบัญชี', 'mode' => 'daily', 'block_reason' => 'รอ Production domain และ route ของ Finished Goods ก่อนเปิดใช้งาน'],
                ],
            ]], 'setup');
        }

        if ($program === 'logistics') {
            return self::decorate([[
                'code' => 'delivery', 'title' => 'Delivery',
                'description' => 'โครงร่างลำดับงานจัดส่งที่จะแสดงเมื่อ Logistics domain พร้อม', 'duration' => 'รอ module readiness',
                'steps' => [
                    ['label' => 'Shipment policy / carrier', 'route' => null, 'permission' => 'logistics.shipments.view', 'effect' => 'เตรียมผู้ให้บริการ รอบจัดส่ง และนโยบายการส่งมอบ', 'mode' => 'setup', 'block_reason' => 'Logistics domain ยังไม่เปิดใน MVP จึงยังไม่มีหน้าตั้งค่าให้เริ่มทำงาน'],
                    ['label' => 'Shipment / Dispatch', 'route' => null, 'permission' => 'logistics.shipments.view', 'effect' => 'เตรียม shipment และรอบจัดส่ง', 'mode' => 'daily', 'block_reason' => 'Logistics domain ยังไม่เปิดใน MVP จึงยังไม่มีหน้าปลายทางให้เริ่มทำงาน'],
                    ['label' => 'Delivery / POD', 'route' => null, 'permission' => 'logistics.shipments.view', 'effect' => 'บันทึกการส่งมอบและหลักฐานรับสินค้า', 'mode' => 'daily', 'block_reason' => 'รอ source document และ route ของ Logistics ก่อนเปิดใช้งาน'],
                ],
            ]], 'daily');
        }

        if ($program === 'asset') {
            return self::decorate([
                ['code' => 'asset-setup', 'title' => 'ตั้งค่าก่อนเริ่มใช้งาน', 'mode' => 'setup', 'description' => 'เตรียมหมวด บัญชี สถานที่ และสิทธิ์ก่อนเริ่มบันทึกสินทรัพย์', 'duration' => 'ประมาณ 10 นาที', 'steps' => [
                    ['label' => 'Asset Dashboard', 'route' => 'asset.index', 'permission' => 'asset.dashboard.view', 'effect' => 'ตรวจรายการค้างและความพร้อมของสาขาปัจจุบัน', 'mode' => 'setup'],
                    ['label' => 'หมวดสินทรัพย์และบัญชี', 'route' => 'asset.categories.index', 'permission' => 'asset.categories.view', 'effect' => 'กำหนดบัญชีสินทรัพย์ ค่าเสื่อม ด้อยค่า และกำไร/ขาดทุน', 'mode' => 'setup'],
                    ['label' => 'สถานที่สินทรัพย์', 'route' => 'asset.locations.index', 'permission' => 'asset.locations.view', 'effect' => 'เตรียมสถานที่และโครงสร้างตำแหน่งตามสาขา', 'mode' => 'setup'],
                ]],
                ['code' => 'asset-posting-readiness', 'title' => 'ค่าเริ่มต้นการลงบัญชี', 'mode' => 'setup', 'description' => 'ตรวจ Account Mapping ที่ใช้เป็นค่าเริ่มต้นของแต่ละเหตุการณ์ โดยเอกสารยังตรวจ Category/Source override ซ้ำก่อน Post', 'duration' => 'ตรวจอัตโนมัติ', 'steps' => [
                    ['label' => 'รับรู้สินทรัพย์', 'route' => 'asset.capitalizations.index', 'permission' => 'asset.capitalizations.view', 'effect' => 'ตรวจบัญชีสินทรัพย์และบัญชีพักการรับรู้', 'event_code' => 'asset.capitalization', 'mode' => 'setup'],
                    ['label' => 'เพิ่มมูลค่าสินทรัพย์', 'route' => 'asset.additions.index', 'permission' => 'asset.capitalizations.view', 'effect' => 'ตรวจบัญชีสินทรัพย์และบัญชีพักการรับรู้', 'event_code' => 'asset.addition', 'mode' => 'setup'],
                    ['label' => 'ค่าเสื่อมราคา', 'route' => 'asset.depreciations.index', 'permission' => 'asset.depreciation.view', 'effect' => 'ตรวจบัญชีค่าเสื่อมและค่าเสื่อมสะสม', 'event_code' => 'asset.depreciation', 'mode' => 'setup'],
                    ['label' => 'ด้อยค่าสินทรัพย์', 'route' => 'asset.impairments.index', 'permission' => 'asset.impairments.view', 'effect' => 'ตรวจบัญชีขาดทุนด้อยค่าและด้อยค่าสะสม', 'event_code' => 'asset.impairment', 'mode' => 'setup'],
                    ['label' => 'จำหน่ายสินทรัพย์', 'route' => 'asset.disposals.index', 'permission' => 'asset.disposals.view', 'effect' => 'ตรวจบัญชีสินทรัพย์ ค่าเสื่อมสะสม และกำไร/ขาดทุน', 'event_code' => 'asset.disposal', 'mode' => 'setup'],
                    ['label' => 'ตัดออกสินทรัพย์', 'route' => 'asset.disposals.index', 'permission' => 'asset.disposals.view', 'effect' => 'ตรวจบัญชีสินทรัพย์ ค่าเสื่อมสะสม และขาดทุนจากการตัดออก', 'event_code' => 'asset.write_off', 'mode' => 'setup'],
                ]],
                ['code' => 'asset-daily', 'title' => 'งานประจำวัน: สินทรัพย์', 'mode' => 'daily', 'description' => 'สร้างทะเบียน รับรู้ต้นทุน คำนวณค่าเสื่อม และควบคุมการเปลี่ยนแปลงสินทรัพย์', 'duration' => 'ตามรายการจริง', 'steps' => [
                    ['label' => 'ทะเบียนสินทรัพย์', 'route' => 'asset.assets.index', 'permission' => 'asset.register.view', 'effect' => 'สร้าง แก้ไข และติดตามสถานะสินทรัพย์ในสาขาปัจจุบัน', 'mode' => 'daily'],
                    ['label' => 'รับรู้ต้นทุน', 'route' => 'asset.capitalizations.index', 'permission' => 'asset.capitalizations.view', 'effect' => 'รับรู้จากใบแจ้งหนี้ซื้อ ตั้งทุนใหม่ หรือยอดยกมา ตาม workflow', 'mode' => 'daily'],
                    ['label' => 'ค่าเสื่อมราคา', 'route' => 'asset.depreciations.index', 'permission' => 'asset.depreciation.view', 'effect' => 'คำนวณ อนุมัติ และลงบัญชี Book/Tax ตามงวด', 'mode' => 'daily'],
                    ['label' => 'โอนย้ายและตรวจนับ', 'route' => 'asset.transfers.index', 'permission' => 'asset.transfers.view', 'effect' => 'ควบคุมการย้ายสาขา/สถานที่และตรวจนับสินทรัพย์', 'mode' => 'daily'],
                    ['label' => 'ด้อยค่าและจำหน่าย', 'route' => 'asset.impairments.index', 'permission' => 'asset.impairments.view', 'effect' => 'บันทึกการด้อยค่าและจำหน่ายเมื่อผ่านการตรวจสอบและอนุมัติ', 'mode' => 'daily'],
                    ['label' => 'รายงานและกระทบยอด', 'route' => 'asset.reports.reconciliation.index', 'permission' => 'asset.reports.view', 'effect' => 'ตรวจยอดทะเบียนเทียบ GL และแก้รายการค้างก่อนปิดงวด', 'mode' => 'daily'],
                ]],
                ['code' => 'maintenance-operations', 'title' => 'งานประจำวัน: แจ้งซ่อม', 'mode' => 'daily', 'description' => 'ติดตามการแจ้งซ่อม มอบหมายงาน และแผนบำรุงรักษาแยกจากวงจรบัญชีสินทรัพย์', 'duration' => 'ตามรายการจริง', 'steps' => [
                    ['label' => 'แจ้งซ่อมและติดตามงาน', 'route' => 'asset.maintenance.index', 'permission' => 'asset.maintenance.view', 'effect' => 'เปิดงาน มอบหมาย เริ่มซ่อม รออะไหล่ และปิดงานพร้อม audit', 'mode' => 'daily'],
                    ['label' => 'แผนบำรุงรักษา', 'route' => 'asset.maintenance.schedules.index', 'permission' => 'asset.maintenance.view', 'effect' => 'วางแผนและบันทึกงานบำรุงรักษาตามรอบ', 'mode' => 'daily'],
                    ['label' => 'รายงานแจ้งซ่อม', 'route' => 'asset.reports.maintenance.index', 'permission' => 'asset.reports.view', 'effect' => 'สรุปค่าใช้จ่าย เวลาหยุดใช้งาน และสถานะงานซ่อม', 'mode' => 'daily'],
                ]],
            ], 'daily');
        }

        return [];
    }

    /**
     * Keep mode metadata consistent while the module-specific cards remain
     * declarative and compact. The views use this to switch setup/daily tabs.
     */
    private static function decorate(array $workflows, string $mode): array
    {
        $decorated = [];

        foreach ($workflows as $workflow) {
            $workflow['mode'] = $workflow['mode'] ?? $mode;
            $grouped = [];

            foreach ($workflow['steps'] as $step) {
                $stepMode = $step['mode'] ?? $workflow['mode'];
                $step['mode'] = $stepMode;
                $step['next_action'] = $step['next_action'] ?? ($step['route'] ? 'เปิดหน้ารายการเพื่อเริ่มขั้นตอนนี้' : 'ตรวจสอบข้อมูลที่ต้องเตรียมและแก้ blocker ก่อน');
                $step['depends_on'] = $step['depends_on'] ?? [];
                $step['readiness'] = $step['readiness'] ?? null;
                $step['recovery_hint'] = $step['recovery_hint'] ?? ($stepMode === 'setup'
                    ? 'ถ้าข้อมูลผิด ให้กลับมาแก้การตั้งค่าหรือรายการ Draft ก่อนเริ่มทำรายการจริง; ถ้าจะลบต้องเป็น Draft ที่ยังไม่ถูกอ้างอิงและหน้าปลายทางมีปุ่ม/สิทธิ์ลบ, เลขเอกสารที่จ่ายไปแล้วไม่ reuse และ Approved/Posted ห้ามลบ'
                    : 'ถ้ายังเป็น Draft และยังไม่ถูกอ้างอิง ให้แก้ไขหรือลบได้เฉพาะเมื่อหน้าปลายทางมีปุ่มและสิทธิ์ลบ; เลขเอกสารที่จ่ายไปแล้วไม่ควรนำกลับมาใช้ซ้ำ. Approved/Posted ห้ามลบหรือแก้ทับ ให้ใช้ Void/Reverse หรือเอกสารแก้ไขตามสิทธิ์');
                $grouped[$stepMode][] = $step;
            }

            foreach ($grouped as $workflowMode => $steps) {
                $copy = $workflow;
                $copy['mode'] = $workflowMode;
                $copy['steps'] = $steps;
                $copy['code'] = count($grouped) > 1 ? $workflow['code'].'-'.$workflowMode : $workflow['code'];
                $copy['mode_label'] = $workflowMode === 'setup' ? 'เริ่มใช้งานครั้งแรก' : 'งานประจำวัน';
                $decorated[] = $copy;
            }
        }

        return $decorated;
    }
}
