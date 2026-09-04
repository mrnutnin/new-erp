# Purchasing MVP Enhancements Plan

## Objective

เพิ่ม Feature สำคัญสำหรับ Purchasing MVP เพื่อปิดวงจรการคืนสินค้า รองรับราคาซื้อจาก Supplier และวัดผลการจัดซื้อจากข้อมูลจริง โดยใช้ Purchasing เป็นเจ้าของ workflow และเรียก WMS เฉพาะ Stock/Cost integration

## Scope and priority

| Priority | Feature | เป้าหมาย |
|---|---|---|
| P0 | Purchase Return + Credit Note | คืนสินค้าผิด/ชำรุดและปิดวงจรกับ Supplier |
| P1 | Supplier Price List | ใช้ราคาซื้อที่ active ตอนสร้าง PO พร้อมเก็บ snapshot |
| P2 | Supplier Performance | แสดงผลการส่งมอบ ราคา คุณภาพ และอัตราการคืน |

## Boundary

Purchasing owns:

- Return document และ Credit Note lifecycle
- Supplier price list และ effective-date selection
- Supplier performance calculation/report
- Permission, approval, branch/warehouse scope และ audit trail

WMS owns:

- Stock movement สำหรับรับคืน/ตัดคืน
- Cost allocation, cost reversal และ RECOST
- Inventory/Cost/GL integration contract

Accounting owns:

- Journal posting และ Credit Note accounting entry
- Tax/VAT และ supplier payable impact

## Phase 1 — Purchase Return + Credit Note (P0)

### Functional scope

- สร้าง Return จาก Goods Receipt หรือ Purchase Invoice ที่ผ่านการอนุมัติแล้ว
- รองรับการคืนบางส่วนและหลายบรรทัด
- จำนวนคืนต้องไม่เกินจำนวนที่รับจริงและจำนวนที่ยังไม่ถูกคืน
- ระบุเหตุผลการคืน สภาพสินค้า และวันที่คืน
- Workflow: Draft → Submit → Approve → Post / Void
- รองรับ Credit Note 2 กรณี: คืนสินค้าให้ Supplier (อ้าง Purchase Return และมี Stock/Cost impact) หรือปรับลดหนี้โดยไม่คืนสินค้า (อ้าง Invoice และไม่มี Stock movement)
- ป้องกันการ Post ซ้ำด้วย idempotency key
- เก็บ source linkage ระหว่าง Return, Receipt, Invoice, Stock และ Journal

### Acceptance criteria

- คืนสินค้าเกินจำนวนรับไม่ได้
- คืนซ้ำเกินยอดคงเหลือไม่ได้
- Post แล้วสร้าง Stock/Cost/GL ครบและ atomic
- Retry ด้วย request เดิมไม่สร้าง movement, allocation หรือ journal ซ้ำ
- Void/Post ที่ขัดกับสถานะหรือ period close ถูกปฏิเสธ
- Credit Note เก็บ VAT, supplier snapshot และเอกสารต้นทางครบ

### Planned components

- `Purchasing` Return model, line, request, controller, service และ views
- Return migration และ document sequence
- WMS inventory return adapter
- Accounting credit-note posting integration
- Unit, feature และ MySQL integration tests

## Phase 2 — Supplier Price List (P1)

### Functional scope

- Supplier + Item + UOM + Currency
- Unit price, minimum quantity และ effective date range
- สถานะ Active/Inactive
- ห้ามช่วงวันที่ active ของ Supplier/Item/UOM เดียวกันซ้อนกันโดยไม่มีกติกา
- ดึงราคาที่ active ล่าสุดตอนสร้างหรือแก้ไข PO
- ผู้ใช้แก้ราคาใน PO ได้ตาม permission
- บันทึกราคาและ currency ที่เลือกเป็น PO snapshot

### Acceptance criteria

- ระบบเลือก price list ที่ตรง Supplier, Item, UOM, Currency และวันที่ PO
- ไม่ใช้ราคาหมดอายุหรือ inactive
- หากไม่มีราคา ระบบยังสร้าง PO ได้โดยให้กรอกราคาเองตาม permission
- การเปลี่ยน price list ภายหลังไม่เปลี่ยนราคาใน PO เดิม
- ราคาที่เลือกต้องตรวจสอบย้อนหลังได้ว่าเกิดจากรายการใด

### Planned components

- `SupplierPriceList` และ `SupplierPriceListLine`
- Price list CRUD และ option endpoint
- PO price suggestion service
- PO price snapshot fields/audit
- Unit และ feature tests

## Phase 3 — Supplier Performance (P2)

### MVP metrics

