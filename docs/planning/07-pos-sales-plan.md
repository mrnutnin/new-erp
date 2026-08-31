# POS / Sales Module Plan

เอกสารนี้เป็นแผนพัฒนา Sales Core และ POS/Sale โดยยึดหลักว่าองค์กรขนาดเล็กเริ่มใช้งานได้ง่าย และองค์กรขนาดกลาง/ใหญ่สามารถเปิด policy เพิ่มได้โดยไม่ต้องเปลี่ยนแกนข้อมูลบัญชี

## ขอบเขตและลำดับงาน

### Wave 1 — Sales Core และข้อมูลลูกค้า

- [ ] คู่มือการทำงาน Sales/POS แยกโหมดเริ่มใช้งานครั้งแรกและงานประจำวัน
- [x] Customer และ Customer Group บน Party/PartyRole กลาง — shared foundation/schema, CRUD, server-side DataTable, Select2 options, audit, routes/sidebar/RBAC และ Admin permission seed ครบ; เหลือการนำกลุ่มไปใช้กับ Sales document
- [x] Credit Term และ Credit Limit — ใช้ `PartyRole` + `finance_payment_terms`; Draft สร้างได้ตามปกติ แต่ Approval/Post ของ Invoice จะตรวจ AR Open Item exposure รวมทุกคลังแบบ decimal-safe; `credit_limit = 0` หมายถึงไม่จำกัดวงเงิน
- [x] ที่อยู่ออกบิลและที่อยู่จัดส่ง — เก็บ `party_addresses` แยกประเภทและ audit แล้ว
- [x] สิทธิ์, warehouse/company scope, audit และ document history — customer foundation ใช้ permission เดิมและบันทึก audit; เอกสารขายจริงยังค้าง

### Wave 2 — Pricing Foundation

- [x] Price List — schema, resolver, immutable snapshot, CRUD/UI, server-side DataTable, Select2 options, routes/sidebar/RBAC และ Admin permission seed ครบ; Sales invoice lines คำนวณจาก server และเก็บ snapshot แล้ว
- [x] ราคาตามกลุ่มลูกค้า — resolver รองรับ group ก่อน global fallback
- [x] ส่วนลดและเงื่อนไขราคา — minimum quantity/effective date/priority รองรับใน foundation
- [x] snapshot ราคา/ส่วนลดในเอกสาร ห้ามแก้ยอดย้อนหลังจาก master — `PriceListSnapshot` ถูกเก็บใน Sales lines แล้ว และการแก้ master ไม่เปลี่ยนเอกสารเดิม
- [~] คำนวณจำนวน เงิน และทศนิยมจาก Global Settings — Sales/GL/Open Item ยังเก็บเงิน 2 ตำแหน่ง จึงบล็อกค่ามากกว่า 2 เพื่อไม่ให้ถูกตัดทิ้ง; ต้องขยาย storage กลางพร้อมกันก่อนเปิด 3–4 ตำแหน่ง (ยังไม่เปิด smoke test)

### Wave 3 — Sales Workflow

- [x] ใบรับข้อมูลเบื้องต้น — schema/model, sequence, customer/item/UOM Select2 AJAX, server-side DataTable, audit/history, RFQ-required flag, CRUD draft และ idempotent conversion ไป RFQ พร้อมลิงก์ต้นทาง/ปลายทางแล้ว; รองรับผู้เตรียมเอกสาร วิธีสั่งซื้อ/จัดส่ง วันนัดรับ ที่อยู่จัดส่ง/ออกบิล, tax treatment/prices-include snapshot และสรุป subtotal/discount/tax base/VAT/grand total โดยคำนวณจากเซิร์ฟเวอร์และใช้จำนวนทศนิยม Global Setting; เหลือเชื่อม Quotation/Sales Order และ UI sign-off
- [~] ใบขอราคา (RFQ) — schema/model/state/validation, UI DataTable + Select2, document sequence, permission-gated actions, mPDF และ conversion ไป Quotation แบบหนึ่งต่อหนึ่งพร้อมลิงก์ย้อนกลับแล้ว; เหลือ manual/UI sign-off
- [x] ใบเสนอราคา — schema/model/state foundation, document sequence, permission และหน้า detail/history พร้อม conversion จาก RFQ ที่ส่งแล้วแบบ idempotent แล้ว; กรอกราคา/ส่วนลดใน DRAFT พร้อมคำนวณจาก serverและล็อกรายการ/จำนวนตาม RFQ แล้ว; สถานะ DRAFT→SENT→ACCEPTED/REJECTED และยกเลิกจาก DRAFT/SENT พร้อมเหตุผลและประวัติภาษาไทย; เปลี่ยนสถานะไม่ได้เมื่อสร้าง Sales Order แล้ว (เหลือ manual/UI sign-off)
- [x] ใบสั่งขาย — สร้างจากใบเสนอราคาที่ส่งแล้ว/ตอบรับแล้ว หรือจาก RFQ ที่ส่ง/ปิดแล้วโดยตรงแบบหนึ่งต่อหนึ่ง, snapshot ลูกค้า/รายการ/ราคา, sequence `SALES_ORDER`, ลิงก์ย้อนกลับและประวัติเอกสาร พร้อมสิทธิ์ Admin seed; เส้นทางตรงคัดลอกเฉพาะจำนวนจาก RFQ และให้กรอกราคาในใบสั่งขาย
- [x] Customer-facing PDF สำหรับ RFQ/ใบเสนอราคา/ใบสั่งขาย — ใช้ mPDF รองรับภาษาไทย/หลายหน้า/โลโก้บริษัท, header ตารางซ้ำทุกหน้า และ Preview/Print action พร้อมสิทธิ์แยกสำหรับ Quotation/Sales Order แล้ว; เหลือ manual/UI sign-off
- [x] เชื่อมเอกสารต้นทาง/ปลายทางและประวัติเอกสาร — RFQ → Quotation → Sales Order และ RFQ → Sales Order เชื่อมแล้ว พร้อมลิงก์ใน DataTable/detail และ PDF ของ Quotation/Order
- [x] Draft → Approved → Posted/Completed และการยกเลิก/แก้ไขตามสถานะ — สำหรับ Sales MVP ใช้สถานะตามชนิดเอกสาร: Quotation มี DRAFT→SENT→ACCEPTED/REJECTED/CANCELLED; แก้ไขได้เฉพาะ DRAFT, ปฏิเสธ/ยกเลิกต้องมีเหตุผล, และล็อกเมื่อมี Sales Order; Intake/Sales Order ยังไม่บังคับ Approval ตาม policy MVP (HS/IV/Post อยู่ Wave 4)

