# Checklist: Physical Sale Posting (Master-controlled)

สถานะ: `[ ]` ยังไม่เริ่ม · `[~]` กำลังทำ · `[x]` ผ่านการตรวจโดย Master

## Gate 0 — Contract

- [x] Master: ยืนยัน sequence ของ transaction, idempotency และ recovery ตาม `07-pos-physical-sale-posting-plan.md`
- [x] Agent A: ตรวจและออกแบบ integration ของ Stock Issue/Cost Allocation/COGS ที่เรียกจาก Physical Sale
- [x] Agent B: ตรวจและออกแบบ Revenue/Tax/AR Open Item และ link กับ Settlement/WHT
- [x] Agent C: ตรวจ UI/route/action/state ของ HS/IV และเสนอจุดเชื่อม service

**Handoff gate:** ทุก agent ต้องรายงานไฟล์ที่แตะ, contract ที่ใช้, test ที่รัน และ blocker; Master ตรวจความสอดคล้องก่อนอนุญาต Gate 1

### ผลตรวจ Gate 0 โดย Master

- ใช้ `StockMovementService::recordIntent()` และ `postWithinTransaction()` สำหรับ Stock ISSUE ได้
- HS รับเงินพร้อม Post เข้าบัญชีเงินสด/ธนาคารโดยตรงและไม่สร้าง AR Open Item/Settlement; IV เท่านั้นที่ Post เข้า AR และรับชำระภายหลัง
- Gate 1 เริ่มด้วย `NONE VAT` ตาม contract ปัจจุบัน; VAT/WHT ของเอกสารขายต้องเพิ่ม field/snapshot ก่อนเปิดจริง
- Blocker ที่ต้องแก้ใน Gate 1: ISSUE allocation ใช้ค่า cost ติดลบ แต่ COGS adapter ปัจจุบันรับเฉพาะค่าบวก; และ FIFO อาจมีหลาย allocation แต่ Physical Sale เก็บ COGS journal ได้เพียงหนึ่งเลข
- UI route ต้องใช้ permission แยก `pos.physical-sales.post`; HS Draft แสดง Post/รับเงินและ Void, IV Posted จึงแสดงรับชำระหนี้

## Gate 1 — Backend posting service

- [x] Master: สร้าง `PhysicalSalePostingService` เป็น outer transaction boundary
- [x] Stock issue ต่อบรรทัดด้วย idempotency และ final cost allocation
- [x] COGS Journal และ Revenue/AR Journal ต้อง balance และ link กลับ HS/IV (`NONE VAT`)
- [x] เปลี่ยน `DRAFT → POSTED` พร้อม audit หลัง side effects สำเร็จทั้งหมด
- [x] Retry ตรวจและคืนผลเดิม ห้ามสร้างรายการซ้ำ

**Gate 1 handoff:** COGS adapter รับเฉพาะ ISSUE allocation มูลค่าติดลบ แล้วใช้ค่าสัมบูรณ์เป็น Dr COGS / Cr Inventory; FIFO หลาย allocation รวมเป็น COGS Journal เดียวและ link immutable Journal-line แยกทุก allocation. Revenue plan lock/validate item revenue account และ `SALES_AR`, จากนั้นสร้าง AR Open Item ของ HS/IV. Master verification: focused Unit `10 tests / 30 assertions`, Pint และ Blade cache ผ่าน; Manual MySQL posting/retry/rollback อยู่ Gate 4.

## Gate 2 — HTTP/UI

- [x] Route/permission/action `ยืนยันขาย`
- [x] UI แสดง progress/error ที่แก้ไขได้ และ disable ปุ่มระหว่าง request
- [x] หลัง Post: ปุ่มรับชำระเงินสำหรับ HS/IV ตามสิทธิ์

**Gate 2 evidence:** local HS `HS-2026-000001` Post สำเร็จเป็น `POSTED`; สร้าง Stock Movement 1, FINAL Cost Allocation 1 ที่ link COGS Journal `800`, Revenue/AR Journal `801` และ AR Open Item 1. Retry ด้วยวันที่เดิมมี counts คงเดิม; วันที่ต่างกันถูก reject. ระหว่าง smoke พบและแก้ 3 contract defects: quantity stock 8 ตำแหน่ง, missing physical-sale identity ใน intent และ UOM snapshot เก่าไม่ครบ. เพิ่ม `due_date` snapshot: IV ใช้ active payment term หรือบังคับระบุวันครบกำหนด, HS legacy ใช้วันเอกสาร.

## Gate 3 — Payment

