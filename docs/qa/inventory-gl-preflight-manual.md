# Inventory → GL Preflight Manual QA

ขอบเขตนี้ตรวจเฉพาะ read-only preflight และ reconciliation contract ยังไม่เปิด Journal posting จริง

## Preconditions

- มี Item ที่กำหนด Inventory control account และ COGS Expense account ถูกประเภท
- มี Stock Movement อย่างน้อยหนึ่งรายการในสถานะ `POSTED`
- มี cost allocation ที่ final และมี `journal_entry_id` หรือมีรายการที่ตั้งใจให้แสดงเป็น blocker
- เลือก Warehouse เดียวกับ Movement และใช้วันที่ as-of ที่ต้องการตรวจ

## Scenarios

### Period close gate

- [ ] ปิดงวดที่มี allocation สถานะ `PENDING`/`REQUIRES_RECOST`, ต้นทุน provisional, Journal/linkage หาย, revision/Journal mismatch, recost ที่ยังไม่สำเร็จ หรือผลต่าง Inventory กับ GL ต้องถูกบล็อก พร้อมเหตุผลที่ผู้ใช้แก้ได้
- [ ] Allocation ที่ link ไปยัง Journal ซึ่งยังไม่ `POSTED`, อยู่หลังวันสิ้นงวด หรืออยู่คนละ Warehouse ต้องถูกบล็อก
- [ ] Inventory GL line ที่เป็น `ITEM` แต่ไม่มี allocation linkage ต้องถูกแสดงเป็น orphan และบล็อกการปิดงวด
- [ ] Recost request ที่ trigger อยู่ก่อนงวดแต่ยัง `PENDING`/`PROCESSING`/`FAILED` ต้องยังบล็อกงวดที่กำลังปิด หากอาจกระทบยอดสะสม
- [ ] งวดที่ไม่มี allocation หรือแก้ปัญหา allocation, linkage, recost และ reconciliation ครบแล้ว ต้องไม่ถูกบล็อกจาก Inventory gate

1. เปิด Stock Valuation → Historical valuation และเลือกวันที่ย้อนหลัง
   - ตารางต้องอ่านจาก cost allocation ตาม `business_date <= as_of_date`
   - ไม่ใช้ยอดปัจจุบันจาก Stock Balance เป็น historical source
2. ตรวจรายการที่มี Pending/RECOST
   - Final value ไม่รวม provisional allocation
   - Pending value/count แสดงแยก และสถานะเป็น `รอ Recost`
3. ตรวจรายการที่ allocation ไม่มี Journal link
   - คอลัมน์ Unlinked มากกว่า 0
   - สถานะเป็น `ต้องตรวจสอบ`
4. เปิด Historical Inventory ↔ GL
   - Inventory GL ต้องมาจาก Posted Journal line ที่เป็น Inventory control account และ `ITEM` subledger
   - ยอดรวมต้องไม่นับ Journal line ที่ไม่มี `ITEM` subledger ซ้ำเป็น Inventory GL
   - ผลต่างคำนวณเป็น Final Inventory ลบ Inventory GL
   - ต้องตรวจ Stock Balance projection เทียบ allocation เพิ่มด้วย; หาก GL ตรงแต่ balance drift ให้แสดง `ต้องตรวจสอบ`
   - ห้ามสร้างหรือแก้ Journal จากการเปิด/refresh หน้านี้
5. ตรวจ preflight ของ OUT movement
   - ถ้า COGS account ไม่พร้อม, movement ไม่ใช่ `POSTED`, มี Pending หรือมี Unlinked allocation ต้องไม่ผ่าน
6. ตรวจ preflight ของ IN movement
   - ไม่บังคับ COGS account สำหรับ receipt แต่ยังต้องมี Inventory account, final allocation และ Journal link
7. ตรวจ Source identity ของ Movement
   - Movement ที่จะเป็น candidate ต้องมี `source_type`, `source_id` และ `source_reference` ครบ
   - หากขาดค่าใดค่าหนึ่งต้องแสดง blocker `source_ready` และยังห้ามเปิด Journal posting
   - ค่า source ต้องใช้ย้อนกลับไปหาเอกสารต้นทางได้ ห้ามใช้ข้อความชั่วคราวแทนรหัสเอกสาร
8. ตรวจ Reconciliation Gate ก่อนอนุมัติการเปิด Posting
   - `allocation_vs_gl_difference` ต้องเป็นศูนย์
   - `balance_vs_allocation_difference` ต้องเป็นศูนย์
   - `unlinked_allocations` ต้องเป็นศูนย์
   - หากไม่ผ่าน ต้องแสดง blocker ที่แก้ได้และยังห้ามสร้าง Journal
9. ตรวจ Source Contract ตาม event
   - `inventory.receipt` ใช้ `RECEIPT/IN` และ source จาก `PURCHASING` หรือ `INVENTORY`
   - `sales_cogs` ใช้ `ISSUE/OUT` และ source จาก `POS`
   - `inventory.adjustment` ใช้ `ADJUSTMENT/COUNT` และ source จาก `INVENTORY`
   - allocation ต้องตรงกับ Movement, Warehouse, Item, UOM และ business date เดียวกัน

## Evidence

