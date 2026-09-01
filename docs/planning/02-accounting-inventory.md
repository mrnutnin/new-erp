# Accounting and Inventory Plan

## 6. Data and accounting invariants

ข้อกำหนดต่อไปนี้มีความสำคัญกว่าความสั้นของ code:

- เงิน ปริมาณ น้ำหนัก และต้นทุนใช้ `decimal` ตาม precision ที่กำหนด ห้ามใช้ float
- stock เปลี่ยนผ่าน immutable `stock_movements`; balance เป็นผลรวม/projection ที่ rebuild และ reconcile ได้
- posted journal ต้อง debit เท่ากับ credit และห้ามแก้หรือลบ ใช้ reversal entry
- approved/posted document ใช้ cancel/reverse flow ห้าม hard delete
- document number ต้อง unique ต่อ branch/type/period ตาม document setting และออกเลขภายใต้ transaction/row lock
- stock issue/transfer ต้อง lock balance ที่เกี่ยวข้องและบังคับ negative-stock policy
- accounting period ที่ lock แล้วห้าม post เอกสารย้อนหลังโดยไม่มีสิทธิ์และ audit
- ทุก cross-module posting ต้อง retry ได้โดยไม่สร้าง stock movement หรือ journal ซ้ำ
- ทุกการเปลี่ยนสถานะต้องตรวจ allowed transition ฝั่ง server
- authorization ต้องจำกัดทั้ง permission และ branch/warehouse scope
- เวลาเก็บเป็น UTC และแสดงตาม company timezone; วันที่เอกสารธุรกิจแยกจาก timestamp ของระบบ

### 6.1 Inventory costing policy — AVG and FIFO

ระบบต้องรองรับทั้ง `AVG` และ `FIFO` ใน MVP เพราะแต่ละ installation อาจเลือกต่างกัน แต่หนึ่งบริษัทเลือกหนึ่ง policy และ costing pool ของแต่ละ item รวมทุกสาขา/คลัง ห้ามแยกวิธีหรือต้นทุนเฉลี่ยตาม warehouse

- `AVG` ใน MVP หมายถึง perpetual moving weighted average: receipt คำนวณมูลค่าคงเหลือใหม่ ส่วน issue ใช้ average cost ก่อน issue; ห้ามเฉลี่ย quantity/value ด้วย float
- `FIFO` เก็บ immutable cost layers จาก opening/receipt/production return และ consume layer เก่าสุดตาม posting order พร้อม `source_movement_id`, original quantity, remaining quantity และ unit cost ที่ trace ได้
- company global setting กำหนด policy เดียว `AVG` หรือ `FIFO`; ไม่มี costing-group, item, branch, warehouse หรือ transaction override ใน MVP
- document เก็บ costing policy/version ที่ใช้ตอน post เพื่อ reproducible; report ต้องแสดง movement → cost allocation/layer → journal source ได้
- purchase cost ใช้ราคาสุทธิและ landed-cost total ที่ user คำนวณ/กรอกแล้ว พร้อม validation และ audit; material return อ้างอิงต้นทุนของ issue เดิมเมื่อ trace ได้ ไม่ใช้ราคาปัจจุบันโดยอัตโนมัติ
- production material issue ใช้ Inventory costing engine เดียวกัน; finished/semi-finished receipt สร้าง AVG value หรือ FIFO layer จาก actual production cost
- ห้ามเปลี่ยน policy ย้อนหลังแบบแก้ยอดเงียบ การเปลี่ยนต้องมี effective date ณ ต้น fiscal period/open period, approval, audit, conversion/revaluation plan และ reconciliation ก่อน-หลัง
- อนุญาต backdate ได้ตราบใดที่ period ยัง open; period ที่ปิดแล้วต้องให้ฝ่ายบัญชีที่มีสิทธิ์ reopen พร้อม approval/audit ก่อน จึง recost ตามลำดับเวลาใหม่
- global setting เปิด/ปิด negative stock ใช้กับทั้ง AVG และ FIFO: เมื่อเปิดและ issue เกินของคงเหลือ ให้สร้าง provisional negative allocation ด้วย current company cost หรือ configured fallback, mark `cost_status=pending` และ recost เมื่อ receipt/layer จริงมาถึง
- FIFO shortage ใช้ provisional negative layer ที่แยกจาก real FIFO layer ห้ามปลอม acquisition date; AVG ต้องรักษา negative quantity/value อย่าง deterministic ทั้งสองวิธีห้ามปิดงวดขณะยังมี unresolved provisional cost
- Inventory valuation, stock card, COGS/WIP/finished goods และ GL control account ต้อง reconcile ด้วย cost allocations ชุดเดียว ห้ามแต่ละ report คำนวณต้นทุนใหม่เอง
- test fixture เดียวกันต้องรันทั้ง `AVG` และ `FIFO` ครอบคลุม opening, หลายราคาซื้อ, partial issue, return, transfer, production receipt, reversal และ locked period พร้อมยอด inventory/COGS/GL ที่คาดหวัง

### 6.2 Cost propagation, transfer and background recosting

หลักสำคัญคือ cost ต้องเดินตามสินค้าและ dependency ไม่ใช่คำนวณแยกใหม่ที่ปลายทาง การใช้ job มีไว้กระจายผลของการแก้ย้อนหลังและลด request latency แต่ห้ามเป็นจุดเดียวที่ทำให้ transfer มีต้นทุน

#### Transfer cost invariants

- การโอนระหว่าง warehouse/branch ต้องจับคู่ outbound/inbound ด้วย `transfer_id` และ movement reference เดียวกัน; ปริมาณและมูลค่าที่ปลายทางรับต้องเท่ากับ allocation ที่ต้นทางตัด รวม rounding delta ที่ตรวจสอบได้
- เพราะ costing pool รวมทั้งบริษัท การโอนไม่เปลี่ยน company AVG และไม่สร้าง FIFO company layer ใหม่; ย้าย warehouse/location attribution ของ allocation/layer เดิมพร้อมรักษา source lineage
- transfer หลายช่วง เช่น สาขา A → B → C ต้อง trace dependency ย้อนถึง receipt ต้นกำเนิดได้ ห้ามปลายทางสร้าง cost จากราคาซื้อล่าสุดหรือค่า default
- flow คือ `draft → dispatched → accepted|partially_accepted|rejected`: ตอน dispatch ตัด on-hand/available ของต้นทางทันที; ระหว่างรอยืนยันเก็บ pending-transfer quantity/value ในเอกสารเพื่อให้ total-company inventory reconcile แต่ไม่สร้างคลังหรือบัญชี Goods in Transit
- company costing balance นับ `warehouse on-hand + pending-transfer quantity/value` จึงทำให้ AVG/FIFO company pool ไม่เปลี่ยนจากการ dispatch; pending quantity ใช้ขาย/เบิกไม่ได้
- ตอน accept จึงเพิ่ม stock ปลายทาง; ตอน reject ให้ reversal กลับต้นทางด้วย allocation เดิม; partial accept/reject ต้องแบ่ง quantity/value เดิมและ audit ผู้ส่ง/ผู้รับ/เหตุผล
- ระบบไม่มี transfer ข้ามบริษัทหรือ intercompany flow

#### Transfer foundation ที่ติดตั้งแล้ว (MVP pre-posting)

- มีเอกสาร `wms_transfers` และบรรทัด `wms_transfer_lines` สำหรับเก็บต้นทาง/ปลายทาง ปริมาณที่วางแผน และสถานะ `DRAFT → DISPATCHED → ACCEPTED|PARTIALLY_ACCEPTED|REJECTED` (ยกเลิกได้เฉพาะ Draft)
- มี immutable `wms_transfer_events` แยก `DISPATCH`, `ACCEPT`, `REJECT` เพื่อรองรับการรับบางส่วนหลายครั้ง พร้อม `stock_movement_id`, `transfer_id`, `source_reference` และ idempotency key
- มี `TransferMovementService` สำหรับ AVG ที่ lock transfer/line/event และ stock movement ตามลำดับ, ทำ dispatch/accept/partial/reject แบบ idempotent และสร้าง immutable events ผ่าน `StockMovementService`
- FIFO ยังถูก safety-gate ไว้ เพราะปลายทางต้องรับ cost layer lineage เดิม ไม่สร้าง layer ใหม่จากราคาปัจจุบัน; ต้องมี Transfer Cost Lineage Adapter ก่อนเปิด FIFO
- ยังไม่เปิด Controller/route/RBAC/UI จนกว่า FIFO contract, audit integration และ A→B→C integration evidence จะผ่าน

#### Upstream adjustment propagation