- On-time delivery: วันที่รับจริงเทียบกำหนดส่ง PO
- Quantity fulfillment: จำนวนรับจริงเทียบจำนวนสั่ง
- Return rate: จำนวนคืนเทียบจำนวนรับ
- Price variance: ราคาซื้อเทียบ price list หรือราคาเฉลี่ยช่วงเวลา
- Quality result: จำนวนบรรทัดที่มี defect หรือถูกคืนด้วยเหตุผลด้านคุณภาพ

### Rules

- เริ่มจาก read-only report/dashboard
- คำนวณจาก PO, Goods Receipt, Purchase Return และ Credit Note ที่ Post แล้ว
- กำหนดช่วงวันที่และ Supplier เป็น filter หลัก
- แสดงจำนวนข้อมูลที่ใช้คำนวณทุก metric
- ไม่สร้างคะแนนรวมจนกว่าจะมีข้อมูลจริงและเจ้าของธุรกิจยืนยัน weight

### Acceptance criteria

- ตัวเลข drill-down กลับไปยังเอกสารต้นทางได้
- ไม่รวมเอกสาร Draft, Void หรือรายการที่ยังไม่ Post ตาม metric ที่เกี่ยวข้อง
- วันที่ส่งตรงเวลารองรับ partial receipt
- Return rate แยกเหตุผลด้านคุณภาพได้
- Report จำกัดตาม branch/warehouse ที่ผู้ใช้มีสิทธิ์

## Cross-cutting requirements

- ใช้ `purchasing.*` route และ permission namespace
- ใช้ branch/warehouse authorization เดิม
- ใช้ Global Settings สำหรับ document sequence
- เก็บ immutable snapshot ของข้อมูลที่มีผลต่อบัญชีและการวัดผล
- รองรับ idempotency, retry และ rollback
- ไม่สร้าง WMS compatibility route/controller/model wrapper ใหม่
- ใช้ DataTable, server-side filter และ canonical Purchasing layout

## Test gates

- Return quantity และ remaining balance
- Partial return และ duplicate return
- Return → Stock Movement → Cost reversal/RECOST → Credit Note (เฉพาะกรณีคืนสินค้า)
- Invoice → Credit Note แบบไม่คืนสินค้า สำหรับ price allowance, quality claim, shortage หรือ commercial adjustment โดยไม่สร้าง Stock movement
- Post retry และ atomic rollback
- Price list effective date, currency และ UOM matching
- PO price snapshot ไม่เปลี่ยนตาม price list ภายหลัง
- Supplier performance aggregation และ branch/warehouse scope
- Permission, document sequence, audit และ period-close

## Checklist

- [x] Phase 1: Return migration และ model/line
- [x] Phase 1: Over-return eligibility service และ decimal-safe quantity guard
- [x] Phase 1: Save Purchase Return request และ source/line validation
- [x] Phase 1: Draft creation และ Submit/Approve/Void service foundation
- [ ] Phase 1: Return Draft/Submit/Approve/Void workflow
- [~] Phase 1: Credit Note linkage และ VAT snapshot — เพิ่ม `credit_note_mode` แยก `RETURN`/`NON_RETURN`, service สร้าง/ผูก Draft Credit Note จาก Approved Return → Posted Invoice และกัน Stock/Cost reversal สำหรับ non-return; รองรับ NONE_VAT ก่อน, VAT snapshot ยังไม่เปิด
- [ ] Phase 1: WMS Stock/Cost/GL integration
- [ ] Phase 1: Return Unit/Feature/MySQL tests
- [ ] Phase 2: Supplier Price List migration และ CRUD
- [ ] Phase 2: PO price suggestion และ snapshot
- [ ] Phase 2: Price List Unit/Feature tests
- [ ] Phase 3: Supplier Performance query/report
- [ ] Phase 3: Performance filters, drill-down และ scope
- [ ] Phase 3: Performance Unit/Feature tests
- [ ] อัปเดต `docs/planning/06-core-feature-menu-checklist.md` หลังจบแต่ละ phase
- [ ] ตรวจ cleanup ไฟล์และ reference ที่ไม่ได้ใช้งาน

> Phase 1 foundation (2026-09-04): สร้าง `purchase_returns` และ `purchase_return_lines` พร้อม source linkage, immutable quantity/cost snapshot, credit-note link, idempotency และ Draft/Submit/Approve/Post/Void state contract แล้ว; ยังไม่เปิด route หรือ posting จนกว่า over-return eligibility service จะผ่านการทดสอบ.

> Phase 1 service foundation (2026-09-04): `PurchaseReturnService` สร้าง Draft แบบ atomic, ออกเลขจาก Global Sequence, snapshot source/UOM/cost และรองรับ Submit/Approve/Void พร้อม lock transition แล้ว.