#### Sales document flow (locked)

รองรับ 4 เส้นทางตามลักษณะธุรกิจ:

**Trading / ซื้อมาขายไป**

1. `ใบรับข้อมูลเบื้องต้น → RFQ → Quotation → Sales Order → HS/IV`
2. `ใบรับข้อมูลเบื้องต้น → RFQ → Sales Order → HS/IV`

**ผลิตเองโดยไม่ต้องผ่าน Production module**

3. `ใบรับข้อมูลเบื้องต้น → RFQ → Quotation → Sales Order → ใบสั่งผลิต → ใบเบิกผลิต → ใบรับผลิต → HS/IV`
4. `ใบรับข้อมูลเบื้องต้น → RFQ → Sales Order → ใบสั่งผลิต → ใบเบิกผลิต → ใบรับผลิต → HS/IV`

RFQ ใช้เฉพาะกรณีที่ราคาขายต่ำกว่าราคามาตรฐานจาก Price List โดยระบบต้องบังคับสร้าง RFQ และเข้าสู่ approval policy ก่อนดำเนินการต่อ ส่วนราคาปกติให้รองรับการข้าม RFQ ตาม policy ที่จะกำหนดในใบรับข้อมูลเบื้องต้น

ความหมายเอกสารปลายทาง:

- `HS` = บิลเงินสด/ใบกำกับภาษี
- `IV` = ใบส่งสินค้า/ใบกำกับภาษี

กติกา MVP สำหรับ Approval และการลบ:

- ใบรับข้อมูลเบื้องต้น, Sales Order, ใบสั่งผลิต, ใบเบิกผลิต และใบรับผลิต ไม่ต้องขออนุมัติใน MVP
- RFQ ขออนุมัติเฉพาะกรณีราคาต่ำกว่าราคามาตรฐานตาม policy
- เอกสารที่ยังเป็น Draft ลบได้เมื่อไม่มีเอกสารปลายทางผูกอยู่
- การลบต้องย้อนจากปลายทางกลับต้นทางตามลำดับ เช่น `HS/IV → ใบรับผลิต → ใบเบิกผลิต → ใบสั่งผลิต → Sales Order → Quotation → RFQ → ใบรับข้อมูล`
- หากมีเอกสารถัดไปแล้ว ระบบต้องบอกผู้ใช้ให้ยกเลิก/ลบเอกสารถัดไปก่อน และห้ามลบข้ามลำดับ

ไม่รองรับ `Quotation` ที่ไม่มี RFQ และไม่ให้เพิ่มรายการสินค้าใหม่ระหว่างแปลง RFQ เป็น Quotation หรือ Sales Order เพื่อป้องกันรายการนอกคำขอเดิมหลุดเข้าเอกสาร

### Wave 4 — Sales Transaction และ AR

- [x] HS/IV dual Journal linkage foundation — เพิ่มลิงก์ `cogs_journal_entry_id` แบบ unique/restrict เพื่อเก็บ Journal รายได้/ลูกหนี้และ `sales_cogs` แยกกัน; ยังไม่เปิด POST จริงจนกว่า orchestrator จะผูก Stock Movement, Cost Allocation และ Journal แบบ atomic
- [x] HS/IV posting-plan contract — สร้างแผนแบบ side-effect-free ที่ตรวจ identity และ line alignment ระหว่าง Stock ISSUE กับ Revenue Journal พร้อม idempotency key; ยังไม่เปิด route POST จริงจนกว่าจะมี orchestrator ครอบ transaction ครบ
- [x] HS/IV COGS payload contract — ตรวจว่า Cost Allocation/Stock Movement มาจาก HS/IV เดียวกัน และ payload เดบิต COGS/เครดิต Inventory แบบยอดเท่ากัน; ยังไม่สร้าง Journal จริงจนกว่า orchestrator จะครอบ transaction เดียว
- [x] HS/IV posting-readiness gate — ตรวจสถานะ DRAFT, source/วันที่, NONE VAT, รายการสินค้า/หน่วย Stock, จำนวนและยอดรวมก่อนเข้า transaction; เป็น validation แบบไม่สร้างผลข้างเคียง