- landed cost, supplier price adjustment, backdated receipt, reversal หรือ production-cost variance ต้องสร้าง `cost_recalculation_request` จาก `item/company-wide costing pool + earliest affected movement` ไม่สแกนหรือ recost ทั้งฐานข้อมูล
- dependency graph ขั้นต่ำเก็บใน `cost_allocations` และ source links: receipt → issue, transfer-out → transfer-in, material issue → production output, production output → downstream issue/transfer
- ผล recost ต้องไหลตาม graph ไปยัง warehouse/branch/production/COGS ปลายทางทั้งหมดและสร้าง accounting delta/reversal ตาม open-period policy; ห้าม update GL ที่ post แล้วแบบเงียบ
- cost result ใช้ version/run ID; calculation เดิมเก็บเป็น audit/superseded version เพื่อเปรียบเทียบ before/after และ retry ได้โดยไม่เพิ่มมูลค่าซ้ำ
- locked period ห้าม rewrite cost; ส่วนต่างที่ได้รับอนุมัติ post เป็น adjustment ใน open period ตาม Accounting policy และยัง link กลับไป source/run เดิม

#### Queue and scheduler model

**MVP scope:** ใช้ Queue/Scheduler เฉพาะ Recost และงานเบื้องหลังที่จำเป็นต่อความถูกต้องของ Inventory/GL เช่น การกระจายผลกระทบจากต้นทุนและการ retry งานที่ล้มเหลว งาน Reconciliation และรายงานหนักทั่วไปยังไม่ย้ายเข้า Queue ใน MVP; ให้ทำ synchronous เฉพาะเมื่อมีขอบเขตข้อมูลชัดเจนและไม่กระทบ transaction หลัก หากพบว่าข้อมูลเกิน threshold ให้บันทึกเป็น follow-up ก่อนเปิดใช้งานจริง

- transaction ที่สร้างเหตุ recost บันทึก request/outbox ใน database และ dispatch `RecalculateInventoryCost` หลัง commit; Laravel scheduler เป็น safety net คอยค้น pending/stale requests แล้ว dispatch ซ้ำอย่าง idempotent
- scheduler มีหน้าที่ dispatch งาน ไม่รัน recost ก้อนใหญ่ใน cron process; ใช้ `onOneServer()` และ `withoutOverlapping()` เมื่อ deploy หลาย instance
- ทุก application/worker instance ต้องใช้ shared cache ที่รองรับ atomic lock สำหรับ scheduler/job uniqueness; production-volume deployment ให้ประเมิน Redis เป็น queue/cache แยกจาก transaction database ส่วน database queue ใช้ได้เฉพาะเมื่อ benchmark ผ่าน
- job unique/lock ตาม company-wide item pool เช่น `cost:item:{item_id}` และเรียง movement ตาม business date + posting sequence; คนละ item ทำ parallel ได้ แต่ item เดียวกันทุก warehouse ห้าม overlap
- job ประมวลผลแบบ chunk/keyset มี checkpoint (`last_movement_id`), timeout/retry/backoff ที่กำหนด, resume ได้ และห้ามโหลด movement ทั้ง item เข้า memory
- สถานะ run อย่างน้อย `pending → processing → completed|failed`, เก็บ earliest affected point, attempts, started/finished time, rows processed, before/after value และ error reference
- failed/stale job ต้อง retry ได้จากหน้า admin/command; scheduler ตรวจซ้ำ แต่ unique key และ run version ต้องทำให้ผลเหมือนเดิมเมื่อรันหลายครั้ง
- quantity transaction ใช้งานต่อได้ตาม policy แต่หน้าจอ valuation/report ต้องแสดง `provisional/pending`; period close และ financial report ที่ประกาศ final ต้อง block เมื่อมี pending/failed cost run ที่มีผลต่องวด

#### Performance controls and evidence

- index ตาม query path อย่างน้อย costing pool + posting sequence, source movement, transfer pair, parent layer และ recost status/earliest point; ห้าม query movement ทีละบรรทัดหรือเกิด N+1
- อ่านเฉพาะ columns ที่ใช้, bulk insert/upsert allocation/result เป็น chunk และใช้ aggregate/projection table สำหรับ balance/report โดยยัง rebuild จาก immutable ledger ได้
- benchmark ขั้นต้นรองรับผู้ใช้พร้อมกันมากกว่า 200 คนและตั้งสมมติฐาน stock movements อย่างน้อย 1,000 รายการต่อวันพร้อมหลายสาขาจนกว่า owner จะยืนยันช่วงเวลา; วัด rows/second, lock wait, memory, queue lag, posting latency และเวลาจาก source adjustment ถึงทุกปลายทาง final
- monitoring ต้องแจ้งเตือน pending age, failed runs, repeated retries, reconciliation difference และ queue worker/scheduler หยุดทำงาน
- verification สำคัญ: Unit Test ของ propagation rule และ manual QA receipt ที่ A → transfer A→B → issue/production ที่ B → transfer B→C จากนั้นแก้ landed cost ที่ A แล้ว inventory/COGS/WIP/GL ของ A/B/C ต้องเปลี่ยนครบหนึ่งครั้งและ reconcile เป็นศูนย์

### 6.3 Inventory cost allocation → GL/COGS contract (trading-only MVP)

#### Sales physical boundary

เฉพาะ HS/IV ที่ Post สำเร็จเท่านั้นจึงถือเป็นการขายสินค้าจริงและตัด Stock; RFQ, Quotation และ Sales Order เป็นเอกสารเชิงพาณิชย์ที่ไม่กระทบ Stock/GL. การ Post HS/IV ต้องสร้าง Stock `ISSUE`, cost allocation ตาม AVG/FIFO และ Journal `sales_cogs` พร้อม AR/revenue ใน transaction เดียวและต้อง idempotent. หากสินค้าคงเหลือไม่พอให้หยุดทั้ง transaction พร้อมข้อความแก้ไขที่ผู้ใช้เข้าใจได้ ห้ามตัดบางส่วนหรือสร้าง Journal ค้าง.

#### Soft-delete and history rule

เอกสาร Purchase/Sales, Stock Movement, Cost Layer, Cost Allocation, Journal, Open Item และ Audit เป็นประวัติการเงิน/สต็อกแบบ immutable: ห้ามใช้ SoftDeletes หรือ hard delete เพื่อแก้ข้อมูลที่ออกเลข/อนุมัติ/Post แล้ว ให้ใช้ `VOID` สำหรับเอกสารที่ยังไม่ Post และใช้ reversal/contra record ที่มีเหตุผล ผู้ทำ และ stable identity เมื่อเปิด contract รองรับ ส่วน Item, UOM, Party และ master ที่ยังไม่มีประวัติผูกพันใช้ SoftDeletes หรือ Deactivate ได้ โดยต้องตรวจ domain history, lock และเขียน audit ก่อนทุกครั้ง

ส่วนนี้เป็น contract ขั้นต่ำก่อนเปิดการ Post สต็อกเข้าบัญชี สำหรับบริษัทซื้อมาขายไปที่ยังไม่เปิด Production/WIP โดย `wms_cost_allocations` เป็นแหล่งจริงของต้นทุน และ `journal_entries` เป็นแหล่งจริงของบัญชี ห้ามให้รายงานคำนวณต้นทุนใหม่จาก balance หรือลำดับ FIFO เอง

#### Event และ payload

- `inventory.receipt`: ใช้เฉพาะ receipt ที่ไม่มีเอกสารซื้อซึ่ง post Inventory อยู่แล้ว เช่น opening/adjustment ที่ได้รับอนุมัติ; payload ต้องมี `source_type`, `source_id`, `stock_movement_id`, `warehouse_id`, `item_id`, `uom_id`, `business_date`, `method`, `policy_version`, `allocations[]` และ `posting_date`
- `sales_cogs`: ใช้เมื่อ Sales Invoice/return ที่เป็นสินค้า post สำเร็จ; `allocations[]` ต้องอ้าง allocation ของ issue/return เดิมและรวม `value` ตามบัญชี COGS/Inventory ก่อนสร้าง Journal
- `inventory_adjustment`: ใช้ count/ปรับปรุงที่ไม่ใช่ receipt ซื้อ; ต้องระบุเหตุผลและทิศทาง `GAIN|LOSS`
- Adjustment readiness contract ตรวจ `reason`, source identity, warehouse/item/UOM/date, positive quantity/value, approval, open period และ reconciliation ก่อน resolve mapping `INVENTORY_ADJUSTMENT_GAIN|LOSS`; retry ใช้ normalized posting hash และ payload เปลี่ยนต้องถูก block โดยยังไม่สร้าง Movement/Allocation/Journal
- `InventoryAdjustmentPostingService` เป็น transaction boundary ของ Adjustment แล้ว: Controller ส่ง Adjustment ที่ APPROVED ให้ service สร้าง Movement/Allocation และโพสต์ Journal แบบ idempotent ใน transaction เดียว; Detail แสดง Movement/Allocation/Journal/Audit และ Posted correction ใช้ reversal ledger ชุดใหม่เท่านั้น (ไม่แก้รายการเดิม). ยังคงปิดด้วย `ERP_INVENTORY_ADJUSTMENT_POSTING_ENABLED=false` จนผ่าน manual UI/owner release
- **Adjustment document UX contract:** ปรับจากรายการเดี่ยวเป็นเอกสาร `Adjustment` แบบ header/lines: เลขที่เอกสารและวันที่เอกสารอยู่ที่ header, 1 เอกสารมีหลายรายการสินค้า, อนุมัติ/Post/Reverse ต้องทำแบบ transaction เดียวทั้งเอกสาร, Detail แสดงรายการทั้งหมดและ Document History ภาษาไทย; เลขเอกสารออกผ่าน Document Sequence และเอกสารที่ Post แล้วห้ามแก้/ลบ ใช้ reversal document เท่านั้น
- ทิศทางของ Adjustment เป็นคุณสมบัติระดับ Header (`GAIN` เพิ่มสินค้า หรือ `LOSS` ลดสินค้า) ไม่ให้ผู้ใช้เลือกซ้ำรายบรรทัด เพื่อป้องกันเอกสารหนึ่งใบมีทั้งเพิ่มและลด; migration ต้อง backfill และ reject ข้อมูลเดิมที่มีทิศทางปะปน
- **สถานะ Wave 1–3:** Header/Lines, legacy backfill, sequence type, document DataTable, detail, ประวัติภาษาไทย และ document-level reversal แบบ atomic ลงแล้วบน local; เหลือ local MySQL/manual sign-off ก่อนเปิดใช้งาน Adjustment → GL เต็มรูปแบบ
- `inventory_recost`: ใช้เฉพาะส่วนต่างจาก provisional/ย้อนหลัง โดยอ้าง `recost_request_id`, `parent_allocation_id`, `run_id`, `revision`, `delta_value`, `earliest_affected_date` และ Journal เดิม/Journal delta ที่เกี่ยวข้อง
- ทุก event ต้องส่ง `allocation_ids[]` แบบ explicit; ไม่อนุญาตให้ posting service ค้นหาแล้วเดา allocation จาก item/วันที่