- บันทึก Warehouse, as-of date, item, allocation IDs, pending/unlinked count และ difference
- หากไม่ผ่าน ให้แก้ source document/account mapping/recost ก่อนเปิด posting
- ห้ามแก้ยอด balance หรือ Journal ด้วยมือเพื่อให้ผลต่างเป็นศูนย์

## Future Journal posting gate (ยังไม่เปิดใน MVP รอบนี้)

ก่อนเชื่อม `Stock Movement → Item subledger → Inventory GL` ต้องผ่านทุกข้อ:

- preflight ของ movement ผ่าน และไม่มี `PENDING`, `REQUIRES_RECOST` หรือ unlinked allocation
- Item/typed mapping ให้บัญชี active, postable และประเภทถูกต้อง; ห้าม fallback เป็นบัญชีแรกหรือ hard-code account ID
- `RECEIPT` ของใบซื้อที่ลง Inventory ใน PJ ต้อง link Journal เดิมและห้าม postซ้ำ; `ISSUE` ของ Sales ใช้ `sales_cogs` เป็น `Dr COGS / Cr Inventory`
- payload ต้องส่ง allocation IDs แบบ explicit, policy/method version, signed value, source document และ revision
- lock order ต้องคงที่: movement → allocation/layer → balance → journal book → fiscal period
- idempotency ต้องตรวจ source/event/revision และ posting hash; payload เดิมคืนผลเดิม payload เปลี่ยนต้อง reject
- Journal line ต้องมี Item subledger เมื่อจะทำ reconciliation ระดับ item และ allocation ต้องเก็บ Journal linkage หลัง post
- RECOST ในงวดเปิดต้องเป็น delta allocation + delta Journal; งวดปิดต้องเป็น approved adjustment ในงวดเปิด ห้ามแก้ Journal เดิม

ผลที่ยอมรับได้ก่อนเปิด route จริงคือ “preflight ผ่าน/ไม่ผ่าน” และ dry-run payload ที่ไม่สร้าง Journal เท่านั้น

### Current implementation boundary

- Adapter ตรวจ UOM และ business date ของ allocation ให้ตรงกับ Stock Movement และป้องกันยอดหลังปัดเศษ 2 ตำแหน่งเป็นศูนย์แล้ว
- มี service ที่ครอบ JournalPostingService + allocation status + immutable journal-line linkage ใน transaction เดียวสำหรับ Inventory Purchase MVP แล้ว; ใช้ release-gate tests และ manual UI sign-off ควบคุมการเปิดใช้งาน
- Recost/negative-stock และ source flow ที่อยู่นอก Inventory Purchase MVP ยังอยู่ภายใต้ preflight/recovery gate; ห้ามตีความรายการที่ยังเป็น dry-run หรือ feature-off ว่าเป็นการลงบัญชีจริง

### Purchase Document `NONE_VAT` → Inventory Post → GL / Receipt Draft reconciliation contract

ขอบเขต QA รอบนี้ตรวจ flow หลักของ MVP คือ Purchase Invoice สินค้าแบบ `NONE_VAT` ที่ Approved แล้วกด `Inventory Post` ใน Warehouse เดียวกัน; หน้า Purchase Receipt เป็น Draft foundation สำหรับเตรียมตรวจรับ ไม่ใช่ posting flow แยก:

- Purchase Document ต้องผ่าน `Inventory Post` ด้วย event `PURCHASING/supplier_invoice.inventory` และ Journal ต้องเป็น `POSTED`; Receipt Draft ใช้ source เดิมและห้ามสร้าง Journal ซ้ำ
- Journal ต้องเป็น `Dr Inventory (ITEM) / Cr AP (SUPPLIER)` ตามยอดเอกสาร โดยไม่มี VAT line หรือ rounding delta
- Stock Movement จาก Inventory Post ต้องเป็น `RECEIPT/IN` และอ้าง `source_type=PURCHASING`, `source_id` และ `source_reference` ของ Purchase Invoice เดียวกัน; Receipt Draft ก่อนหน้านั้นต้องไม่สร้าง Movement/Cost Layer/GL
- Cost Layer/Allocation ต้องตรงกับ Movement ใน `warehouse_id`, Item, UOM, business date และ quantity/value; ต้องมี immutable Journal-line linkage และไม่มี `PENDING`/unlinked ค้าง
- Retry ด้วย document/event/revision/hash เดิมต้อง reuse Journal, Movement, Allocation และ linkage เดิม; payload เปลี่ยนต้อง reject หรือสร้าง revision ใหม่ตาม contract
- กำหนด lock order: Purchase document → Journal book → Fiscal period → Movement → Allocation/Layer → Stock balance และ failure จุดใดต้อง rollback ทั้งชุด
- Warehouse context ต้องตรงกันระหว่าง Purchase Invoice, Journal, Movement, Allocation และ session; วันที่ Post/Receipt ห้ามอยู่ก่อนวันที่เอกสารหรือในงวดปิด

Automated contract evidence (22 สิงหาคม 2026): `PurchaseReceiptNoneVatGlReconciliationContractTest` **4 tests / 12 assertions ผ่าน** ครอบคลุม Inventory Post debit/credit payload, reconciliation/rollback/idempotency gates และการ reject VAT/rounding นอก scope

นอกขอบเขตรอบนี้: VAT settlement, Production และ posted reversal; Production operational sign-off ยังเป็น final gate