- [~] ขายสด/ขายเชื่อ (HS/IV) — Draft/DataTable/รายละเอียด/PDF foundation พร้อมแล้ว; ยังไม่เปิดตัด Stock หรือ GL จนกว่า Inventory → GL gate และ posting orchestrator จะผ่าน
- [x] HS/IV physical-sale validation contract foundation — ตรวจ HS/IV, source document, สินค้าเท่านั้น, UOM conversion, stock quantity และลำดับรายการแบบ deterministic; ยังไม่เปิด posting จริง
- [x] HS/IV Stock ISSUE intent foundation — สร้าง intent แบบ deterministic ไปยัง Stock Movement ด้วยหน่วย Stock, quantity ที่แปลงแล้ว, source linkage และ idempotency key; ยังไม่ตัด Stock/GL จริงจนกว่า posting orchestrator และ Inventory → GL gate จะผ่าน
- [x] HS/IV AR/revenue Journal intent foundation — สร้าง payload `sales_invoice` แบบ deterministic สำหรับ Dr AR และ Cr รายได้ที่จัดกลุ่มตามบัญชีสินค้า รองรับเฉพาะ NONE VAT; ยังไม่ POST จริงจนกว่าจะมี COGS Journal linkage และ orchestrator แบบ transaction เดียว
- [x] HS/IV customer-facing PDF — ใบขายสด/ใบกำกับภาษี (HS) และใบส่งสินค้า/ใบกำกับภาษี (IV) พิมพ์ได้หลายหน้า รองรับภาษาไทย/โลโก้บริษัท และเครื่องพิมพ์ Dot Matrix/ทั่วไป ด้วย mPDF/shared renderer; เป็น read-only และไม่ทำให้สถานะหรือ Stock/GL เปลี่ยน
- [~] ใบลดหนี้/รับคืนสินค้า (Sales Return) — DRAFT foundation, เลือก HS/IV และ source line ด้วย Select2 AJAX, ป้องกันเลือกบรรทัดซ้ำ, เก็บ source linkage, date/status filters และ PDF หลายหน้า/โลโก้บริษัทแล้ว; ยังไม่เปิด Stock/GL reversal
- [ ] ใบแจ้งหนี้
- [ ] รับชำระหนี้ (ใช้ Finance Settlement กลาง)
- [ ] ใบรับมัดจำ (AI) — เอกสารรับเงินล่วงหน้าที่มีเลขที่เอกสาร, วิธีภาษี, WHT และ Tender; Post รับเงินพร้อมกันแบบ atomic แล้ว HS จึงเลือกตัดยอดคงเหลือได้โดยไม่รับเงินซ้ำ
- [~] ใบลดหนี้ (CN) และใบเพิ่มหนี้ (DN) — Sales Document/AR CN foundation มีแล้ว; DN และการเชื่อม Sales Return/Stock ยังอยู่ในแผนถัดไป
- [ ] AR/Open Item และ Journal posting แบบ idempotent
- [ ] Inventory/COGS เปิดได้ต่อเมื่อ Inventory → GL gate ผ่านแล้วเท่านั้น

#### HS/IV inventory boundary (locked)

HS (บิลเงินสด/ใบกำกับภาษี) และ IV (ใบส่งสินค้า/ใบกำกับภาษี) เป็นเอกสารขายจริงเพียงจุดเดียวที่มีผลต่อสินค้าคงเหลือ เมื่อผู้ใช้กด **Post** เท่านั้น เอกสารรับข้อมูล, RFQ, Quotation และ Sales Order ไม่มีผลต่อ Stock หรือ COGS

