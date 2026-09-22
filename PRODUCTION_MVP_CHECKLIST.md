# Production MVP Checklist

Checklist นี้สรุปจาก `PRODUCTION_MODULE_PLANING.md` และสถานะงานปัจจุบันของ Production MVP

Legend: `[x]` ทำแล้ว, `[~]` ทำบางส่วน, `[ ]` ยังเหลือ

## 1. Foundation / Optional Module / Security

- [x] Program code `production`
- [x] Entry route `production.index`
- [x] Production service provider/module shell
- [x] Guard routes ด้วย `program:production`, `capability:production`, `warehouse`
- [x] WMS Manual Production ไม่ขึ้นกับ `capability:production`
- [x] Seed/installer สำหรับ production program และ permissions หลัก
- [x] ใช้ `production.*` permission สำหรับ workflow หลักทั้งหมด
  - [x] WO view/create/release/execution post บางส่วน
  - [x] Approve/reverse/GL/cost scoped permissions ในหน้า WO
- [x] Installer schema check สำหรับ BOM/WO tables
- [x] Installer schema check สำหรับ lifecycle columns
  - [x] `production_orders` core/lifecycle columns
  - [x] production-WMS linkage tables

## 2. Sales Order / Made to Order Demand

- [x] เพิ่ม `sales_orders.required_delivery_date`
- [x] Demand Queue จาก `CONFIRMED` Sales Order lines
- [x] กรองเฉพาะ line ที่มี Active BOM
- [x] Block service/non-stock หรือ UOM ไม่ตรง base UOM ตอนสร้าง WO
- [x] หนึ่ง active WO ต่อ Sales Order line; retry คืน WO เดิม
- [x] WO snapshot item/quantity/UOM/spec/required delivery date
- [x] ไม่ copy ราคา/ส่วนลด/ภาษี เข้า Production
- [x] Sales Order detail แสดง production status/shortcut ไป WO
- [x] Sales Order cancelled exception `SALES_ORDER_CANCELLED`
- [x] Make to Stock / Manual WO create form

## 3. BOM

- [x] BOM header/revision/lines
- [x] Finished item ต่อ BOM
- [x] Component item, quantity, UOM
- [x] Effective date
- [x] Status `DRAFT`, `ACTIVE`, `INACTIVE`
- [x] Active revision ห้ามแก้ทับ
- [x] Copy revision
- [x] กัน component ซ้ำ
- [x] กัน finished item เป็น component ตัวเอง
- [x] กัน BOM cycle ขั้นต่ำ
- [x] Server-side DataTable

## 4. Production Order Core

- [x] ตาราง `production_orders`
- [x] ตาราง `production_order_materials`
- [x] ตาราง `production_order_events`
- [x] Document sequence `PRODUCTION_ORDER`
- [x] WO list/detail
- [x] สร้าง WO จาก Demand Queue
- [x] Snapshot material จาก Active BOM ตอนสร้าง/release flow ปัจจุบัน
- [x] Status: `DRAFT`, `RELEASED`, `IN_PROGRESS`, `COMPLETED`, `CANCELLED`
- [x] Release action
- [x] Sync `IN_PROGRESS` เมื่อ Material Issue posted
- [x] Sync `COMPLETED` เมื่อ Finished Receipt posted ครบ planned quantity
- [x] Finished Receipt reversal recompute WO completion
- [x] Edit/delete draft WO
- [x] Cancel WO rule: `DRAFT`, `RELEASED` เฉพาะยังไม่มี posted WMS docs
- [x] Material Issue POST เปลี่ยน WO เป็น IN_PROGRESS
- [x] Shop Floor ต้องยืนยันเริ่มงานก่อนเปิด Action ผลิตต่อ
- [x] ไม่เพิ่ม Complete command: Finished Receipt POST ตรวจ pending return/scrap และปิด WO อัตโนมัติ

## 5. Material Requirement / Readiness