ใน trading-only MVP ใบซื้อที่ post Inventory โดยตรงเป็นเจ้าของรายการ `Dr Inventory / Cr AP`; receipt ที่อ้างใบซื้อนั้นเป็น stock/cost allocation และต้อง link ไปยัง Journal เดิม ไม่สร้าง Journal ซ้ำ ส่วน sales issue เป็น `Dr COGS / Cr Inventory` ผ่าน `sales_cogs` ใน transaction เดียวกับการ post Sales ที่ idempotent

#### Account mapping และ Journal lines

typed mapping foundation ถูกเพิ่มใน Account Mapping แล้ว และต้องตั้งค่าให้ครบก่อนเปิด posting route:

#### แผนมาตรฐานการตั้งค่าการลงบัญชีตาม Feature (Posting Configuration Plan)

แผนกลางถูกแยกไว้ใน [12-feature-posting-configuration-plan.md](12-feature-posting-configuration-plan.md) เพื่อให้ทุก Module ใช้มาตรฐานเดียวกัน โดยยังไม่เริ่ม implementation จนกว่า Owner จะอนุมัติ

`InventoryCostPostingContract` และ `InventoryCostPostingService` ทำหน้าที่ dry-run ตรวจ event, allocation type/direction, final cost status และ mapping ที่ต้องใช้ พร้อมสร้าง payload ที่เรียง allocation/line แบบ deterministic และ `posting_hash` สำหรับตรวจ retry โดยยังไม่สร้าง Journal เพื่อให้ทดสอบ readiness ก่อนเปิด posting จริง ทุกครั้งต้องส่ง `allocation_ids[]` แบบ explicit; ห้าม scan แล้วเดา allocation

ในรอบนี้ `sales_cogs` และ `inventory.adjustment` สร้างได้เฉพาะ preview line ที่ resolve mapping ครบ ส่วน `inventory.receipt` ยังหยุดที่ source-account gate (เพราะใบซื้อเป็นเจ้าของ Dr Inventory/Cr AP) และ `inventory.recost` ยังหยุดจนกว่าจะมี typed revaluation mapping กับ reversal contract ครบ

**Receipt Draft Intent (current MVP boundary):** WMS มีหน้า Receipt Draft สำหรับเลือก Purchase Invoice/line และ Item/UOM ผ่าน Select2 AJAX พร้อม warehouse scope, server-side DataTable, idempotency validation และการแก้ไข Draft ก่อน Post เพื่อเตรียมรับสินค้า แต่ยังไม่อนุมัติหรือ Post Stock Movement/Cost Layer/GL การเปิดรับ Receipt จริงต้องรอ event `supplier_invoice.inventory`, atomic posting/cost allocation, Journal-linkage และ reversal contract; ห้ามให้ผู้ใช้แก้ stock ledger โดยตรง

| Mapping key | บัญชี | ใช้เมื่อ |
|---|---|---|
| `INVENTORY_DEFAULT` | Asset / Inventory control | receipt และ credit/return ที่เพิ่มสินค้าคงเหลือ |
| `COGS_DEFAULT` | Expense / COGS | sales issue และ inventory return ที่ตัดต้นทุน |
| `INVENTORY_ADJUSTMENT_GAIN` | Revenue/Other income | count gain |
| `INVENTORY_ADJUSTMENT_LOSS` | Expense | count loss |
| `INVENTORY_REVALUATION` | ตาม policy ของ Accounting | recost delta ที่ไม่สามารถแก้ในงวดเดิม |

Item-level `inventory_account_id`/`cogs_account_id` ใช้ override ได้เฉพาะเมื่อ account เป็น active/postable และมี control-account type/statement section ที่ compatible; mapping และ account snapshot ที่ใช้จริงต้องอยู่ใน allocation metadata และ posting hash เพื่อให้ retry/reconcile ย้อนตรวจได้

หนึ่ง `sales_cogs` Journal รวมได้หลาย allocation แต่ต้อง aggregate แยกตาม `account_id`, `warehouse_id` และ `direction`; ทุก line ต้องมี `subledger_type=ITEM`, `subledger_id=item_id` และ metadata ที่อ้าง allocation IDs หาก Journal schema ไม่มี column แยก ให้ใช้ metadata JSON ที่ immutable

#### AVG/FIFO/RECOST semantics

- AVG receipt allocation: `direction=IN`, `value=quantity × trusted_unit_cost`; issue allocation: `direction=OUT`, `value` เป็นค่าติดลบของต้นทุนที่ตัด
- FIFO receipt สร้าง layer allocation หนึ่งชุด; issue สร้าง allocation แยกตาม layer ที่ consume และเก็บ `stock_cost_layer_id`, parent/source lineage
- provisional negative allocation มี `cost_status=PENDING`, ห้ามทำ period close และยังไม่ถือเป็น final COGS report
- เมื่อ actual receipt แก้ provisional issue ให้คำนวณ `delta = actual_issue_cost - provisional_issue_cost`; ฝั่ง COGS ใช้ `+delta` และฝั่ง Inventory ใช้ `-delta` เสมอ (delta บวกคือ Dr COGS / Cr Inventory) ห้ามเอา delta เดียวกันไปบวก inventory balance ตรง ๆ
- RECOST ต้องสร้าง immutable delta allocation ที่มี `parent_allocation_id`, `recost_request_id`, `run_id`, `revision` และอ้าง Journal delta/reversal; ห้ามแก้ allocation หรือ Journal ที่ Post แล้ว
- Recost → GL ใน MVP เป็น preflight/fail-closed เท่านั้น: ใช้ typed mapping `INVENTORY_RECOST_GAIN` เมื่อ delta เป็นบวก และ `INVENTORY_RECOST_LOSS` เมื่อ delta เป็นลบ; delta หมายถึง `new_value - old_value`, source identity ต้องประกอบด้วย warehouse/item/request/parent allocation/revision และ hash ต้องคงที่เมื่อ retry payload เดิม
- ก่อนเปิด posting ต้อง lock งวดบัญชีและตรวจ reconciliation ให้ผ่าน, link allocation กับ journal แบบ exact เดียวกัน, และ reversal ต้องสร้าง immutable reversal entry ที่อ้าง `reversal_of`; ห้ามแก้ Journal เดิมหรือสร้าง Journal จาก preflight

#### Lock, idempotency และ reversal

- lock order มาตรฐานคือ `stock_movement → cost_recalculation_request → cost_allocations/layers → warehouse/item balance → journal book → fiscal period`; ทุก caller ต้องใช้ลำดับเดียวกันเพื่อลด deadlock
- idempotency key ต้อง deterministic: `inventory:{event}:{source_id}:allocation:{allocation_id}:revision:{revision}`; ห้ามใช้ UUID เป็นค่าเริ่มต้นของ allocation ที่จะถูก Post เพราะ retry จะสร้าง allocation คนละตัว
- Journal key ใช้ `source_type + event_code + source_id + revision`; payload เดิมคืน Journal เดิม payload ต่างปฏิเสธ
- reversal ไม่ unpost/update: สร้าง reversal allocation และ reversal Journal ด้วย `reversal_of`, เหตุผล, ผู้ทำ, เวลา และ stable key; RECOST ในงวดปิดให้ postเป็น adjustment ในงวดเปิดและ link กลับ run เดิม

#### Reconciliation และ trading-only gate

รายงานกระทบยอดต้องแสดงอย่างน้อยต่อ period/warehouse/item/account:

