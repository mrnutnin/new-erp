# Core Feature & Menu Checklist

เอกสารนี้ใช้ตรวจว่าแต่ละ Module มีเมนูหลักและความสามารถขั้นต่ำของ MVP ครบหรือยัง
โดยแยกจาก `CHECKLIST.md` ซึ่งใช้ติดตามงาน implementation เชิงลึก

## วิธีอ่านสถานะ

- `[ ]` ยังไม่มีหรือยังไม่พร้อมใช้งาน
- `[~]` มี foundation/บางส่วนแล้ว แต่ยังมี integration, QA หรือ gate ค้าง
- `[x]` มี route, permission, UI และ core flow ตามขอบเขต MVP แล้ว
- `Optional` ไม่บังคับสำหรับบริษัทประเภท `TRADING`

กติกากลางทุกเมนู:

- มี route และ permission ที่ตรงกัน
- Sidebar แสดงตาม permission และ capability/module ที่เปิด
- รายการจำนวนมากใช้ DataTable server-side/Yajra และเรียกข้อมูลผ่าน AJAX
- ตัวเลือกจำนวนมากใช้ Select2 AJAX พร้อม pagination
- วันที่/สถานะ/จำนวนเงินแสดงเป็นรูปแบบที่มนุษย์อ่านได้
- Draft แก้ไขได้; เอกสาร Posted ห้ามลบ ใช้ Void/Credit Note/Reversal ตาม contract
- ทุก transaction ต้องมี Warehouse scope เมื่อเป็นข้อมูลปฏิบัติการ
- เมนู Workflow Center เปิดให้ผู้ใช้ดูได้ แต่ action จริงยังตรวจ permission

ลำดับ Sidebar ต้องช่วยนำผู้ใช้ตามงานจริง โดยไม่สร้างเมนูปลอม: Purchasing เรียง
`ข้อมูลหลัก (Supplier) → PR → PO → Goods Receipt → ใบตั้งหนี้ซื้อ/AP → Payment` และ WMS เรียง โดยแยก `ใบลดหนี้ซื้อ` เป็นเมนูแก้ไขยอดจากใบตั้งหนี้ที่ Post แล้ว
`ข้อมูลหลัก (Category → Item → UOM → แปลงหน่วย) → Stock/Valuation → โอนสินค้าออก → รับโอนสินค้าเข้า`;
รายงานหรือการตั้งค่าที่ไม่มี route จริงห้ามแสดงเป็นเมนู placeholder

## 1. Settings / Global

### เมนู

- [x] Dashboard / Workflow Center
- [~] Company Profile / PAE-NPAE / business profile
- [~] Branches และ Warehouses
- [~] Fiscal Year / Fiscal Period
- [x] Users, Roles, Permissions และ Warehouse assignment
- [x] Document Sequence / Format
- [~] Document Template Builder / Preview (แผนพัฒนาอยู่ที่ `docs/planning/18-document-template-builder-plan.md`; สร้าง Platform Template/Version schema, publish contract, Template Service, field registry, normalized payload contract, แยกหน้า List/Create/Edit, responsive Section cards, Layout Properties, signature fields, A4 table pagination, generic field renderer, ordered company-header sections, Auto HTML Preview หน้า Edit, Ajax Save โดยไม่ reload, PDF Preview จริง, HTML/PDF parity tests, Edit Draft, New Version, Archive และ company logo แล้ว; Purchase Order PDF เชื่อมแล้ว; ยังเหลือ PDF document types อื่น)
- [~] Global settings: AVG/FIFO, VAT/WHT, negative stock, retention และ module capability
- [ ] Password reset และ session policy
- [ ] Import template / staged validate-preview-commit

### Core flow

- [x] เลือก Program → เลือก Branch/Warehouse
- [~] ตั้งค่าบริษัทก่อนเริ่มใช้งาน
- [ ] dependency validation ก่อนเปิด module/feature

## 2. Purchasing (`/purchasing`)

### เมนู

- [x] Dashboard
- [x] Workflow Center: Setup / Daily operations
- [x] Supplier (Controller และ views ย้าย ownership ไป Purchasing แล้ว; legacy WMS route/controller/view ถูก retire; canonical Purchasing program boundary และ contract tests ผ่าน)
- [~] Purchase Receipt Draft (persistence/UOM-cost snapshot พร้อม; ยังไม่สร้าง Stock/GL)
- [x] Purchase Requisition (Draft/Submit/Approve/Reject/Void; Controller, Model/Line, Request, `PurchaseRequisitionState` support และ views ย้าย ownership ไป Purchasing แล้ว; stale adapter และ legacy WMS route/controller/request/view/support ถูก cleanup; canonical permission/layout และ Manual UI/Owner sign-off ผ่าน)
- [~] Purchase Order และ Partial Receipt (PR→PO linkage พร้อม; Purchase Order และ Goods Receipt Controller, model/line, request contract และ views ย้าย ownership ไป Purchasing แล้ว; legacy WMS Purchasing surface ถูก retire; canonical view รองรับ Purchasing permission/layout; DataTable/option routes ผ่าน wildcard collision audit; Manual UI/Owner sign-off ผ่าน; MySQL integration Purchase Document inventory posting ผ่านและ rollback-safe; Partial Receipt foundation พร้อม; ยังคงเรียก WMS service boundary สำหรับ stock/cost; Purchase Document ↔ PO ↔ Receipt allocation schema พร้อม; ยังไม่เปิด posting เป็นค่า default)
- [~] ใบตั้งหนี้ซื้อ / ใบลดหนี้ซื้อ (แยกเมนูและ route filter แล้ว; Purchase Document/AP Controller, Purchasing-only PDF Controller/view/route/permission, state/calculator support, views, allocation/variance models และ AP Request contracts อยู่ Purchasing แล้ว; 3-way matching Contract/Policy/Service/Gate คงเป็น WMS integration support; legacy WMS Purchasing route/view/PDF surface ถูก retire และผ่าน route collision/frontend audit; Manual UI/Owner sign-off ผ่าน; Stock/Cost/GL posting ยังคงผ่าน WMS service boundary; flow สินค้า/ค่าใช้จ่าย, หลาย GR และ VAT profile มี QA รองรับ)