Manual UI checklist ล่าสุดสำหรับ Purchase Document → Inventory Post อยู่ที่
[`purchase-inventory-post-ui-manual.md`](purchase-inventory-post-ui-manual.md); browser/manual UI sign-off ผ่านแล้ว เหลือ Production operational sign-off เท่านั้น.

### Purchase Receipt release-candidate regression (22 สิงหาคม 2026)

#### Goods Receipt UOM/partial-receipt contract (local foundation)

- Receipt Draft ยังคงเป็น intent เท่านั้น: ห้ามสร้าง Stock Movement ที่ `POSTED`, Cost Layer/Allocation หรือ Journal ซ้ำกับ Purchase Document → Inventory Post
- เมื่อ Purchase UOM ต่างจาก Stock UOM ต้องมี conversion ที่ active และมีผลใน `business_date` ของ Receipt เพียงหนึ่งรายการ; ห้ามใช้ factor ปัจจุบันแทน snapshot ย้อนหลัง, ใช้ conversion ซ้ำหรือ factor ศูนย์/ติดลบ
- จำนวน Stock ต้องคำนวณด้วย decimal-safe arithmetic เป็น `purchase_qty × factor`; contract คืน `purchase_uom_id`, `stock_uom_id`, factor, conversion id และ effective-date snapshot ให้ movement/cost layer ที่จะเปิดภายหลังบันทึกไปพร้อมกัน
- ต้นทุนรวมจาก Purchase line ต้องกระจายเป็น stock-unit cost หลัง conversion และคืน rounding delta ให้ reconciliation ตรวจสอบ; partial receipt ต้องตรวจยอดสะสมไม่เกิน Purchase line และ retry ด้วย receipt reference เดิมต้อง idempotent
- `GoodsReceiptConversionContract` และ Unit tests ครอบคลุม forward conversion, same-UOM factor 1, missing/duplicate effective conversion, zero factor และ decimal-safe stock-unit cost แล้ว; ยังไม่ใช่การเปิด Receipt Post หรือ GL/Movement เพิ่ม

#### Goods Receipt → Inventory Post integration audit

- Receipt Draft ไม่มี `post` route และ `PurchaseReceiptFoundationService` สร้างได้เฉพาะ `DRAFT` intent; ห้ามเรียก Journal, Stock Movement posting หรือ Cost Allocation จาก Receipt โดยตรง
- Inventory Post ยังคงเข้าได้ทาง `PurchaseDocumentController@inventoryPost` เพียงทางเดียว และ retry ต้องตรวจ exact `PURCHASING` + `supplier_invoice.inventory` + document identity, Posted Movement, allocation เดียว และ immutable journal-line linkage ก่อน reuse
- Receipt Draft ยังไม่ถูกนำไปรวมกับ Inventory Post เพราะแม้ `wms_uom_conversions` และ `goods_receipt_lines` จะเก็บ effective-date/factor snapshot แล้ว แต่ movement/cost layer ที่จะสร้างจาก Receipt ยังไม่มี contract รับ snapshot นี้แบบถาวร; ห้ามใช้ factor ปัจจุบันเพื่อคำนวณย้อนหลังโดยไม่มี snapshot
- Regression audit test ยืนยันว่าไม่มี Receipt posting route/duplicate posting path และ Inventory Post ไม่ claim snapshot-ready จนกว่า source/conversion/cost/reconciliation contract จะครบ

- Contract/source/adapter/feature-gate/permission/warehouse-period tests: **50 tests / 140 assertions ผ่าน**
- Transfer regression transaction suite: **6 tests / 24 assertions ผ่าน**; ใช้ `DatabaseTransactions` และ rollback fixtures ครบ
- Local database row check เป็น read-only เท่านั้น: `purchase_documents=2`, `journal_entries=6`, `wms_stock_movements=2`, `wms_cost_allocations=2`, `wms_cost_allocation_journal_lines=2`
- Local mock Purchase Inventory `PI-INVENTORY-MOCK-001` อยู่สถานะ `REVERSED` และ allocation เดิมยังเป็น `PENDING`; จึงใช้ยืนยันได้เฉพาะ schema/idempotency/reversal smoke ที่มีอยู่ ไม่ใช้เป็นหลักฐานเปิด Inventory Post หรือ Receipt posting ใหม่
- สถานะฝั่งนี้: **Local Release Candidate / Manual UI Sign-off ผ่านแล้ว**; production operational sign-off จะทำรวมครั้งเดียวหลัง module ในขอบเขต MVP พร้อมครบทั้งหมด

### Release readiness review — ready for User sign-off (22 สิงหาคม 2026)