1. quantity จาก posted stock movements เทียบ allocation quantity
2. valuation จาก final allocations เทียบ `wms_stock_balances.inventory_value` ณวันที่เดียวกัน
3. Inventory control account เทียบ allocation value ที่มี Journal line
4. COGS ใน `sales_cogs` เทียบ issue allocations ของ Sales
5. pending/failed RECOST, unlinked allocation และ difference โดย difference ต้องเป็นศูนย์ก่อน close

บริษัทที่ไม่เปิด Production ให้ gate เฉพาะ Inventory/COGS/AR/AP; ห้ามบังคับ mapping WIP, finished goods หรือ variance จนกว่าจะเปิด capability Production แต่ event และ schema ต้อง reject `production.*` เมื่อ capability ปิด

#### Blockers ก่อน implementation

- **Decision locked (trading-only MVP):** Purchase Invoice ที่เป็นสินค้าใช้ `INVENTORY_DEFAULT / PURCHASE_AP` โดยตรงในจังหวะ Post ใบตั้งหนี้: `Dr Inventory / Cr AP` (และ VAT ตาม tax contract) เมื่อมีเอกสารซื้อแล้วเท่านั้น; MVP ไม่เปิด GRNI และไม่สร้าง Journal จาก Goods Receipt ก่อนใบแจ้งหนี้
- Goods Receipt/รับสินค้าใน WMS เป็น stock movement และ cost-allocation event แยกจาก Accounting จนกว่า Purchase Invoice จะ Post; ห้ามให้ Receipt สร้าง AP, GRNI หรือ Journal ซ้ำเอง
- หากอนาคตต้องรองรับ receipt-before-invoice ให้เพิ่ม typed `GRNI_DEFAULT` mapping, matching/clearing contract, partial receipt/invoice, reversal และ reconciliation ก่อนเปิดใช้งาน; ห้ามอนุมานหรือ fallback จาก `PURCHASE_AP`
- ยืนยันรูปแบบ Journal line linkage แล้ว: ใช้ immutable `wms_cost_allocation_journal_lines` เก็บ `allocation_id`, `journal_entry_line_id`, `revision` และ stable `identity_key`; ต้องตรวจว่า Journal line อยู่ใน Journal เดียวกับ allocation และห้าม overwrite/delete หลัง Post. `allocation_ids[]` และ `reversal_of` ยังคงเป็น payload/metadata ของ posting contract เพื่อรองรับ drill-down และ reversal revision
- แก้ implementation ให้ costing pool เป็น company-wide ตามข้อ 6.1 หรือประกาศ exception ว่า balance/layer แยก warehouse ก่อนเปิด GL integration
- กำหนด owner/permission ของ inventory adjustment gain/loss และ policy ของ RECOST เมื่อ period ปิด

## 7. Accounting-first architecture — บัญชี 5 เล่ม

### Finance master data ที่ต้องมี

Finance แยกเป็น Module แต่ใช้ Accounting เป็น posting kernel กลาง โดยก่อนทำเอกสารรับ/จ่ายต้องมี master ต่อไปนี้:

- Bank/Cash Account: บัญชีเงินสด/ธนาคาร, เลขบัญชี, ธนาคาร, สาขา, currency, บัญชีคุม GL, active และ warehouse scope
- Payment Term: เครดิตกี่วัน, วันครบกำหนด, วิธีคำนวณ และสถานะ active
- Other Income / Other Expense: รายการรายได้/รายจ่ายเบ็ดเตล็ดที่ผูกบัญชีรายได้/ค่าใช้จ่ายและ tax profile
- Document Sequence/Format: เลขที่แยกตาม document type, warehouse และรอบ reset พร้อม format ที่ตรวจสอบซ้ำได้

ทุก master และเมนูของ Finance ต้องมี permission, route middleware, Sidebar visibility, audit และ soft delete ตาม convention เดียวกับ Settings/Accounting

### Report catalogue ที่ต้องรองรับ

รายงานทั้งหมดต้องใช้ filter/scope ตาม Warehouse, period/date, branch และ permission เดียวกับหน้าจอ พร้อม export ที่ใช้ query เดียวกัน:

- **Accounting/Tax:** รายงานหลัก, รายงานเปรียบเทียบรายได้, รายงานภาษีซื้อ, รายงานภาษีขาย, รายงานภาษีสินค้า, รายงานภาษีหัก ณ ที่จ่ายค่าใช้จ่าย, รายงานภาษีถูกหัก ณ ที่จ่าย, รายงานรายได้, รายงานรายได้–รายจ่าย, รายงานค่าใช้จ่าย, รายงานกำไรขาดทุน, รายงานสรุปการเงิน
- **Finance/AR/AP:** รายชื่อลูกหนี้, รายงานบัญชีลูกหนี้, รายชื่อเจ้าหนี้, รายงานบัญชีเจ้าหนี้, รายงานการชำระบิล, รายงานการชำระมัดจำ, รายงานสรุปโครงการ
- **Sales/POS:** รายงานสรุปยอดขายประจำวัน, รายงานสรุปยอดขายลูกค้า, รายงานสรุปจำนวนสินค้าที่ขาย, รายงานการจ่ายคอมมิชชั่น
- **Inventory:** รายงานความเคลื่อนไหวสินค้า, รายงานสินค้าคงเหลือ, รายงานต้นทุนสินค้าและราคาขาย

รายงานที่อ้างอิง subledger ต้อง drill-down กลับเอกสารต้นทางและ reconcile กับ GL ได้; ห้ามคำนวณยอดซ้ำแยกจาก Accounting posting ledger

Accounting ไม่ใช่รายงานที่ค่อยทำหลัง module อื่นเสร็จ แต่เป็น contract กลางที่ต้องกำหนดก่อนเริ่ม transaction จริง ทุก module ต้องตอบได้ว่าเหตุการณ์ใดรับรู้บัญชี วันที่ใด ใช้สมุดใด เดบิต/เครดิตบัญชีใด และย้อนกลับอย่างไร

### 7.1 นิยามบัญชี 5 เล่มในผลิตภัณฑ์

ระบบใช้สมุดรายวัน 5 ประเภทบน General Ledger ชุดเดียว:

| Code | สมุดรายวัน | รายการหลัก |
|---|---|---|
| `PJ` | สมุดรายวันซื้อ | ใบแจ้งหนี้เจ้าหนี้ ซื้อสินค้า/บริการ ใบลดหนี้ซื้อ |
| `SJ` | สมุดรายวันขาย | ใบแจ้งหนี้ลูกหนี้ ขายสินค้า/บริการ ใบลดหนี้ขาย |
| `CR` | สมุดรายวันรับเงิน | รับชำระลูกหนี้ รับล่วงหน้า รายรับอื่น และเงินเข้าธนาคาร |
| `CP` | สมุดรายวันจ่ายเงิน | จ่ายเจ้าหนี้ ค่าใช้จ่าย เงินทดรอง คืนเงิน และเงินออกธนาคาร |
| `GJ` | สมุดรายวันทั่วไป | ยอดยกมา ปรับปรุง ปิดบัญชี ค่าเสื่อม ผลิต สต็อก และรายการที่ไม่เข้ารายวันเฉพาะ |

รหัสเดิมของ `minterp` (`PI/IV/RV/PV/JV`) ใช้สำหรับ migration mapping เท่านั้น ระบบใหม่ใช้ code มาตรฐานด้านบนและแสดง label ภาษาไทยจาก configuration

สมุดทั้งห้าเป็น classification/reporting view ของ `journal_entries` ไม่ใช่ ledger แยกกัน ยอดทุกเล่มต้องไหลเข้าสมุดบัญชีแยกประเภทและงบทดลองเดียวกัน

ข้อกำหนดตามกฎหมายอาจต่างตามประเภทผู้มีหน้าที่จัดทำบัญชี ก่อน production release ต้องให้ผู้ทำบัญชีหรือผู้สอบบัญชีไทยตรวจ chart, book format, tax point, report columns และ retention policy อีกครั้ง แหล่งอ้างอิงตั้งต้นคือประกาศกรมพัฒนาธุรกิจการค้าเรื่องชนิดและรายการที่ต้องมีในบัญชี และแนวทางกรมสรรพากรเรื่องสมุดรายวัน/รายงานภาษี