> Manual UI/Owner sign-off (2026-09-03): ผู้ใช้ยืนยันว่า Purchasing UI ใช้งานได้แล้ว ครอบคลุม canonical sidebar, PR/PO/GR/AP/Landed Cost screens, DataTable, form และ route navigation; legacy WMS Purchasing surface ถูก retire เพราะยังไม่มีผู้ใช้งานจริง.

> MySQL integration QA (2026-09-03): Purchase Document inventory posting และ Goods Receipt → Landed Cost ผ่าน 2 tests / 32 assertions; เพิ่ม multi-GR coverage แล้ว โดย 2 posted receipts จาก PO เดียวกันถูก allocate แยกต่อ receipt ผ่าน 2 tests / 22 assertions และ rollback-safe. VAT IN exclusive/inclusive, NONE VAT และ Landed Cost allocation calculator ผ่าน 11 tests / 23 assertions; real Supplier VAT profile ผ่าน read-only MySQL QA 1 test / 8 assertions.

> Focused Purchasing regression QA (2026-09-03): Purchasing/PR/PO/GR/AP/Landed Cost/VAT/PDF boundary suite ผ่าน 87 tests / 674 assertions; แก้ test references ที่ยัง reflect WMS wrapper ให้ชี้ canonical Purchasing files แล้ว.

> Cross-module cleanup (2026-09-03): Accounting journal drill-down และ Platform Workflow runtime/catalog เปลี่ยนมาใช้ canonical `purchasing.*` route/permission แล้ว; legacy WMS Purchasing routes/controllers/requests/views/domain support, purchase-model wrappers, orchestration services และ three-way matching support ถูกย้ายหรือลบแล้ว; ไม่ต้องเปิด compatibility window. WMS เหลือเฉพาะ inventory/cost integration boundary.

> Final integration verification (2026-09-04): Local MySQL Landed Cost multi-GR/lifecycle, Supplier VAT profile และ Credit Purchase reversal ผ่าน 5 tests / 41 assertions; Inventory Purchase enabled smoke ผ่าน 1 test / 8 assertions. Persistent operational evidence test intentionally skipped ตาม execution flag.

> Boundary hardening (2026-09-04): ย้าย three-way matching และ `LandedCostAllocationCalculator` ไป Purchasing Support; WMS คงเฉพาะ Stock Movement, Cost Allocation, RECOST และ GL integration. Unit boundary suite ผ่าน 38 tests / 453 assertions.

> Purchase Return foundation (2026-09-04): เพิ่ม `SavePurchaseReturnRequest` และเชื่อม `PurchaseReturnEligibilityService` เพื่อตรวจ source, line ซ้ำ, Receipt เดียวกัน และจำนวนคืนคงเหลือ; ผ่าน 4 tests / 30 assertions. เพิ่ม `PurchaseReturnPostingContract`/`PurchaseReturnCreditNoteService` สำหรับกรณีคืนสินค้าจริงเพื่อสร้างและผูก Draft Credit Note จาก Posted Invoice แบบ NONE_VAT; เพิ่ม `credit_note_mode`, Request guard และตัวเลือกในฟอร์มเพื่อแยก non-return ซึ่งไม่สร้าง Stock/Cost movement พร้อม service-boundary guard. Migration local และ dedicated MySQL non-return guard/financial posting ผ่านแล้ว; เพิ่ม `PurchaseReturnPostingService` orchestration และ Return WMS boundary แล้ว; Partial calculation, AVG/FIFO cost resolver, WMS cost preflight, feature-gated partial movement writer, Journal linkage/immutable link writer, FIFO multi-layer aggregate linkage และ Partial MySQL E2E พร้อมแล้ว เหลือ Return state integration และ manual QA.
- [x] Purchase Return + Credit Note (FIFO multi-layer aggregate Journal link แบบ atomic และ MySQL E2E ผ่านแล้ว; Partial Return state integration เปลี่ยน Return เป็น POSTED แบบ atomic แล้ว; policy ปัจจุบันบังคับยอด Credit Note = ต้นทุนรวม; Owner ทดสอบ UI/Workflow ผ่านแล้ว)

> FIFO linkage regression verification (2026-09-04): Unit ผ่าน 8 tests / 21 assertions; Purchasing MySQL integration ผ่าน 9 tests / 60 assertions โดยมี persistent operational skip เดิม 1 รายการ.

> FIFO multi-layer E2E verification (2026-09-04): Credit/Return regression class ผ่าน 7 tests / 34 assertions โดยมี persistent operational skip เดิม 1 รายการ; ยืนยัน 2 FIFO allocations ถูก link กับ Journal line เดียวแบบ atomic.

> Partial Return state verification (2026-09-04): `postPartial()` ผ่าน MySQL 2 tests / 11 assertions; ยืนยัน Credit Note, Stock OUT, multi-layer allocation links และ Return `POSTED` อยู่ใน transaction เดียว.

> Automated acceptance verification (2026-09-04): FIFO Partial Return retry/idempotency ผ่าน MySQL 1 test / 8 assertions; จำนวนเอกสาร, movement, allocation และ journal link คงเดิมหลัง retry จึงลด manual workflow verification เหลือเฉพาะ visual/UI sign-off.

> Owner UI/Workflow sign-off (2026-09-04): ผู้ใช้ยืนยันการทดสอบผ่านแล้ว จึงปิด Manual QA ของ Purchase Return + Credit Note MVP.

> Purchasing Dashboard (2026-09-04): เพิ่ม Dashboard แบบ section loading (`summary`, `work`, `trend`, `recent`) แยก request/cache ตามคลัง ใช้ Chart.js สำหรับแนวโน้ม PO และมี automated contract test; ไม่โหลด query หนักทั้งหมดใน initial request.

> Document Template UX (2026-09-04): Builder รองรับเพิ่ม/ลบ/เลือก field และลากจัดลำดับ section ด้วย native drag-and-drop พร้อม Preview; ไม่เพิ่ม dependency ใหม่.
- [~] Purchase Return / Landed Cost (มี migration, Models, lifecycle, allocation calculator, WMS RECOST/GL bridge, gated Post endpoint, UI/routes, UX pass รายการ/ตัวกรอง/ฟอร์ม/รายละเอียด, server-side DataTable, auto document number ผ่าน Global Setting sequence `LANDED_COST`, Create แสดง Receipt ที่รอ Post Stock พร้อมทางไปหน้าต้นทาง, contract tests 9 tests / 33 assertions และ MySQL live Post integration 1 test / 18 assertions; MySQL integration รวมกับ Purchase Document ผ่าน 2 tests / 32 assertions และ rollback-safe; ยังเหลือ Manual QA และ Workflow/report catalog)