- [x] แสดง material readiness จาก WMS balance
- [x] Required quantity
- [x] Available quantity
- [x] Ready/not enough badge
- [x] Reserved quantity
- [x] Issued quantity
- [x] Returned quantity
- [x] Net issued quantity
- [x] Shortage quantity
- [x] สถานะ `NOT_READY`, `PARTIAL`, `READY`, `ISSUED`
- [x] Release WO ต้องไม่ require stock ครบ แต่แสดง shortage ชัดเจน

## 6. Material Reservation

- [x] WMS `StockReservationService` มี reserve/release/consume foundation
- [x] Consumption ledger / installer / tests
- [x] Reserve material จาก WO material lines
- [x] `source_type = PRODUCTION_ORDER`
- [x] deterministic idempotency key ต่อ WO material line
- [x] Release reservation เมื่อ cancel WO
- [x] Consume/reduce reservation เมื่อ Material Issue posted
- [x] กัน reserved ติดลบผ่าน `StockReservationService`
- [x] Contract test สำหรับ reserve/consume/release จาก WO
  - [x] reserve contract
  - [x] consume contract
  - [x] release contract

## 7. Material Issue / Unused Return

- [x] สร้าง Draft Production Material Issue จาก WO โดย reuse `IssueReturnService::createIssue()`
- [x] กันสร้างซ้ำด้วย `production_order_events`
- [x] บังคับ 1 WO มี active Material Issue ได้ใบเดียว
- [x] Link กลับไป WMS material issue
- [x] Material Issue posted แล้ว WO เป็น `IN_PROGRESS`
- [x] MVP ใช้ snapshot เต็มเพียง 1 Material Issue ต่อ WO
- [x] ห้ามเบิกสะสมเกิน Required quantity โดยไม่อนุญาตให้มี Material Issue เพิ่ม
- [x] Approve issue จากหน้า WO ด้วย `production.execution.approve`
- [x] Post issue จากหน้า WO ด้วย `production.execution.post`
- [x] Reverse/cancel material issue แล้ว sync WO/status/reservation
  - [x] cancel/delete draft-approved issue sync WO event/status
  - [x] posted issue reversal sync WO/WIP/reservation
- [x] สร้าง Material Return จากหน้า WO
  - [x] shortcut จาก WO ไปหน้า create return พร้อม `issue_document_id`
  - [x] embedded create return ใน Production layout
- [x] Return อ้าง issue line จริงและไม่เกิน remaining ผ่าน WMS return flow
- [x] Return post/reverse sync WIP/WO view
  - [x] post/reverse records WO events
  - [x] WIP summary recomputes and displays net material cost

## 8. Recoverable / Non-recoverable Scrap

- [x] Posting/reversal contract foundation สำหรับ `PRODUCTION_SCRAP_RECEIPT`
- [x] Account mapping seed/installer coverage พื้นฐาน
- [x] WMS application service/UI สำหรับ Scrap Receipt ครบ draft/approve/post/reverse/delete
  - [x] approve/post application service
  - [x] reversal service/UI
- [x] WO embedded recoverable scrap action
  - [x] create draft scrap receipt from WO
  - [x] approve/post from WO
  - [x] reverse from WO
- [x] Link WO/source issue/material line
  - [x] WO/source issue link
  - [x] optional material line in UI
- [x] Validate recovery value สะสมไม่เกิน Available WIP ของ WO
- [x] Non-recoverable scrap quantity/reason ใน WO
- [x] Scrap post/reversal sync WIP/WO related docs
  - [x] WIP summary subtracts posted scrap receipt value
  - [x] WO related docs lists scrap receipts
  - [x] scrap post emits WO event
  - [x] scrap reversal emits WO event
- [x] `production_order_scraps` schema/model/installer coverage

## 9. Finished Goods Receipt