- Checklist และ QA evidence ถูกทวนกับ flow หลัก `Purchase Document → Inventory Post`; Purchase Receipt ยังคงเป็น Draft foundation และไม่เปิด posting flow แยก
- Migration status ของ local `new_erp` ไม่มีรายการ Pending; migrations `313` (Transfer) และ `314` (cost-layer lineage) อยู่สถานะ `Ran` พร้อม index/foreign-key contract ที่ตรวจแล้ว
- Feature gate `erp.inventory.purchase_posting_enabled` ใน config default เป็น `false`; local `.env` ปัจจุบันตั้ง `ERP_INVENTORY_PURCHASE_POSTING_ENABLED=true` เพื่อ smoke เดิม จึงต้องปิดกลับเป็น `false` ก่อน release/ก่อน user sign-off หากยังไม่อนุมัติ. Route และปุ่ม Inventory Post ต้องผ่าน feature gate + permission `wms.purchase-documents.inventory-post` และ warehouse context
- Regression evidence ล่าสุดผ่าน: Purchase/Inventory/GL/adapter/source/permission/feature gate/warehouse/period/retry/rollback **50 tests / 140 assertions** และ Transfer transaction suite **6 tests / 24 assertions**
- Operational blocker ที่ยังต้องให้ User ตัดสินใจ: local mock ถูก Reverse และ allocation เดิมเป็น `PENDING`; ห้ามใช้เป็นหลักฐานเปิด feature เพิ่ม และยังต้องคง gate ปิด
- สถานะ: **LOCAL_READY_FOR_CONTINUED_DEVELOPMENT**; Manual UI/User sign-off ของ Inventory → GL ผ่านแล้ว แต่ยังไม่ใช่ production-ready และยังไม่ทำ production operational sign-off จนกว่า module ในขอบเขต MVP จะพร้อมครบ

### Atomic transaction and retry evidence

ก่อนเปิด Inventory Posting จริง ต้องมี manual QA card สำหรับกรณี transaction ล้มเหลว:

- [ ] จำลอง failure หลังสร้างบางส่วนของ Movement/Cost Allocation/Journal แล้วตรวจว่า transaction rollback ทั้งชุด ไม่มี record หรือ linkage ค้างครึ่งทาง
- [ ] หน้าหรือ Workflow Center ต้องบอกสาเหตุ, จุดที่ต้องแก้ และ recovery action; ห้ามให้ผู้ใช้กด Post ซ้ำโดยไม่ตรวจ current state/idempotency
- [ ] Retry ด้วย source/event/revision และ posting hash เดิมต้องคืนผลเดิม ไม่สร้าง Movement, Allocation หรือ Journal ซ้ำ
- [ ] เก็บหลักฐาน idempotency key, source identity, revision, posting hash, status ก่อน/หลัง และจำนวน ledger/linkage ก่อน/หลัง retry
- [ ] หาก payload เปลี่ยนต้อง reject หรือสร้าง revision ใหม่ตาม contract; ห้ามเขียนทับ Posted history

### Readiness evidence per line

- [ ] Preflight แสดง blocker แยกราย Movement/Allocation/Journal line โดยมี source identity, item, UOM, warehouse, business date และ revision ให้ตามกลับได้; ห้ามรวมเป็นข้อความว่า “ข้อมูลไม่พร้อม” อย่างเดียว
- [ ] แต่ละบรรทัดระบุ gate ที่ไม่ผ่านอย่างชัดเจน เช่น source wiring, mapping, pending cost, unlinked/mismatched journal-line linkage, period หรือ reconciliation difference พร้อมผู้รับผิดชอบและ recovery action
- [ ] Transaction boundary ต้องครอบคลุมการ lock และการเขียน Movement → Cost Allocation/Layer → Journal Entry/Line → immutable linkage → status transition ในชุดเดียว; failure จุดใดต้อง rollback ทั้งชุด
- [ ] หลัง Journal/Allocation เป็น `POSTED` แล้ว journal-line linkage ต้อง immutable; การแก้ไขใช้ reversal/correction และสร้าง revision/linkage ใหม่ ห้าม update/delete proof เดิม
- [ ] เมื่อแก้ blocker แล้วให้ rerun preflight ด้วย source/event/revision เดิม ตรวจว่า blocker รายบรรทัดหายเฉพาะรายการที่แก้จริง และไม่มีรายการอื่นถูกเปลี่ยนสถานะโดยอ้อม
- [ ] ตรวจ exact Journal-line mapping ของแต่ละ allocation โดยจับคู่ `account_id`, `subledger_type`, `item_id`, signed `amount`, `warehouse_id`, `business_date` และ revision ให้ตรงกันทุกค่า
- [ ] Journal line ที่จับคู่ได้มากกว่าหนึ่งบรรทัด, ไม่มีคู่จับคู่, account/subledger/item ไม่ตรง หรือ amount ต่างจาก allocation ต้องเป็น blocker ระดับบรรทัดและห้ามผ่าน reconciliation
- [ ] Recovery ของ duplicate/mismatch ต้องแก้ source mapping หรือสร้าง reversal/correction และ revision ใหม่ตาม contract; ห้ามลบหรือแก้ Journal line ที่ Posted เพื่อให้ยอดตรง

### Recost / reversal recovery

- [ ] จำลอง Posted inventory ที่ต้นทุนหรือจำนวนผิด: ระบบต้องไม่เปิดให้แก้หรือลบ Movement, Allocation, Cost Layer หรือ Journal เดิม
- [ ] Recovery ต้องสร้าง reversal/correction และ revision ใหม่ โดยเก็บ source identity, parent allocation, reason, actor และ idempotency identity ให้ตรวจสอบย้อนกลับได้
- [ ] Recost ต้องสร้าง delta allocation/Journal ตามงวดที่เปิด; หากงวดเดิมปิด ให้ใช้ approved adjustment ในงวดเปิด ห้ามแก้ Journal เดิม
- [ ] หลัง reversal/recost ให้ตรวจ Stock projection, cost layer/valuation, Inventory/COGS GL, allocation↔Journal-line linkage และ reconciliation difference ใหม่ทุกครั้ง
- [ ] ทีมเล็ก 1–2 คนต้องเห็นข้อความ recovery ภาษาคนว่า “หยุด Post → เปิดรายการต้นทาง → ทำ reversal/recost ตามสิทธิ์ → ตรวจ reconciliation” โดยไม่ต้องสร้าง approval chain เพิ่มนอก policy
- [ ] Retry reversal/recost ด้วย source/revision เดิมต้อง idempotent ไม่สร้าง delta หรือ Journal ซ้ำ; payload เปลี่ยนต้องสร้าง revision ใหม่หรือถูก reject
- [ ] Recost queue exception (`PENDING`, `PROCESSING`, `FAILED`, `STALE`) ต้องแสดงสถานะ/สาเหตุ/ผู้รับผิดชอบและ retry action ที่ทำซ้ำได้อย่างปลอดภัย; ระหว่างยังไม่ resolve ต้อง block period close และ final reconciliation