- HS/IV ต้องมีรายการสินค้าและหน่วยขายที่แปลงกลับเป็น **หน่วย Stock** ได้ พร้อม snapshot factor/UOM ณ วันที่เอกสาร; ห้ามใช้บรรทัดบริการเป็น stock issue
- Stock ISSUE intent ต้องอ้างอิงบรรทัด HS/IV ที่บันทึกแล้ว เรียงตาม line number และเก็บ source/reference, conversion snapshot และ idempotency key เพื่อรองรับ retry โดยไม่สร้าง movement ซ้ำ
- Journal intent ฝั่งขายต้องอ้างอิงเลขที่เอกสาร/วันที่จริง, Party CUSTOMER และบัญชีรายได้ของสินค้าแบบจัดกลุ่ม deterministic; ภาษีต้องเป็นศูนย์ใน MVP และห้ามเดาบัญชี AR/รายได้
- ต้องเชื่อม Sales Order (หรือใบรับผลิตในกรณี capability ผลิตเปิด) และตรวจยอดที่ส่งแล้วไม่เกินยอดสั่ง/ยอดรับผลิต
- การ Post ต้องเป็น transaction เดียว: lock เอกสารและ source → ตรวจ Party/warehouse/mapping → สร้าง Stock `ISSUE` ตามหน่วย Stock → คำนวณ AVG/FIFO Cost Allocation → สร้าง AR/revenue และ `sales_cogs` Journal → บันทึก linkage, audit และ idempotency
- หาก Stock ไม่พอ ระบบต้องบล็อกก่อน Post พร้อมบอกสินค้าที่ขาดและวิธีแก้; ห้ามสร้าง Journal หรือหัก Stock บางส่วน
- HS/IV ที่ Post แล้วแก้หรือลบไม่ได้ การแก้ไขใช้ Credit Note/เอกสารกลับรายการตาม contract; Draft ยกเลิกหรือลบได้เมื่อไม่มีเอกสารปลายทาง
- หน้าสร้าง/รายละเอียดต้องแสดงสถานะ, เอกสารต้นทาง, Stock movement, cost allocation, Journal และ Document History; มี PDF สำหรับพิมพ์หลายหน้า/โลโก้บริษัท
- ใบขายบริการที่ไม่มี Stock ใช้ service-sale path แยกต่างหากและไม่เรียก inventory posting

การเปิดใช้ HS/IV แบบตัด Stock จะทำหลัง Inventory → GL gate ผ่านเท่านั้น ส่วนบริษัทที่ไม่ใช้ Inventory สามารถเปิดเฉพาะ service/revenue sales path ได้โดยไม่สร้าง Stock/COGS

### Next priority checklist — หลังเปิด HS/IV cash-sale flow

รายการนี้เป็น backlog ที่เรียงตามความเสี่ยงของข้อมูลบัญชี ไม่ใช่การเปิด scope ใหม่พร้อมกัน

#### P0 — ใบรับมัดจำ (AI) และตัดยอดใน HS

- [~] สร้างใบรับมัดจำ (AI) แบบ Draft → Posted พร้อมเลขเอกสาร, ลูกค้า, บริษัท/คลัง, บัญชีเงินสด/ธนาคาร, วันที่รับเงิน, เลขอ้างอิง, สกุลเงิน และ audit snapshot
- [~] AI ต้องระบุวิธีคำนวณภาษี `รวมภาษี` / `ภาษีนอก` / `ไม่มีภาษี` (default `รวมภาษี`), Tax Code/rate/base/amount snapshot, วันที่ภาษี และ policy ว่า AI เป็น tax point หรือไม่
- [~] AI ต้องรองรับ WHT ที่ผู้จ่ายหัก: snapshot WHT code/rate/base/amount, default ยอด Tender สุทธิหลัง WHT, เลขอ้างอิงหนังสือรับรอง และ validation ยอดเงินรับจริง
- [~] สร้าง AI พร้อมรับชำระใน transaction เดียว: Tender ได้หลายช่องทางพร้อม Bank/Cash Account, reference, amount; lock/recheck ยอด, เลขที่เอกสารและ idempotency ก่อน Post
- [~] Post AI แบบ atomic/idempotent: `Dr เงินสด/ธนาคาร` ตาม Tender สุทธิ + `Dr ภาษีหัก ณ ที่จ่ายรอขอคืน` (ถ้ามี) + `Cr เงินรับล่วงหน้าลูกค้า`; เก็บยอดตั้งต้น, ยอดใช้แล้ว และยอดคงเหลือแบบ decimal-safe
- [ ] หาก policy ระบุว่า AI เป็น tax point ให้แยก `Cr ภาษีขาย` จากยอดมัดจำและเก็บ tax-invoice linkage; HS ที่ตัด AI ต้องไม่ Post VAT ซ้ำ และต้อง Post VAT เฉพาะส่วนเพิ่มตาม tax policy ที่บัญชีอนุมัติ
- [~] เพิ่ม AI allocation ในหน้าคอนเฟิร์ม HS: เลือกได้เฉพาะ AI `POSTED` ของลูกค้า/บริษัท/คลังเดียวกันที่ยังเหลือยอด **และมีวิธีคำนวณภาษีเดียวกับ HS แบบ exact match** (`รวมภาษี` / `ภาษีนอก` / `ไม่มีภาษี`); lock/recheck ทุก AI และห้ามตัดเกินหรือใช้ซ้ำ
- [~] Post HS ที่ตัด AI: `Dr เงินรับล่วงหน้าลูกค้า` สำหรับส่วนที่ตัด, รับ Tender เงินสด/ธนาคารเฉพาะส่วนต่าง, และ reconcile ร่วมกับ WHT/ยอดเอกสารโดยไม่เกิด cash movement ซ้ำ
- [~] Detail/DataTable/related-document timeline แสดง AI, allocation, ยอดคงเหลือ และ Journal ทั้งสองฝั่ง; refund/reversal ใช้ได้เฉพาะ AI ที่ยังไม่ถูกตัด, งวดเปิด, Tender เดียว และคืนเข้าบัญชีเดิม (หลาย Tender/คืนต่างบัญชียังไม่เปิด)
- [~] MySQL E2E: Finance Advance regression และ AI allocation (ตัดบางส่วน/เต็มจำนวน, retry, ตัดเกิน, ภาษีไม่ตรง, ไม่มี AR/Journal แยก) ผ่านแล้ว; ยังต้องเพิ่ม receipt WHT, refund และ concurrent-worker scenario ที่เกิดจริง