### Core flow

- [x] Supplier → Purchase Document Draft → Approve
- [x] NONE_VAT และ VAT_IN ตาม mapping ที่เปิดใช้
- [~] Purchase Invoice → Inventory Post สำหรับสินค้าในขอบเขต MVP (foundation/adapter และ local evidence พร้อม แต่ feature flag ยังปิด; รอ owner/release sign-off ก่อนเปิดใช้งาน)
- [x] AP Open Item และ Journal linkage
- [~] Receipt Draft เป็น foundation; ไม่สร้าง GL/Stock ซ้ำ
- [~] รับสินค้าโดยใช้หน่วยซื้อที่ต่างจากหน่วยสต็อก พร้อมเก็บ conversion snapshot
- [~] คำนวณ `stock_qty = purchase_qty × conversion_factor` ด้วย decimal-safe arithmetic
- [~] กระจายต้นทุนรวมเป็นต้นทุนต่อหน่วยสต็อกหลังแปลงหน่วย และรักษา factor/cost lineage ย้อนตรวจได้
- [~] Partial receipt (รับบางส่วน/กันรับเกิน/บันทึก snapshot พร้อม; Inventory Post ยังผ่าน Purchase Document boundary เดียว)
- [ ] Purchase Return

## 3. WMS (`/wms`)

### เมนู

- [x] Dashboard
- [x] Workflow Center: Setup / Daily operations
- [x] Item / Category / UOM / Unit Conversion
- [~] Stock Balance / Reserved / Available
- [~] Stock Card
- [x] Stock Valuation / Historical Valuation / Reconciliation (ตรวจรับแล้ว; เหลือเฉพาะงาน Recost ตามลำดับถัดไป)
- [x] Transfer
- [~] Recost Queue Health
- [x] Stock Count (เอกสารตรวจนับหลายรายการ, snapshot, ผลต่าง และประวัติ; ใช้สำหรับตรวจสอบ/รายงานเท่านั้น ไม่สร้างหรือเชื่อม Adjustment และไม่ Post Stock/GL อัตโนมัติ)
- [x] Inventory Adjustment (เอกสารปรับเพิ่ม/ลดแยกต่างหาก; approve/post/reversal เชื่อม Stock/GL ตามสิทธิ์และ gate ของ Adjustment โดยตรง)
- [ ] Lot / Serial / Expiry / Warranty (Optional)

### Core flow

- [x] Item master พร้อม Inventory/COGS account validation
- [x] UOM และ Unit Conversion master พร้อม validation factor
- [~] Purchase Document → Inventory Post → Movement → Cost Layer → Allocation → GL (foundation/adapter พร้อม; posting feature flag ยังปิด)
- [x] AVG/FIFO costing และ persisted balance projection (ตรวจรับแล้ว)
- [x] Transfer dispatch/accept/partial/reject พร้อม cost lineage
- [x] Recost retry/idempotency และ period-close gate (ผ่าน local MySQL rollback/integration และ Unit gate; queue health และ preflight พร้อมใช้งาน; Manual UI sign-off และ final release/production operational sign-off เป็นขั้น deployment ภายหลัง)
- [x] Recost scheduler safety-net: bounded dispatch, no-overlap/single-server guard, health/SLA และ retry
- [ ] Issue/COGS posting จาก Sales
- [~] Sales inventory-line readiness contract: ตรวจ `item_id`, `uom_id`, `stock_uom_id`, warehouse, business date และ conversion factor พร้อมสร้าง conversion snapshot แบบ read-only แล้ว; ยังไม่สร้าง Stock ISSUE/Cost Allocation/GL
- [x] Adjustment posting และ document reversal; [x] Stock Count variance report แบบไม่สร้าง Adjustment อัตโนมัติ

## 4. POS / Sales

### Sale Workflow / Sales Core (ส่วนกลางที่ POS ใช้ร่วมกัน)

รายการนี้เป็นแผนแม่บทของ Sales Foundation โดยแยกความสามารถที่ใช้ร่วมกันระหว่าง Sales และ POS ออกจากงาน Production ที่เปิดใช้เฉพาะบริษัทผลิต:

- [x] คู่มือการทำงาน Sales/POS แบบ Setup และ Daily Operations
- [x] ลูกค้า (Customer) และ Party/Role
- [x] กลุ่มลูกค้า — CRUD/permission/Admin seed พร้อม
- [x] Credit Limit และ Credit Term — PartyRole/payment-term foundation พร้อม
- [x] Price List — resolver/snapshot/DataTable/Select2 พร้อม
- [ ] ที่อยู่ออกบิลและที่อยู่จัดส่ง
- [x] ใบรับข้อมูลเบื้องต้น (ฟิลด์การขาย/ภาษี/ที่อยู่/ผู้เตรียม และสรุปยอดจากเซิร์ฟเวอร์แล้ว)
- [~] ใบขอราคา — foundation/UI/PDF พร้อม เหลือ manual/UI sign-off
- [x] ใบเสนอราคา — foundation/linkage/PDF พร้อม เหลือ manual/UI sign-off
- [x] ใบสั่งขาย — foundation/linkage/PDF พร้อม เหลือ manual/UI sign-off
- [~] ใบขายสด/ขายเชื่อ (HS/IV) — Draft/DataTable/Detail/PDF foundation พร้อม; Stock/GL posting ยังปิดตาม Inventory → GL gate
- [ ] การชำระเงิน
- [~] ใบรับมัดจำ (AI) — Finance advance/deposit foundation มีแล้ว; full posting/application ยัง gated
- [ ] ใบแจ้งหนี้
- [ ] ใบวางบิล
- [~] ใบลดหนี้/รับคืน (CN/Sales Return) — DRAFT + source-line Select2 AJAX, duplicate guard, date/status filters และ PDF หลายหน้า/โลโก้บริษัทพร้อม; Stock/GL reversal ยัง deferred
- [ ] ใบเพิ่มหนี้ (DN)
- [ ] รายงานวิเคราะห์ขายสุทธิ
- [ ] รายงานวิเคราะห์ขายสาขา
- [ ] รายงานยอดขายประจำวัน
- [ ] Dashboard Sales/POS
- [ ] ตั้งค่าราคาขายตามกลุ่มลูกค้า
- [x] ตั้งค่าเป้าสาขา
- [x] ตั้งค่าเป้าพนักงาน