**ลำดับงานที่ล็อก:** ทำใบรับมัดจำ (AI), AI allocation เข้า HS และ MySQL E2E ให้เสร็จก่อน แล้วจึงเริ่ม Sales Return/credit-note/reversal รอบถัดไป

- [x] Tender หลายช่องทางในหน้า HS และบันทึก `PhysicalSaleTender` พร้อมบัญชีเงินสด/ธนาคารและเลขอ้างอิง; ไม่ใช้ Finance Settlement หรือ AR Open Item
- [x] WHT snapshot ตั้งแต่ร่าง HS/IV; HS คำนวณยอดรับสุทธิและ default Tender ตามยอดสุทธิ
- [x] HS ยอดเกินเป็น Customer Advance และยอดขาด block; เอกสารยอดศูนย์ยืนยันได้
- [x] ใบรับเงินล่วงหน้า: สร้างพร้อม Tender/รับเงินใน transaction เดียว, มีเลขที่เอกสาร, tax treatment `รวมภาษี`/`ภาษีนอก`/`ไม่มีภาษี`, VAT snapshot, WHT snapshot และเลขอ้างอิง
- [x] การตัดเงินรับล่วงหน้าเข้า HS: ใช้ได้เฉพาะเอกสารที่ Post แล้วยังเหลือยอด, อยู่ใน customer/company/warehouse scope เดียวกัน และมี `tax_treatment` ตรงกับ HS แบบ exact match; UI ไม่แสดงรายการที่ต่างกันและ server lock/recheck ป้องกันตัดเกิน/ตัดซ้ำ โดยไม่ Post cash/VAT ซ้ำ
- [x] IV ใช้ Finance Settlement กลางสำหรับรับชำระหนี้; MySQL E2E ยืนยัน AR Open Item, WHT realization และยอดคงเหลือเป็นศูนย์

**Gate 3 handoff:** AI และ HS lock/recheck ยอด Tender/AI allocation ใน transaction. AI ออกเลขเอกสารและ Post รับเงินพร้อม Tender: Dr เงินสด/ธนาคาร, Dr WHT receivable (ถ้ามี), Cr เงินรับล่วงหน้าลูกค้า; ถ้า AI เป็น tax point ให้แยก Cr ภาษีขายตาม tax policy และ link tax invoice. HS Post Dr เงินรับล่วงหน้าลูกค้า (ส่วนที่ตัด AI), Dr เงินสด/ธนาคารและ Dr WHT receivable (ส่วนที่รับใหม่), Cr รายได้/ภาษีขายโดยต้องไม่ Post VAT ซ้ำจาก AI. AI ต้องเป็นเอกสารรับเงินล่วงหน้าที่ Post แล้วและลดเฉพาะยอดคงเหลือ ห้ามเกิด cash movement ซ้ำเมื่อถูกตัด. IV เท่านั้นที่สร้าง AR Open Item และเปิด Finance Settlement ตาม warehouse/party/account scope. HS/IV/AI เก็บ Tax Code/rate/base/amount เป็น snapshot ที่ร่าง และ server คำนวณจาก snapshot เพื่อไม่เปลี่ยนตาม Tax Code master ภายหลัง.

### POS-native receipt follow-up

- [x] หน้าคอนเฟิร์ม HS รับเงินใน POS พร้อม Post โดยตรง
- [x] รับหลาย Tender, default ยอดสุทธิหลัง WHT และเงินเกินเป็น Customer Advance
- [x] แสดง Tender และ Journal ที่ Post แล้วในหน้า HS; รับชำระหนี้คงไว้เฉพาะ IV ผ่าน Finance Settlement กลาง
- [x] แสดงเงินรับล่วงหน้าที่ตัดแล้วและยอดคงเหลือในหน้า HS พร้อม related-document timeline

## Gate 4 — Verification

- [x] Unit tests: status/idempotency/tender/WHT/HS cash/VAT, IV AR/receipt, advance และ return/reversal ผ่าน
- [x] MySQL E2E: advance/deposit, HS VAT cancellation, IV AR/receipt/WHT และ Sales Return ผ่าน 5 tests / 65 assertions และ rollback ไม่มี fixture ค้าง
- [ ] Manual browser sign-off: HS cash/WHT/zero/overpayment/advance allocation, IV AR/receipt, insufficient stock, mapping missing และ retry
- [~] Master review: code-level stock, COGS, revenue, HS cash/bank/WHT/advance, IV AR/settlement และ journal balance ผ่าน; เหลือ browser sign-off และ GL reconciliation รายงานจริง