#### P1 — ปิดวงจรการยกเลิกและรับคืนให้สมบูรณ์

- [~] Post ใบรับคืน/ลดหนี้จาก Sales Return Draft แบบ atomic: รับ Stock คืน, สร้าง COGS/Inventory reversal และ GL ลดหนี้ใน transaction เดียว พร้อม idempotent retry, audit, reason และ RBAC แล้ว; HS รองรับคืนเต็มจำนวนและต้องไม่มี AI allocation ที่ยังใช้งานอยู่, IV รองรับลด AR เฉพาะใบที่ยังไม่รับชำระ. การคืนเงิน HS บางส่วน/AI และ IV ที่รับชำระแล้วอยู่ใน contract ถัดไป
- [ ] รองรับ return ทั้งกรณีคืนสินค้าและลดหนี้เฉพาะยอดเงิน พร้อม snapshot ปริมาณ/ราคา/ภาษีจาก HS/IV ต้นทาง และห้ามคืนเกินยอดที่ขาย
- [ ] กำหนด contract การคืนเงิน: HS คืนเข้าบัญชีเงินสด/ธนาคารเดิม, IV ลด AR หรือคืนเงินหลังรับชำระ; ทำได้เฉพาะงวดบัญชีเปิด
- [ ] ทำ reversal engine ที่ trace ได้ครบ `HS/IV → Sales Return/Credit Note → Stock/COGS/GL/เงินคืน` และห้าม hard delete เอกสารที่ออกเลขแล้ว
- [ ] เพิ่ม reason, audit, permission และ approval policy สำหรับ void/return/refund ตามวงเงิน
- [ ] MySQL E2E: HS cash + WHT + คืนเต็มจำนวน, HS ยอดศูนย์, IV + รับชำระ + ลดหนี้, rollback เมื่อ stock/account/period ไม่พร้อม

#### P2 — ภาษีและการรับชำระที่ใช้จริง

- [ ] ยืนยันและทดสอบ tax treatment `รวมภาษี`, `ภาษีนอก`, `ไม่มีภาษี` สำหรับ Intake/RFQ/Quotation/Sales Order/HS/IV และเอกสารถัดไป
- [ ] VAT output / tax invoice report, tax rounding และ Credit Note VAT reversal
- [ ] WHT certificate/reference, mapping ภาษีหัก ณ ที่จ่าย และ reversal ที่ไม่เหลือยอดค้าง
- [ ] IV partial payment, payment allocation, receipt/withholding document และสถานะ overdue/partial/paid ที่มาจาก AR จริง
- [ ] รองรับส่วนลดท้ายบิล, ค่าธรรมเนียมบัตร/QR และช่องทางชำระผสม

#### P3 — การควบคุมและประสบการณ์ผู้ใช้

- [~] Approval matrix สำหรับส่วนลด, ราคาใต้ policy, ยกเลิก, คืนสินค้า และคืนเงิน — ส่วนลดนอก Price List มีเพดาน Global Setting, เหตุผลเมื่อเกิน และ snapshot ตอนอนุมัติแล้ว; matrix ตามบทบาท/วงเงินและกรณีอื่นยังรอ
- [ ] Role/scope review: แยกสิทธิ์ draft, post, receive, void, return, refund และดู GL ตามบริษัท/คลัง
- [ ] Audit trail ที่อ่านง่าย: ผู้ทำ, เวลา, เหตุผล, เอกสารก่อนหน้า/ถัดไป และ Journal ที่เกี่ยวข้อง
- [ ] Template กลางสำหรับ document header, badge, action และ related-document timeline ครบทุกเอกสารขาย

#### P4 — รายงานและการเชื่อมต่อ

- [ ] รายงานยอดขายสุทธิ, ยอดรับชำระ, WHT, VAT, คืนสินค้า/ลดหนี้, กำไรขั้นต้น และอายุลูกหนี้ พร้อม filter/export
- [ ] Cashier shift / cash reconciliation และสรุปช่องทางรับเงินรายวัน
- [ ] API/webhook สำหรับ e-commerce, payment gateway และ e-Tax Invoice หลัง contract เอกสาร/ภาษีคงที่

**เกณฑ์เลือกงานถัดไป:** เริ่มจาก `P0 ใบรับมัดจำ (AI) + allocation เข้า HS + MySQL E2E` ก่อน เพื่อให้ธุรกิจรับเงินล่วงหน้าและตัดยอดขายสดได้อย่างถูกต้อง; เมื่อผ่าน reconciliation แล้วจึงทำ `P1 Sales Return posting + reversal` ต่อเพื่อปิดวงจร Stock, GL และเงินคืน

### Wave 5 — Dashboard และรายงาน

- [ ] Dashboard การขาย
- [ ] รายงานยอดขายประจำวัน
- [ ] รายงานวิเคราะห์ขายสุทธิ
- [ ] รายงานวิเคราะห์ขายสาขา
- [ ] ตั้งค่าราคาขายตามกลุ่มลูกค้า
- [x] ตั้งค่าเป้าสาขา
- [x] ตั้งค่าเป้าพนักงาน