> Phase 1 Credit Note boundary (2026-09-04): เพิ่ม `PurchaseReturnPostingContract` และ `PurchaseReturnCreditNoteService` สำหรับกรณีคืนสินค้าจริงเท่านั้น โดยตรวจ Approved Return, Posted Invoice, Supplier/Warehouse scope, duplicate link และยอด NONE_VAT ก่อนสร้าง Draft Credit Note พร้อม receipt allocation และผูกกลับที่ `purchase_returns.credit_note_id`; Credit Note แบบไม่คืนสินค้าเป็นอีก flow หนึ่งที่ยังใช้ `PurchaseDocumentPostingService` ได้โดยไม่แตะ Stock/Cost.

> Phase 1 Credit Note mode (2026-09-04): เพิ่ม `purchase_documents.credit_note_mode` (`RETURN`/`NON_RETURN`), validation และตัวเลือกในฟอร์ม Purchase Document; `NON_RETURN` ลดหนี้ได้โดยไม่สร้าง Stock/Cost movement และทั้ง Controller/Posting/Reversal service boundary รับ inventory reversal เฉพาะ `RETURN`. ผ่านชุดทดสอบที่เกี่ยวข้อง 10 tests / 43 assertions และ route/view cache.

> Phase 1 Non-return guard (2026-09-04): `NON_RETURN` ห้ามส่ง receipt allocation ผ่าน Request และฟอร์มซ่อน Goods Receipt picker พร้อมแสดงคำอธิบายว่าเป็น Financial-only adjustment; MySQL ยืนยันการ Post AP/Journal โดยไม่มี Stock/Cost side effect แล้ว.

> Phase 1 MySQL verification (2026-09-04): รัน migration `2026_09_04_010000_add_credit_note_mode_to_purchase_documents` บน local `new_erp` สำเร็จ; Credit Purchase reversal/VAT integration ผ่าน 3 tests / 19 assertions โดยมี 1 persistent operational test skip ตามเงื่อนไขเดิม และ dedicated `NON_RETURN` guard + financial posting ผ่านในชุด Credit Purchase integration (4 tests / 18 assertions, 1 skip).

> Phase 1 Return WMS boundary (2026-09-04): เพิ่ม `PurchaseReturnWmsPostingContract` ให้ Return posting ต้องมี Posted `RETURN` Credit Note, scope เดียวกัน และเป็น Full-line ก่อนเรียก WMS reversal; Partial Return ยังถูก block เพราะ engine ปัจจุบันยังไม่รองรับ movement ตามปริมาณบางส่วน.

> Phase 1 Return posting orchestration (2026-09-04): เพิ่ม `PurchaseReturnPostingService` ให้ทำงานแบบ atomic ตั้งแต่ lock Return, ตรวจ Full-line, สร้าง/อนุมัติ/โพสต์ Credit Note, เรียก WMS reversal และ mark Return เป็น `POSTED`; ยังรอ MySQL end-to-end evidence ก่อนเปิด route/permission.

> Phase 1 Return E2E verification (2026-09-04): MySQL ยืนยัน Full-line Return → Credit Note `RETURN` → AP Open Item → Stock OUT → Cost reversal แบบ atomic ผ่านในชุด Credit Purchase integration 5 tests / 25 assertions มี 1 persistent operational skip เดิม; เพิ่ม Item subledger สำหรับ Inventory Credit Note และข้าม Three-way Match เฉพาะ Credit Note.

> Phase 1 Partial Return design (2026-09-04): เพิ่ม `PurchaseReturnPartialPostingContract` สำหรับคำนวณ Stock Quantity, Total Cost และ Return Ratio แบบ decimal-safe ตามจำนวนคืนจริง; ยังไม่เปิด production posting จนกว่าจะมี partial Stock Movement/Cost Allocation implementation และ MySQL E2E.

> Phase 1 Partial WMS preflight (2026-09-04): เพิ่ม `PurchaseReturnPartialInventoryAdapter` แบบ read-only เพื่อโหลด GR source จริงและส่งเข้า partial contract; ยังไม่สร้าง movement/allocation จนกว่าจะรองรับการตัด Cost Layer ของ AVG/FIFO อย่างปลอดภัย.

> Phase 1 Partial cost resolver (2026-09-04): เพิ่ม `PurchaseReturnPartialCostAllocationContract` แบบ pure สำหรับคำนวณ AVG ตามปริมาณคืน และ FIFO จาก source layers โดยไม่ mutate layer ระหว่าง preflight; ยังรอ adapter ที่ทำ movement/allocation ใน transaction จริง.