- [x] Shared `ProductionFinishedReceiptDocumentService`
- [x] ใช้ document context `PRODUCTION_RECEIPT`
- [x] Create/approve/post/reverse WMS finished receipt flow
- [x] WO shortcut ไป create finished receipt พร้อม `source_issue_id`
- [x] Finished receipt posted sync `completed_quantity`/`COMPLETED`
- [x] Finished receipt reversal recompute `completed_quantity`/status
- [x] Embedded create/approve/post finished receipt ในหน้า WO ไม่ redirect ไป WMS
- [x] บังคับหนึ่ง WO มี posted finished receipt หนึ่งชุดเมื่อจบงาน
- [x] รับเท่ากับ Planned quantity และมีใบรับผลิตได้ใบเดียวต่อ WO
- [x] ต้องจัดการ material return/scrap ให้เสร็จก่อนรับงานจบ
- [x] Actual finished value ต้องมาจาก Net Posted Material Cost ของ WO ไม่ใช่ user input
- [x] Finished-goods reservation ให้ Sales Order line หลัง post receipt
- [x] Receipt + finished-goods reservation ต้องอยู่ transaction เดียวกัน

## 10. Finished Goods Reservation / POS Fulfillment

- [x] Reserve finished goods ให้ Sales Order line เมื่อ Made to Order WO complete
- [x] `source_type`/source identity สำหรับ Sales Order line
- [x] POS Physical Sale consume reservation ของ `source_line_id` แบบ atomic
- [x] ห้าม POS update reservation/stock balance โดยตรง
- [x] Contract test end-to-end SO → WO → Receipt → Reservation → POS fulfillment

## 11. Accounting / Cost / WIP

- [x] Reuse WMS/Accounting services; Production ไม่สร้าง journal เอง
- [x] Material issue accounting ผ่าน WMS issue posting
- [x] Finished receipt accounting ผ่าน `ManualProductionReceiptPostingService`
- [x] Finished receipt reversal ใช้ reversal service
- [x] Scrap accounting contract/service มีแล้ว
- [x] WIP summary ในหน้า WO
- [x] Posting blockers/preflight แสดงในหน้า WO
- [x] GL preview เฉพาะ journals ที่ผูกกับ WO ด้วย `production.orders.gl.view`
- [x] Cost view guarded ด้วย `production.orders.cost.view`

## 12. Production UI / UX

- [x] Production sidebar: Demand, WO, BOM
- [x] Demand Queue server-side DataTable
- [x] WO list/detail เบื้องต้น
- [x] Material readiness ใน WO detail
- [x] Next action alerts สำหรับ DRAFT/RELEASED/IN_PROGRESS/COMPLETED
- [x] Production Dashboard metrics: draft, in-progress, near/overdue, shortage, ready-to-receive
- [x] WO create/edit form สำหรับ Make to Stock
  - [x] create form
  - [x] edit draft form
- [x] Embedded Material Issue/Return/Scrap/Finished Receipt
  - [x] Material Issue create from WO
  - [x] Scrap create/approve/post/reverse from WO
  - [x] Finished Receipt create/approve/post from WO
  - [x] Material Return create/approve/post from WO
- [x] Related Sales Order, WMS documents, Accounting proof section
  - [x] WMS material issue/return/scrap related section
  - [x] Sales Order link/status
  - [x] accounting proof/GL
- [x] Audit timeline เต็มใน WO detail
- [~] Excel export scope/label ตรวจครบทุก DataTable
  - [x] Production WO/Demand ใช้ shared `ส่งออก Excel (หน้านี้)`
  - [ ] ตรวจครบทุก module

## 13. Audit / Concurrency / Idempotency

- [x] Audit create/release/material issue created/posted/receipt posted/reversed บางส่วน
- [x] Lock SO line/WO ใน key commands บางส่วน
- [x] DB transaction สำหรับ create/release/material issue/posted sync/reversal sync
- [x] Deterministic idempotency keys
  - [x] material issue event กันซ้ำ
  - [x] reservation keys
  - [x] finished receipt keys from WO/issue
  - [x] finished goods reservation key
- [x] Audit before/after/reason ครบสำหรับ edit/cancel/reverse ทุก action
  - [x] WO edit/cancel/delete และ production reversal contracts
  - [x] ตรวจ runtime audit payload ทุก controller path