ความสามารถ Production ต่อไปนี้เป็น optional capability ไม่บังคับสำหรับบริษัทซื้อมาขายไป:

- [ ] ใบสั่งผลิต (เฉพาะบริษัทผลิต)
- [ ] ใบเบิกผลิต (เฉพาะบริษัทผลิต)
- [ ] ใบรับผลิต (เฉพาะบริษัทผลิต)
- [ ] ใบฝากผลิต/รับฝากผลิต (เฉพาะบริษัทผลิต)

### เมนู

- [x] Dashboard
- [x] Workflow Center: Setup / Daily operations
- [x] Customer
- [~] Price List / Discount / Promotion / Customer Group Pricing — Price List, ส่วนลดและ approval threshold พร้อม; Promotion ต่อรายการ/ท้ายบิล, stacking policy, server-side eligibility และ frozen snapshot ใน sales flow พร้อม; campaign/เงื่อนไขซับซ้อนยังรอ
- [x] Sales Invoice / Credit Note แบบ service/revenue
- [~] Preliminary Information / RFQ / Quotation / Sales Order — foundation และ linkage พร้อม; เหลือ manual/UI sign-off ภายหลัง
- [ ] HS / IV และ Fulfillment
- [ ] Sales Reports

### Core flow

- [x] Customer → Sales Document → Approve → Post → AR Open Item
- [x] NONE_VAT และ VAT_OUT ตาม mapping ที่เปิดใช้
- [x] Credit Note จัดสรรกับ invoice ต้นทางตาม contract
- [ ] Sales item/stock issue → COGS
- [~] Sales item/UOM/stock linkage และ cost lineage: Sales line รองรับ Item/UOM/Stock UOM และ immutable conversion snapshot พร้อม Select2 AJAX แล้ว; ยังต้องทำ Stock ISSUE idempotency และ final AVG/FIFO allocation ก่อนเปิด posting
- [~] Local mock readiness: มี idempotent Item/Base UOM และ BOX→PCS conversion fixture และ migration snapshot รันบน local แล้ว; ยังไม่ seed หรือสร้าง Stock ISSUE/COGS/GL จาก Sales จนกว่า WMS issue/cost lineage gate จะผ่าน
- [ ] Receipt status และ sales summary reports
- [ ] Billing / Invoice / Deposit (AI) / Debit Note (DN)
- [ ] Net sales, branch sales และ daily sales analytics

### Testing policy สำหรับ POS / Sales

- [~] ระหว่างพัฒนาแต่ละ feature ให้ใช้ Unit Test เป็น gate ก่อนเสมอ โดยครอบคลุม calculator, validation, state transition, permission/invariant, document linkage และ idempotency ที่แยกทดสอบได้
- [ ] เลื่อน local smoke test ของ POS/Sales ไปทำครั้งเดียวเมื่อทั้ง module พร้อม (Sales Core, POS flow, migrations, seed/mock data และ optional capability ที่เปิดใช้) เพื่อทดสอบ flow ข้ามหน้าจอ/route/database แบบ end-to-end
- [ ] Smoke test ท้าย module ต้องครอบคลุมเฉพาะ capability ที่บริษัทเปิดใช้; Production flow ไม่ block บริษัทโปรไฟล์ `TRADING`
- [ ] ไม่รัน smoke ซ้ำทุก feature ระหว่างพัฒนา เว้นแต่มีการเปลี่ยน shared contract, schema, route boundary หรือ posting/costing contract ที่กระทบ flow เดิม

## 5. Finance

### เมนู

- [x] Dashboard
- [x] Workflow Center: Setup / Daily operations
- [x] Sidebar Finance จัด Main menu / Sub menu แบบพับได้ตามมาตรฐาน Accounting (เงินรับ–จ่าย, เงินสดย่อย, ลูกหนี้และเจ้าหนี้, รายงาน, ข้อมูลหลัก)
- [x] Bank/Cash Accounts
- [x] Payment Terms / Other Income / Other Expense
- [x] Receipt / Payment Draft → Approve → Post
- [~] Customer Receipt และ Supplier Payment allocation (รองรับ allocation หลักแล้ว; partial, overpayment, unapplied และ reversal hardening ยังเหลือ)
- [x] AR/AP Open Items และ Aging
- [~] Pre-Payment Voucher / Payment Voucher (สร้าง/อนุมัติ/ส่ง Settlement แล้ว; allocation และ GL/Open Item integration ยังต้อง harden)
- [~] Advance / Deposit foundation (มี subledger, typed mapping, application และ reversal foundation; ต้องปิด integration/reconciliation ให้ครบ)
- [x] Advance / Deposit typed Account Mapping contract (`CUSTOMER_ADVANCE` = LIABILITY, `SUPPLIER_ADVANCE` = ASSET)
- [x] Petty Cash fund/voucher/top-up/clearing workflow และ backend API: Draft → Submit → Approve → Post → Reverse/Void, Journal/idempotency/audit, route/RBAC, Yajra/AJAX, attachments, document sequence และ branch/warehouse scope
- [x] Petty Cash รองรับหลายวงเงินต่อบัญชีเงินสดเดียวกัน, ลบ Draft ได้ตาม guard และ deactivation เมื่อไม่ถูกอ้างอิง
- [x] Petty Cash Clearing รองรับ expected/actual/variance, เงินเกิน/เงินขาด mapping และ Post/Reversal ผ่าน Posting Contract
- [x] Employee Advance และ Employee Advance Clearing: schema, sequence, Draft → Submit → Approve → Reject → Post → Reverse/Void, VAT/WHT snapshot, refund/additional advance, attachments, audit, soft delete Draft และ GL link
- [ ] Employee Advance รองรับ partial/multiple clearing และ policy self-approval แบบครบทุกกรณี
- [x] Bank Reconciliation workflow อยู่ใน Accounting ตาม boundary กลาง; Finance ใช้ Bank/Cash Account เป็น source และมีรายงาน Finance-to-GL แยกต่างหาก
- [x] Internal Transfer ระหว่างบัญชีเงินสด/ธนาคาร: Draft → Submit → Approve → Post → Reverse/Void, branch scope, document sequence, audit, GL/GL reversal, RBAC และ DataTable/AJAX UI
- [~] Finance Reports: Petty Cash, Employee Advance, Payment Activity, Finance-to-GL Reconciliation, Cash Position และ Expected Collection/Payment พร้อม branch/warehouse/date scope; ยังเหลือ integration และ owner manual QA