> Phase 1 Partial WMS cost preflight (2026-09-04): เชื่อม `PurchaseReturnPartialInventoryAdapter` ให้เลือก AVG Balance หรือ FIFO Final Layers และคืน movement/cost plan เดียวกันแบบ read-only; `posting_enabled` ยังเป็น false จนกว่าจะ implement writer transaction.

> Phase 1 Partial WMS writer (2026-09-04): เพิ่ม feature-gated writer ให้สร้าง ISSUE/OUT movement ตาม partial stock quantity และเรียก WMS costing engine ใน outer transaction; ค่าเริ่มต้นยังปิด และยังไม่ mark Return/Credit Note จนกว่าจะเชื่อม Journal linkage และ idempotency E2E ครบ. ปรับให้ใช้ Stock UOM จาก Receipt Movement จริง และ MySQL E2E ผ่านเมื่อ fixture มี on-hand.

> Phase 1 Partial Journal linkage (2026-09-04): เพิ่ม `PurchaseReturnPartialJournalLinkContract` ตรวจ Posted Credit Note Journal, scope, account และยอด Cost Allocation ให้ตรงกันก่อนสร้าง immutable allocation→Journal link; ยังไม่เปิด Return state transition จนกว่า writer integration จะผ่าน E2E.

> Phase 1 Partial Journal link writer (2026-09-04): เพิ่ม `linkCostJournal` ใน Partial WMS Adapter ให้รองรับเฉพาะหนึ่ง allocation, ค้นหา Inventory Journal line แบบ decimal-exact และ reuse immutable WMS linkage ภายใน transaction; กรณี FIFO หลาย layer และยอด Credit Note ไม่ตรง Cost จะยังถูก block.

> Phase 1 Partial E2E verification (2026-09-04): Partial Return 2.5/10 ผ่าน MySQL ตั้งแต่ Credit Note/AP, Stock OUT, Cost allocation จนถึง immutable Journal link รวมชุด Credit Purchase integration 6 tests / 30 assertions มี 1 persistent operational skip เดิม.

> Phase 1 FIFO multi-layer Journal linkage (2026-09-04): เพิ่ม pure contract และปรับ Partial WMS adapter ให้ aggregate Cost Allocation ทุก FIFO layer แล้ว link แบบ atomic ไปยัง Inventory Journal line เดียว โดยตรวจ scope, status, account และยอดรวมแบบ decimal-safe; MySQL FIFO multi-layer E2E ผ่านแล้ว โดย policy ปัจจุบันบังคับให้ยอด Credit Note ตรงกับต้นทุนรวม.

> Phase 1 FIFO linkage regression verification (2026-09-04): Unit ที่เกี่ยวข้องผ่าน 8 tests / 21 assertions และ Credit/Return Purchasing MySQL regression ผ่าน 7 tests / 34 assertions (skip เดิม 1 รายการ); ยังไม่ได้เปิด feature flag หรือเปลี่ยน Return state transition.

> Phase 1 Partial Return state integration (2026-09-04): เพิ่ม `PurchaseReturnPostingService::postPartial()` ให้ทำ Credit Note → Partial Stock OUT → multi-layer Journal linkage → Return `POSTED` ภายใน transaction เดียว และ retry ของ Return ที่ `POSTED` คืนผลเดิมโดยไม่สร้างรายการซ้ำ; MySQL Partial regression ผ่าน 2 tests / 11 assertions.

> Phase 1 Automated acceptance coverage (2026-09-04): เพิ่ม assertion สำหรับ retry/idempotency ของ FIFO Partial Return; MySQL acceptance test ผ่าน 1 test / 8 assertions และยืนยันจำนวน Document, Movement, Allocation และ Journal Link ไม่เพิ่มหลัง retry.

> Phase 1 Owner sign-off (2026-09-04): ผู้ใช้ทดสอบ UI/Workflow ของ Purchase Return + Credit Note ผ่านแล้ว; ถือว่า Manual QA และ Definition of Done ของขอบเขต MVP นี้ครบถ้วน.

> Purchasing Dashboard (2026-09-04): ออกแบบหน้า Dashboard ให้สอดคล้องกับ module อื่น โดยแยกโหลด summary/work/trend/recent เป็นคนละ section, cache 30 วินาทีตาม warehouse scope และใช้ Chart.js ในกราฟแนวโน้ม PO; เพิ่ม route/data contract test แล้ว.

## Definition of done

- Feature ใช้งานผ่าน canonical Purchasing routes
- ไม่มี legacy WMS Purchasing surface เพิ่มขึ้น
- Workflow, permission, scope, sequence และ audit ผ่าน
- Unit และ MySQL integration tests ที่เกี่ยวข้องผ่าน
- UI/UX ผ่าน manual owner sign-off
- Checklist และเอกสาร module workflow อัปเดตแล้ว