### Recost queue implementation note (MVP)

- `wms_cost_recalculation_requests` มีสถานะ `PENDING`, `PROCESSING`, `FAILED`, `STALE`, `RESOLVED`; job เปลี่ยนเป็น `PROCESSING` ก่อนทำงานและเก็บ `last_error` เมื่อ retry ครบ/ล้มเหลว โดยไม่สร้าง ledger ซ้ำ
- Scheduler `Schedule::call(...)->hourly()` เรียก `RecostQueueHealth::markStale()` ตาม `recost_sla_minutes`; งานที่ไม่ขยับเกิน SLA จะเป็น `STALE` และยัง block period close
- การ retry ทำผ่าน `RecostQueueHealth::retry($requestId)` ภายใต้ transaction/row lock และอนุญาตเฉพาะ `FAILED` หรือ `STALE`; ห้าม reset งาน `PENDING`/`PROCESSING` ที่ยังทำงานอยู่ และห้าม retry `RESOLVED`; dispatcher จะ enqueue งานแบบ bounded ต่อ receipt
- `RecostQueueHealth::summary()` และ `recentOpen()` เป็น read path ของการ์ด `RECOST Queue Health` ใน Stock Valuation; แสดงยอดตาม warehouse, SLA, รายการล่าสุดที่ค้าง/ล้มเหลว และ retry เฉพาะรายการ `FAILED`/`STALE` ที่มี permission; รายงานหนักทั่วไปยังไม่เข้า queue ใน MVP
- Scheduler mark-stale ใช้ conditional update ซ้ำด้วย status และ SLA timestamp เพื่อไม่ให้รอบ scheduler ที่ช้ากว่า worker เปลี่ยนรายการที่เพิ่ง `RESOLVED` กลับเป็น `STALE`; การ retry ล้าง `resolved_at` และ error เดิมก่อนกลับ `PENDING`

## Inventory → GL release QA checklist

### Verified local integration evidence (2026-08-22)

- Inventory Purchase mock `PI-INVENTORY-MOCK-001` posted atomically to Journal `11`; one Movement, one Cost Allocation and one immutable Journal-line linkage were created.
- Repeating the same post returned Journal `11` without increasing Journal, Movement, Allocation or linkage counts.
- Live reversal smoke created reversal Journal `13`, one reversal Movement, one reversal Allocation and one linkage; allocation, stock balance and Inventory GL reconciled to zero.
- Repeating the same reversal returned Journal `13`; a changed reason was rejected without adding rows.
- Migration `2026_08_22_310000_add_inventory_reversal_audit_to_purchase_documents` ran successfully. Local owner sign-off is recorded by enabling `ERP_INVENTORY_PURCHASE_POSTING_ENABLED=true`; production still requires the same evidence and sign-off gate.
- Local recheck after idempotent `InventoryGlMockupSeeder`: Item mock count `1`, Purchase mock remains `POSTED` with Journal `11` and `REVERSED` reversal; Movement count `2`, Cost Allocation count `2`, and immutable Journal-line linkage count `2` (no duplicate transaction rows created).
- Read-only reconciliation at `HQ-WH`, as-of `2026-08-22`: allocation↔GL difference `0.00`, stock-balance↔allocation difference `0.00`, unlinked allocations `0`, release gate `ตรงกัน`.
- Release-gate Unit tests: `27 tests / 70 assertions` passed, covering preflight blockers, feature-off safety, atomic rollback, retry identity, reversal contract and reconciliation gate.
- Route middleware recheck confirms separate permissions for `wms.purchase-documents.approve`, `wms.purchase-documents.post`, `wms.purchase-documents.inventory-post` and `wms.purchase-documents.inventory-reverse`.
- Manual UI owner sign-off (local MVP) passed: duplicate Post is guarded, permission/warehouse isolation is visible and enforced, failed transactions show recovery guidance, and the user is directed to fix the source/preflight blocker before retrying.
- Local release status is `APPROVED`; production remains a separate deployment gate requiring the same migration, seed, smoke, reconciliation and UI evidence on the target environment.

สำหรับ local MVP ใช้เป็นหลักฐานหลังเปิด feature flag/route แล้ว; production และ source flow อื่นยังต้องผ่าน migration, smoke, reconciliation และ owner sign-off ของ environment นั้นก่อนเปิดใช้งาน

### Purchase Document → Inventory Post operational runbook (NONE_VAT only)