> Finance Dashboard P0 (2026-09-04): โหลด Summary, Cash Trend, AR/AP Aging, Work Queue และ Recent Activity แยก section; ทุก query จำกัดตามสาขาและคลังที่ผู้ใช้มีสิทธิ์, AR/AP หัก allocation/advance application, Trend รับเฉพาะ Settlement `POSTED`, และ Recent Activity ใช้ Yajra DataTable + Excel export. ผ่าน contract tests 2 tests / 20 assertions; รอ Owner UI sign-off.

### Core flow

- [x] Settlement → Journal → Open Item → Allocation แบบ atomic/idempotent
- [x] VAT realization ตาม allocation และ WHT snapshot/realization
- [~] Advance/unapplied cash และ reversal (มี foundation; ต้อง harden partial/unapplied และตรวจ integration)
- [~] Petty Cash/Employee Advance posting พร้อมแล้ว; reconciliation, partial clearing และ browser/MySQL release evidence ยังเหลือ

### สถานะการตรวจสอบล่าสุด (2026-09-05)

- [x] Finance unit tests ที่เกี่ยวข้องผ่าน `68 tests / 356 assertions`
- [x] ตรวจ PHP syntax ของ `app/Modules/Finance` ผ่าน
- [x] ตรวจ route Finance พบ 190 routes ครอบคลุมหน้าหลัก, workflow, reports, attachment และ document sequence
- [x] Finance migrations ล่าสุดติดตั้งครบ รวม soft delete ของ Employee Advance/เอกสารเคลียร์
- [ ] Owner manual QA และ release sign-off ทุก branch/status/permission

## 6. Accounting

### เมนู

- [x] Dashboard
- [x] Workflow Center: Setup / Daily operations
- [x] Chart of Accounts / Parent Account Select2 AJAX
- [x] Fiscal Years / Periods
- [x] Tax Codes
- [x] Journal Entry / Journal Book
- [x] General Ledger / Trial Balance
- [~] P&L / Balance Sheet / Comparative Income
- [x] Account Mapping
- [~] Tax Reports / WHT Reports
- [~] Period Close / Reopen
- [ ] Manual Journal / Opening Balance Import
- [ ] Full control-account reconciliation

### Core flow

- [x] Journal posting ใช้ Accounting posting contract เดียว
- [x] AR/AP และ Inventory source link กลับ Journal line ได้
- [~] Tax Point/Settlement Date และ WHT reconciliation ครบทุก report
- [~] Period close ตรวจ Inventory/GL pending, orphan และ mismatch

## 7. Optional Modules

### Production (Optional)

- [~] Capability/profile guard และ Workflow catalog
- [ ] BOM/Recipe revision
- [ ] Work Order / Material Issue / WIP
- [ ] Finished Goods / By-product / Variance posting

### Logistics (Optional)

- [~] Workflow catalog
- [ ] Shipment / Trip / Dispatch
- [ ] Delivery / POD / Return
- [ ] Transport cost allocation

### Asset (Optional)

- [~] Workflow catalog
- [ ] Asset register / Capitalization
- [ ] Depreciation / Disposal
- [ ] Asset accounting reports

## 8. Cross-module release checks

- [ ] ทุกเมนูใหม่มี permission, route middleware และ Sidebar visibility
- [ ] Admin เห็นเมนูตาม seed และ user อื่นถูกจำกัดตาม permission
- [ ] Warehouse isolation ผ่านทั้งหน้า, DataTable, option endpoint และ action
- [ ] Human-readable date/status/amount ผ่านทุก form และ DataTable
- [ ] Workflow มี Setup และ Daily mode แยกกัน
- [ ] Error ทุกจุดบอกสาเหตุและแนวทางแก้/ย้อนกลับ
- [ ] Migration และ local seed ทำซ้ำได้โดยไม่สร้างข้อมูลการเงินซ้ำ
- [ ] Local unit/integration/UI checks ผ่าน
- [ ] Production operational sign-off ทำครั้งเดียวหลัง MVP modules พร้อมครบทั้งหมด

### DataTable action UI standard

- [ ] ปุ่ม action ใน DataTable ใช้ icon-only เมื่อความหมายสื่อได้ชัดเจน
- [ ] ทุก icon action ต้องมี `title` และ `aria-label` ภาษาไทย เพื่อให้เอา pointer ชี้และรองรับ accessibility
- [ ] ปุ่มลบต้องแสดงตาม permission และสถานะเอกสารเท่านั้น และต้องมี confirmation แบบ `warning`
- [ ] Action ที่ผิดพลาดต้องแจ้งด้วย `error` และ action สำเร็จต้องแจ้งด้วย `success`
- [ ] DataTable ข้อมูลเอกสารจำนวนมากต้องมี server-side filters ตามงานจริง เช่น ช่วงวันที่, คู่ค้า, สถานะ และวันครบกำหนด/คาดรับ พร้อมปุ่มล้างตัวกรอง

### Purchasing document print/PDF

รายละเอียด contract อยู่ที่ `docs/qa/purchasing-document-print-contract.md`; MVP ใช้ `mpdf/mpdf` และเปิด print route แบบ permission-gated แล้ว