- [x] Retry payload ต่างกันต้อง reject

## 14. Recovery Paths

- [x] Finished receipt reversal recompute WO completion
- [x] Material issue cancel/reverse sync WO/reservation/WIP
- [x] Material return reverse sync WO/WIP
- [x] Scrap receipt reverse sync WO/WIP
- [x] Sales Order cancelled exception
- [x] Cancel WO release reservations and block when posted docs exist

## 15. Tests / Quality Gate

- [x] Production foundation contract tests
- [x] BOM foundation tests
- [x] Production Order MVP contract tests
- [x] Stock reservation consumption tests
- [x] Finished receipt service contract tests
- [x] Scrap receipt contract tests
- [x] WO 1-1-1 receipt policy unit test
- [x] Installer schema coverage บางส่วน
- [x] Feature/integration test สำหรับ Demand → WO → issue → receipt → complete
  - [x] Workflow wiring contract Demand → WO → issue → receipt → FG reservation → POS
  - [x] Runtime database integration test (MySQL opt-in: SO line → WO service → Issue → Receipt → reservation → POS → ordered recovery)
- [x] Permission contract: Production workflow ไม่ต้องใช้ `wms.*`/`accounting.*`
- [x] Reservation end-to-end tests
  - [x] Finished Goods reservation → POS consume แบบ atomic
  - [x] SO → WO → Receipt → Reservation → POS fulfillment
- [x] Related docs / GL preview scoped tests
- [x] Recovery path tests ทุก reversal/cancel
  - [x] Contract coverage: issue/return/receipt/scrap reversal
  - [x] Runtime integration coverage ทุก recovery path (issue/return/scrap/receipt/POS/WO cancel)
- [x] Run unit tests แบบ chunked เพราะ full suite memory 128MB
- [x] `git diff --check` หลังทุกชุดงาน

## 16. หลัง MVP เสถียร ค่อยทำ

> Basic QC และ Partial Production หลายรอบ ถูกตัดออกจาก MVP ตาม scope ล่าสุด และจะพิจารณาเป็นเฟสถัดไป

- [ ] Partial production หลายรอบ (ตัดออกจาก MVP ชั่วคราว)
- [x] Routing Lite / operations (BOM Routing Template + WO Operation Snapshot; Work Center/Shift ยังไม่ทำ)
- [ ] Basic QC (ตัดออกจาก MVP ชั่วคราว)
- [x] Material substitute / controlled override (BOM/WO Snapshot)
- [x] Hold / exception workflow
- [x] Production reports เพิ่มเติม (Production, Cost, Material Movement)
- [ ] Labor / machine / overhead allocation
- [ ] Standard cost variance (รอ Standard Cost master)
- [ ] By-product / co-product costing

## 17.1 งานหลัง MVP ที่ทำเพิ่มแล้ว

- [x] แจ้งปัญหาหน้างานผลิตและสถานะ Open/Resolved
- [x] Database/Web Push แจ้ง Supervisor ตาม Branch/Warehouse
- [x] Production Planning Timeline/Gantt เบื้องต้น
- [x] Filter Planning: สินค้า, เกินกำหนด, วัตถุดิบขาด
- [x] รายงาน Production/Cost/Material Movement แยกเมนูและ Routing

## 17. Shop Floor Operator — หลัง MVP

### สิทธิ์และขอบเขตใหม่
- [x] สร้าง permission เดียว `production.shop_floor.use` สำหรับผู้ปฏิบัติงานหน้างาน
- [x] Admin ได้รับ `production.shop_floor.use` อัตโนมัติผ่านสิทธิ์ทั้งหมดของ Admin
- [x] ใช้งาน Issue, Return, Scrap และ Finished Receipt ได้ครบใน Shop Floor menu เดียว
- [x] ไม่ต้องมีสิทธิ์ WMS, Accounting, GL หรือ Production Supervisor
- [x] Route/action ของ Shop Floor ใช้ permission นี้และยังตรวจ Branch/Warehouse/WO ownership ฝั่ง Server
- [x] ทุกการ Approve/Post ยังเรียก WMS/Accounting service กลางและบันทึก Audit ตามเดิม