ขอบเขตนี้ใช้ `PurchaseDocumentController@inventoryPost` เป็นทางเข้าเดียวของ MVP; ไม่เปิด Post จากหน้า Purchase Receipt Draft แยก และไม่ครอบคลุม VAT, Production หรือ Inventory Reversal

**ก่อนเปิดใช้งาน**

1. ตรวจ `ERP_INVENTORY_PURCHASE_POSTING_ENABLED=false` ระหว่าง migration/schema check และตรวจ migration `310000`, `313000`, `314000` เป็น `Ran` ครบ
2. ตรวจ Item เป็น `is_stock_item`, Inventory Account active/postable/control `INVENTORY`, mapping `PURCHASE_AP` active/postable/control `AP`, Journal Book `PURCHASE` active และ Fiscal Period ของวัน Post เป็น `OPEN`
3. รัน smoke ใน transaction-only environment: Purchase Document `APPROVED` + `NONE_VAT` → Journal `Dr Inventory / Cr AP` → Movement/Layer/Allocation/Linkage → reconciliation; ต้อง rollback fixture และตรวจ counts เดิม
4. ตรวจ permission `wms.purchase-documents.inventory-post` และ warehouse scope ด้วยผู้ใช้จริงก่อนเปิด flag
5. เปิด flag เฉพาะ environment ที่ owner อนุมัติ และบันทึกเวลา/ผู้เปิด/ผล reconciliation; production ยังต้องมี operational sign-off แยก

**การใช้งานประจำวัน**

- สร้าง/อนุมัติ Purchase Document แบบ `NONE_VAT` แล้วเลือกวัน Post ที่ไม่ก่อนวันเอกสาร; route จะใช้ posting date นี้กับ Journal, Movement และ allocation
- การ Post สำเร็จต้องได้เอกสาร `POSTED`, Journal identity, Movement `POSTED`, final cost allocation และ immutable allocation↔Journal-line link ครบ
- Retry request เดิมทำซ้ำได้และต้องคืนผลเดิม; payload, posting date หรือ warehouse ต่างจากรายการเดิมต้องหยุดและให้ตรวจเอกสารเดิม

**เมื่อผิดพลาดหรือระบบตอบล้มเหลว**

- ห้ามสร้าง Journal/Movement/Allocation ซ้ำด้วยการกรอกเลขใหม่เพื่อหลบ error; ตรวจ source idempotency, Journal identity, allocation/linkage และ reconciliation ก่อน retry
- หาก source/account/fiscal period/warehouse ไม่ผ่าน ให้แก้ต้นเหตุที่เอกสารหรือ master แล้วส่ง retry เดิม; ห้ามแก้ Posted ledger โดยตรง
- หาก transaction ล้มเหลวกลางทาง ต้องตรวจ counts และ source identity ว่า Journal, Movement, Layer, Allocation, Linkage และสถานะเอกสารถูก rollback ทั้งชุด
- หากพบผลต่างหรือรายการ orphan ให้ปิด flag, หยุดการ Post, เก็บ evidence และให้ Accounting/WMS ตรวจ reconciliation; ใช้ reversal ภายใต้ gate แยกในรอบถัดไป ไม่ทำใน flow นี้

**ปิดใช้งานฉุกเฉิน**

ตั้ง `ERP_INVENTORY_PURCHASE_POSTING_ENABLED=false`, clear/reload config ตาม deployment process และตรวจว่า route ตอบ `404`/ไม่แสดง action จาก feature gate; ข้อมูลที่ Post สำเร็จแล้วไม่ถูกลบหรือแก้ย้อนหลัง ต้องใช้ recovery contract ที่อนุมัติเท่านั้น

### Setup / Daily / access

- [ ] Setup ผ่าน Item/Category/UOM, Opening Balance, AVG/FIFO policy, Inventory/COGS mapping และ fiscal period ตาม Warehouse scope
- [ ] Daily flow ผ่าน Receipt Draft → ตรวจรับ → approval/post ตาม policy → inventory event/cost layer → Inventory/COGS GL โดยไม่มีขั้น Production สำหรับบริษัท `TRADING`
- [ ] Workflow Center เปิดอ่านได้กับผู้ใช้ใน Program ทุกคน; action ปลายทางและ route Post ตรวจ permission, warehouse และ feature gate ฝั่ง server ซ้ำ
- [ ] ทีมเล็ก 1–2 คนทำ flow ได้ตาม policy โดยไม่ถูกบังคับ second approver; หากเปิด maker-checker ต้องทดสอบ maker/checker แยกสิทธิ์
- [ ] Feature gate ปิดอยู่ต้องแสดง readiness/preflight blocker และไม่มีปุ่ม Post/Resolve ปลอม; เปิดได้เมื่อ source wiring, atomic linkage, reconciliation zero และ reversal gate ผ่าน

### Rollback / reconciliation evidence

- [ ] จำลอง failure ทุกช่วงของ atomic transaction แล้วตรวจ rollback Movement, Allocation/Layer, Journal/Line, linkage และ status ทั้งชุด
- [ ] จำลอง Posted ผิดแล้วตรวจ reversal/correction + revision, immutable history และ idempotent retry โดยไม่สร้างรายการซ้ำ
- [ ] แนบหลักฐานก่อนเปิด gate: source/event/revision identities, item/UOM/warehouse/date, mapping, allocation IDs, exact Journal-line pairs, pending/unlinked counts, reconciliation differences และ period status
- [ ] หลังเปิด gate ตรวจ happy path, duplicate request, payload mismatch, closed period, wrong warehouse และ missing source identity; ทุกกรณีต้อง block/recover ได้ตาม contract