- [x] PR, PO, Goods Receipt และ Credit Purchase มี action ออกเอกสารสำหรับพิมพ์/ดาวน์โหลดของตัวเอง
- [x] รูปแบบเอกสารต้องมี company profile และ Logo บริษัทจาก Settings (ไม่ hard-code asset ใน Module)
- [x] มี print profile แยก A4/เครื่องพิมพ์ทั่วไป และ Dot Matrix (ตารางเรียบ, ขนาดกระดาษ/จำนวนบรรทัดกำหนดได้, ไม่พึ่ง CSS ซับซ้อน)
- [x] PDF/print ใช้ human-readable date, amount, status และ document history/reference link ที่จำเป็น
- [x] สิทธิ์พิมพ์แยกจากสิทธิ์แก้ไข/อนุมัติ และตรวจ Warehouse/document scope ทุกครั้ง
- [ ] ทดสอบเอกสารตัวอย่างทั้ง A4 และ Dot Matrix ก่อนรวมเป็น production sign-off
- [x] PDF MVP เลือก `mpdf/mpdf` สำหรับภาษาไทย ตารางหลายหน้า และ page-break; ใช้ PDF-specific CSS แยกจาก UI CSS และไม่รับประกัน CSS3/Bootstrap ทุก property

## 9. ลำดับพัฒนาถัดไป

1. ปิด WMS Transfer UI/RBAC และ Stock Balance projection
2. ปิด FIFO/AVG issue, Recost และ Inventory reconciliation
3. เติม Finance Petty Cash, Employee Advance และ Bank Reconciliation
4. เติม Sales item/stock flow และรายงานขาย
5. เติม Purchasing PR/PO/partial receipt/return
6. ปิด Accounting tax reconciliation และ Period Close
7. ทำ cross-module local smoke และ final production operational sign-off

## 10. Pre-costing feature inventory (Purchasing / WMS)

รายการนี้เป็นขอบเขตตรวจซ้ำก่อนกลับไปทดสอบ AVG/FIFO, Recost และ Reconciliation
โดยใช้สถานะเดียวกับเอกสารนี้: `[x]` มีแล้วข้ามการพัฒนาซ้ำ, `[~]` มี foundation แต่ต้องเก็บ integration/QA,
`[ ]` ยังต้องพัฒนา และ `Optional` ไม่บังคับสำหรับบริษัทโปรไฟล์ `TRADING`

### Purchasing

| ความสามารถ | สถานะ | หมายเหตุ/ทางไปต่อ |
|---|---:|---|
| คู่มือการทำงาน (Setup / Daily) | [x] | มี Workflow Center และคู่มือ Purchasing แล้ว ข้ามการสร้างซ้ำ |
| ใบขอซื้อ (PR) | [~] | มีเอกสาร, อนุมัติ, เชื่อม PO, history และ PDF; เหลือ manual/UI sign-off ตาม release gate |
| ใบสั่งซื้อ (PO) | [~] | สร้างจาก PR หรือสร้างเอง, เลือก Supplier, partial receipt และ reference แล้ว; เหลือ QA ปลายทาง |
| ใบรับสินค้า (GR) | [~] | รับบางส่วน, UOM/cost snapshot และเชื่อม PO แล้ว; Inventory→GL ใช้เฉพาะ local feature flag ที่เปิดอย่างมีเงื่อนไข |
| ใบตั้งหนี้ซื้อ (Credit Purchase / AP Invoice) | [~] | แยกโหมดสินค้า/วัตถุดิบกับค่าใช้จ่าย, เลือกหลาย GR และเชื่อม AP/Inventory foundation แล้ว; local MySQL Purchase/GR → Stock Movement → Cost Allocation → Journal ผ่านรวม 3 tests / 25 assertions (มี 1 skip ตาม feature flag); เหลือ QA posting/ภาษี |
| คืนสินค้า Supplier และ Debit/Credit Note | [~] | เริ่ม Phase 1 แล้ว: มี Purchase Return header/line, source linkage, quantity/cost snapshot, credit-note link, decimal-safe over-return eligibility, Request, Draft/Submit/Approve/Void service, `credit_note_mode` และ Draft Credit Note linkage จาก Posted Invoice แบบ NONE_VAT สำหรับคืนสินค้าจริง; non-return ไม่กระทบ Stock/Cost แต่ยังเหลือ integration/QA |
| รายงาน PR / PO / GR / ซื้อเชื่อ | [x] | ศูนย์รวมรายงานปฏิบัติการ `purchasing.reports.index` ใช้งานได้แล้ว ลิงก์ไป DataTable server-side/filter ของเอกสารต้นทาง และผ่าน Manual UI/Owner sign-off แล้ว |

### WMS — ความสามารถที่จำเป็นสำหรับบริษัทซื้อมา-ขายไป (`TRADING`)

| ความสามารถ | สถานะ | หมายเหตุ/ทางไปต่อ |
|---|---:|---|
| คู่มือการทำงาน (Setup / Daily) | [x] | มี WMS Workflow Center แล้ว ข้ามการสร้างซ้ำ |
| ใบโอนสินค้าออก / ใบโอนสินค้าเข้า | [x] | แยกเมนูและ route ตามฝั่งคลังแล้ว พร้อมรับเข้าเต็มจำนวน/ปฏิเสธ, detail, destination warehouse ทุกคลัง และ AVG/FIFO cost lineage; local integration ผ่านแล้ว เหลือเฉพาะ manual UI/owner release gate |
| ใบเบิกสินค้า | [x] | มีเอกสาร Issue แบบ Draft→Approve→Post และ cost allocation; FIFO movement/rollback ผ่าน local MySQL integration แล้ว เหลือเฉพาะ manual UI/owner release gate |
| ใบรับคืนจากการเบิก | [x] | อ้างอิง Issue, กันคืนเกิน, แยก FIFO หลายชั้น และ retry ไม่สร้าง movement ซ้ำ; local MySQL integration ผ่านแล้ว เหลือเฉพาะ manual UI/owner release gate |
| ใบปรับเพิ่ม / ใบปรับลด | [x] | Inventory Adjustment เป็นเอกสารหลายรายการ มีเลขที่, approve/post, history และ document-level reversal แล้ว |
| ตรวจนับสินค้า | [x] | มี dedicated count sheet หลายรายการ, snapshot, ผลต่าง และประวัติ; เป็นเอกสารตรวจสอบ/รายงานเท่านั้น ไม่สร้างหรือเชื่อม Adjustment และไม่ลง Stock/GL อัตโนมัติ |
| ผลต่างจากการตรวจนับ | [x] | แสดง variance จาก snapshot/count เพื่อให้ผู้ใช้ตรวจสอบ; หากต้องแก้ Stock ให้สร้างเอกสาร Inventory Adjustment แยกเองตามขั้นตอนและสิทธิ์ของ Adjustment |
| ตั้งค่า Min/Max Stock ตามคลัง/สาขา | [x] | รองรับ policy รายสินค้า+คลัง, Dashboard alert ที่หัก reserved/open PO พร้อมแปลง Purchase UOM → Stock UOM และลิงก์สร้าง PR แบบผู้ใช้ยืนยันแล้ว; local MySQL readiness ผ่าน 3 tests / 13 assertions และผู้ใช้ตรวจ Manual UI/Owner sign-off แล้ว |
| ตั้งค่าประเภทการเบิก | [x] | มี issue type master, permission/RBAC และใบเบิกใช้ประเภทที่ตั้งค่าในคลังแล้ว; แก้เฉพาะ defect หากพบ ไม่สร้างซ้ำ |
| Stock Card / Stock Valuation / Reconciliation | [x] | ตรวจรับแล้ว; มีหน้ารายละเอียด movement, cost layer, preflight และ reconciliation; งานถัดไปคงเหลือเฉพาะ Recost retry/period-close |