### Wave 6 — Optional Manufacturing Handoff

ฟีเจอร์ชุดนี้ไม่บังคับสำหรับบริษัทซื้อมา–ขายไป และเปิดเมื่อ Production capability พร้อมเท่านั้น:

- [ ] ใบสั่งผลิต
- [ ] ใบเบิกผลิต
- [ ] ใบรับผลิต
- [ ] ใบฝากผลิต/รับฝากผลิต

## หลักการออกแบบที่ต้องรักษา

- Customer ใช้ Party/PartyRole กลาง ไม่สร้างตารางลูกค้าแยกจาก Supplier
- เอกสารที่ออกเลขแล้วห้าม hard delete; ใช้สถานะและเอกสารแก้ไขตาม policy
- ทุกจำนวน/ราคา/ภาษีใช้ decimal-safe arithmetic และจำนวนทศนิยมจาก Global Settings
- Select ขนาดใหญ่ใช้ Select2 AJAX พร้อม search, pagination และ debounce
- รายการขนาดใหญ่ใช้ Yajra server-side DataTable; วันที่/สถานะ/ตัวเลขต้องแสดงแบบ human-readable
- ทุกเมนูใหม่ต้องมี route middleware, permission, Sidebar visibility และผูก role `admin` ใน RbacSeeder พร้อมกัน
- Workflow ต้องมีข้อความบอกจุดผิด วิธีแก้ และเมนูที่ใช้ย้อนกลับอย่างชัดเจน
- บริษัทเล็กสามารถทำงานด้วยผู้ใช้ 1–2 คนได้ โดย approval chain เป็น policy ที่เปิดเพิ่ม ไม่ใช่เงื่อนไขตายตัว
- Production และ Inventory/COGS เป็น optional gate ไม่บล็อก service/revenue sales flow

## Feature design contract: Input, Filter และข้อมูลที่ต้องเก็บ

ก่อนเริ่มพัฒนา feature ใดใน POS/Sale ต้องทำ mini data contract ให้ครบ 6 ส่วนนี้ และให้ Master Agent ตรวจรับก่อนลงมือทำ:

1. **Input** — ระบุ field ที่ผู้ใช้ต้องกรอก, field ที่ระบบคำนวณ, ค่าเริ่มต้น, required/optional, หน่วย, จำนวนทศนิยม และเงื่อนไขที่เปลี่ยนตามประเภทเอกสาร
2. **Selection** — ระบุ master ที่เลือกได้, scope บริษัท/สาขา/คลัง, Select2 AJAX หรือ native select, search fields, pagination และ duplicate-selection rule
3. **Filter** — ระบุ filter ของ DataTable/รายงาน เช่น ช่วงวันที่เอกสาร, วันที่ครบกำหนด, ลูกค้า, กลุ่มลูกค้า, สาขา, คลัง, สถานะ, ประเภทเอกสาร, เลขที่เอกสาร และ source document; filter ต้องถูกส่งไป server-side query เดียวกับ export HTML5
4. **Persisted data** — ระบุข้อมูลที่ต้องเก็บจริง เช่น header/lines, source document IDs, party/item/account IDs, quantity, price, discount, tax, payment term, warehouse, status, user และ audit timestamps
5. **Snapshot และ linkage** — ข้อมูลที่มีผลต่อประวัติ (ชื่อลูกค้า ที่อยู่ ราคา term ภาษี UOM conversion) ต้อง snapshot ณ ตอนเอกสาร และเชื่อมเอกสารต้นทาง/ปลายทางแบบไม่พึ่งข้อมูลที่เปลี่ยนภายหลัง
6. **Output และ recovery** — ระบุยอดรวม, สถานะ, Journal/Open Item/Stock effect, ปุ่มดูรายละเอียด, Document History, วิธีแก้เมื่อผิด, การยกเลิก/ย้อนกลับ และข้อความ blocker ที่ผู้ใช้เข้าใจได้

### Checklist ต่อ feature

- [ ] มี Input/validation contract และตัวอย่างข้อมูล valid/invalid
- [ ] มี Filter contract สำหรับ list/detail/report และกำหนด default ordering
- [ ] มี schema/relationship/index ที่รองรับข้อมูลและ volume ระยะยาว
- [ ] มี snapshot fields สำหรับข้อมูลที่ห้ามเปลี่ยนตาม master
- [ ] มี document linkage และ detail/history route
- [ ] มี permission สำหรับ view/create/update/approve/post/void ตามจริง และผูก Admin ใน Seeder
- [ ] มี Unit Tests ครอบคลุม calculation, validation, state, linkage และ idempotency
- [ ] ระบุว่าจะทำ final Smoke Test จุดใดเมื่อ POS/Sale ทั้ง module เสร็จ

### RFQ foundation contract (Wave 3)