### Flow และข้อมูล
- [x] Operator Cockpit แยกจากหน้า WO Supervisor
- [x] Queue แบบการ์ด แสดงเฉพาะ WO ตาม Branch/Warehouse scope
- [x] ค้นหา WO ด้วยเลขเอกสาร
- [x] สแกน QR เพื่อเลือก WO พร้อม fallback เมื่อกล้องใช้ไม่ได้
- [x] แสดงสถานะ WO และเอกสารย่อยครบ: Issue, Return, Scrap, Finished Receipt
- [x] แสดง Next action เดียวที่ชัดเจนตามสถานะจริง
- [x] Operator ทำ Issue → Return/Scrap → Finished Receipt จนจบจากเมนูเดียว
- [x] Operator ไม่เห็น/ไม่แก้ต้นทุน บัญชี BOM ราคา หรือข้อมูลลูกค้า
- [x] Retry/refresh/network retry ไม่สร้างเอกสารซ้ำ
- [ ] แสดง error/success ใน Cockpit โดยไม่ redirect ไป WMS/Accounting
- [ ] Online-only และแจ้งชัดเจนเมื่อ network ขัดข้อง

### UX/UI สำหรับหน้างาน
- [ ] Responsive mobile/tablet รองรับ portrait และ landscape
- [ ] ปุ่มและ input สำหรับหน้างานแตะง่ายอย่างน้อย 44px
- [ ] ใช้สีและ badge แยกสถานะ/ขั้นตอนชัดเจน ไม่ใช้โทนขาวดำเป็นหลัก
- [ ] ใช้คำสั่งภาษาไทยที่เข้าใจง่าย ลดศัพท์ WMS/Accounting
- [ ] ซ่อน field ที่ไม่จำเป็นและลดจำนวนขั้นตอนการกรอก
- [ ] มี confirmation สำหรับการลง Stock/จบงาน และป้องกันการกดซ้ำ
- [ ] ผู้ใช้ที่ไม่ถนัด IT เห็นว่าต้องทำอะไรต่อได้ภายในหน้าจอแรก

### Tests
- [x] Contract tests สำหรับ permission, scope, idempotency และ mobile action flow
- [x] Runtime MySQL test ครบ Issue, Approve/Post, Return, Scrap และ Finished Receipt
- [x] Runtime test ยืนยันไม่มีสิทธิ์เข้าเมนู WMS/Accounting จาก Shop Floor role
- [x] Responsive/accessibility test สำหรับสี, focus, label และ touch target ผ่านการทดสอบจริง

## 18. Production Planning Board — หลัง MVP

- [ ] Calendar/Gantt view รายวัน/สัปดาห์/เดือน
- [ ] แสดง WO, สินค้า, จำนวน, สถานะ, คลัง, ผู้รับผิดชอบ และกำหนดส่ง
- [ ] แสดง shortage, overdue และ Sales Order linkage
- [ ] Filter Branch/Warehouse, สถานะ, ผู้รับผิดชอบ, สินค้า และช่วงวันที่
- [ ] ใช้ WO เป็น source of truth ไม่สร้าง schedule state ใหม่
- [ ] เปิด WO detail จากการ์ด/แถบเวลา
- [ ] แก้ planned date ผ่าน WO edit ที่มี permission และ audit
- [ ] ใช้ `production.orders.view` สำหรับดู และ `production.orders.update` สำหรับแก้แผน
- [ ] ตรวจ Branch/Warehouse scope ฝั่ง Server
- [ ] รองรับอ่านและกรองบน mobile/tablet โดยไม่ต้อง zoom
- [ ] ไม่สร้าง Stock Movement, Reservation หรือ Journal
- [ ] ไม่ทำ finite-capacity scheduling, Shift/Work Center master หรือ auto-create WO ในระยะแรก
- [ ] Contract tests สำหรับ scope, filters, permission และ no-posting side effects