### Migration / seed runbook (ทีมเล็ก)

- [ ] ก่อนรัน migration/seed ต้องมี owner approval ระบุ environment, release version, scope, backup/rollback plan และผู้รับผิดชอบ; หากไม่มี approval ให้หยุดไว้ที่ read-only preflight
- [ ] ตั้ง feature flag/route gate เป็น `false` ก่อนเริ่ม migration/seed และคงเป็น `false` ระหว่างตรวจ schema, seed control totals และ integration smoke
- [ ] ผู้รับผิดชอบสำรองฐานข้อมูลและบันทึก release version ก่อน migration/seed; ห้ามทำบน production โดยไม่มี backup/rollback plan
- [ ] รัน migration ตาม release pipeline ใน maintenance window ที่ตกลง และตรวจ schema/index/foreign key ของ allocation, linkage และ idempotency ก่อนเปิด feature gate
- [ ] Seed เฉพาะ master/config ที่จำเป็น (journal mapping, policy, feature gate) ด้วย seeder ที่ idempotent; ห้าม seed transaction, stock, cost หรือ Journal ปลอมเพื่อให้ผ่าน reconciliation
- [ ] ตรวจ post-migration counts, unique constraints, settings readiness และ permission isolation; หากไม่ผ่านให้ปิด gate/หยุด release และใช้ rollback plan
- [ ] เปิด feature flag ได้ต่อเมื่อ integration smoke ของ source→movement→allocation→Journal/linkage, rollback/retry, permission/warehouse isolation และ reconciliation ผ่านครบ พร้อม owner sign-off; หาก smoke หรือ evidence ไม่ครบให้คง `false`
- [ ] บันทึกผู้รัน เวลา environment, migration/seed version, row/control totals และ sign-off ของ Accounting/WMS แม้บริษัทมีผู้ดูแลเพียง 1–2 คน

## Known blockers

### Transfer UI readiness (deferred until service contract)

- [ ] WMS Transfer ยังไม่มี controller, route, DataTable AJAX endpoint หรือ form view ที่เปิดใช้งานจริง; ห้ามสร้างหน้า UI โดยเดา route/permission หรือแสดงปุ่ม Dispatch/Accept/Reject ก่อน service พร้อม
- [ ] เมื่อ Transfer service พร้อม ให้ตรวจ UI ตาม contract นี้: list ใช้ server-side Yajra/AJAX และ warehouse scope, วันที่/สถานะแสดงเป็น human-readable/pastel badge, Item/UOM ใช้ Select2 AJAX, action ปิดปุ่มระหว่าง request และบังคับ current-state/idempotency จาก server
- [ ] Manual QA ที่ต้องทำภายหลัง: Draft → Dispatch → Accept/Partial/Reject, duplicate action, wrong warehouse, closed period, insufficient stock, reversal/recovery และตรวจ cost lineage/reconciliation ไม่สร้างกำไรขาดทุนจากการย้ายคลัง

- Costing policy ยืนยันแล้วว่าเลือก AVG/FIFO ระดับบริษัทเดียว แต่ MVP ใช้ cost pool/balance แยก warehouse ตาม locked contract ข้อ 7.6.1; ห้ามตีความเป็น policy แยกคลัง และ transfer ต้องรักษา lineage เดิม
- ต้องกำหนด line-level allocation linkage หรือ immutable metadata ให้ drill-down ได้ครบ
- ต้องยืนยัน direct Inventory/AP สำหรับ Purchase Invoice หรือเพิ่ม GRNI mapping
- ต้องมี reconciliation report ที่ difference, pending, unlinked และ missing mapping เป็นศูนย์ก่อน period close
- Receipt ต้อง lookup เอกสารต้นทางที่ POSTED และ reuse Journal เดิมก่อนเปิด Atomic Posting; ห้ามสร้าง Journal จาก allocation เพียงอย่างเดียว

## Contract freeze: costing pool and Journal linkage

- `warehouse_id` เป็น operational attribution และ reconciliation scope ไม่ใช่ costing policy selector; ห้ามตั้ง AVG/FIFO ต่างกันต่อคลัง
- Current schema มี `journal_entry_id` ระดับ allocation แต่ยังไม่มี `journal_entry_line_id` หรือ allocation-link table จึงยังตรวจได้เพียง Journal-level link ไม่ใช่ line-level proof
- มี migration `wms_cost_allocation_journal_lines` แล้ว โดยเก็บ allocation ID, journal entry line ID, revision และ stable unique identity; ห้าม overwrite/delete link หลัง `POSTED`
- Preflight ตรวจทั้ง allocation ที่ไม่มี line proof และ line ที่ชี้คนละ Journal/revision เป็น `ต้องตรวจสอบ`; จนกว่าจะผ่านครบยังไม่ถือว่า reconcile สำเร็จ

## Draft document deletion safety audit