- Input: ลูกค้า, วันที่เอกสาร, วันหมดอายุ (ถ้ามี), เหตุผล และรายการสินค้า/บริการกับจำนวน; ไม่กรอกราคาใน RFQ
- Selection: ลูกค้าใช้ Party/Customer role; สินค้าและหน่วยใช้ Select2 AJAX; ห้ามเลือกซ้ำในเอกสารเดียว
- Persisted: header/lines, warehouse, customer snapshot, item/UOM snapshot, สถานะ DRAFT/SENT/CLOSED/CANCELLED และ audit timestamps
- Recovery: แก้ไขได้เฉพาะ Draft; ส่งแล้วแก้ไม่ได้; ยกเลิกต้องมีเหตุผล; ไม่มี GL/Stock effect

### HS/IV feature contract (Wave 4)

- **Input:** ประเภทเอกสาร `HS` หรือ `IV`, เลขที่/วันที่เอกสาร, วันที่ Post, คลัง, ลูกค้า, เงื่อนไขชำระเงิน, รายการสินค้า, หน่วยขาย, จำนวน, ราคาต่อหน่วย และส่วนลด; ภาษี/ยอดรวมคำนวณจาก server และต้องใช้จำนวนทศนิยมจาก Global Settings
- **Selection:** เลือกได้เฉพาะ Sales Order หรือใบรับผลิตตาม capability; สินค้า/หน่วย/ลูกค้าใช้ Select2 AJAX เมื่อข้อมูลมาก; ห้ามเลือกบรรทัดต้นทางซ้ำและห้ามขายเกินจำนวนคงเหลือจากต้นทาง
- **Filter:** DataTable ต้องรองรับช่วงวันที่เอกสาร/วันที่ Post, HS/IV, ลูกค้า, สาขา/คลัง, สถานะ, เลขที่เอกสาร, Sales Order/ใบรับผลิตอ้างอิง และสถานะการ Post; query และ HTML5 export ใช้ server-side source เดียวกัน
- **Persisted data:** header/lines, source IDs และ line IDs, customer/warehouse/payment-term snapshots, item/UOM และ conversion snapshot, quantity/stock quantity, price/discount/tax/total, status, Journal IDs, Stock Movement/Cost Allocation references, user และ audit timestamps
- **Output/recovery:** Detail ต้องแสดง source → HS/IV → Stock Movement → Cost Allocation → AR/revenue และ COGS Journal พร้อม Document History, PDF และ blocker ที่บอกวิธีแก้; Post ต้อง atomic/idempotent และ Posted แล้วแก้หรือลบไม่ได้ ให้ใช้ CN/เอกสารกลับรายการ

### ใบรับมัดจำ (AI) feature contract (P0)

- **Input:** วันที่เอกสาร/วันที่รับเงิน, ลูกค้า, บริษัท/คลัง, สกุลเงิน, บัญชีเงินสดหรือธนาคาร, ยอดมัดจำ, วิธีคำนวณภาษี (default `รวมภาษี`), Tax Code, WHT Code, เลขอ้างอิงการชำระ และหมายเหตุ; ระบบสร้างเลขที่เอกสารตาม sequence `AI` และคำนวณ subtotal/VAT/WHT/ยอดรับสุทธิจาก server เท่านั้น
- **Validation:** ลูกค้า, คลัง, วันที่ Post และ Tender อย่างน้อยหนึ่งบรรทัดเป็น required; ยอดมัดจำต้องมากกว่าศูนย์, Tender รวมต้องเท่ากับยอดรับสุทธิ, Bank/Cash Account ต้องอยู่ใน scope และ active; WHT ต้องไม่เกินฐานที่กำหนด และเลขอ้างอิง/บัญชีต้องบังคับตามประเภท Tender. เมื่อใช้ AI ใน HS ต้องตรวจ customer/company/warehouse และ `tax_treatment` ของ AI ตรงกับ HS แบบ exact match; ต่างกันแม้เป็นลูกค้าคนเดียวกันต้องไม่แสดงเป็นตัวเลือกและ server ต้อง reject ซ้ำ
- **Tax policy:** ต้องเลือก policy กลางของบริษัทก่อน Post ว่าเงินมัดจำเป็น tax point หรือไม่. ถ้าเป็น tax point ให้สร้าง VAT output/tax-invoice linkage ณ AI; ถ้าไม่เป็น ให้ HS เป็น tax point. ห้ามให้ AI และ HS รับรู้ VAT จากเงินก้อนเดียวกันทั้งคู่
- **Persisted data:** header/sequence/status, customer/company/warehouse/account/currency snapshots, tax/WHT snapshot, tender lines, gross/net/used/balance amounts, AI-to-HS allocation, Journal ID, user/audit และ void/refund linkage; ยอดเงินต้อง decimal-safe
- **Output/recovery:** DataTable/detail/PDF แสดงสถานะ `DRAFT/POSTED/VOID/USED`, ยอดตั้งต้น/ใช้แล้ว/คงเหลือ, Tender, VAT/WHT, Journal และ HS ที่ตัดยอด; Draft ยกเลิกได้, Posted void/refund ได้เฉพาะงวดเปิดด้วย reversal และ AI ที่ถูกใช้แล้วห้าม void โดยไม่ reverse allocation ปลายทางก่อน

### ลำดับเมนูงานขาย (Sales navigation order)

