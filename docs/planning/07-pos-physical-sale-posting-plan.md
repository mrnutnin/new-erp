# แผนพัฒนา Post HS/IV และรับชำระเงิน

## เป้าหมาย

ทำให้ HS/IV เปลี่ยนจาก `DRAFT` เป็น `POSTED` อย่างถูกต้องใน transaction เดียว โดยแยก contract ให้ชัดเจน:

- `HS` เป็นขายสด: รับเงินพร้อม Post เข้าบัญชีเงินสด/ธนาคารโดยตรง ไม่สร้าง AR Open Item หรือใบรับชำระหนี้
- `IV` เป็นขายเชื่อ: Post ลูกหนี้การค้า แล้วรับชำระภายหลังผ่าน Finance Settlement กลาง

## ขอบเขตและลำดับงาน

1. เพิ่ม `PhysicalSalePostingService` และ route `POST /pos/physical-sales/{physicalSale}/post`
   - Lock HS/IV, Sales Order และรายการขาย
   - อนุญาตเฉพาะ `DRAFT`; retry เอกสาร `POSTED` ต้องตรวจ identity เดิมและไม่สร้างรายการซ้ำ
   - ตรวจวันที่ Post, คลัง, Item/UOM/conversion snapshot, fiscal period และ account mapping

2. Post Stock และต้นทุน
   - สร้าง Stock `ISSUE` ต่อ Physical Sale Line ผ่าน `StockMovementService::recordIntent()`
   - Post ด้วย `postWithinTransaction()` เพื่อ rollback ได้ทั้งชุด
   - ใช้ Cost Allocation ที่สถานะ final เท่านั้น; negative/provisional cost ต้อง block พร้อมข้อความแก้ไข

3. Post GL
   - สร้าง COGS Journal จาก allocation ผ่าน `sales_cogs`
   - สร้าง Revenue/Tax Journal จากยอด HS/IV
   - ทุก Journal ต้องมี idempotency key ของ Physical Sale และยอด Debit = Credit

4. รับเงิน, AR และสถานะเอกสาร
   - IV: สร้าง AR Open Item จาก Journal ลูกหนี้; การรับชำระใช้ Finance Settlement กลาง
   - HS: ระบุ Tender ในหน้าคอนเฟิร์มและ Post เดบิตบัญชีเงินสด/ธนาคารโดยตรง; ไม่สร้าง AR Open Item หรือ Settlement
   - Link journal/movement/allocation/tender กับ HS/IV, เปลี่ยนสถานะเป็น `POSTED`, บันทึก Audit

5. รับเงินพร้อม Post สำหรับ HS
   - แสดง action `ยืนยันขายและรับชำระเงิน` ขณะ HS ยังเป็น Draft
   - หนึ่งใบรับเงินมี Tender ได้หลายบรรทัด: เงินสด/โอน/บัตร โดยผูก Bank/Cash Account และเลขอ้างอิง
   - ค่าเริ่มต้น Tender ต้องเท่ากับยอดสุทธิหลัง WHT; ยอดรับจริงต้องไม่น้อยกว่ายอดสุทธิ และยอดเกินบันทึกเป็น Customer Advance
   - WHT ทำให้ Dr เงินสด/ธนาคารลดลง และ Dr ภาษีหัก ณ ที่จ่ายรอขอคืน; Credit รายได้/ภาษีขายยังเท่ายอดเอกสาร
   - เอกสารยอดศูนย์ยืนยันได้โดยไม่ต้องมี Tender
   - ผู้ใช้เลือกตัด `ใบรับมัดจำ (AI)` ที่ Post แล้วและยังเหลือยอดได้; ต้องเป็นลูกค้า/บริษัท/คลังเดียวกัน, lock ยอด AI ใน transaction และห้ามตัดเกินยอดคงเหลือ
   - ยอดที่ตัด AI ไม่สร้าง cash movement ซ้ำ: Post `Dr เงินรับล่วงหน้าลูกค้า, Cr รายได้/ภาษีขาย` ร่วมกับ Tender เงินสด/ธนาคารของส่วนต่าง และเก็บ allocation link กลับ AI

6. UI และ recovery
   - Draft: ยืนยันขาย, ยกเลิก
   - Posted: HS แสดง Tender และดู Journal/Stock/Allocation; IV แสดง AR/Open Item และรับชำระหนี้; ห้ามแก้ไขหรือลบ
   - ยกเลิกก่อน Post เป็น `VOID`; ยกเลิกหลัง Post ต้องสร้าง Sales Return/Credit Note และ reversal ที่ trace กลับเอกสารเดิมได้ (เฉพาะงวดเปิด)

## Transaction และ idempotency

`lock source → validate tax/tender/AI balance → stock issue → final cost allocation → COGS journal → revenue journal → (HS: cash/bank tender + AI application | IV: AR Open Item) → update HS/IV status → audit`

ทุกขั้นอยู่ใน outer database transaction เดียว หากขั้นใดล้มเหลวต้อง rollback ทั้งหมด ห้ามมี Stock, Journal หรือ Open Item ค้างเพียงบางส่วน

## เกณฑ์เสร็จ

- ปุ่ม `ยืนยันขาย` ใช้งานได้เฉพาะ Draft และเปลี่ยนเป็น Posted ได้ครั้งเดียว
- Stock, Cost Allocation, COGS และ Revenue/Tax reconcile กัน; HS reconcile กับ Tender/เงินสด-ธนาคาร, IV reconcile กับ AR Open Item
- Retry ไม่สร้าง Movement/Journal/Open Item ซ้ำ
- HS รับหลาย Tender, WHT, การตัด AI และยอดเกินเป็น Advance ได้โดยไม่สร้าง AR/Settlement
- มี Unit Tests สำหรับ status, tender/WHT, idempotency และ manual MySQL QA สำหรับ rollback/reconciliation