### WMS — ความสามารถสำหรับบริษัทที่มี Production (ไม่บังคับใน MVP `TRADING`)

| ความสามารถ | สถานะ | หมายเหตุ/ทางไปต่อ |
|---|---:|---|
| ใบเบิกวัตถุดิบเข้าผลิต | Optional / [ ] | ทำเมื่อเปิด Production capability และมี BOM/Work Order contract |
| ใบรับสินค้าผลิตเสร็จ | Optional / [ ] | ทำพร้อม finished-good cost lineage และ production posting |
| WIP | Optional / [ ] | ทำพร้อม Work Order, material issue, finished receipt และ variance |

### กติกาการข้ามงานก่อน AVG/FIFO

1. รายการ `[x]` ไม่สร้างซ้ำ ให้ใช้เป็น input ของ integration test และแก้เฉพาะ defect ที่พบ
2. รายการ `[~]` ที่จำเป็นต่อ `TRADING` ต้องปิดเฉพาะ integration/QA ที่เกี่ยวกับ stock movement, cost layer และ document trace ก่อนทดสอบ AVG/FIFO, Recost และ Reconciliation
3. รายการ `[ ]` ที่เหลือของ WMS operational wave ต้องปิดตามลำดับ: local integration ของ issue/return/count → เชื่อม issue type → Min/Max local QA/นับ reserved-open PO → แล้วจึงกลับไป AVG/FIFO, Recost และ Reconciliation
4. รายการ Production ทั้งสามรายการไม่ block บริษัทซื้อมา-ขายไป; เปิดเมื่อ company capability เป็น `MANUFACTURING` เท่านั้น

> Verification record (2026-08-25): `tests/Feature/InventoryAdjustmentMySqlReadinessTest.php` ผ่าน 4 tests / 37 assertions บน local MySQL `new_erp` ด้วย `ERP_RUN_MYSQL_INTEGRATION=1` ครอบคลุม GAIN/LOSS posting, rollback, Stock Movement/Cost Allocation และ multi-line reversal/idempotency. ให้รันทดสอบชุดนี้ซ้ำเมื่อมีการเปลี่ยน Adjustment posting/reversal contract หรือ cost/journal contract เท่านั้น เพื่อป้องกันการทดสอบวนซ้ำโดยไม่จำเป็น.

> Verification record (2026-08-25): `InventoryPurchaseMySqlIntegrationReadinessTest.php` และ `CreditPurchaseInventoryMySqlIntegrationReadinessTest.php` ผ่านบน local MySQL `new_erp` รวม 3 tests / 25 assertions ครอบคลุม Purchase/GR → Stock Movement → Cost Allocation → Journal, credit-purchase reversal/rollback และ idempotency; มี 1 test skip ตาม feature flag. ให้รันทดสอบซ้ำเมื่อ purchase/GR allocation, credit reversal, cost allocation หรือ Journal contract เปลี่ยนเท่านั้น.

> Next feature boundary: Purchase Return / Debit-Credit Note มี implementation และ local gate แล้ว จึงไม่สร้างซ้ำ; ขั้นถัดไปคือปิด manual UI/owner sign-off ตาม release gate แล้วเดินหน้ารายงาน/operational hardening ของ Purchasing/WMS ก่อนทดสอบ AVG/FIFO/Recost/Reconciliation ซ้ำ. `Landed Cost` ยังอยู่นอก MVP และไม่ควรเริ่มจนมี source/cost allocation contract ที่ชัดเจน.

> Verification record (2026-08-25): `CreditPurchaseInventoryMySqlIntegrationReadinessTest.php` + `CreditPurchaseInventoryReversalContractTest.php` + `CreditPurchaseInventoryReversalServiceTest.php` ผ่านบน local MySQL `new_erp` รวม `8 tests / 25 assertions`, มี `1 skipped` ตาม feature flag. หลักฐานนี้ครอบคลุม Credit Purchase reversal/rollback/idempotency และไม่ต้องรันทดสอบซ้ำจนกว่า reversal, cost allocation, Journal หรือ feature-flag contract จะเปลี่ยน.

> Verification record (2026-08-25): Costing gate ผ่าน `9 tests / 59 assertions` และ Unit `25 tests / 64 assertions`; Inventory→GL readiness ผ่าน `9 tests / 84 assertions` พร้อม enabled smoke `1 test / 8 assertions` (มี operational skip 1 รายการตาม feature flag); Reconciliation regression ผ่าน `16 tests / 109 assertions` และ Unit `29 tests / 85 assertions`. ไม่ต้องรันซ้ำจนกว่าจะมีการเปลี่ยน contract ที่ระบุในแต่ละ gate.

> Verification record (2026-08-25): `tests/Feature/WmsTransferCostLineageTest.php` ผ่าน 6 tests / 24 assertions และ `tests/Feature/IssueReturnFifoMySqlIntegrationReadinessTest.php` ผ่าน 1 test / 13 assertions บน local MySQL `new_erp` ด้วย `ERP_RUN_MYSQL_INTEGRATION=1` ครอบคลุม Transfer FIFO/AVG lineage, accept/reject/rollback และ Issue → FIFO Return split/idempotency. ให้รันทดสอบซ้ำเฉพาะเมื่อ state, movement, cost-lineage หรือ source contract เปลี่ยน.