เมนูต้องเรียงตามลำดับการทำงานที่ผู้ใช้เข้าใจได้ง่าย:

1. ใบรับข้อมูลเบื้องต้น
2. ใบขอราคา
3. ใบเสนอราคา
4. ใบสั่งขาย
5. ใบรับมัดจำ (AI)
6. ขายสด/ขายเชื่อ (HS/IV)
7. ใบลดหนี้/รับคืน
8. รับชำระหนี้ (ใช้เมนูและเอกสาร Settlement กลางของ Finance ไม่สร้างระบบรับชำระเงินซ้ำใน POS)
9. ใบแจ้งหนี้

ใบรับมัดจำ (AI) เป็นเอกสารรับเงินล่วงหน้า ไม่ใช่ AR Open Item: ต้องผูกลูกค้า บัญชีรับเงิน Tender, ภาษี, WHT และยอดคงเหลือไว้จนถูกนำไปตัด HS หรือคืนเงิน. การตัด AI ต้องอ้าง allocation ที่ immutable และไม่ให้ VAT/WHT/cash movement ถูกบันทึกซ้ำ. ใบลดหนี้/รับคืนต้องรองรับการระบุว่ามีผลต่อ Stock หรือมีผลเฉพาะยอดเงินตาม contract ของเอกสารต้นทาง การรับชำระหนี้ใช้ Finance Settlement กลางและต้องตรวจสิทธิ์ Finance ก่อนแสดงเมนู

### ตัวอย่าง filter มาตรฐานของ POS/Sale

- รายการลูกค้า: รหัส/ชื่อ/Tax ID/สถานะ/กลุ่มลูกค้า
- รายการราคา: Price List/กลุ่มลูกค้า/สินค้า/วันที่มีผล/สถานะ
- เอกสารขาย: ช่วงวันที่, ลูกค้า, กลุ่มลูกค้า, สาขา, สถานะ, ประเภทเอกสาร, เลขที่ และ source document
- AR/การชำระเงิน: ลูกค้า, due date, payment status, overdue bucket, เลขที่ใบแจ้งหนี้/เอกสารรับชำระหนี้
- รายงานขาย: ช่วงวันที่, สาขา, ลูกค้า/กลุ่มลูกค้า, พนักงานขาย, สินค้า/หมวดสินค้า และสถานะการ Post

## Testing และการตรวจรับ

### Unit Test gate ระหว่างพัฒนา

แต่ละ Wave ต้องผ่าน Unit Test ก่อน handoff โดยครอบคลุมเฉพาะกฎที่มีความเสี่ยง:

- calculator: ราคา ส่วนลด ภาษี rounding และ credit-limit
- validation: Party/role, term, price list, warehouse และ document linkage
- state transition: Draft, Approved, Posted, Void, Credit/Debit Note
- permission/invariant: scope, duplicate document, over-allocation และ idempotency
- snapshot: ราคา ที่อยู่ term และข้อมูลลูกค้าต้องไม่เปลี่ยนตาม master ภายหลัง

### Master Agent review

Master Agent ตรวจทุก handoff ว่า:

1. ไม่สร้าง contract ซ้ำกับ Accounting, Finance, WMS หรือ Purchasing
2. migration, model, service, request, route, view และ permission อยู่ใน module ที่ถูกต้อง
3. transaction/locking/idempotency และ document history ไม่ทำให้ข้อมูลการเงินเสียหาย
4. query สำหรับ DataTable เป็น server-side และไม่มี `get()` โหลด dataset ใหญ่ใน index
5. UI ใช้ shared design tokens, pastel badges, responsive form และ action icon พร้อม tooltip
6. Admin ได้สิทธิ์เมนูใหม่ใน Seeder และ permission middleware ตรงกับ Sidebar
7. CHECKLIST และ Workflow Center อัปเดตสถานะตรงกับของจริง

### Smoke Test policy

- ไม่ทำ browser/local smoke test ซ้ำทุก feature ระหว่างการพัฒนา
- ทำ smoke test ครั้งเดียวเมื่อ Sales Core, migration, seed, routes, permissions และ capability ที่ประกาศเปิดใช้งานครบทั้ง module
- ถ้ามีการแก้ shared schema, posting contract, costing หรือ route boundary ที่กระทบหลาย module ให้ทำ targeted regression เพิ่ม
- Production operational sign-off ทำภายหลังทุก module พร้อมเท่านั้น

## Gate ก่อนเปิดใช้งาน POS/Sale

- [~] Wave 1–2 Unit Tests ผ่านและข้อมูล master พร้อม — focused tests ผ่านและ Credit Limit enforcement พร้อม; เหลือ Global Settings calculator, การ seed ในฐานข้อมูลที่ใช้งานจริง และการทดสอบ Smoke ตอนปิดทั้งโมดูล
- [ ] Workflow และ document linkage ผ่าน review
- [ ] Sales transaction/AR posting ผ่าน idempotency และ reconciliation gate
- [ ] Inventory/COGS ยังปิดได้สำหรับบริษัทที่ไม่ใช้ stock
- [ ] รายงานและ Dashboard ใช้ scope/permission ถูกต้อง
- [ ] Smoke Test รอบสุดท้ายผ่าน