- [ประกาศกรมพัฒนาธุรกิจการค้าเรื่องชนิดและรายการที่ต้องมีในบัญชี](https://www.dbd.go.th/data-storage/attachment/c23937b2414a61d510165735b.pdf)
- [กรมสรรพากร: งบทดลองและสมุดรายวันที่เป็นแหล่งรายการ](https://www.rd.go.th/region/10/roiet/306/%E0%B8%87%E0%B8%9A%E0%B8%97%E0%B8%94%E0%B8%AA%E0%B8%AD%E0%B8%87-%E0%B8%AA%E0%B8%97%E0%B8%A3%E0%B9%89%E0%B8%AD%E0%B8%A2%E0%B9%80%E0%B8%AD%E0%B9%87%E0%B8%94.html)

### 7.2 Accounting data model

ขั้นต่ำต้องมี:

- `account_types` และ `accounts`: code, name, parent, level, normal balance, postable flag, statement section และ control-account type
- `accounting_profiles`: PAE/NPAE, tax/report configuration และ effective date; ไม่แยก ledger คนละชุด
- `fiscal_years` และ `fiscal_periods`: open/soft-close/locked ระดับทั้งบริษัทพร้อม lock metadata ห้ามปิดแยกสาขา
- `journal_books`: 5 system books ต่อ installation พร้อม sequence และ active state
- `journal_entries`: branch dimension, book, number, entry/document date, source reference, description, currency/rate, status, posted/reversed metadata และ idempotency key
- `journal_entry_lines`: account, party, branch, cost center/project, description, debit, credit, currency amount, tax dimension และ sequence
- `posting_rules`/`account_mappings`: mapping เฉพาะ accounting event ที่ระบบรองรับ ไม่สร้าง generic formula engine ใน MVP
- `opening_balances`: import ผ่าน journal entry ที่ตรวจสอบและ audit ได้ ไม่เขียนยอดเข้าบัญชีโดยตรง

ค่าการเงินทุกช่องใช้ `decimal`; `debit` และ `credit` ห้ามเป็น `varchar` แบบ `minterp` และหนึ่งบรรทัดต้องมีค่าเป็นบวกเพียงฝั่งเดียว ระบบต้องไม่เก็บ `sum_debit/sum_credit` เป็นแหล่งจริงที่แก้แยกจาก lines

### 7.3 Journal lifecycle

```text
Draft → Validated → Posted → Reversed
```

- Draft แก้ได้และยังไม่กระทบ GL
- Posted ต้อง balanced, อยู่ใน period ที่เปิด, ใช้ postable accounts และ immutable
- ยกเลิกเอกสารต้นทางหลัง post ต้องสร้าง reversal ที่กลับเดบิต/เครดิตและอ้าง entry เดิม
- ห้าม unpost หรือ hard delete เพื่อแก้ประวัติ
- ฝ่ายบัญชีที่ได้รับ permission สามารถ approve Manual `GJ`, reopen period และอนุมัติ cost adjustment; ทุก action ต้องมีเหตุผลและ audit log
- ปิด/reopen period ครั้งเดียวทั้งบริษัท ไม่อนุญาตสถานะ period ต่างกันตามสาขา

Database/application constraints ที่ต้องบังคับ:

- `SUM(debit) = SUM(credit)` ตาม company precision ก่อน commit
- unique entry number ต่อ book/period และ unique idempotency key ต่อ source event
- entry date อยู่ใน fiscal period ที่เปิด
- account เป็น leaf/postable ใน chart ของ installation
- source document หนึ่ง event post ซ้ำไม่ได้ แม้ request/queue retry
- control account ต้องมี party/subledger reference ตามชนิด
- ห้ามแก้ account, amount, date, source และ dimension ของ posted entry

### 7.4 Posting contract

Operational modules ห้าม insert `journal_entries` หรือ `journal_entry_lines` โดยตรง ให้เรียก synchronous Accounting posting service ภายใน transaction เดียวกับการเปลี่ยนสถานะสำคัญ หรือใช้ transactional outbox ที่รับประกันผลครั้งเดียวเมื่อ transaction เดียวทำไม่ได้

Finance รับ/จ่ายเงินจริงต้องเรียก `SettlementPostingService` โดยใช้ event `customer_payment` หรือ `supplier_payment` เท่านั้น; service จะยึดวันที่รับ/จ่ายเป็น `entry_date` และเติม `tax_settlement_date` ให้ tax lines ที่ยังไม่ระบุ ก่อนส่งต่อ `JournalPostingService` แบบ idempotent

ทุก event ใน accounting event matrix ต้องกำหนด:

- event code และ recognition point
- journal book
- posting date rule และ fiscal-period rule
- debit/credit account source
- party, branch, warehouse, cost center/project dimensions
- tax treatment และ rounding
- source reference/idempotency key
- reversal event

ไม่สร้าง rule engine ที่ให้ผู้ใช้เขียนสูตรอิสระใน MVP ใช้ typed posting events และ account mapping ที่ตั้งค่าได้ เพื่อให้ตรวจสอบและทดสอบได้

Posting contract รุ่นแรกเป็น synchronous service ภายใน modular monolith โดย caller ส่ง `event_code`, stable `source_type/source_id`, วันที่, Warehouse และ journal lines; Accounting เป็นผู้เลือกสมุดจาก typed event map และสร้างรายการ `POSTED` สกุลเงินฐานของบริษัทเท่านั้น

- `idempotency_key` derive จาก event/source identity และมี unique constraint; retry payload เดิมคืน entry เดิม
- เก็บ `posting_hash` ของ normalized accounting payload; key เดิมแต่ payload เปลี่ยนต้องปฏิเสธเพื่อไม่ซ่อนยอดที่ไม่ตรงกัน
- control account ทุกบรรทัดต้องมี `subledger_type/subledger_id`; การ reconcile และ foreign key ไป customer/supplier/item/asset เพิ่มเมื่อ master ของ domain นั้นพร้อม
- caller ต้องครอบ posting service และการเปลี่ยนสถานะเอกสารต้นทางใน database transaction เดียวกัน; ไม่มี HTTP route สำหรับ bypass contract
- multi-currency, tax dimension, cost center และ project dimension เพิ่มใน event use case ที่ยืนยัน field/recognition rule แล้ว ไม่เพิ่ม placeholder ที่ยังตรวจสอบความถูกต้องไม่ได้

รูปแบบเรียกใช้จาก operational module:

```php
$entry = DB::transaction(function () use ($posting, $warehouse, $user, $document) {
    $entry = $posting->post([
        'source_type' => 'WMS',
        'source_id' => (string) $document->id,
        'source_reference' => $document->number,
        'event_code' => 'supplier_invoice.inventory',
        'entry_date' => $document->posting_date->format('Y-m-d'),
        'document_date' => $document->document_date->format('Y-m-d'),
        'description' => $document->description,
        'lines' => [
            ['account_id' => $inventoryAccountId, 'subledger_type' => 'ITEM', 'subledger_id' => (string) $itemId, 'debit' => '100.00', 'credit' => '0.00'],
            ['account_id' => $payableAccountId, 'subledger_type' => 'SUPPLIER', 'subledger_id' => (string) $supplierId, 'debit' => '0.00', 'credit' => '100.00'],
        ],
    ], $warehouse, $user);

    $document->update(['status' => 'POSTED']);

    return $entry;
});
```

ยกเลิก event ที่ Post แล้วเรียก `JournalPostingService::reverse($entry, ['source_type' => ..., 'source_id' => ..., 'reversal_date' => ..., 'reason' => ...], $actor)` ด้วย stable cancellation identity; retry เดิมคืน reversal เดิม ส่วน identity เดิมที่ payload เปลี่ยนถูกปฏิเสธ

#### 7.4.2 Payment Voucher → Settlement contract (locked before implementation)

Payment Voucher เป็นเอกสารควบคุมการขอจ่าย/อนุมัติ ไม่ใช่เอกสารลงบัญชี และห้ามสร้าง Journal หรือ Open Item จาก Voucher โดยตรง การลงเงินจริงต้องสร้าง `Settlement` ประเภท `PAYMENT` แล้วให้ `SettlementPostingService` เป็น single write path เท่านั้น

- Voucher `DRAFT -> SUBMITTED -> APPROVED` จึงสร้าง Settlement ได้; `VOID` และ Voucher ที่มี `settlement_id` แล้วห้ามสร้างซ้ำ
- Voucher ต้องเก็บรายละเอียด line allocation แบบ immutable snapshot เมื่อสร้าง Settlement: `open_item_id`, จำนวนเงิน, line number และ source voucher identity; ห้ามแก้ allocation หลัง Settlement ถูก approve/post
- Settlement ต้องอ้าง `source_type=FINANCE`, `source_id=settlement:{id}` ตาม contract ปัจจุบัน และ Journal ใช้ event `supplier_payment`; Voucher เก็บ `settlement_id` เป็น one-to-one reference เพื่อ drill-down และ idempotency เท่านั้น
- Payment Voucher แบบ `PAYMENT` จัดสรรได้เฉพาะ AP Open Item ของ Supplier เดียวกัน Warehouse เดียวกัน และยอด allocation ต้องเท่ากับยอด Settlement; ห้ามเลือก AR หรือ Open Item ต่าง scope
- แบบ `PRE_PAYMENT` ยังไม่ตัด AP invoice: ให้สร้าง Settlement เงินจ่ายล่วงหน้า/เงินมัดจำใน subledger แยกต่างหาก และห้ามใช้ `finance_allocations` ผูกกับ invoice จนกว่าจะมี advance application contract
- การ retry ต้อง lock Voucher และ Settlement ตามลำดับเดียวกัน, ตรวจ `settlement_id`, `journal_entry_id` และ allocation source identity; payload เดิมคืนเอกสารเดิม ส่วน payload เปลี่ยนต้องปฏิเสธ
- Void Voucher ที่ยังไม่มี Settlement ทำได้ตาม approval policy; Voucher/Settlement ที่ Post แล้วห้ามแก้หรือ unpost ให้ใช้ reversal contract พร้อม audit และสิทธิ์แยก
- การสร้าง Settlement จาก Voucher ต้องอยู่ใน transaction เดียวกับการบันทึก line snapshot และการผูก `settlement_id`; ห้ามสร้าง Settlement แล้วค่อยผูก Voucher ภายหลังแบบ best effort

**MVP gate:** ยังไม่เปิดปุ่ม/route “สร้าง Settlement จาก Voucher” จนกว่าจะมี schema สำหรับ voucher lines และ advance/deposit subledger ครบ การเพิ่ม route ก่อน gate นี้เสี่ยงสร้างเอกสารที่ไม่มี Open Item/บัญชีคู่ตรงกัน

#### 7.4.3 Advance/deposit subledger contract (foundation)

เงินรับล่วงหน้าของ Customer และเงินจ่ายล่วงหน้าของ Supplier เป็นยอดคงค้างคนละ subledger กับ AR/AP invoice จนกว่าจะมีการนำไปตัดเอกสาร ระบบจึงเก็บ `finance_advance_deposits` และ `finance_advance_deposit_applications` แยกจาก `finance_open_items`/`finance_allocations` โดยยังไม่เดา GL account หรือสร้าง Journal จากตารางนี้เอง

- Customer ใช้ `direction=RECEIPT` เท่านั้น; Supplier ใช้ `direction=PAYMENT` เท่านั้น
- `instrument_type` แยก `ADVANCE` กับ `DEPOSIT`; เอกสารต้องมี Warehouse, Party, จำนวนเงินบวก, เลขเอกสาร และ idempotency key
- สร้างจาก Settlement ที่ Post แล้วได้ครั้งเดียวด้วย `source_settlement_id` แบบ unique; Settlement ต้องเป็นประเภท RECEIPT สำหรับ Customer หรือ PAYMENT สำหรับ Supplier และอยู่ใน scope เดียวกัน
- สถานะคือ `DRAFT -> POSTED -> PARTIAL -> APPLIED`; เอกสารที่ยังไม่ Post ยกเลิกได้ด้วย `VOID`; เอกสารที่ Post แล้วห้ามแก้ทับ ให้สร้าง reversal record พร้อมเหตุผล ผู้ทำ เวลา และ stable reversal key
- การนำไปตัดต้องสร้าง application snapshot ที่อ้าง Advance/Deposit และ AP/AR Open Item โดยตรง, ห้ามเขียน `finance_allocations` แทน และยอดรวม application ที่ยังไม่ reverse ห้ามเกินยอดตั้งต้น
- Customer advance ใช้ตัด AR invoice/credit note ตามกติกา Party/Warehouse; Supplier prepayment ใช้ตัด AP invoice/credit note ตามกติกาเดียวกัน ห้ามข้าม Party, Warehouse หรือ ledger type
- Retry ต้องคืน record เดิมเมื่อ identity และ payload เดิมตรงกัน; payload เปลี่ยนต้องปฏิเสธ และ application/reversal ต้องมี idempotency แยกของตนเอง
- Reconciliation ต้องตรวจยอดคงเหลือของ subledger กับบัญชีพัก Customer Advances/Supplier Advances เมื่อ Account Mapping และ posting contract ถูกล็อกแล้ว จึงค่อยเปิด Journal/Settlement integration และรายงาน

**Foundation gate:** รอบนี้เพิ่มเฉพาะ schema, model และ pure validation contract; ยังไม่มีเมนู/permission/route สำหรับสร้างหรือ apply จนกว่าจะกำหนด account mapping, Settlement POST integration, application reversal และ reconciliation service ครบ

- Advance/Deposit mapping foundation: ใช้ `CUSTOMER_ADVANCE` (บัญชีประเภท LIABILITY) และ `SUPPLIER_ADVANCE` (บัญชีประเภท ASSET) ที่ผู้ใช้ตั้งผ่าน Account Mapping เท่านั้น; การตัดกับ AR/AP ใช้ `SALES_AR`/`PURCHASE_AP` และยังไม่เปิด posting/application จนกว่า reversal และ reconciliation contract จะครบ
- Cross-module gate: `customer_advance` ใน `PostingEvent` เป็นชื่อ event ที่สงวนไว้สำหรับ adapter ในอนาคตเท่านั้น; ยังไม่มี Settlement integration และห้ามถือว่าการมี event/mapping เป็นการเปิด Post. Supplier advance จะต้องมี typed event เพิ่มพร้อม implementation/reversal ครบในงานเดียวกัน ไม่ใช้ fallback ไปที่ `supplier_payment` หรือ `customer_advance`

การเชื่อม Settlement ระยะนี้มีเพียง `AdvanceDepositSettlementService::assertPostedSource()` เป็น boundary สำหรับตรวจเอกสารที่ลงบัญชีแล้ว: ต้องเป็น Receipt ของ Customer หรือ Payment ของ Supplier ใน Warehouse เดียวกัน, ไม่มี AR/AP allocation, ไม่มี VAT/WHT และยอดรับ/จ่ายเต็มจำนวน พร้อม idempotency key ที่คำนวณซ้ำได้ การตรวจนี้ยังไม่สร้าง subledger row และไม่มี route/UI เพื่อไม่ให้ยอด Advance/Deposit เกิดขึ้นโดยไม่มี GL mapping, reversal และ reconciliation ที่ครบถ้วน

#### 7.4.1 Wave 2 invoice contract (locked)

- Sales และ Purchase แยก schema/service ตาม domain และใช้ร่วมเฉพาะ Accounting/Finance services; ไม่สร้าง generic invoice engine
- รอบนี้ Post ได้ service/expense invoice และ credit note แบบ `NONE_VAT`, Purchase `VAT_IN` และ Sales `VAT_OUT` ด้วย Deferred VAT; Settlement realization เชื่อม partial/final rounding แล้ว ส่วน WHT source snapshot, inventory invoice, Item และ COGS ยังรอ contract
- ห้าม Post จนกว่าจะมี typed mappings `SALES_AR`, `SALES_REVENUE_DEFAULT`, `PURCHASE_AP`, `PURCHASE_EXPENSE_DEFAULT`; ห้าม hard-code account ID หรือเลือกบัญชีแรกที่พบ
- เลขเอกสารใช้ `DocumentSequenceService` type `SALES_INVOICE`, `SALES_CREDIT_NOTE`, `PURCHASE_INVOICE`, `PURCHASE_CREDIT_NOTE`; ออกครั้งเดียวตอนสร้าง Draft และยอมรับเลขขาด
- workflow คือ `DRAFT -> APPROVED -> POSTED`; Draft/Approved เปลี่ยนเป็น `VOID` ได้ และ Post ต้องเกิดใน transaction เดียวกับ Journal/Open Item
- credit note ต้องอ้าง invoice ที่ Post แล้วของ Warehouse/Party เดียวกัน, สร้าง contra Open Item และ allocate โดยห้ามเกินยอดคงเหลือ
- ยังไม่เปิด source-invoice reversal เพราะ Journal reversal ยังไม่รักษา tax dimensions และยังไม่มี Open Item reversal contract; รอบนี้ใช้ credit note แทน
- VAT-at-payment ต้องมี `DEFERRED_INPUT_VAT`/`DEFERRED_OUTPUT_VAT` mappings และให้ Finance Settlement realize VAT ตาม allocation จริง; ตอนนี้ Purchase `VAT_IN` และ Sales `VAT_OUT` ลง Deferred VAT ได้แล้ว ส่วนการรับรู้ VAT จริงใน Settlement ยังปิดอยู่

### 7.5 Minimum posting matrix

| Event | Book | Debit | Credit |
|---|---|---|---|
| Supplier invoice: inventory | PJ | Inventory, Input VAT | Accounts Payable |
| Supplier invoice: expense/service | PJ | Expense/Asset, Input VAT | Accounts Payable |
| Purchase credit note | PJ | Accounts Payable | Inventory/Expense, Input VAT |
| Sales invoice | SJ | Accounts Receivable | Revenue, Output VAT |
| Cost of goods sold | SJ | Cost of Goods Sold | Inventory |
| Sales credit note/return | SJ | Revenue, Output VAT | Accounts Receivable |
| Receive customer payment | CR | Cash/Bank, Withholding Tax Receivable | Accounts Receivable |
| Receive customer advance | CR | Cash/Bank | Customer Advances |
| Pay supplier | CP | Accounts Payable | Cash/Bank, Withholding Tax Payable |
| Pay expense/petty cash | CP | Expense/Input VAT | Cash/Bank |
| Inventory adjustment | GJ | Inventory loss/Inventory | Inventory/Inventory gain |
| Material issue to production | GJ | Work in Process | Raw Material Inventory |
| Finished production receipt | GJ | Finished Goods Inventory | Work in Process/Production variance |
| Asset depreciation | GJ | Depreciation Expense | Accumulated Depreciation |
| Period adjustment/closing | GJ | ตามรายการปรับปรุง | ตามรายการปรับปรุง |

Matrix นี้เป็น baseline; account จริงมาจาก company settings และ chart template ไม่ hard-code account ID ใน Item หรือ controller

ขายเงินสดต้องไม่ post ซ้ำหรือข้ามสมุด: ระบบสร้าง `SJ` สำหรับ invoice และสร้าง/allocate `CR` สำหรับ receipt แบบจับคู่ใน use case เดียวกัน ซื้อเงินสดใช้ `PJ + CP` ด้วยหลักเดียวกัน

### 7.6 Subsidiary ledgers and reconciliation

ระบบต้องมีรายละเอียดประกอบ GL และ reconcile ได้:

- AR subledger แยกตาม customer/invoice/credit note/receipt allocation
- AP subledger แยกตาม supplier/invoice/credit note/payment allocation
- Inventory quantity report แยก item/warehouse/lot แต่ใช้ company-wide item cost pool และรวม valuation reconcile กับ Inventory control account
- Asset register/depreciation reconcile กับ Asset cost และ Accumulated depreciation accounts
- Cash/bank book และ bank reconciliation แยกตามบัญชีธนาคาร
- Tax subledger แยก VAT input/output, tax invoice และ withholding tax certificate

ห้ามให้ยอด AR/AP/Stock/Asset/Cash มีแหล่งจริงคนละชุดโดยไม่มี reconciliation report กับ GL

#### 7.6.1 Inventory cost allocation → GL contract (locked before posting)

> Contract หลักของ event, mapping, lock, idempotency และ reconciliation อยู่ที่ §6.3; ส่วนนี้เป็น operational checklist ที่ใช้ตรวจ implementation และ period-close โดยห้ามสร้างกติกาที่ขัดกับ §6.3

**ขอบเขตต้นทุนที่ยืนยันสำหรับ MVP:** `AVG` หรือ `FIFO` เป็นนโยบายเดียวระดับบริษัทและห้ามตั้งคนละวิธีต่อคลัง แต่ cost pool/ยอดคงเหลือยังแยกตาม `warehouse_id` เพื่อรักษามิติการปฏิบัติงานและการกระทบยอดรายคลัง การโอนระหว่างคลังต้องรักษา cost lineage เดิมและไม่เฉลี่ยต้นทุนข้ามคลังโดยอัตโนมัติ; การรวมต้นทุนข้ามคลังเป็นรายงานระดับบริษัทเท่านั้นจนกว่าจะมี contract ใหม่

ก่อนเปิดใช้การ Post สต็อกเข้า GL ต้องมี cost allocation ledger เป็นแหล่งกลางของมูลค่าต้นทุน ไม่ให้รายงานหรือ controller คำนวณยอดใหม่จาก `stock_balances` หรือ FIFO layers เอง โดย allocation หนึ่งรายการต้องอ้างอิงอย่างน้อย:

- `stock_movement_id`, item, warehouse, business date, costing method/version และ source document
- ทิศทาง `RECEIPT`, `ISSUE`, `RETURN`, `ADJUSTMENT`, `TRANSFER` หรือ `PRODUCTION` พร้อม quantity, unit cost และ signed value ที่เก็บทศนิยมแบบ decimal
- FIFO layer allocation หรือ AVG calculation lineage; กรณี negative stock ต้องระบุ provisional/recost status และ request ที่เกี่ยวข้อง
- stable idempotency identity ต่อ movement/event/recost revision และลิงก์ `journal_entry_id` เมื่อ Post สำเร็จ; posted allocation ห้ามแก้ ให้สร้าง delta/reversal ใหม่

ลำดับการทำงานที่บังคับใช้:

1. WMS Post movement และ costing engine สร้าง allocation ที่ immutable ภายใน transaction เดียวกัน
2. WMS ตรวจ account mapping ที่ active/postable จาก Item/Category/Global Settings ตาม typed event; ถ้า inventory หรือ COGS mapping ขาด/ผิดประเภทให้หยุดทั้ง transaction ห้ามเลือกบัญชีแรกหรือ hard-code account ID
3. เรียก `JournalPostingService` ด้วย event เช่น `inventory.receipt`, `inventory.issue`, `inventory.return`, `inventory.adjustment` หรือ `production.material_issue` เพื่อสร้าง journal แบบ idempotent; WMS ห้าม insert journal tables โดยตรง
4. บันทึกผล allocation ↔ journal line และสถานะ `PENDING`, `POSTED`, `REVERSED`, `REQUIRES_RECOST`; retry payload เดิมต้องคืนผลเดิม และ payload ที่เปลี่ยนต้องถูกปฏิเสธ
5. เมื่อ receipt/landed cost/backdated source ทำให้ต้นทุนเปลี่ยน ให้สร้าง recost revision และ accounting delta/reversal ในงวดเปิดเท่านั้น ไม่ update journal เดิมแบบเงียบ

Minimum journal mapping (บัญชีจริงมาจาก typed mapping ไม่ใช่ค่าคงที่): receipt คือ `Dr Inventory / Cr AP-clearing หรือ source account`, issue คือ `Dr COGS / Cr Inventory`, return/adjustment ใช้ event และ mapping เฉพาะที่ระบุทิศทางชัดเจน ส่วน transfer ต้องรักษา company-wide cost lineage และไม่สร้างกำไรขาดทุนจากการย้ายคลัง

Reconciliation ต้องคำนวณจาก allocation ledger ชุดเดียวกัน: quantity/value ตาม item + warehouse + date เทียบกับ stock projection และยอดสุทธิของ Inventory/COGS journal lines เทียบกับ GL control accounts โดยแสดง unmatched allocation, pending recost, missing mapping และ rounding delta แยกกัน ห้ามปิดงวดเมื่อรายการเหล่านี้ยังไม่เป็นศูนย์ตาม policy

Final verification: `InventoryReconciliationCalculator` ต้อง block เมื่อ allocation-vs-GL, balance-vs-allocation หรือ unlinked allocation ไม่เป็นศูนย์; `InventoryPurchaseProductionAdapter::defaultReconciliationGate` ตรวจ pending allocation เพิ่มอีกชั้นก่อน transaction และ feature flag ของ Inventory Purchase ยังคงปิดจนกว่าจะผ่าน release evidence

#### 7.6.2 Cost allocation ledger foundation (implemented, GL deferred)

`wms_cost_allocations` เป็น immutable valuation ledger สำหรับ movement ที่ Post แล้ว โดยเก็บ movement → FIFO layer/AVG calculation → allocation, method/policy version, cost status, signed value, recost parent และ idempotency key รวมถึง `journal_entry_id` ที่ยังว่างได้ในช่วงที่ยังไม่มี typed Inventory/COGS mapping โดย migration นี้ไม่สร้าง Journal และไม่เลือกบัญชีอัตโนมัติ

- Receipt/issue ของ AVG และ FIFO บันทึก allocation ใน transaction เดียวกับ stock projection; FIFO เก็บ layer lineage และ negative stock เก็บ provisional allocation
- Recost บันทึกเป็น allocation `RECOST` แยกจากรายการเดิมพร้อม parent/request identity ห้ามแก้ allocation เดิมทับ
- Historical valuation ใช้ allocation ledger ด้วย `business_date <= as-of` และไม่นับรายการที่ reversed; รายการหลักที่จะเปิดเป็น DataTable ต้องใช้ query builder/server-side ไม่โหลดทั้งชุดด้วย `get()`
- Historical valuation read path ต้องแยก `final_value/final_quantity` ออกจาก `pending_value/pending_count`; ห้ามใช้ `wms_stock_balances` เป็น historical source และห้ามนับ provisional allocation เป็นยอด final ก่อน recost เสร็จ
- `status=PENDING` หมายถึงรอ accounting posting contract ไม่ใช่การยืนยันว่า GL post สำเร็จ; การเปิด GL ต้องเพิ่ม typed mapping validation และเรียก `JournalPostingService` จาก WMS service เท่านั้น
- ก่อนเปิด posting ต้องผ่าน read-only preflight ต่อ movement: movement เป็น `POSTED`, item มี Inventory control account และ COGS เป็น Expense เมื่อเป็น issue, มี allocation, ไม่มี provisional pending cost และ allocation ทุกตัวมี Journal link; preflight ห้ามสร้าง Journal หรือแก้ยอด และรายการไม่ผ่านต้องแสดง blocker ให้แก้ที่ต้นทาง
- ขอบเขต local MVP แยกชัดเจน: Purchase/GR/Inventory Adjustment เป็น source ที่พิจารณาเปิด Inventory → GL; Issue, Issue Return และ WMS Transfer ที่ยังไม่มี source posting contract จะแสดงเป็น `Deferred` ใน preflight และยัง block global/production release ห้ามซ่อนหรือสร้าง Journal ทดแทน

### 7.7 Accounting reports required for MVP

- สมุดรายวันซื้อ, ขาย, รับเงิน, จ่ายเงิน และทั่วไป พร้อมเลขที่ วันที่ คู่ค้า เอกสารอ้างอิง เดบิต เครดิต และผู้บันทึก
- บัญชีแยกประเภททั่วไป แสดงยอดยกมา รายการเคลื่อนไหว และยอดคงเหลือรายบัญชี
- งบทดลอง: opening, period debit/credit และ closing พร้อมตรวจ debit=credit
- รายละเอียดลูกหนี้/เจ้าหนี้และ aging ที่ reconcile กับ control accounts
- รายงานภาษีซื้อ ภาษีขาย และภาษีหัก ณ ที่จ่ายจาก tax subledger
- รายงานกระทบยอด inventory valuation, asset register และ cash/bank กับ GL
- งบกำไรขาดทุนตามช่วงเวลา
- งบดุล ณ วันที่ พร้อม retained earnings/current-period profit ที่ถูกต้อง
- Financial statements ใช้เฉพาะ journal ที่ `POSTED` และกรอง Warehouse session เดียวกับ GL; P&L แสดงรายได้/ค่าใช้จ่ายตาม statement section และ normal balance ส่วน Balance Sheet ใช้ยอดสะสมถึงวันสิ้นงวด พร้อมแสดงกำไร(ขาดทุน)สะสม/งวดปัจจุบันเป็น calculated component ใน summary โดยไม่สร้าง journal ซ้ำ
- Tax MVP contract: รองรับ VAT IN, VAT OUT และ NONE VAT; เอกสารเลือก VAT-inclusive หรือ VAT-exclusive ได้ต่อเอกสาร; Tax point ใช้วันที่เอกสาร; VAT จะ post เข้า GL ตอนรับ/จ่ายเงินจริงตามวิธีชำระเงิน; WHT รองรับอัตราและประเภทแบบ custom และหักตอนรับเงินจริง; e-Tax/e-Withholding อยู่นอก MVP; การปัดเศษทำทั้งระดับบรรทัดและเอกสารตามจำนวนทศนิยมใน Global Settings
- Cash flow statement เป็น direct/indirect mapping ที่ตั้งค่าได้หลัง P&L และ Balance Sheet ผ่าน reconciliation
- drill-down จากงบ → account → journal entry → source document และย้อนกลับได้
- comparative period/previous year และ export Excel/PDF โดยผลรวมต้องตรงกับหน้าจอ

### 7.8 Period close

ก่อน lock period ระบบต้องตรวจ:

1. ไม่มี source document ที่ควร post แต่ยังไม่ post หรือ post fail
2. ทุก journal balanced และไม่มี draft ที่ต้องรับรู้
3. AR/AP reconcile กับ control accounts
4. Stock quantity/valuation reconcile กับ Inventory accounts
5. Cash/bank reconciliation อยู่ในสถานะที่กำหนด
6. VAT/withholding tax reports reconcile กับ GL
7. Depreciation และ production/WIP posting ครบ
8. บันทึก accrual, prepaid, FX/rounding และ adjustment ที่จำเป็น
9. สร้าง trial balance และ financial statements ผ่าน validation

สถานะ period คือ `open → soft_close → locked`; soft close ให้เฉพาะผู้มีสิทธิ์ post adjustment และ locked ห้าม post ทุกกรณียกเว้น reopen แบบมี approval/audit

### 7.9 Accounting acceptance scenarios

- แต่ละสมุดมีอย่างน้อยหนึ่ง end-to-end scenario จาก source document ถึง GL/report
- cash sale สร้าง `SJ + CR` เพียงครั้งเดียวเมื่อ retry
- cash purchase สร้าง `PJ + CP` เพียงครั้งเดียวเมื่อ retry
- unbalanced, non-postable account และ locked-period entry ถูกปฏิเสธ
- reversal กลับยอดครบและยังรักษา audit trail
- AR/AP/Inventory/Asset/Cash control accounts reconcile เป็นศูนย์
- trial balance, P&L และ Balance Sheet คำนวณจาก posted lines ชุดเดียวกัน
- rounding/tax/withholding test ครอบคลุม precision และเอกสารหลายบรรทัด

Scenario ด้าน calculation, posting rule, balance, classification, rounding และ reversal ที่แยกจาก framework ได้ให้เขียนเป็น Unit Test ส่วนการเชื่อม database transaction, constraint, route, permission และรายงานจริงให้ตรวจด้วย manual acceptance checklist ตาม testing policy ด้านล่าง

### 7.10 Migration from Express/WinSpeed via ERP Excel templates

Express และ WinSpeed เป็นตัวอย่างระบบเดิมที่ลูกค้าอาจเลิกใช้ เป้าหมายคือย้ายข้อมูลเข้ามาเริ่มงานใน ERP นี้ได้ง่าย ไม่ใช่ทำ synchronization หรือส่งบัญชีกลับไปยังระบบเดิม:

- สร้าง vendor-neutral Migration Import กลาง โดยผู้ใช้ download Excel template ของ ERP แล้วนำข้อมูลที่ export จากระบบเดิมมาแปลง/วางตาม template; importer ไม่ต้องรู้ schema, database, API หรือ file format ภายในของ Express/WinSpeed
- template ต้อง versioned และมี data dictionary ภาษาไทย: sheet, column, required/optional, type, format, code reference, precision, ตัวอย่าง และข้อผิดพลาดที่พบบ่อย; ใช้ `.xlsx` ที่ไม่มี macro และไม่รับ formula เป็นข้อมูลเพื่อให้ผล import deterministic
- MVP import ตาม dependency order: company setup/branch/warehouse → chart of accounts/UOM/tax/party/item masters → accounting cutover/opening data → optional open purchase/sales documents
- accounting cutover อย่างน้อยรองรับ opening Trial Balance, AR/AP open items และ aging, cash/bank balances, stock quantity/value แยก item/warehouse และข้อมูล lot/serial/expiry/warranty ที่มี, fixed assets พร้อม accumulated depreciation/NBV และยอด VAT/WHT ค้างที่ผู้ทำบัญชียืนยัน
- ไม่ import transaction history ทั้งหมดใน MVP; เก็บระบบเดิม/ไฟล์ archive เพื่ออ้างอิงตาม retention policy และพิจารณา historical read-only import ภายหลังเมื่อมีลูกค้าต้องการจริง
- ทุก import ใช้สองขั้น `upload/stage/validate → approve/commit`: preview จำนวน/ยอด, แสดง error รายแถวและให้ download error workbook; ห้าม partial commit เมื่อ batch มี error ที่กระทบ invariant
- validation ต้องตรวจ duplicate code/reference, FK/dependency, enum/date/decimal/UOM, account mapping, debit=credit, opening date อยู่ใน period ที่กำหนด, AR/AP เทียบ control accounts, stock valuation เทียบ Inventory GL และ asset NBV/depreciation
- commit ผ่าน service/posting/movement contract ของ module เจ้าของเท่านั้น ห้าม bulk insert ข้าม business rule ลงตาราง master, stock balance, cost layer, subledger หรือ journal โดยตรง
- batch เก็บ source-system label (`express`, `winspeed`, `minterp`, `other`), template version, cutover date, original/staged file checksum, row key, actor, validation/approval/commit status, counts/totals และ source reference เพื่อ audit; source-system label ใช้เพื่อ trace ไม่ใช้เปลี่ยน business behavior
- import ต้อง idempotent ด้วย batch + sheet + stable row key/external reference; retry ห้ามสร้างข้อมูลหรือ opening journal/stock movement ซ้ำ
- ก่อน commit ต้อง freeze/cut off การลงข้อมูลในระบบเดิมตามแผน cutover และให้ผู้รับผิดชอบ sign off ยอดควบคุม; หลัง commit ต้องออกรายงาน reconciliation เทียบ source control totals → staged totals → ERP masters/subledgers/stock/GL
- batch ที่ยังไม่ commit ลบ/แทนที่ได้ตามสิทธิ์; batch ที่ commit และมีผล stock/accounting ห้ามลบตรง ให้แก้ผ่าน reversal/correction หรือ reset installation ก่อน go-live โดยมี approval/audit ชัดเจน
- เก็บไฟล์ต้นฉบับ/error workbook ใน private GCS ผ่าน Platform storage contract ตาม retention setting; batch ใหญ่ validate/commit ผ่าน chunked idempotent queued job พร้อม progress, retry และ failure recovery
- ใช้ public maintained Excel library ที่ compatible กับ Laravel/PHP รุ่นจริงผ่าน Migration service กลาง ห้ามแต่ละ module parse spreadsheet หรือสร้าง template เอง

Express/WinSpeed-specific work ใน MVP มีเพียงคู่มือ mapping จาก export ที่ลูกค้าพบได้ทั่วไปเข้าสู่ ERP template หลังได้รับไฟล์ตัวอย่างที่ปกปิดข้อมูลแล้ว ห้ามรับประกัน one-click import ตามชื่อผลิตภัณฑ์จนกว่าจะมี requirement และ sample หลายรุ่นที่พิสูจน์ความคุ้มค่า

Unit Test ครอบคลุม template-version/header validation, row mapping, type/precision, dependency ordering, reconciliation totals และ deterministic row key ส่วน upload/GCS, queue, transaction rollback และ cutover reconciliation จริงตรวจด้วย manual QA checklist