> Verification record (2026-08-25): `tests/Feature/RecostRuntimeMySqlIntegrationReadinessTest.php` และ `tests/Feature/RecostPeriodCloseMySqlIntegrationReadinessTest.php` ผ่านรวม 2 tests / 22 assertions บน local MySQL `new_erp` ด้วย `ERP_RUN_MYSQL_INTEGRATION=1` ครอบคลุม Recost runtime rollback, retry/idempotency, allocation→Journal proof และ Period Close/queue safety. ให้รันทดสอบซ้ำเฉพาะเมื่อ Recost lifecycle, dispatcher, period-close gate, allocation หรือ Journal contract เปลี่ยน.

> Verification record (2026-08-25): เพิ่มศูนย์รวมรายงานปฏิบัติการ Purchasing ที่ route `purchasing.reports.index` พร้อม permission `purchasing.reports.view` และ seed ให้ Admin; หน้าใหม่ไม่ query เอกสารซ้ำ แต่ลิงก์ไป PR/PO/GR/ใบตั้งหนี้ที่ใช้ DataTable server-side/filter เดิม. ตรวจ `php -l`, Pint, `view:cache` และ `route:list` ผ่าน. ไม่ต้องรันทดสอบข้อมูลซ้ำจนกว่า report catalog, permission หรือ route contract เปลี่ยน.

> Owner confirmation (2026-08-25): ผู้ใช้ยืนยันว่า `/purchasing/reports` ใช้งานได้จริงและผ่าน Manual UI/Owner sign-off แล้ว. ให้ทดสอบซ้ำเฉพาะเมื่อรายการรายงาน, permission, route หรือ DataTable contract เปลี่ยน.

### Min/Max Stock → Dashboard Alert → PR Recommendation (WMS)

Min/Max เป็นนโยบายแจ้งเตือนและช่วยตัดสินใจ ไม่ใช่การสั่งซื้ออัตโนมัติ

- [x] เก็บค่า Min/Max แยกตามสินค้า+คลัง (`warehouse_id + item_id`) พร้อม validation `max >= min`; policy เดิมที่ไม่มี item เป็น legacy default
- [x] Dashboard แสดงสินค้าที่ต่ำกว่า Min หลังหักยอด PO ที่อนุมัติและยังรับไม่ครบ พร้อม on-hand/reserved/available/Min/Max แบบ read-only
- [x] คำนวณจำนวนแนะนำสั่งซื้อแบบ `max - (available + open approved PO)` เพื่อไม่แนะนำซ้ำ
- [x] ให้ผู้ใช้เปิดดูรายการและกดไปสร้าง PR พร้อม prefill สินค้า/หน่วย/จำนวนแนะนำได้ทีละรายการ; ยังไม่สร้าง PR อัตโนมัติ และ bulk หลายรายการอยู่นอก MVP ขั้นนี้
- [x] ตัวเลข input/display ใช้ decimal จาก Global Settings และ policy ใช้ warehouse scope; มี audit เดิมและลิงก์สร้าง PR แบบ user-confirmed
- [x] Dedicated local MySQL test ครอบคลุม reserved/open approved PO, Purchase UOM → Stock UOM conversion, warehouse scope และ rollback

> Verification record (2026-08-25): `tests/Feature/StockMinMaxMySqlIntegrationReadinessTest.php` ผ่าน 3 tests / 13 assertions บน local MySQL `new_erp` ด้วย `ERP_RUN_MYSQL_INTEGRATION=1` ครอบคลุม reserved/open approved PO, Purchase UOM → Stock UOM conversion, warehouse scope และ rollback. ให้รันทดสอบซ้ำเฉพาะเมื่อ Min/Max policy, reserved/open-PO calculation, UOM conversion หรือ PR recommendation contract เปลี่ยน.

> Owner confirmation (2026-08-25): ผู้ใช้ตรวจสอบ Min/Max Dashboard alert และ PR prefill ด้วยตนเองแล้ว ถือว่า Manual UI/Owner sign-off ผ่าน ไม่ต้องทดสอบซ้ำจนกว่า Min/Max policy, reserved/open-PO calculation, UOM conversion หรือ PR recommendation contract จะเปลี่ยน.

> Verification record (2026-09-04): แก้ PDF Preview ของ Document Template ที่ mPDF สร้างหน้าว่างจำนวนมาก โดยแยก browser CSS ออกจาก PDF parser และคง layout สำคัญด้วย inline style; ทดสอบ Version 2 จาก local data แล้วเหลือ 1 หน้า (จากเดิม 678 หน้า). `DocumentPdfRendererTest` และ `DocumentTemplateBuilderUiContractTest` ผ่าน 4 tests / 40 assertions.

> Verification record (2026-09-04): ปรับ HTML/PDF parity เพิ่มเติม โดยจัด document metadata ใน Header ขวา, บังคับ signature footer ไว้ล่างสุด และใช้ fixed table columns กับ mPDF-safe typography/spacing. Blade cache และชุด renderer/UI tests ผ่านแล้ว.

> Verification record (2026-09-04): เพิ่มฟอนต์ `Noto Sans Thai` จาก Google Fonts สำหรับ PDF profile A4 ใน `resources/fonts` และตรวจ render ภาษาไทยจริงแล้ว; `DocumentPdfRendererTest` ผ่าน 3 tests / 5 assertions.

> Finance Petty Cash (2026-09-04): เพิ่ม Petty Cash subledger แบบแยกจาก Settlement ได้แก่ Fund/Voucher/Line ที่ warehouse-scoped, cash-account gate, expense snapshots, workflow service, atomic post/reverse, backend routes/RBAC, Yajra endpoint และ fund setup/deactivation guard. เพิ่ม Top-up จาก BANK ที่ active/postable ไป CASH ของ Fund พร้อม snapshot, sequence, audit, idempotency, Journal reverse, route/RBAC, Yajra DataTable + Excel และ AJAX UI; clearing/reconciliation ยังไม่เริ่ม.