- Purchase/Sales document routes ไม่มี `DELETE`; Draft/Approved ใช้ `VOID` transition และ Posted ใช้ reversal/credit-note contract
- Draft update ลบเฉพาะ document lines ของเอกสารที่ยังเป็น Draft ไม่แตะ `wms_stock_movements`, `wms_cost_allocations` หรือ `journal_entries`
- Stock Movement เป็น immutable หลัง Post และ Cost Allocation ห้าม delete จึงไม่มีการลบแหล่ง ledger จากการแก้ Draft
- ก่อนเพิ่ม delete route ในอนาคตต้องตรวจ source reference/stock movement/allocation/journal/open item และอนุญาตเฉพาะ Draft ที่ไม่มี downstream record; หากมี downstream ให้ใช้ Void/Reverse เท่านั้น
- Manual QA: สร้าง Draft → แก้บรรทัด → ตรวจว่าไม่มี stock/allocation/Journal เพิ่ม → Void → ตรวจว่าเอกสารไม่ปรากฏเป็น source ที่พร้อม Post และ reconciliation ไม่เปลี่ยน

## AVG/FIFO costing invariant review

- AVG receipt ใช้ trusted unit cost และ issue ใช้ average ก่อนตัด; ตรวจ quantity/value ด้วย decimal 8 ตำแหน่ง ไม่ใช้ float
- FIFO issue ต้องแยก allocation ตาม layer และเก็บ layer lineage; ห้ามเลือก layer ใหม่จากราคาปัจจุบันเมื่อมี source layer เดิม
- Negative stock ต้องเป็น provisional allocation ที่มี Pending/Recost request และไม่ผ่าน close/final reconciliation จนกว่าจะ resolve
- RECOST ต้องสร้าง allocation delta/revision ใหม่ ไม่แก้ allocation เดิมหรือ Stock Movement ที่ Post แล้ว
- Retry ด้วย identity เดิมต้องคืนผลเดิม; payload หรือ cost policy version เปลี่ยนต้อง reject หรือสร้าง revision ที่ตรวจสอบได้
- Transfer/return/reversal ต้องรักษา parent/source allocation และไม่สร้างกำไรขาดทุนจากการย้ายคลังเอง

### Transfer integration gate (ยังไม่เปิดใน MVP)

- ปัจจุบันมี `TransferMovementService`, routes และ permission สำหรับ dispatch/accept/reject แล้ว โดย service ล็อก transfer/line และสร้าง paired movement แบบ idempotent; หลักฐาน DB-backed ใน isolated transaction ผ่านแล้ว แต่ยังห้ามเปิด production จนกว่าจะผ่าน manual UI/operational sign-off
- DB-backed integration evidence ผ่านใน isolated transaction เมื่อ 22 สิงหาคม 2026: `WmsTransferCostLineageTest` 6 tests / 24 assertions ครอบคลุม FIFO/AVG A → B → C, partial/reject/retry, wrong warehouse, closed period และ insufficient stock rollback; fixture ทั้งหมด rollback แล้ว
- ก่อนเปิดจริงต้องพิสูจน์ A → B → C ว่า pending-transfer, on-hand, cost allocation และ source layer lineage รวมกันถูกต้อง และ retry เดิมไม่สร้าง movement/allocation ซ้ำ
- Transfer ห้ามใช้ `inventory.adjustment` mapping และห้ามสร้าง GL gain/loss; Inventory→GL adapter ต้อง reject transfer จนกว่าจะมี contract ที่ระบุว่าเป็นเพียงการย้าย attribution พร้อม reconciliation คู่ต้นทาง/ปลายทาง
- `transfer_id`/`transfer_key`, movement pair, allocation parent/source layer และ revision ต้อง trace ย้อนถึง receipt ต้นกำเนิดได้ รวมถึงกรณี partial accept/reject และ reversal; AVG accept ผูก destination allocation กับ source FINAL allocation แล้ว และ FIFO partial accept ต้องหัก parent allocation ที่เคยโอนไปแล้วก่อนสร้าง allocation รอบใหม่
- ปริมาณที่รับ/ปฏิเสธจาก UI ให้ถือเป็น `base_quantity`; service ต้องคำนวณ `quantity` ตามอัตราแปลงของ Transfer line ไม่ใช่คัดลอก base quantity ไปเป็นจำนวนหน่วยขาย
- ต้องตรวจ warehouse assignment และ Fiscal Period ของทั้ง dispatch/accept; ห้ามรับ transfer เข้าคลังปลายทางหรือ post movement ในงวดปิด
- มี Unit invariant coverage สำหรับ company-wide source OUT + destination IN net zero, แยก warehouse scope, ห้าม Journal linkage และ parent/layer lineage ของ AVG/FIFO; DB-backed A → B → C evidence ผ่านใน isolated transaction แล้ว แต่ยังต้องทำ manual UI/production operational sign-off ก่อน release จริง
- หลักฐานเพิ่มเติม 23 สิงหาคม 2026: `InventoryPurchaseMySqlIntegrationReadinessTest` ผ่าน 1 test / 14 assertions บน MySQL `new_erp` แบบ transaction/rollback ครอบคลุม Purchase/GR → Stock Movement → Cost Allocation → Journal balance, linkage, `FINAL` + `POSTED` lifecycle และ counts ก่อน/หลังเท่ากัน โดยไม่ทิ้งข้อมูลถาวร; `WmsTransferCostLineageTest` ผ่าน 6 tests / 24 assertions ครอบคลุม AVG/FIFO transfer lineage, partial/reject/retry, warehouse scope, closed period และ insufficient stock rollback
