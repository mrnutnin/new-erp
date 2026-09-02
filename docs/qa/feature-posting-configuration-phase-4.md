# Manual QA — Feature Posting Configuration / Phase 4

## Automated evidence — 02/09/2026

- [x] Unit contract suite: 27 tests / 152 assertions
- [x] Local MySQL rollback-only suite: 9 tests / 102 assertions
- [x] `pint` และ `git diff --check` ผ่าน

MySQL proof ครอบคลุม:

- [x] `customer_advance`: เงินรับล่วงหน้า customer/supplier, retry และ reversal
- [x] `customer_advance`: รับหลาย Bank Account และ refund กลับบัญชีต้นทางทุกบัญชี
- [x] `sales_invoice`: HS VAT-inclusive, IV, WHT และ retry
- [x] `sales_invoice`: เปลี่ยน AR mapping แล้ว Journal เดิมคง mapping version เดิม ขณะที่ IV ใหม่ใช้ account/version ใหม่
- [x] HS ตัดเงินรับล่วงหน้าโดยไม่สร้าง AR หรือ Journal รายได้ใบที่สอง
- [x] `sales_credit_note`: คืน HS ไปยัง Bank ที่เลือก และ IV credit note ลด AR
- [x] cancellation/reversal เก็บ original Journal metadata
- [x] `sales_commission_payout`: mapping `COMMISSION_EXPENSE`, Bank source และ idempotency

## Dataset / configuration ที่ใช้

- Local MySQL `new_erp`, rollback ทุก test transaction
- User fixture, Warehouse, open fiscal period, customer, stock item, VAT OUT/WHT และ Bank Account ที่ active
- Event mapping: `sales_invoice` (`ACCOUNTS_RECEIVABLE`, `DEFERRED_OUTPUT_VAT`, `WHT_RECEIVABLE`, `CUSTOMER_ADVANCE`), `customer_advance` (`CUSTOMER_ADVANCE`, `WHT_RECEIVABLE`) และ `sales_commission_payout` (`COMMISSION_EXPENSE`)
- รายได้ขาย POS มาจาก `Item.sales_account_id` เป็น `MASTER` source ตาม Owner decision; ไม่ใช้ mapping `SALES_REVENUE`

## Manual QA / Owner sign-off

- [x] ตรวจผ่าน UI: HS/IV ใหม่ใช้ `Item.sales_account_id`; Journal เดียวสมดุล และ metadata แสดง Item/Bank/mapping source ถูกต้อง
- [x] ตรวจผ่าน UI: เปลี่ยน mapping แล้ว Journal ที่ Post เดิมไม่เปลี่ยน แต่เอกสารใหม่ใช้ mapping version ใหม่
- [x] ตรวจผ่าน UI: รับชำระลูกค้าและจ่ายผู้ขาย ใช้ Bank จากเอกสาร, AR/AP จาก Open Item ต้นทาง และ VAT/WHT ตาม event mapping
- [x] ตรวจผ่าน UI: AI/customer advance และการตัด AI เข้า HS ใช้ account snapshot ต้นทาง; retry ไม่ซ้ำ
- [x] ตรวจผ่าน UI: partial return/refund และ cancellation ใช้ original Journal; Credit Note/AR/Open Item/ภาษี reconcile
- [x] ตรวจผ่าน UI: commission payout ใช้ค่าใช้จ่ายคอมมิชชั่นจาก mapping และ Bank จากเอกสาร
- [x] ตรวจ GL/subledger reconciliation ของ AR, AP, VAT/WHT, customer advance และ commission payout เป็นศูนย์

Owner sign-off: ผ่านการตรวจรับ Phase 4 — 02/09/2026
