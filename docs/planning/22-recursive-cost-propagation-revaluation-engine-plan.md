# Recursive Cost Propagation & Revaluation Engine Plan

สถานะ: Phase 1–7 พร้อมแล้ว และ Phase 8 async runtime + transaction trigger wiring + partition concurrency/stale protection + Manual Recovery Console + compensating reversal run พร้อม โดย feature gate ยังปิด; Legacy Review decision แยกสิทธิ์ View/Approve แล้ว และ Review #5 ปิดแบบ `APPROVED_NO_ACTION` โดยไม่ mutate ledger; เหลือ legacy Journal recovery sign-off, negative-permission test, benchmark, monitoring, Accounting UAT และ staged rollout

อัปเดตการวิเคราะห์ล่าสุด: 2026-09-12

การแก้ไขล่าสุด 2026-09-11: หน้า Revaluation Approval Queue แสดง Scope Batch ที่อยู่ระหว่างวางแผน/รอ worker/กำลังคำนวณแยกจาก Run แล้ว ดังนั้นการสร้าง manual scope จะมองเห็น Batch ได้ทันทีแม้ database queue worker ยังไม่สร้าง child Run; รายการยังคงจำกัดตาม Warehouse ที่เลือกและแสดงเพียง 25 batch ล่าสุดเพื่อควบคุมขนาด query

ตรวจ blocker Batch #43 เพิ่มเติม 2026-09-11: พบ Production Receipt Movement เดียวมี generic `movement:{id}:receipt` allocation แบบ PENDING ซ้ำกับ canonical allocation ที่ POSTED/มี Journal proof จึงเพิ่ม guard ใน `StockCostLayerService` ไม่ให้สร้าง generic allocation สำหรับ `WMS_PRODUCTION_RECEIPT` ทั้ง AVG/FIFO; allocation เดิม #2059 และ POS date mismatch #1535 ยังคงอยู่ใน Legacy Review ตามหลัก immutable ledger และรอ Accounting decision ไม่แก้ข้อมูล Posted ย้อนหลัง

Regression check 2026-09-11: `CostImpactClassifier` รองรับ Purchase allocation ที่ไม่มี Journal proof เป็น Legacy Review warning ตาม accounting-proof policy ขณะที่ Production Receipt pending allocation ยังคงเป็น blocker; Unit tests ผ่าน 17 tests / 67 assertions

Legacy Review evidence check 2026-09-11: พบ evidence เดิมของ Review #3 แสดง `warehouse_id=0` เพราะ review service select Movement fields ไม่ครบ จึงแก้ให้ Review ใหม่เก็บ Warehouse scope จริง; Review เดิมยังคง immutable และต้องใช้ Allocation/Movement source ตรวจประกอบ

## Implementation Readiness Audit — 2026-09-08

- [x] System-wide transaction/date/trigger/fan-out/performance/recursive-stop contracts ถูกระบุแล้ว
- [x] Reuse ledger, allocation, layer, lineage, revaluation run/delta และ journal-link foundation เดิม
- [x] Revaluation foundation migrations `2026_09_08_010000` และ `2026_09_08_020000` รันแล้วใน development database
- [x] Installer มี required-schema guard สำหรับ Revaluation foundation
- [x] `ERP_REVALUATION_APPLY_ENABLED` และ `ERP_REVALUATION_GL_POSTING_ENABLED` default เป็น `false`
- [x] Stock Valuation, Lineage, Shadow และ Revaluation routes โหลดได้ครบ
- [x] Baseline + Effective Date + Impact Classifier unit tests ผ่าน 27 tests / 106 assertions และ foundation PHP files ผ่าน syntax check
- [x] Migration `2026_09_07_060000_add_production_finished_receipt_document_sequence` รันใน development database แล้วเมื่อ 2026-09-09

ผลการตัดสิน: **GO สำหรับ Phase 5 Canonical Read-only Calculation เท่านั้น** ห้ามเปิด Apply/GL flags หรือขยาย write path จนกว่า Phase 5–7 exit gates จะผ่าน

## เป้าหมาย

สร้าง Engine แบบ Asynchronous สำหรับกระจาย Cost Delta จาก transaction ต้นทางไปยัง transaction ปลายทางจำนวนมาก โดยรองรับ:

```text
Receipt / Opening Cost
        ↓
Material Issue / Sale Issue / Transfer Out
        ↓
Finished Receipt / Transfer In / Return
        ↓
Sale / COGS / Issue / Further Transfer
```

Engine ต้องทำงานได้เมื่อข้อมูลมีจำนวนมาก มีการแก้ไขต้นทุนย้อนหลัง และมีเส้นทางข้าม Warehouse หรือ Branch โดยไม่แก้ไข Posted Movement, Cost Allocation หรือ Journal เดิม

## หลักการออกแบบ

- Asynchronous เป็นค่าเริ่มต้น ไม่บังคับให้ผู้ใช้รอผลลัพธ์แบบ realtime
- ใช้ Immutable Delta/Revaluation เท่านั้น
- ทุก Node ต้อง trace กลับไปยัง Source Document และ Source Allocation ได้
- ใช้ Stable Identity และ Idempotency Key ไม่อ้างอิงลำดับการประมวลผล
- ประมวลผลเป็น bounded transaction ต่อ Node/Chunk ไม่เปิด transaction ครอบทั้ง graph
- รองรับ Retry, Resume, Partial Failure และ Dead-letter Recovery
- ต้นทุนที่ยังไม่ Final ต้องไม่ถูกนำไป Post เป็น Final
- Period ที่ปิดแล้วต้องสร้าง Revaluation ใน Period เปิดถัดไปตาม policy
- Stock Valuation เป็น Read Model/Report ที่อ่านผลจาก Ledger ไม่ใช่ตัวกระจายต้นทุน
- Backdated transaction ต้องคำนวณตาม `business_date`/effective date ไม่ใช่ `created_at`; เอกสารที่ถูกสร้างวันนี้แต่ลงวันที่ย้อนหลังต้องเปิด impact propagation ไปยัง movement ที่เกิดหลัง effective date
- ห้ามแก้ไข Posted movement/allocation/journal เดิมของรายการอนาคต ให้สร้าง immutable delta/revaluation ต่อ downstream node แทน
- หากต้นทางย้อนหลังอยู่ในงวดปิด ต้องแยก `historical impact date` ออกจาก `posting date` และสร้าง Revaluation ในงวดเปิดตาม accounting policy พร้อม audit reference กลับไปยังเอกสารย้อนหลัง
- ห้าม replay ledger ตั้งแต่ transaction แรกเป็นค่าเริ่มต้น; ใช้ Historical Anchor/Cost Snapshot ก่อน `business_date` ของเอกสารย้อนหลัง แล้วคำนวณเฉพาะ Impact Window ที่ได้รับผลกระทบ

## ขอบเขต Cost Flow

### Node ที่ต้องรองรับ

- Receipt / Goods Receipt
- Opening Balance
- Material Issue
- Sale Issue / COGS
- Finished Receipt
- Stock Transfer Out
- Stock Transfer In
- Issue Return / Sales Return
- Inventory Adjustment ที่มีผลต่อต้นทุน
- Purchase Return / Credit Note แบบคืนสินค้า
- Landed Cost / Cost Correction
- Stock Count Variance ที่สร้าง Inventory Adjustment
- Reversal ของทุก Stock/Cost transaction

### Edge ที่ต้องรองรับ

- Receipt → Issue Allocation
- Issue → Finished Receipt
- Finished Receipt → Transfer Out
- Transfer Out → Transfer In
- Transfer In → Sale Issue / Further Issue
- Source Allocation → Reversal Allocation
- Original Allocation → Recost Allocation
- AVG Pool State → movement ถัดไปตามเวลา
- FIFO Layer → consumption split
- Purchase Receipt → Purchase Return
- Sale Issue → Sales Return
- Issue Allocation → Issue Return
- Stock Count → Inventory Adjustment

Edge ไม่ได้มีชนิดเดียว:

- `EXPLICIT_PARENT`: ใช้ `parent_allocation_id` เช่น reversal, transfer in, sales return และ issue return
- `FIFO_CONSUMPTION`: ใช้ `stock_cost_layer_id` และ split quantity ของ layer
- `AVG_POOL_SEQUENCE`: คำนวณจาก movement ใน scope เดียวกันตามเวลา ไม่มี parent row โดยธรรมชาติ
- `DOCUMENT_BRIDGE`: ใช้เอกสารเชื่อม material issue → finished receipt หรือ GR → purchase return เพื่อหา candidate เท่านั้น แล้วต้องยืนยัน quantity/value ด้วย allocation
- `RECOST_REVISION`: original allocation → immutable RECOST allocation

ทุก Edge ที่ใช้ Apply ต้องระบุ source/target identity, quantity, value, allocation ratio, warehouse, branch, revision และ evidence type ห้ามใช้ document relation เพียงอย่างเดียวเป็นหลักฐานมูลค่า

## Persistence Strategy — Reuse ก่อนเพิ่ม Schema

ไม่สร้าง `cost_propagation_runs/nodes/edges/deltas` ชุดใหม่ซ้ำกับ foundation ที่มีแล้ว ขอบเขตแรกให้ reuse:

- `wms_cost_revaluation_runs`: run, approval, impact/posting date, snapshot, status และ idempotency
- `wms_cost_revaluation_deltas`: source allocation, old/new cost, delta, apply status และ applied allocation
- `wms_cost_allocations`: immutable source/target allocation, parent, layer, revision และ journal identity
- `wms_stock_cost_layers`: FIFO/AVG receipt state และ pending-cost resolution
- `wms_cost_allocation_journal_lines`: allocation-to-GL proof
- Document linkage ที่มีอยู่: transfer key/event, issue-return allocation split, sales-return inventory link และ production `source_issue_id`

สิ่งที่อาจต้องเพิ่มหลัง Read-only resolver ผ่าน test แล้วเท่านั้น:

- Delta classification: `impact_bucket`, `target_event`, `target_warehouse_id`, `target_branch_id`
- Run cursor/checkpoint: scope key, anchor identity, last `(business_date, movement_id, allocation_id)`, heartbeat และ counters
- Persisted edge evidence เฉพาะกรณี retry ข้าม chunk ต้องใช้ โดยเก็บ `relation_type`, source/target, quantity/value และ resolver version
- AVG historical anchor/snapshot หากพิสูจน์แล้วว่า ledger เดิมหา opening state แบบ bounded ไม่ได้

ยังไม่สร้าง graph tables ทั้งชุดล่วงหน้า เพราะ explicit edge ใช้ schema เดิมได้ และ AVG เป็น temporal replay ซึ่งการบันทึก parent edge ถาวรทุกคู่จะใหญ่และ stale ง่าย

## Phase 0 Discovery Findings — 2026-09-08

การสำรวจ Existing Codebase เสร็จแล้ว โดยรอบนี้ยังไม่สร้าง migration, model, job หรือ business write path ใหม่

### สิ่งที่มีอยู่แล้วและควร Reuse

| Area | Existing contract | บทบาทต่อ Engine ใหม่ |
|---|---|---|
| Movement ledger | `wms_stock_movements` มี `source_type`, `source_id`, `source_reference`, `transfer_key`, `idempotency_key`, `status` และ immutable Posted row | ใช้เป็น transaction node หลักและ source identity |
| Cost lineage | `wms_cost_allocations` มี `parent_allocation_id`, `stock_cost_layer_id`, `recost_request_id`, `journal_entry_id`, `allocation_type`, `direction`, `revision` | ใช้เป็น allocation/edge เดิมก่อนเพิ่ม read model ของ graph |
| FIFO/AVG | `FifoTransferCostLineageService`, `AvgTransferCostLineageService`, `StockCostLayerService` | ใช้คำนวณ split และรักษา layer lineage ห้ามสร้าง costing algorithm ซ้ำ |
| Recost | `StockRecostService`, `CostRecalculationRequest`, `RecalculateInventoryCost`, `DispatchPendingInventoryRecost` | เป็น upstream trigger และ safety-net queue เดิม แต่ยังไม่ recursive |
| GL proof | `wms_cost_allocation_journal_lines`, `JournalPostingService`, `RecostGlPostingService` | ใช้เป็น immutable allocation-to-journal proof และต้องเพิ่ม event/revaluation contract ภายหลัง |
| Projection | `StockBalanceProjectionService`, `wms_stock_balances` | ใช้เป็น projection ที่ปรับด้วย delta หลังผ่าน contract ไม่ใช่ source of truth |
| Valuation | `InventoryCostAllocationService::valuationQuery()` และ `historicalValuationQuery()` พร้อม `/wms/stock-valuation` | ใช้ตรวจผลและ reconciliation ของ propagation |
| Production | `ManualProductionReceiptPostingService` ใช้ `WMS_PRODUCTION_RECEIPT` และ `production.finished_receipt`; source issue รองรับ allocation หลายรายการ | ใช้เป็น edge ระหว่าง Material Issue → Finished Receipt |
| Transfer | Web UI รับเข้าหรือปฏิเสธยอดคงเหลือครบทั้งเอกสาร; `TransferMovementService` และ FIFO/AVG lineage สร้าง `parent_allocation_id` จาก Transfer Out → Transfer In | ใช้เป็น cross-warehouse/branch edge ที่มีอยู่แล้ว; Engine ต้องอ่าน quantity จาก event จริงเพื่อรองรับข้อมูลเดิมที่อาจเป็น partial แต่ไม่เพิ่ม partial flow ใน UI |
| Sale/COGS | POS stock movement ใช้ `source_type = POS`; COGS และ Sales Return มี parent allocation linkage | ต้องเพิ่ม downstream revaluation traversal โดยไม่แก้ Posted COGS เดิม |

### ช่องว่างที่ยืนยันแล้ว

- มี `wms_cost_revaluation_runs` และ `wms_cost_revaluation_deltas` เป็น Apply foundation แล้ว แต่ยังไม่มี cursor/checkpoint, impact bucket และ persisted edge evidence สำหรับ retry/resume ระดับหลาย partition
- `StockRecostService::resolveFromReceipt()` จำกัดอยู่ที่ pending cost layers และปรับ Stock Balance ของ scope ต้นทาง ยังไม่ enqueue downstream consumers
- `RecostGlPostingService` รองรับ Journal ของ `WMS_RECOST` แต่ยังไม่สร้าง revaluation สำหรับ Production Receipt, Transfer In หรือ POS COGS ที่รับผลกระทบ
- Finished Receipt มี source allocation lineage แล้ว แต่ยังไม่มี contract สำหรับรับ Cost Delta หลังเอกสารถูก Posted และถูกโอน/ขายต่อ
- Transfer lineage รองรับ parent allocation ตอนรับเข้า แต่ยังต้องมี read-only traversal ที่ป้องกัน cycle, orphan, partial quantity และหลาย downstream consumers
- Sale/COGS มี movement/allocation อยู่แล้ว แต่ต้องยืนยัน mapping ของ quantity/value/period/branch ก่อนเปิด Apply Delta
- `/wms/stock-valuation` เป็น read/report path ของ valuation ปัจจุบันและ historical ไม่ใช่ propagation engine; จะใช้เป็น reconciliation consumer
- ยังไม่มี Temporal Impact Resolver สำหรับกรณีเอกสารถูกสร้างภายหลังแต่ `business_date` ย้อนหลัง และยังไม่มี policy แยก impact date/posting date เมื่อ period ปิด

### ข้อสรุป Phase 0

ไม่ควรแก้ `StockRecostService` ให้เดิน graph ทันที เพราะจะผูก transaction ต้นทางกับงานจำนวนมากและเสี่ยงกระทบ retry/period close เดิม Read-only Graph Explorer ถูกสร้างแล้ว แต่ผลสำรวจยืนยันว่า parent graph อย่างเดียวไม่ครอบคลุม AVG ดังนั้นขั้นถัดไปคือ **Phase 5 Canonical Read-only Calculation** ก่อนขยาย Apply/Journal

## System-wide Transaction Matrix — 2026-09-08

ผลการวิเคราะห์นี้อิงจาก writer ที่สร้าง `wms_stock_movements`, `wms_cost_allocations`, Cost Layer, Journal และ reversal จริง ไม่ได้อิงชื่อเมนูอย่างเดียว

### Transaction ที่มี Stock/Cost impact

| Business flow | Stock / allocation ปัจจุบัน | GL ปัจจุบัน | Lineage ที่ใช้ได้ | สิ่งที่ Engine ต้องทำ / gap |
|---|---|---|---|---|
| Opening Balance | `OPENING_BALANCE`, `RECEIPT/IN`, FINAL layer/allocation | ไม่มี allocation-to-opening GL proof ใน service นี้ | movement → layer → allocation | เป็น root/anchor เท่านั้น; ห้าม recost หลัง cutover โดยไม่มี approval และ opening GL reconciliation |
| Goods Receipt operational | มี closed writer ใช้ `GOODS_RECEIPT`, `RECEIPT/IN`; ยังไม่มี controller/release gate | `inventory.receipt` เป็น `NO_GL` | GR line metadata → movement/allocation | ต้องตัดสิน canonical stock owner ระหว่าง GR กับ Purchase Invoice ก่อน rollout; ห้ามสร้าง stock ซ้ำ |
| Purchase Invoice inventory | Runtime path ใช้ `PURCHASING`, `RECEIPT/IN`, AVG/FIFO | `supplier_invoice.inventory`: Inventory/AP และ allocation journal link | Purchase document/GR allocation metadata → movement → allocation → journal | เป็นต้นทุนรับเข้าหลักในข้อมูลปัจจุบัน; backdated price/quantity change ต้องเริ่ม replay จาก business date |
| Purchase Invoice expense/service | ไม่มี Stock | `supplier_invoice.expense` | financial document เท่านั้น | ไม่เข้า cost graph |
| Landed Cost | `RECOST/IN` เป็น child ของ receipt allocation | `inventory.recost` | `parent_allocation_id`, GR line และ recost request | เป็น root delta ที่ชัดเจน แต่ยังไม่ propagate ไป consumer หลัง GR |
| Purchase Return เต็มจำนวน | reversal `RECEIPT/OUT` และ parent ไป receipt allocation | `purchase_credit_note`/original journal | explicit reversal parent + journal proof | delta ต้นทางต้องไหลถึง returned quantity และใช้ Inventory/Cost variance; ห้ามเปลี่ยน AP amount อัตโนมัติ |
| Purchase Return บางส่วน | `PURCHASING`, `ISSUE/OUT`; AVG/FIFO คำนวณจาก stock ณ วันคืน | Credit Note inventory line ถูก link กับ allocation | document bridge ไป GR; explicit receipt parent ยังไม่ครบทุก path | ต้องสร้าง resolver แยกตาม AVG/FIFO และพิสูจน์ returned quantity ต่อ source receipt; MVP ปัจจุบันรองรับ 1 line |
| Purchase Credit Note แบบ `NON_RETURN` | ไม่มี Stock | Financial-only credit note | financial document เท่านั้น | ไม่เข้า cost graph; ห้ามสร้าง movement จากการเปลี่ยนต้นทุน |
| Inventory Adjustment Gain | `INVENTORY`, `ADJUSTMENT/IN`, FINAL | `inventory_adjustment`: Inventory/Adjustment Gain | movement/allocation/journal | เป็น cost root; หากลงย้อนหลังต้อง replay AVG pool หรือ FIFO layer หลังวันที่มีผล |
| Inventory Adjustment Loss | `INVENTORY`, `ADJUSTMENT/OUT`, FINAL | Inventory/Adjustment Loss | allocation เป็น consumer; reversal มี parent | เป็น terminal inventory consumption และต้องจัด delta ไป Adjustment Gain/Loss ไม่ใช่เพิ่ม Stock Balance ทั้งก้อน |
| Stock Count variance | ยังไม่ post stock โดยตรง; สร้าง/อ้าง Inventory Adjustment document | ผ่าน adjustment เมื่อ post | Stock Count → adjustment document | Engine รับเฉพาะ adjustment ที่ Posted; Stock Count เป็น audit origin ไม่ใช่ allocation node ซ้ำ |
| Generic Issue | `ISSUE_DOCUMENT`, `ISSUE/OUT`; ข้อมูลปัจจุบันเป็น FINAL cost แต่ allocation status `PENDING` | ยังไม่มี event/journal proof สำหรับค่าใช้จ่ายเบิกใช้ | movement metadata มี issue type; AVG ไม่มี parent | เป็น terminal expense/consumption; ต้องกำหนด account role และทำ allocation status ให้สมบูรณ์ก่อน Apply revaluation |
| Material Issue | ใช้ writer เดียวกับ Generic Issue แต่ `issue_type = PRODUCTION` | `production.material_issue` ยัง `DEFERRED` | issue document/line/allocation; AVG ไม่มี receipt parent | เป็น WIP consumer และ source ของ Finished Receipt; ต้องเปิด end-to-end WIP contract ก่อน GL propagation |
| Issue Return / Material Return | `ISSUE_RETURN`, `RETURN/IN`, split ตาม source allocation และมี parent | ข้อมูลปัจจุบันยังไม่มี journal link | explicit parent + `IssueReturnLineAllocation` | คืนต้นทุนเดิมกลับ pool ตามสัดส่วน แล้ว replay downstream หลังวันคืน; Material Return ต้อง reverse WIP ด้วย |
| Issue Return reversal | `ISSUE_RETURN`, `RETURN/OUT`, parent ไป allocation รับคืน | ยังไม่มี journal proof | explicit reversal parent | เป็น immutable compensating edge; ห้ามลบ return เดิม |
| Transfer Dispatch | `WMS_TRANSFER`, `TRANSFER/OUT`; AVG/FIFO allocation | ไม่มี GL สำหรับ stock transfer | transfer key, line/event และ source allocation | เป็น consumer ของคลังต้นทางและ in-transit bridge; cost delta ต้องส่งต่อเฉพาะ quantity ที่ accept/reject จริง |
| Transfer Accept | `WMS_TRANSFER`, `TRANSFER/IN`; parent ไป Transfer OUT, layer ปลายทาง | ไม่มี GL | explicit parent + transfer key + event quantity | Web UI ปัจจุบันรับยอดคงเหลือครบทั้งเอกสาร; ขยาย scope ไปคลัง/สาขาปลายทาง แล้ว replay transaction หลังวันที่รับ |
| Transfer Reject/Return | IN กลับคลังต้นทางจาก dispatch | ไม่มี GL | reversal/transfer event | Web UI ปัจจุบันปฏิเสธยอดคงเหลือครบทั้งเอกสาร; คืน delta ให้ source pool ตาม event quantity และตรวจผลรวม Accept/Reject ต้องไม่เกิน Dispatch เพื่อรองรับข้อมูลเดิม |
| Finished Receipt แบบ Manual | `WMS_PRODUCTION_RECEIPT`, `RECEIPT/IN`; allocation ของ output | `production.finished_receipt`: Finished Goods/WIP | `source_issue_id` เชื่อมเอกสาร; Shadow แบ่ง output ตามมูลค่าบรรทัด | Document bridge มีแต่ allocation ของ output ยังไม่มี parent ต่อ input ทุก split; ต้องสร้าง production conversion edges และ residual rule |
| Finished Receipt reversal | `RECEIPT/OUT`, parent ไป receipt allocation | reverse original finished-receipt journal | explicit reversal parent | เป็น compensating edge; หาก output ถูกใช้ต่อแล้วต้อง queue downstream revaluation แทนการแก้ row เดิม |
| HS/IV physical sale | `POS`, `ISSUE/OUT`, FINAL/POSTED | `sales_cogs`: COGS/Inventory พร้อม allocation journal link; revenue แยก `sales_invoice` | movement metadata → sale line; allocation → COGS line | เป็น terminal COGS; delta ต้อง post COGS revaluation ไม่ใช่ `inventory.recost` ทุกกรณี |
| Sales Return / physical credit | `POS`, `RETURN/IN`; split parent ไป sale allocations | reverse COGS ผ่าน `sales_cogs`; revenue credit แยก `sales_credit_note` | explicit parent + `SalesReturnInventoryLink` + journal proof | ต้นทุนใหม่ต้องปรับทั้ง inventory ที่รับคืนและ reverse COGS แล้ว replay การใช้ stock หลังวันรับคืน |
| Physical Sale cancellation | reverse movement/allocation และ COGS/revenue journal | original-journal reversal | explicit parent per source allocation | ถือเป็น reversal flow ไม่ใช่ลบ sale; run เดิมที่เคย revalue ต้องสร้าง compensating run |
| Negative-stock provisional recost | OUT สร้าง PENDING layer/request; receipt ภายหลังสร้าง `RECOST` | `inventory.recost` | recost request + pending layer + resolving receipt | safety-net เดิมแก้เฉพาะ pending layer/source balance ยังไม่ recursive; ต้องส่ง root delta เข้า engine ใหม่หลัง resolve |

### Transaction ที่ไม่ควรเข้า Cost Graph

- PR, PO, RFQ, Quotation, Sales Order และเอกสารอนุมัติที่ยังไม่สร้าง Posted Stock Movement
- Supplier Invoice แบบ expense/service และ Purchase Credit Note แบบ `NON_RETURN`
- Revenue/AR/AP/VAT/Payment/Receipt/Advance/Petty Cash/Internal Bank Transfer ที่ไม่มี stock leg
- Sales Credit Note เชิงการเงินที่ไม่มี Physical Sales Return
- Manual Journal และ Period Adjustment เว้นแต่เป็น Journal ที่ Engine สร้างเพื่อ revaluation โดยตรง
- Delivery/Fulfillment status ที่ไม่พบ stock writer ใน codebase ปัจจุบัน; หากเพิ่ม writer ภายหลังต้อง register event ก่อนจึงเข้า graph

หลักตัดสินคือ “มี Posted Stock Movement + Cost Allocation ที่พิสูจน์ได้” ไม่ใช่ชื่อเอกสาร หากมีเฉพาะ GL หรือ document relation จะไม่ถูกนำมาคำนวณต้นทุนสินค้า

## Current Architecture Findings และ Blockers

### สิ่งที่ยืนยันจาก development data

ข้อมูล ณ 2026-09-08 แสดงรูปแบบสำคัญดังนี้:

- `ISSUE_DOCUMENT/OUT` มี FINAL AVG allocations แต่เป็น `PENDING`, ไม่มี parent/layer/journal link
- `POS/OUT` มี FINAL/POSTED allocations และ journal link ครบ แต่ AVG allocation ไม่มี parent/layer ซึ่งเป็นพฤติกรรมปกติของ average pool
- `ISSUE_RETURN/IN`, `WMS_TRANSFER/IN`, purchase reversal และ production reversal มี explicit parent link
- `WMS_TRANSFER/OUT` และบาง Production Receipt ยังมี allocation `PENDING`/ไม่มี journal ตาม feature boundary ปัจจุบัน
- Adjustment Gain ตัวอย่าง `ADJHQ2609000005` วันที่ 2026-09-01 และ Issue `ISSUEHQ2609000004` วันที่ 2026-09-07 อยู่ scope Item/UOM/Warehouse เดียวกัน จึงต้องมีผลต่อกันภายใต้ AVG แม้ไม่มี `parent_allocation_id`

### Blocker ก่อนเปิด Apply จริง

1. **AVG temporal lineage ขาดอยู่** — `CostShadowCalculationService` เดินเฉพาะ parent และ production document edges จึงหา Adjustment 1 Sep → Issue 7 Sep ไม่ได้
2. **ผลรวม Shadow นับ node ซ้ำเชิงเศรษฐกิจ** — root delta, transfer/production intermediate delta และ terminal delta ห้ามนำมาบวกเป็นยอดเดียว ต้องแยก carrying/terminal/bridge/residual
3. **Apply ปรับ Stock Balance ทุก allocation** — terminal COGS, Issue Expense และ WIP ไม่ใช่ on-hand ทั้งหมด ต้อง classify impact ก่อนปรับ projection
4. **Journal ใช้ `inventory.recost` เดียว** — ต้อง route ตามผลกระทบ Inventory, COGS, Adjustment, WIP/Finished Goods, Issue Expense และ purchase-return variance
5. **Historical anchor ปัจจุบันอิง Cost Layer** — ใช้กับ FIFO ได้บางส่วน แต่ AVG ต้องมี bounded opening pool state ก่อนวันเริ่ม ไม่ใช่หา remaining layer อย่างเดียว
6. **Purchase stock ownership มีสอง contract** — `GOODS_RECEIPT` closed writer กับ runtime `PURCHASING/supplier_invoice.inventory`; ต้องเลือก canonical writer และป้องกัน double stock ก่อนเชื่อม Landed Cost
7. **Generic/Material Issue accounting ยังไม่ complete** — allocation/journal lifecycle ต้อง Final/Posted พร้อม account mapping ก่อน Engine สร้าง GL delta
8. **Production input→output เป็น document bridge** — ต้อง persist หรือคำนวณ quantity/value split ที่ deterministic; การหารตาม output value อย่างเดียวไม่พอเมื่อมีหลายใบเบิก หลาย output หรือ partial receipt

## Canonical Impact Contract

ทุก calculated delta ต้องถูกจัดเข้าหนึ่ง bucket ก่อน Apply:

| Impact bucket | ความหมาย | Stock Balance | GL target |
|---|---|---|---|
| `INVENTORY_ON_HAND` | มูลค่าส่วนที่ยังเหลือในคลัง ณ ปลาย impact window | ปรับ inventory value/average เท่านั้น ไม่ปรับ quantity | Inventory ↔ Recost Gain/Loss |
| `COGS_CONSUMED` | สินค้าถูกขายแล้ว | ไม่ปรับ on-hand | COGS ↔ Inventory/Recost clearing ตาม policy |
| `ISSUE_EXPENSE_CONSUMED` | เบิกใช้ทั่วไป/สูญเสีย/adjustment loss | ไม่ปรับ on-hand | Issue Expense หรือ Adjustment Gain/Loss ↔ Inventory |
| `WIP_CONSUMED` | วัตถุดิบถูกเบิกเข้าผลิตแต่ยังไม่รับ output | ไม่ปรับ on-hand ของวัตถุดิบ | WIP ↔ Inventory |
| `FINISHED_GOODS_BRIDGE` | ต้นทุน WIP ถูกแปลงเป็น output | output ที่เหลือเข้าสู่ pool ใหม่ | Finished Goods ↔ WIP/Production Variance |
| `TRANSFER_BRIDGE` | ต้นทุนกำลังข้าม warehouse/branch | ปรับเฉพาะ pool ปลายทางหลัง accept | ไม่มี GL ภายในบริษัทเป็นค่าเริ่มต้น; ใช้ clearing เฉพาะ policy ที่กำหนด |
| `RETURN_BRIDGE` | ต้นทุนกลับจาก issue/sale ไป inventory | เข้า pool ณ วันที่รับคืน | reverse COGS/WIP/Expense ↔ Inventory |
| `PURCHASE_RETURN_CONSUMED` | สินค้าคืน supplier แล้ว | ไม่ปรับ on-hand | Inventory cost variance; ห้ามแก้ AP/credit amount อัตโนมัติ |
| `ROUNDING_RESIDUAL` | เศษจาก scale/ratio | ลง node สุดท้ายตาม deterministic order | บัญชีตาม bucket ต้นทาง; ห้ามทิ้งเงียบ |

Invariant หลัก:

```text
Source Delta
  = Ending On-hand Delta
  + Sum(Terminal Consumed Delta)
  + Sum(Open Bridge Delta)
  + Rounding Residual
```

ห้ามใช้ `Sum(delta ของทุก node)` เป็นมูลค่าผลกระทบ เพราะ intermediate node เป็นการส่งต่อมูลค่า ไม่ใช่ผลกระทบเพิ่ม

## Resolver Architecture

ใช้ registry ของ read-only resolver ขนาดเล็ก แทน controller หรือ service ก้อนเดียว:

- `AvgPoolResolver`: replay movement ใน `(warehouse,item,uom)` จาก anchor ตาม `(business_date, id)` แล้วคำนวณ pool ก่อน/หลังแต่ละ event
- `FifoLayerResolver`: rebuild affected layer consumption split จาก active layers ณ anchor แล้วเทียบ old/new allocation
- `ExplicitParentResolver`: reversal, transfer in, issue return, sales return และ RECOST child
- `ProductionBridgeResolver`: material issues หลายใบ → finished receipts หลายใบ ด้วย consumed value/quantity และ deterministic residual
- `PurchaseReturnResolver`: แยก full reversal, partial return และ financial-only credit note
- `ImpactClassifier`: แปลง movement/document context เป็น impact bucket และ target accounting event
- `PostingDateResolver`: แยก impact date จาก posting date ตาม Fiscal Period

Resolver ต้องคืน DTO/read model เท่านั้นในช่วงแรก และห้ามเขียน Stock/Allocation/Journal จนกว่า reconciliation ของ Shadow จะผ่าน

## Backdated Transaction Contract

กรณีที่ต้องรองรับตั้งแต่แรก:

- สร้าง Goods Receipt หรือ Credit Purchase วันนี้ แต่ `business_date` เป็นวันก่อนหน้าที่มี Sale/Issue/Transfer/Finished Receipt อยู่แล้ว
- สร้าง Inventory Adjustment ย้อนหลังเพื่อแก้ยอดก่อนวันที่มี downstream movement
- สร้าง Material Issue/Finished Receipt ย้อนหลัง และมี Transfer หรือ Sale เกิดหลังจาก effective date ไปแล้ว
- แก้ต้นทุนต้นทางย้อนหลังจนกระทบ FIFO layer หรือ AVG pool ที่ถูก consume ไปแล้ว

Temporal traversal ต้องทำตามลำดับดังนี้:

```text
Backdated Source
  → หา allocation/layer ที่มี effective date >= source business_date
  → เดินตาม parent/child lineage ของทุก downstream consumer
  → คำนวณ delta ตาม FIFO layer หรือ AVG pool ณ effective date
  → สร้าง immutable delta ต่อ node ที่ได้รับผลกระทบ
  → แยก impact date กับ posting date ตาม Period policy
  → Reconcile Stock / Allocation / GL และแจ้งรายการที่ต้องอนุมัติ
```

กติกาที่ต้องบังคับ:

- วันที่เอกสาร (`document_date`) คือ effective date ที่เป็น authority ของ transaction; ตอน Post ต้อง snapshot ค่านี้ลง `wms_stock_movements.business_date` และ `wms_cost_allocations.business_date`
- Engine resolve วันที่จาก Source Document ก่อน แล้วตรวจว่า movement/allocation `business_date` ตรงกัน หากไม่ตรงให้ block เป็น `DATE_MISMATCH` ห้ามเลือกวันที่ใดวันที่หนึ่งเอง
- ใช้ effective document date เป็นหลักในการกำหนด affected window และใช้ movement/allocation `id` เป็น deterministic tie-breaker ภายในวันเดียวกัน; `created_at`, `updated_at` และเวลาที่ Job เริ่มใช้เพื่อ audit/operation เท่านั้น ห้ามมีผลต่อผลลัพธ์ต้นทุน
- เอกสารย้อนหลังที่สร้างวันนี้ต้องถูกแทรกเชิงตรรกะก่อน movement วันที่ภายหลัง แม้ database id จะมากกว่า; จากนั้น replay ผลกระทบต่อรายการถัดไปทั้งหมดใน scope
- Backdated receipt ต้องไม่เพียงปรับยอดคงเหลือปัจจุบัน แต่ต้องคำนวณผลกระทบต่อรายการที่ consume ต้นทุนไปแล้ว
- FIFO ต้องแยก layer และ split ตามลำดับ effective date; AVG ต้องคำนวณ pool ใหม่ตามจุดเวลาที่มี movement แล้วกระจายผลต่างไปยัง consumer ที่ได้รับผลกระทบ
- ห้ามสร้าง duplicate delta เมื่อเอกสารย้อนหลังถูก retry หรือเปิด propagation ซ้ำ
- ถ้า period ปิด ห้าม Post ย้อนงวดโดยอัตโนมัติ; ต้องสร้าง revaluation ในงวดเปิดและอ้างอิง original effective date
- หากข้อมูลย้อนหลังไม่มี lineage ครบ ให้หยุดที่ Shadow/Review และห้าม Apply Delta แบบเดา

### Date Authority ต่อเอกสาร

| Source | Effective date | หมายเหตุ |
|---|---|---|
| Opening Balance | `cutover_date` | ทำหน้าที่เป็น document date ของ opening batch |
| Purchase/GR/Return/Credit แบบมี stock | วันที่เอกสาร stock ที่สร้าง movement | Posting date ใช้เฉพาะ GL period; ห้ามเปลี่ยนลำดับ stock |
| Adjustment/Stock Count result | Adjustment `document_date` | Stock Count date เป็น audit origin; movement ยึด Adjustment date |
| Issue/Issue Return/Material flow | `document_date` ของเอกสารนั้น | Return ไม่ inherit วันที่ใบเบิกต้นทาง |
| Transfer | วันที่ของ dispatch/accept/reject event | แต่ละ event อาจคนละวันและต้อง replay แต่ละ warehouse จากวันที่ของ event |
| Finished Receipt/Reversal | `document_date`/reversal date | Source issue date ใช้หา lineage แต่ receipt เข้าสู่ output pool ณ วันที่รับจริง |
| HS/IV/Sales Return | วันที่ Post stock ที่ถูก snapshot จากเอกสารตาม contract | Revenue posting date ไม่ใช้เปลี่ยน stock chronology |
| Revaluation Journal | `posting_date` ในงวดเปิด | เก็บ original effective date แยก; ห้ามนำ posting date กลับไปเรียง Stock Ledger |

## Trigger Event Contract

Engine ไม่ poll เอกสารทั้งหมด ให้ trigger หลัง database transaction ต้นทาง commit สำเร็จเท่านั้น:

| Trigger | Root ที่ enqueue | เงื่อนไข |
|---|---|---|
| Posted receipt/opening/adjustment gain | allocation IN ทุกบรรทัด | enqueue เมื่อเป็น backdated หรือทำให้ต้นทุน/ลำดับ pool เปลี่ยน |
| Purchase invoice cost finalization | receipt allocation ที่ได้รับต้นทุนจริง | รวม price/quantity variance ที่ผ่าน approval |
| Landed Cost / Cost Correction Posted | immutable RECOST allocation | enqueue เสมอเมื่อ delta ไม่เป็นศูนย์ |
| Pending negative-stock cost resolved | RECOST allocation ของ pending layer | enqueue หลัง `StockRecostService` commit |
| Posted OUT/IN transaction ย้อนหลัง | allocation ของ issue/sale/return/transfer/adjustment | enqueue เมื่อมี movement ภายหลังใน partition หรือมี bridge ต่อ |
| Transfer accept/reject | allocation ของ event ทุกบรรทัด | enqueue ทั้ง source และ destination partition ที่ได้รับผล |
| Finished Receipt Posted | input issue roots และ output allocations | enqueue output partition หลัง source-cost reconciliation |
| Reversal Posted | reversal allocation และ root revision เดิม | สร้าง compensating run; ห้ามแก้ run ที่ Completed แล้ว |
| System Defaults/Import | ไม่มีโดยอัตโนมัติ | Import ที่ Post stock ต้องยิง event เดียวกับ writer ปกติ ไม่ bypass service |

Trigger payload ขั้นต่ำต้องมี `source_document_type`, `source_document_id`, `document_date`, `root_allocation_ids`, `revision`, `warehouse_id`, `branch_id` และ idempotency key ห้ามส่ง `created_at` เป็น effective date

Trigger ที่ retry ต้องได้ parent run เดิมเมื่อ source/revision เดิม และการ dispatch ต้องเกิด `afterCommit`; เอกสาร DRAFT/APPROVED ที่ยังไม่มี Posted movement ห้าม enqueue

## Multi-line Document Fan-out Contract

เอกสารต้นทางหนึ่งใบอาจมีหลายสินค้า หลาย UOM หรือหลายคลัง จึงใช้ Parent Run หนึ่งรายการต่อ document revision แล้ว fan-out งานให้ครบ:

```text
Source Document Revision
  → resolve Posted allocations ของทุก line
  → validate expected line/allocation count
  → group เป็น cost partition (warehouse + item + base_uom + method)
  → enqueue 1 calculation job ต่อ partition พร้อม root allocation ids ทุกบรรทัดในกลุ่ม
  → fan-out bridge jobs เมื่อพบ transfer/production downstream
  → finalize ได้เมื่อ expected partitions และ discovered bridge children เป็น terminal state ครบ
```

กติกา:

- ห้าม enqueue เฉพาะบรรทัดแรกหรือใช้ `first()` เมื่อเอกสารมีหลาย stock line
- ทุก Posted stock line ต้องถูก map เป็น root allocation อย่างน้อยหนึ่งรายการ หรือมี blocker ระบุ line ชัดเจน
- หลายบรรทัดที่เป็น partition เดียวกันให้รวมใน job เดียวเพื่อรักษาลำดับ AVG และลด lock collision แต่ parent run ต้องเก็บ source line/allocation ids ครบ
- แต่ละ child identity ใช้ `run + partition + root revision`; retry ต้องไม่สร้างงานหรือ delta ซ้ำ
- Parent Run เก็บ `expected_root_lines`, `resolved_root_lines`, `expected_partitions`, `completed_partitions`, `failed_partitions` และห้าม Completed หากจำนวนไม่ครบ
- จำกัดจำนวน partition ต่อ dispatch batch และใช้ continuation job; ห้ามโหลดทุก line/downstream node ไว้ใน memory ครั้งเดียว
- Partial failure ทำให้ Run เป็น `PARTIAL/FAILED_RETRYABLE`; partition ที่สำเร็จไม่ต้องคำนวณใหม่ เว้นแต่ root revision เปลี่ยน

## Performance Contract — Impact Window และ Historical Anchor

รองรับข้อมูลหลายสาขา หลายคลัง และ transaction สะสมหลายปี โดยไม่คำนวณทั้งประวัติทุกครั้ง:

```text
Backdated Source (effective_date = D)
  → โหลด Anchor ก่อน D ของ Company/Branch/Warehouse/Item/Cost Method
  → เริ่มอ่าน movement/allocation ตั้งแต่ D เป็นต้นไป
  → เดินเฉพาะ child lineage ที่ได้รับผลกระทบ
  → หยุดเมื่อ delta เป็นศูนย์ หรือถึง bounded horizon/terminal consumer
```

กติกา:

- ใช้ Source Document, Item/UOM, Warehouse และ Branch เป็น scope แรก ห้าม scan ทุกบริษัท/ทุกคลังโดยไม่จำเป็น
- AVG ใช้ quantity/value pool snapshot ก่อน `D` เป็น opening state แล้วคำนวณเฉพาะ movement หลัง `D`; ห้ามใช้ remaining FIFO-style layer เป็น AVG anchor
- FIFO ใช้ active Cost Layer ณ ก่อน `D` เป็น opening layer แล้วเดินเฉพาะ layer ที่ถูก consume หลัง `D`; ไม่โหลด layer ที่หมดอายุก่อนช่วงผลกระทบ
- Cross-warehouse/branch ให้ขยาย scope เฉพาะเส้นทาง Transfer ที่มี parent/child lineage ต่อจาก affected allocation
- อ่านข้อมูลแบบ keyset/chunk ตาม `(business_date, movement_id, allocation_id)` และมี maximum nodes/depth/time budget ต่อ Shadow Run
- Partition หลักคือ Company → Warehouse → Item → Base UOM → Cost Method; Branch ใช้ routing/audit และขยายข้าม partition เฉพาะ transfer/production bridge ที่พิสูจน์ได้
- Stop propagation ได้เมื่อ state เดิมและ state ใหม่กลับมาเท่ากันทั้ง quantity/value/average และไม่มี open bridge child; FIFO หยุดเมื่อ affected layer ไม่มี remaining/consumption ต่อ
- ถ้าพบ missing anchor หรือ snapshot ไม่พอ ให้สถานะ `REQUIRES_REBUILD`/Review แทนการ fallback ไป full replay อัตโนมัติ
- Full replay เป็นงาน maintenance/rebuild แยกต่างหาก ไม่ใช่ path ของ User หรือ Job ปกติ
- ผลลัพธ์ต้องบันทึก `anchor_date`, `impact_start_date`, `impact_end_date`, `scope`, `nodes_scanned` และ `nodes_affected` เพื่อวัด performance และ audit

## Recursive Stop, Yield และ Circuit-breaker Contract

ห้าม implement ด้วย recursive PHP call ที่เดิน graph จนจบใน process เดียว ให้ใช้ iterative work queue + persisted cursor และมีขอบเขต 3 ชั้น:

### 1. Business Stop — จบเส้นทางได้ตามปกติ

- delta ของ state ใหม่เท่ากับ state เดิมที่ scale 8 ทั้ง quantity, value และ average cost และไม่มี open bridge child
- ถึง terminal bucket เช่น COGS, Issue Expense, Adjustment Loss หรือ Purchase Return ที่ไม่มี inventory downstream
- ไม่พบ downstream movement/explicit bridge หลัง effective document date
- FIFO affected layer ถูก consume/returned ครบและไม่มี remaining affected quantity
- reversal หักล้าง affected delta ครบเป็นศูนย์

### 2. Cooperative Yield — งานยังไม่จบแต่คืน resource ก่อน

เมื่อถึง budget ต่อ Job ให้บันทึก cursor/partial accumulator ใน transaction สั้น แล้ว dispatch continuation หลัง commit:

- rows ต่อ chunk เริ่มต้น `250`
- soft wall-clock ต่อ Job เริ่มต้น `20 วินาที`
- soft memory threshold `70%` ของ PHP memory limit
- จำกัด SQL page/keyset batch ไม่เกิน chunk size; ห้าม `get()` graph ทั้งก้อน

การ Yield ไม่ใช่ failure: Run ใช้สถานะ `WAITING_CONTINUATION`, Job ถัดไปเริ่มจาก cursor เดิม และ accumulator ต้อง idempotent

### 3. Hard Circuit Breaker — หยุด Run เพื่อป้องกันระบบล่ม

ค่าเริ่มต้นก่อน benchmark:

- max depth `64`
- max discovered nodes `50,000` ต่อ Parent Run
- max cost partitions `5,000` ต่อเอกสาร revision
- max fan-out `1,000` children ต่อ node/bridge
- node identity เดิมเยี่ยมได้ครั้งเดียวต่อ run revision; พบซ้ำใน path ให้ block `CYCLE_DETECTED`
- lock timeout/deadlock retry ตาม backoff ที่กำหนด; เกิน attempts ให้ `FAILED_RETRYABLE`

เมื่อชน hard limit ให้หยุด enqueue งานใหม่ของ Run นั้น บันทึก cursor, metric และ technical detail แล้วเปลี่ยนเป็น `LIMIT_REACHED/REQUIRES_REVIEW` ห้าม fallback เป็น full replay และห้าม mark `COMPLETED`

ค่าทั้งหมดต้องอยู่ใน System Configuration/ERP config พร้อม safe minimum/maximum; เปลี่ยนได้โดยผู้ดูแลระบบ แต่ห้าม customer request ส่ง limit เอง

### Safety Invariants

- Parent Run Finalize ได้เมื่อ `outstanding_jobs = 0`, discovered children ถูกนับครบ และ expected/completed/failed partition counters reconcile
- Job แต่ละตัว lock เฉพาะ run/partition ของตน ไม่ lock ทั้ง company หรือทั้ง graph
- Queue lag หรือ failed-job rate เกิน operational threshold ให้หยุดรับ low-priority propagation ใหม่ แต่ Stock Posting ต้นทางต้องแจ้งสถานะ `Propagation Pending` แทนการล่มตาม
- Closed Period ไม่ใช่เหตุให้หยุด calculation; คำนวณ Shadow ต่อได้ แต่ Apply/GL เปลี่ยนเป็น `WAITING_OPEN_PERIOD` หรือใช้ posting date ในงวดเปิดตาม policy
- Missing anchor, pending cost, date mismatch, unsupported event และ missing lineage หยุดเฉพาะ partition เป็น `REQUIRES_REVIEW` ไม่ทำให้ worker process crash
- ทุก exit path ต้อง persist heartbeat, cursor, counters และสาเหตุ ก่อน release lock

## Target Job Architecture

### 1. Trigger Job

ใช้ `wms_cost_revaluation_runs` เป็น run boundary เดิม และเพิ่ม job เฉพาะเมื่อ resolver contract ผ่าน Shadow แล้ว

`QueueCostPropagationRun`

สร้าง Run แบบ idempotent เมื่อเกิด:

- Recost ต้นทางเสร็จ
- Cost Correction ผ่านการอนุมัติ
- Transfer/Receipt/Issue ถูกแก้ไขตาม policy
- Reversal สำเร็จ

Trigger service ต้องอ่านวันที่เอกสารและ root allocations ทุกบรรทัดใน transaction ที่ commit แล้ว จากนั้นสร้าง Parent Run และ dispatch partition jobs แบบ `afterCommit`

### 2. Calculate Chunk Job

`CalculateCostPropagationChunk`

- โหลด anchor และ movement ของ partition แบบ keyset chunk
- เรียก AVG/FIFO resolver แล้วขยาย explicit bridge เฉพาะเมื่อพบ
- ตรวจ duplicate/cycle/orphan และบันทึก cursor/heartbeat
- จำกัด nodes/time ต่อ job และ dispatch continuation หลัง commit
- ตรวจ cooperative-yield budget ระหว่างแต่ละ page; ห้ามรอให้ memory/time limit ของ PHP เป็นตัวหยุด

### 3. Apply Classified Delta Job

`ApplyCostPropagationDeltaChunk`

- lock run revision และ delta rows ของ chunk
- สร้าง immutable Cost Allocation delta ตาม impact bucket
- ปรับ Stock Balance เฉพาะ `INVENTORY_ON_HAND` และ pool/bridge ที่มี on-hand จริง
- สร้าง Journal Revaluation ตาม target event/account mapping ไม่ใช้ `inventory.recost` เหมารวม
- สร้าง immutable allocation-to-journal-line proof
- บันทึกผลต่อ Node แบบ idempotent

### 4. Continue Job

`ContinueCostPropagationRun`

- enqueue partition/bridge ถัดไปที่ dependency ครบ
- ประมวลผลเป็น batch
- update progress/heartbeat
- retry เฉพาะ node ที่ล้มเหลว
- serialize run ต่อ root revision และ lock partition ช่วงสั้น เพื่อไม่ชนกับ posting/อีก revaluation run

### 5. Finalize Job

`FinalizeCostPropagationRun`

- ตรวจ Source Delta = Ending On-hand + Terminal Consumed + Open Bridge + Residual
- ตรวจ Stock/Allocation/GL reconcile
- เปลี่ยนสถานะ COMPLETED หรือ PARTIAL
- แจ้งเตือน Implementer/Accounting หากต้องตรวจสอบ

## Cost Policy

### AVG

- คำนวณ delta จากจำนวนคงเหลือและต้นทุนเฉลี่ย ณ Node
- กระจาย delta ไปยัง On-hand และ downstream ที่ยังเหลือ
- สินค้าที่ถูกขาย/เบิกไปแล้วต้องสร้าง COGS/Expense revaluation

### FIFO

- เดินตาม Cost Layer และ allocation split เดิม
- ห้ามรวมหลาย Layer เป็นยอดเดียวจนสูญเสีย lineage
- แต่ละ Layer ต้องมี propagated quantity/value แยกกัน
- Residual จาก rounding ให้ลง Layer/รายการสุดท้ายตาม deterministic order

## Warehouse และ Branch Transfer

สำหรับ Transfer ข้ามคลัง/สาขา:

- Transfer Out และ Transfer In ต้องอยู่ใน graph เดียวกัน
- ต้นทุนของ Transfer In ต้อง inherit จาก Transfer Out
- Recost ต้นทางต้อง propagate ข้าม branch ได้
- Journal ต้องอ้างอิง Warehouse/Branch ของแต่ละฝั่งถูกต้อง
- ห้ามใช้ Company-wide aggregate แทน Stock Scope จริง
- ต้องรองรับ Transfer ที่ปลายทางถูกขาย/เบิกต่อแล้ว

## Transaction Boundary

แต่ละ Node ต้องทำงานใน transaction เดียว:

```text
Lock Node
→ Verify Revision
→ Create Delta Allocation
→ Update Stock Projection
→ Create/Reconcile Journal
→ Link Allocation ↔ Journal Line
→ Mark Node Applied
```

หากขั้นใดล้มเหลวต้อง rollback เฉพาะ Node นั้น และ Run ต้องอยู่ในสถานะ Retryable

## Reconciliation Contract

ทุก Run ต้องตรวจ:

- Source Delta = Ending On-hand Delta + Terminal Consumed Delta + Open Bridge Delta + Residual
- Cost Allocation ไม่มี Pending ที่ไม่คาดหมาย
- ไม่มี unlinked allocation
- Stock Projection delta ตรงกับ allocation
- Journal Debit/Credit สมดุล
- Inventory/COGS/Variance account ตรงกับ Event Mapping
- ไม่มี duplicate revaluation
- ไม่มี cycle หรือ orphan node
- Branch/Warehouse scope ถูกต้อง

ผลต่างแม้แต่ทศนิยมต้องเก็บเป็น residual ที่ระบุ Node/Allocation ได้ ห้ามปัดทิ้งเงียบ ๆ

## Accounting Verification Contract — Stock Card to GL

หน้า `/wms/stock/{item}?branch_id={branch}&warehouse_id={warehouse}&item_id={item}` เป็นจุดตรวจหลักระดับ **Stock Subledger รายสินค้า/คลัง** แต่ห้ามใช้หน้า Stock Card เพียงหน้าเดียวเพื่ออนุมัติผล Revaluation หรือปิดงวดบัญชี

ลำดับการตรวจสอบมาตรฐานของฝ่ายบัญชี:

1. **Stock Card** — ตรวจลำดับตาม `business_date`/วันที่เอกสาร, เอกสารต้นทาง, จำนวนรับเข้า-จ่ายออก, ต้นทุน Movement และยอดจำนวน/มูลค่าสะสม
2. **Stock Valuation** — ตรวจมูลค่าคงเหลือ ณ วันสิ้นงวดใน scope Branch/Warehouse เดียวกัน
3. **Cost Lineage / Revaluation Run** — ตรวจต้นทุนเดิม, immutable delta, ต้นทุนหลังปรับ, downstream document, residual และสถานะของทุก partition/job
4. **GL Reconciliation** — ตรวจว่า Inventory Cost Allocation/Subledger เท่ากับบัญชีคุมสินค้าคงเหลือ และตรวจ COGS, WIP, Issue Expense, Production/Purchase Variance ตาม impact bucket

Stock Card ต้องใช้ต้นทุน effective หลังรวม immutable Revaluation Delta โดยยัง drill-down กลับไปดูต้นทุนเดิมและ Run ที่ทำให้เปลี่ยนแปลงได้ และต้อง:

- แสดง `Original Value`, `Revaluation Delta` และ `Effective Value` แยกกัน
- แสดงต้นทุนต่อหน่วยคงเหลือจาก running quantity/value หลัง Movement ไม่ใช้ unit cost ของ Movement มาแทน
- แสดงสถานะ `FINAL`, `PENDING`, `REVALUED`, `RUNNING`, `PARTIAL` หรือ `REQUIRES_REVIEW` อย่างชัดเจน
- แสดงผลตาม `business_date` และ deterministic order; `created_at` ใช้เพื่อ audit เท่านั้น
- แจ้งเตือนและห้าม Accounting sign-off เมื่อมี Pending Cost, งาน Revaluation ค้าง/ล้มเหลว, missing lineage, residual ที่ยังไม่จัดสรร หรือ reconciliation difference
- แสดงหรือเชื่อมไปยังสรุป `Stock Card/Allocation Value`, `Stock Balance Projection`, `Stock Valuation` และ `GL Inventory Balance` ณ as-of date/scope เดียวกัน พร้อมผลต่าง

Accounting sign-off ผ่านได้เมื่อ Run ทุก partition อยู่สถานะสำเร็จ, ไม่มี blocker และผลต่าง Stock Allocation ↔ Stock Projection ↔ GL เป็นศูนย์ภายใน currency rounding policy เท่านั้น

## Queue และ Operational Design

- queue แยก `cost-propagation` จากงานทั่วไป
- รองรับ backoff และ max attempts
- ใช้ unique job identity ต่อ Run/Node/Revision
- มี timeout และ heartbeat
- ป้องกัน concurrent run ของ root เดียวกัน
- มี admin action: Resume, Retry Failed Nodes, Cancel Queued Run
- ห้ามมี Reset/Delete Data แบบง่าย
- เก็บ technical detail สำหรับ Developer และข้อความ business สำหรับ User

## Manual Recovery Console

ต้องมีหน้าใน Configuration/Implementation Center สำหรับสั่งคำนวณ Cost Propagation ด้วยตนเอง เมื่อ automatic trigger สูญหาย, queue มีปัญหา หรือเกิดเหตุไม่คาดคิด โดยหน้า Manual ต้องเรียก `CostPropagationTriggerPlanner` และ queue pipeline ชุดเดียวกับ automatic trigger ห้ามมี calculation/write path แยก

- ค้นหาและเลือก Source Document หรือ Source Allocation ได้
- เลือก scope แบบจำกัด: Item, Branch, Warehouse และ `business_date` เริ่มต้น; ค่าเริ่มต้นต้องแคบที่สุดจากเอกสารต้นทาง
- แสดงทุก line/partition ที่จะถูก enqueue ก่อนยืนยัน เพื่อป้องกันตกหล่นในเอกสารหลายรายการ
- บังคับ Preview/Shadow และ preflight ก่อนสร้าง Apply Run
- แสดง Historical Anchor, Impact Window, จำนวน node/partition โดยประมาณ, closed period และ blocker
- ใช้ stable trigger identity/revision และ idempotency เดียวกับ automatic trigger; กดซ้ำต้อง resume/reuse Run เดิมแทนการสร้าง delta ซ้ำ
- ปุ่มหลักใช้เพียง `คำนวณใหม่`, `Resume` และ `Retry Failed`; ห้ามแก้ Posted row, ลบ Run หรือบังคับข้าม blocker
- แยก permission สำหรับ View, Trigger, Approve และ Post Journal; Apply/Post ต้องใช้ผู้อนุมัติที่มีสิทธิ์ตาม policy
- การ Trigger, Preview, Confirm, Retry, Cancel และผลลัพธ์ต้องเก็บ Audit Log พร้อม user, IP, reason, scope, run id และเวลา
- งานขนาดใหญ่ต้อง enqueue background job และแสดง progress/status แบบ polling; HTTP request ห้ามคำนวณ graph ยาวโดยตรง
- Full warehouse/company rebuild เป็น Advanced Emergency action เท่านั้น ต้อง typed confirmation, impact estimate และสิทธิ์ Super Admin/Accounting Admin

## UI/UX ที่ต้องมี

- แสดงสถานะ Cost Propagation บน Source Document
- แสดง `Queued / Running / Waiting / Partial / Completed / Failed`
- แสดง progress แบบไม่ต้อง reload ทั้งหน้า
- แสดง affected documents: Finished Receipt, Transfer, Sale, Issue
- Drill-down จาก delta ไปยัง source/target document
- แสดง old cost, new cost, delta, account และ warehouse/branch
- ปุ่ม Retry เฉพาะ Failed Node
- ปุ่ม Apply/Approve เฉพาะ role ที่ได้รับสิทธิ์
- หน้า Manual Recovery สำหรับ Preview/Trigger/Resume/Retry ผ่าน queue pipeline เดียวกับ automatic trigger

## Rollout Plan

### Phase 0 — Discovery

- [x] map existing lineage และ recost flow
- [x] ตรวจว่าตาราง Cost Allocation, Cost Layer, Recost Request, Transfer Lineage และ Journal Link มีอยู่แล้ว
- [x] ตรวจ period, account mapping, event contract และ Stock Valuation read path
- [x] ระบุจุดที่ยัง propagate ไม่ถึง Finished Receipt, Transfer downstream และ Sale/COGS
- [x] ห้ามสร้าง schema ซ้ำก่อนยืนยัน gap จากข้อมูลจริง

### Phase 1 — Read-only Graph Explorer

- สร้าง graph จากข้อมูลจริง
- ไม่สร้าง Delta/Journal
- ตรวจ cycle/orphan/missing lineage

สถานะปัจจุบัน: **เสร็จในขอบเขต Read-only Explorer** ผ่าน `CostLineageExplorer` และหน้า `/wms/stock-valuation/lineage` โดย reuse permission `wms.stock-valuation.view` และ selected warehouse เดิม ผลลัพธ์แสดง allocation node, parent edge, source movement, cross-warehouse parent closure และปัญหา lineage แบบ read-only; ยังไม่มีการบันทึก graph ลงฐานข้อมูลและยังไม่สร้าง Delta/Journal

Verification read-only บน local `new_erp` วันที่ 2026-09-08: active warehouse แรกพบ `46 nodes`, `12 edges`, `34 roots`, `missing_parents = 0`, `missing_movements = 0`, `cycles = 0`; เป็น smoke result เท่านั้น ยังไม่ใช่ automated test และยังไม่เปิด Apply Delta/Journal

Automated integration readiness test: `tests/Feature/CostLineageExplorerMySqlIntegrationReadinessTest.php` ผ่าน `1 test / 14 assertions` โดยยืนยันว่าอ่านข้อมูลจริงได้และไม่เพิ่ม/แก้ `wms_cost_allocations` หรือ `wms_stock_movements`

Synthetic unit coverage: `tests/Unit/CostLineageExplorerTest.php` ผ่าน `4 tests / 19 assertions` ครอบคลุม cross-warehouse parent closure, missing parent, cycle detection และ shadow delta ratio/variance blocker

### Phase 2 — Shadow Calculation

- คำนวณผลต่างโดยไม่เขียนบัญชี
- เปรียบเทียบกับ Stock Valuation
- เก็บ variance report
- รองรับ Temporal Impact Window จาก Backdated Receipt/Adjustment/Purchase
- แสดง affected downstream ที่เกิดหลัง `business_date` ของต้นทาง
- แยก `impact_date`, `calculation_date` และ `posting_date` ในผลลัพธ์
- ตรวจ closed period โดยไม่สร้าง Journal จริง
- ใช้ Historical Anchor ก่อน Backdated `business_date` และไม่ replay ตั้งแต่ transaction แรก
- แสดง Impact Window, scope, nodes scanned/affected และหยุดเมื่อไม่พบ downstream delta
- มี bounded limit/time budget และสถานะ `REQUIRES_REBUILD` เมื่อ anchor ไม่เพียงพอ

สถานะปัจจุบัน: **AVG/FIFO canonical Shadow เชื่อมใช้งานแล้ว แต่ยังไม่ครบ System-wide** ผ่าน `CostTimelineReader` และ resolver แยกตาม costing method หลัง interface เดิมของ `CostShadowCalculationService`; ทั้งสองวิธีใช้ bounded historical anchor, keyset timeline และ summary ที่ไม่ double count ส่วน Direct Bridge ข้าม partition ยังไม่เปิด Apply

### Phase 3 — Async Delta Apply

- เปิดใช้เฉพาะ internal/test company
- สร้าง Cost Allocation Delta และ Stock Projection
- ยังไม่เปิด Auto Journal หาก mapping ไม่พร้อม

สถานะปัจจุบัน: **มี Apply Run Boundary แต่ยังเป็น foundation เท่านั้น** โดยมี migration/model สำหรับ `wms_cost_revaluation_runs` และ `wms_cost_revaluation_deltas`, approval/preflight/job/idempotency/reconciliation boundary แล้ว แต่ Apply รุ่นปัจจุบันยังปรับ Stock Projection ต่อ affected allocation และยังไม่มี impact classification จึงต้องคง `ERP_REVALUATION_APPLY_ENABLED=false` จนจบ Phase 5–7

### Phase 4 — Journal Revaluation

- เปิด Journal ตาม Event Mapping
- ตรวจ period และ approval policy
- เพิ่ม audit และ reconciliation gate

สถานะปัจจุบัน: **Journal boundary ผ่านในกรณี `inventory.recost` เดี่ยว** โดยใช้ `Run.posting_date`, ตรวจงวด `OPEN`, มี idempotency และ allocation→journal-line proof แต่ยังไม่รองรับ COGS/WIP/Issue Expense/Return buckets จึงคง `ERP_REVALUATION_GL_POSTING_ENABLED=false`; integration test เดิมเป็น foundation test ไม่ใช่ UAT ครบระบบ

### Phase 5 — Canonical Read-only Calculation (งานถัดไป)

- [x] เพิ่ม `EffectiveDocumentDateResolver` และ `DATE_MISMATCH` blocker; ห้ามใช้ `created_at` ใน calculation/order
- [x] เพิ่ม `CostImpact` DTO, `CostImpactBucket` enum และ `CostImpactClassifier` โดยยังไม่เพิ่ม migration
- [x] เพิ่ม read-only trigger planner ที่คืน root allocations ครบทุก document line และ partition plan โดยยังไม่ dispatch จริง
- [x] เพิ่ม `CostTimelineReader` สำหรับ partition เดียวแบบ bounded Historical Anchor และ keyset `(business_date, movement_id, allocation_id)`
- [x] ทำ `AvgPoolResolver` สำหรับ partition เดียวจาก bounded anchor และ deterministic event order
- [x] เชื่อม `CostTimelineReader` + `AvgPoolResolver` เข้า `CostShadowCalculationService` และคง route/UI เดิม
- [x] แยก Shadow summary เป็น source, ending on-hand, terminal, open bridge และ residual
- [x] ยืนยันเคส Adjustment 2026-09-01 → Issue 2026-09-07 ได้ delta ที่ถูกต้อง
- [x] แยก calculation blocker ออกจาก accounting warning: Allocation `PENDING` ที่ Cost Final/Movement Posted เดิน Shadow ต่อได้ แต่ยังห้าม Apply จนมี GL proof
- [x] ทำ pure/read-only `FifoLayerResolver` โดยเทียบ old/new layer consumption split ตาม `stock_cost_layer_id`
- [x] เพิ่ม bounded Historical FIFO Layer Anchor และเชื่อม `FifoLayerResolver` เข้า Shadow โดยไม่โหลดประวัติทั้งก้อน
- [x] blocker เมื่อพบ PENDING cost, missing anchor, duplicate stock owner หรือ unsupported event

Exit gate: Shadow ต้อง reconcile ตาม invariant และไม่มี write ต่อ allocation/balance/journal

Effective Date verification วันที่ 2026-09-08:

- `EffectiveDocumentDateResolver` รองรับ authority ของ Opening, Adjustment, Issue, Issue Return, Transfer Event, Production Finished Receipt, Goods Receipt, Purchasing และ POS แบบ explicit; unknown source ต้องได้ `DATE_AUTHORITY_UNRESOLVED`
- Unit tests ผ่าน 3 scenarios: วันที่ตรงกัน, `DATE_MISMATCH` และ missing authority โดย resolver ไม่รับ `created_at` เป็น input
- Read-only smoke กับ 80 allocations ล่าสุดใน development database: 79 รายการผ่าน และตรวจพบ POS allocation `#1535` ที่เอกสาร `2026-08-28` แต่ Movement/Allocation `2026-08-29`; ถูก block เป็น `DATE_MISMATCH` โดยไม่มีการแก้ข้อมูล
- Root cause ถูกแก้ที่ POS writer: `PhysicalSalePostingService` snapshot `document_date` เป็น Movement `business_date` และ line conversion snapshot; `PhysicalSaleStockPostingIntent` ปฏิเสธ payload ที่ส่ง `business_date` ต่างจาก `document_date`; regression/contract tests ผ่าน `4 tests / 10 assertions` และ related posting-plan suite ผ่าน `11 tests / 32 assertions`
- Legacy Movement `#1765` ของ `IV-2026-000002` ยังมี `business_date=2026-08-29` ขณะที่ `document_date=2026-08-28`; ห้ามแก้ด้วย SQL หรือ mutate immutable Movement ให้ใช้ controlled reversal/repost หรือ legacy recovery ที่มี audit แทน จนกว่า Accounting จะอนุมัติแนวทาง
- กักกันข้อมูลจริงแล้วเป็น Legacy Review `#3` สำหรับ Allocation `#1535` โดยคง Movement `#1765`, Allocation และ Journal `#816` เป็น `POSTED` เดิมทั้งหมด; Review อยู่ `OPEN/REVIEW_REQUIRED` และมี evidence hash/audit สำหรับ Accounting ตรวจสอบ

Impact Classifier verification วันที่ 2026-09-08:

- Classifier map bucket แบบ explicit สำหรับ Inventory On-hand, COGS, Issue Expense, WIP, Finished Goods, Transfer, Return และ Purchase Return; unknown source ได้ `UNSUPPORTED_IMPACT_EVENT` และห้าม fallback เป็น Inventory
- Read-only smoke กับ 65 active allocations ใน development database: ทุก allocation resolve bucket ได้ (`unsupported = 0`)
- Allocation `PENDING` ที่ Movement Posted/Cost Final ถูกจัดเป็น `ACCOUNTING_PROOF_PENDING` warning เพื่อให้ read-only calculation เดินต่อ แต่ Variance ยัง `REQUIRES_REVIEW`; สถานะอื่นที่ไม่ใช่ Pending/Posted ยังคง block
- Unit tests ครอบคลุม 11 event combinations, unknown event และ Pending/lifecycle/scope blockers

Trigger Planner verification วันที่ 2026-09-08:

- เพิ่ม `CostPropagationTriggerPlanner` แบบ read-only รองรับ Opening Balance, Inventory Adjustment, Issue, Issue Return split allocation, Transfer event, Production Finished Receipt, Goods Receipt, Purchase Document/Return และ POS Sale/Return ผ่าน stable document type/id
- planner อ่านทุก Movement/Allocation ของทุก stock line แล้ว group ด้วย `(warehouse, item, base UOM, costing method)`; line ที่อยู่ partition เดียวกันถูกรวมโดยยังเก็บ root line/movement/allocation IDs ครบ
- trigger/child identity คำนวณจาก stable document type/id/revision และ partition key จึงได้ผลเดิมเมื่อ retry หรือ input order เปลี่ยน; planner ระบุ `read_only=true`, `dispatch=false` และไม่มี queue/write path
- blocker ครอบคลุม source lifecycle, เอกสารไม่มี stock line, line ไม่มี Movement/Allocation, Movement ไม่มี Allocation, Effective Date และ Cost Impact lifecycle; ขาดหนึ่ง line หรือ allocation ต้องทำให้ parent plan ไม่ Ready
- Unit regression ของ calculation foundation ผ่าน `30 tests / 118 assertions`; MySQL read-only integration ของเอกสารหลายบรรทัดผ่าน `1 test / 6 assertions` และยืนยันจำนวน Movement, Allocation, Revaluation Run และ Delta ไม่เปลี่ยน
- Development smoke พบ Adjustment/Physical Sale พร้อมวางแผนได้, Issue/Transfer ที่ Allocation ยังไม่ Posted ถูก block ตาม lifecycle และ Goods Receipt/Sales Return ที่ยังไม่มี Stock Movement ถูกแจ้ง `ROOT_LINE_MISSING` ตามข้อมูลจริง ไม่ fallback เป็น Ready

Cost Timeline Reader verification วันที่ 2026-09-08:

- เพิ่ม `CostTimelineReader` แบบ read-only ที่อ่านเฉพาะ `(warehouse, item, base UOM, method)` และเรียงลำดับด้วย `business_date → movement_id → allocation_id`; ไม่ใช้ `created_at`/`updated_at`
- ใช้ keyset cursor และจำกัด page เริ่มต้น 250 สูงสุด 1,000 rows; ไม่ใช้ offset และไม่โหลด timeline ทั้งก้อน
- Historical Anchor คำนวณ quantity/value pool ก่อน impact date จาก allocation ledger แบบ bounded; ค่าเริ่มต้นสูงสุด 5,000 และ hard cap 10,000 rows ถ้าเกินคืน `REQUIRES_REBUILD + ANCHOR_LIMIT_EXCEEDED` โดยไม่ fallback ไป full replay
- Anchor ตรวจ Pending Cost, Allocation/Movement lifecycle, Movement↔Allocation date mismatch และ value ที่ไม่มี quantity; blocker ของ anchor ถูกยกขึ้นระดับ timeline result
- MySQL read-only integration ผ่าน `1 test / 12 assertions` ครอบคลุม keyset หน้าถัดไป, stable order, anchor circuit breaker, query-plan index และยืนยันจำนวน Movement/Allocation/Revaluation Run/Delta ไม่เปลี่ยน
- Smoke กับ `ADJHQ2609000005` allocation `#2101`: scope `warehouse=1,item=1,uom=1,AVG`, anchor พร้อม ณ `2026-08-31` จาก 25 rows และ timeline เริ่ม `2026-09-01` ตามวันที่เอกสาร โดยหน้าแรก 5 nodes มี continuation cursor และไม่มี blocker

AVG Pool Resolver และ Performance verification วันที่ 2026-09-08:

- เพิ่ม pure/read-only `AvgPoolResolver` ที่ replay old/new quantity, value และ average ต่อ event; รองรับ root value/unit-cost override, RECOST ที่ไม่เปลี่ยน quantity, downstream OUT ที่ใช้ average ใหม่ และคืน delta/ก่อน/หลังทุก allocation
- Arithmetic ใช้ `BigDecimal` scale 8; เศษจากการคำนวณคงอยู่ใน ending value เช่น `79.99999999` ไม่ถูกปัดทิ้งเงียบ และ timeline ที่ out-of-order/duplicate/Pending/blocker ต้องหยุด partition แบบไม่ทำให้ worker crash
- เปลี่ยน query ของ calculation foundation จาก `whereDate` เป็น comparison ตรงบน DATE column และกำหนด `select` ทุก bounded `get()` ที่เกี่ยวข้องใน Timeline, Trigger Planner, Lineage Explorer และ Shadow foundation
- เพิ่ม migration `2026_09_08_030000_add_cost_timeline_partition_index.php` แบบ retry-safe สำหรับ `(warehouse_id,item_id,uom_id,method,business_date,stock_movement_id,id)` และเพิ่ม Installer schema guard; migration ถูก apply บน development แล้ว
- MySQL `EXPLAIN` เลือก `wms_ca_timeline_partition_idx` แบบ `range` (`Using where`, ไม่มี `Using filesort`) โดยไม่ต้อง `FORCE INDEX`
- Unit/performance foundation ผ่าน `38 tests / 153 assertions`; MySQL read-only pipeline ผ่าน `3 tests / 32 assertions` โดย row counts ของ Movement/Allocation/Revaluation Run/Delta ไม่เปลี่ยน
- Read-only AVG smoke ของ `ADJHQ2609000005` allocation `#2101` กระจาย delta ไป COGS และ WIP ตามวันที่เอกสารได้; Allocation ที่ยังไม่มี GL proof ถูกเก็บเป็น accounting warning จึงยังไม่เปิด Apply

Canonical AVG Shadow integration วันที่ 2026-09-08:

- `CostShadowCalculationService` ใช้ canonical timeline/resolver สำหรับ `method=AVG` โดยอัตโนมัติ ส่วน FIFO ยังอยู่เส้นทางเดิมจนมี resolver เฉพาะ; response ระบุ contract `cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof`
- รองรับ keyset continuation สูงสุด 1,000 rows ต่อ query และส่ง old/new pool state แยกกันข้ามหน้า; bounded run สูงสุด 10,000 nodes และคืน `TIMELINE_LIMIT_REACHED` แทน full-table replay
- Summary reconcile แบบไม่ double count ด้วย `Source Delta = Ending On-hand + Terminal + Open Bridge + Rounding Residual`; Stock Valuation เปลี่ยนเฉพาะ Ending On-hand ของ partition ต้นทาง
- เพิ่ม batch prime วันที่เอกสารต่อ timeline page สำหรับ Opening, Adjustment, Issue/Return, Transfer, Production Receipt, Goods Receipt, Purchasing และ POS; development profile 17 timeline rows ลดจาก 22 queries ที่มี query ซ้ำ เหลือ 15 queries ไม่มี repeated SQL และ service wall timeประมาณ 51 ms
- Unit/performance regression ก่อน lifecycle split ผ่าน `40 tests / 164 assertions`; ชุดล่าสุดให้อ้างอิงผล verification ด้านล่าง
- แก้ lifecycle gate โดยแยก `ACCOUNTING_PROOF_PENDING` เป็น warning ที่ไม่หยุด read-only arithmetic แต่ยังทำให้ Variance เป็น `REQUIRES_REVIEW`; Cost=`PENDING`, Movement ไม่ Posted และสถานะอื่นที่ไม่ใช่ Pending/Posted ยังคงเป็น calculation blocker
- Development Shadow ของ `ADJHQ2609000005` เดินถึง `ISSUEHQ2609000004` allocation `#2054` วันที่ 2026-09-07 เป็น `WIP_CONSUMED` พร้อม delta และ reconcile source/on-hand/terminal/bridge/residual ลงตัว โดย row count ของ ledger/run/delta ไม่เปลี่ยน
- เพิ่ม MySQL regression `CostShadowAvgMySqlIntegrationReadinessTest` สำหรับเส้นทาง Adjustment 2026-09-01 → Material Issue 2026-09-07; fixture ไม่มีให้ Skip อย่างชัดเจน ไม่สร้างข้อมูลทดสอบลง development database
- Verification ล่าสุดผ่าน Unit/performance `41 tests / 167 assertions` และ MySQL read-only pipeline `4 tests / 39 assertions`; Blade compile และ `git diff --check` ผ่าน

Pure FIFO Layer Resolver verification วันที่ 2026-09-08:

- เพิ่ม pure/read-only `FifoLayerResolver` สำหรับ partition ที่เรียงตาม `(business_date, movement_id, allocation_id)` โดยไม่ query หรือเขียนฐานข้อมูลภายใน resolver
- รักษา layer identity ด้วย `stock_cost_layer_id`, รองรับ receipt override, partial consumption และ old/new layer snapshot สำหรับ resume ข้าม bounded timeline page
- หยุด partition อย่างปลอดภัยเมื่อ layer หาย, consume เกิน remaining quantity, layer ซ้ำ, timeline out-of-order หรือ allocation ซ้ำ
- `RECOST` ถูก block ด้วย `FIFO_RECOST_REQUIRES_LAYER_REBUILD` จนกว่า Historical FIFO Layer Anchor จะ reconstruct layer revision ได้ครบ; ห้ามคาดเดาหรือ fallback เป็น AVG
- `CostTimelineReader` reconstruct active FIFO layer ก่อนวันเอกสารจาก allocation ledger ตาม `stock_cost_layer_id`; ไม่ใช้ `remaining_quantity` ปัจจุบันซึ่งถูก transaction หลังวันย้อนหลังเปลี่ยนไปแล้ว
- Historical FIFO anchor ตรวจ missing layer, duplicate layer owner, consume ก่อน receipt, overdraw, Pending lifecycle และมี hard cap เดิมสูงสุด 10,000 allocations; เกินขอบเขตคืน `REQUIRES_REBUILD`
- `CostShadowCalculationService` เลือก canonical FIFO path อัตโนมัติและคืน contract `cost-shadow-v2-fifo`; continuation เก็บ old/new layer snapshot แยกกันข้าม keyset page
- SQLite regression ยืนยัน anchor จาก receipt 10 ลบ prior issue 4 เหลือ 6 และ reconcile `source 10 = ending 4 + bridge 6 + residual 0`
- Development MySQL fixture allocation `#320 → #321` กระจาย delta ตาม FIFO layer จริงและยืนยัน ledger ไม่เปลี่ยน
- Unit/performance regression ผ่าน `48 tests / 204 assertions`; MySQL read-only pipeline รวม Lineage, Trigger, Timeline, AVG และ FIFO ผ่าน `5 tests / 47 assertions`
- ขั้นนี้ไม่มี migration, Installer schema หรือ ERP seed data ใหม่

### Phase 6 — Direct Bridge Coverage

- [x] Explicit parent: reversal, issue return, sales return และ RECOST
  - [x] batch discovery + signed delta propagation สำหรับ Reversal, Issue Return, Sales Return และ AVG RECOST
  - [x] ส่ง parent unit delta ต่อข้าม keyset page และตรวจ child delta เทียบ explicit edge
  - [ ] FIFO RECOST ต้อง rebuild pending/resolved layer revision ก่อนจึงถอด blocker ได้
- [x] Transfer full accept/full reject ข้าม warehouse/branch
  - [x] ยืนยัน Current UI Contract: รับเข้าหรือปฏิเสธยอดคงเหลือครบทั้งเอกสาร (`full_receipt=1`); ไม่เปิดให้กรอก partial ต่อบรรทัด
  - [x] Resolve Transfer OUT → `TRANSFER_ACCEPT`/`TRANSFER_REJECT` ตาม allocation quantity จริง เพื่อรองรับข้อมูลเดิมจาก service/API ที่อาจเป็น partial โดยไม่เพิ่ม partial UI
  - [x] Replay target warehouse partition หลัง `TRANSFER_ACCEPT` จนถึง downstream terminal/transfer ถัดไป; `TRANSFER_REJECT` replay ใน source timeline เดิม พร้อม bounded continuation
- [x] Purchase return full/partial และแยก `NON_RETURN`
  - [x] Full return ใช้ explicit parent allocation และ relation `PURCHASE_RETURN_FULL`; delta ออกจาก inventory ตาม quantity/value ของ reversal จริง
  - [x] Partial return ใช้ canonical AVG/FIFO timeline ใน partition เดิมและจัด impact เป็น `PURCHASE_RETURN_CONSUMED` ตาม `return_date`
  - [x] `NON_RETURN` ไม่มี Stock/Cost side effect; หากพบ legacy/invalid Stock node ให้หยุดด้วย `NON_RETURN_STOCK_EVENT_FORBIDDEN`
  - [x] Current posting contract รองรับ Purchase Return ทีละหนึ่ง GR line; multi-line fan-out เป็นงาน parent-run ใน Phase 8 และห้ามรวมแบบ unbounded ใน worker เดียว
- [x] Sales Credit Note และ Sales Return แยก stock effect ชัดเจน
  - [x] `sales_documents/CREDIT_NOTE` เป็น `NON_RETURN`: ลด AR/Revenue/VAT โดยไม่สร้าง Stock/Cost node และบังคับอ้าง Sales Invoice ต้นทาง
  - [x] `pos_sales_returns` เป็น `RETURN`: รับสินค้าคืน, reverse COGS และเชื่อม parent Cost Allocation ของ HS/IV ต้นทาง
  - [x] Journal metadata เก็บ `credit_note_mode=NON_RETURN|RETURN`; หาก Credit Note แบบไม่คืนมี item/stock line ให้ block และแนะนำใช้ Sales Return
  - [x] Engine เดิน `SALES_RETURN` bridge ต่อได้ ส่วน Sales Credit Note `NON_RETURN` ไม่ trigger recost เพราะจำนวนและต้นทุนสินค้าไม่เปลี่ยน
  - [x] แก้ Sales Return receipt cost ให้ส่ง `receipt_value` scale 8 และ reuse Cost Allocation ที่ Stock Movement สร้างแทนการสร้างซ้ำ; MySQL rollback-only HS/IV partial return ผ่าน `2 tests / 25 assertions`
- [x] Production material issue หลายใบ → finished receipt หลาย output พร้อม deterministic residual
  - [x] เพิ่ม normalized bridge ต่อ Source Cost Allocation พร้อม `consumed_quantity`/`consumed_value`; backfill `source_issue_id` เดิมแบบ idempotent และบังคับ schema ผ่าน Installer
  - [x] Shadow resolver รองรับ material allocation หลายรายการ → receipt/output หลายรายการ และปัด residual เข้า output สุดท้ายตาม `(document_date, receipt_id, allocation_id)`
  - [x] Replay ต่อจาก Finished Goods ไป Transfer/downstream partition โดยใช้ node/partition/depth/cycle limit ชุดเดียวกับ Engine
  - [x] Finished Receipt writer และ Gate C preflight รองรับหลาย Source Issue, กันใช้ allocation ซ้ำ และตรวจ stale source revision
  - [x] Finished Receipt UI เลือกใบเบิกหลายใบและกำหนด partial consumed value; ระบบคำนวณ consumed quantity/value ตามสัดส่วน allocation คงเหลือและลง residual ที่ allocation สุดท้ายแบบ deterministic
  - [x] MySQL integration: หลายใบเบิก → หลายใบรับ/หลาย output → transfer พร้อม ledger unchanged ใน Shadow

Production Bridge UI verification วันที่ 2026-09-09:

- หน้า Finished Receipt ใช้ Select2 แบบ server-side เลือก Production Material Issue ได้หลายใบ และกำหนด consumed value แยกต่อใบได้
- Source selector จำกัดครั้งละ 30 รายการ, เลือกเฉพาะ columns ที่ใช้, scope ตาม warehouse และเสนอเฉพาะ Cost Allocation ที่เป็น `FINAL` และยังมีต้นทุนคงเหลือ
- Writer แบ่ง consumed quantity/value ตามสัดส่วน allocation คงเหลือ และเก็บ residual 8 ตำแหน่งไว้ allocation สุดท้ายตาม source allocation ID
- Validation ป้องกันยอดศูนย์/ติดลบ/เกินคงเหลือ, allocation ที่ยังไม่ Final, การใช้ source ข้ามคลัง และ stale/mixed source revision
- Filter วันที่หน้า Finished Receipt เปลี่ยนเป็น indexed range predicate โดยไม่ใช้ `whereDate`
- Unit/contract regression ผ่าน `34 tests / 173 assertions`; Blade compile และ `git diff --check` ผ่าน
- migration `2026_09_09_010000_create_wms_production_receipt_sources.php` รันเฉพาะไฟล์บน Development แล้วเป็น batch 195; migration `2026_09_04_210000` และ `2026_09_07_060000` ยังคง Pending

Production Bridge MySQL integration preparation วันที่ 2026-09-09:

- เพิ่ม rollback-only fixture ใน `CostDirectBridgeMySqlIntegrationReadinessTest` ครอบคลุม 2 Material Issues → 2 Finished Receipts → 4 Outputs → Transfer Accept และตรวจ ledger counts ก่อน/หลัง Shadow
- fixture ใช้ normalized bridge จริง, partial split `60/40`, ตรวจ 8 production edges, 4 output allocations, transfer replay และ residual เป็นศูนย์
- normalized graph ผ่าน `1 test / 7 assertions`; Production Bridge + Manual Production Receipt regression รวมผ่าน `8 tests / 42 assertions` โดย skip 1 legacy fixture ที่ไม่มีข้อมูลจริงรองรับ
- ยืนยัน `ProductionBridgeResolver` ได้ 8 edges/4 output allocations, Recursive Shadow เดินต่อผ่าน Transfer Dispatch OUT → Accept IN, rounding residual เป็นศูนย์ และ ledger counts ก่อน/หลังไม่เปลี่ยน
- [x] เลือก canonical purchase stock owner เป็น Purchase Invoice (`PURCHASING`) และเพิ่ม duplicate guard

Canonical Purchase Stock Owner verification วันที่ 2026-09-09:

- Purchase Invoice (`PURCHASING`) เป็น owner เดียวของ Stock Movement, Cost Allocation และ Journal; Goods Receipt คงเป็นหลักฐานรับสินค้า/จำนวน/UOM conversion และ 3-way matching
- ปิด internal Goods Receipt inventory writer ด้วย validation ที่ชัดเจน และ Purchase Invoice preflight ตรวจ legacy `GOODS_RECEIPT` movement เฉพาะ GR line ที่เชื่อมก่อนเขียนข้อมูล จึงไม่เกิด stock owner ซ้ำ
- Landed Cost resolve ต้นทุนผ่าน Invoice→GR allocation และ indexed Purchase Invoice movement identity แทนการค้น JSON ของ Goods Receipt; หากไม่พบหรือพบหลาย owner จะหยุดด้วย explicit blocker
- แก้ partial Invoice allocation ให้ base quantity คำนวณจาก `allocated_quantity × GR factor` ไม่ใช้ stock quantity เต็มของ GR ซ้ำ
- Query ที่เพิ่มเลือกเฉพาะ columns ที่ใช้, จำกัดอยู่ใน receipt lines ของเอกสาร และ batch movement identity ใน query เดียว; ไม่มี migration หรือ Installer schema/seed เพิ่ม
- Rollback-only MySQL regression ครอบคลุม Purchase Invoice owner, legacy duplicate blocker, Landed Cost, Purchase Return full/partial, Credit Note `RETURN`/`NON_RETURN` และ FIFO multi-layer ผ่าน `11 tests / 80 assertions` โดย skip 1 persistent legacy fixture; Unit/contract ที่เกี่ยวข้องผ่าน `23 tests / 172 assertions`

Exit gate: transaction matrix ทุก stock row ต้อง resolve เป็น supported bucket หรือ explicit blocker ห้ามตก default

Explicit Parent Bridge verification วันที่ 2026-09-08:

- เพิ่ม `CostDirectBridgeResolver` แบบ read-only ซึ่ง batch-read child allocation ตาม `parent_allocation_id` ครั้งละ 250 parents, จำกัด fan-out สูงสุด 1,000 edges และเลือกเฉพาะ columns ที่ใช้
- map relation แบบ explicit เป็น `REVERSAL`, `ISSUE_RETURN`, `SALES_RETURN`, `RECOST`; event ข้าม partition ที่ยังไม่รองรับคืน `DIRECT_BRIDGE_EVENT_UNSUPPORTED` โดยไม่ fallback
- AVG/FIFO resolver ส่ง normalized parent unit delta ไป child ตาม quantity ratio และทิศทาง IN/OUT; state `propagation` เดินต่อข้าม keyset page ได้ภายใต้ run cap เดิม
- Return IN หัก terminal consumed และ Return reversal บวกกลับตาม signed delta; Transfer/Finished Goods bridge คำนวณ open bridge แบบไม่ double count ending on-hand
- Shadow response เพิ่ม `direct_bridges`, จำนวน edge/target partition และ blocker เมื่อ timeline delta ไม่ตรง explicit edge
- หน้า Shadow แสดงตาราง `Direct Parent Bridges` สำหรับ Parent, Child, relation, target partition, delta และ blocker เพื่อให้ Accounting/Developer ตรวจเส้นทางได้
- Development smoke ของ `ISSUE #2041 → ISSUE_RETURN #2042 → REVERSAL #2056` เปลี่ยนจาก `-0.33000000 → 0 → ค่า AVG ผิดสาย` เป็น `-0.33000000 → +0.22000000 → -0.22000000`
- Unit/performance regression ผ่าน `55 tests / 233 assertions`; MySQL read-only pipeline รวม Phase 5–6 ผ่าน `6 tests / 50 assertions` และยืนยัน ledger ไม่เปลี่ยน
- พบ Transfer Accept ข้าม `warehouse 1 → 676` ใน fixture จริง; blocker `DIRECT_BRIDGE_EVENT_UNSUPPORTED` เดิมถูกถอดเมื่อเพิ่ม Transfer Bridge contract ในขั้นถัดมา
- ขั้นนี้ไม่มี migration, Installer schema หรือ ERP seed data ใหม่

Transfer Bridge verification วันที่ 2026-09-08:

- ยืนยัน Web UI ปัจจุบันส่ง `full_receipt=1` และรับเข้าหรือปฏิเสธยอดคงเหลือครบทั้งเอกสาร จึงไม่เพิ่ม Partial Transfer UI
- `CostDirectBridgeResolver` จำแนก explicit parent ของ `WMS_TRANSFER/TRANSFER/IN` จาก movement metadata เป็น `TRANSFER_ACCEPT` หรือ `TRANSFER_REJECT`
- `TRANSFER_ACCEPT` เปิด target partition ตาม Warehouse/Item/UOM/Cost Method ของ allocation ปลายทาง; `TRANSFER_REJECT` คืนเข้า source partition และทั้งคู่คำนวณ signed delta ตาม allocation quantity จริง
- Development MySQL fixture ของ Transfer Accept ข้ามคลัง resolve เป็น `TRANSFER_ACCEPT` และไม่คืน `DIRECT_BRIDGE_EVENT_UNSUPPORTED`; ledger counts ไม่เปลี่ยน
- Shadow เดิน Transfer destination แบบ breadth-first, batch-load root allocation ต่อ frontier และคง timeline keyset continuation เดิม โดยจำกัดรวมสูงสุด 10,000 nodes, 1,000 partitions และ depth 64
- Recursive reconciliation หัก bridge ที่ replay แล้วออกจาก Open Bridge จากนั้นรวม Ending On-hand, Terminal และ bridge ที่ยังไม่ถูก consume ข้ามทุกคลังโดยไม่ double count
- หากเริ่ม Shadow จาก Transfer OUT โดยตรง ระบบจัดเป็น internal bridge root และแยก `root_transfer_bridge_delta_value` เพื่อให้มูลค่าที่ออกจากต้นทางกับเข้าปลายทางหักล้างกันในระดับบริษัท
- หน้า Shadow แสดง `Transfer Destination Replay` พร้อม depth, target partition, nodes scanned, on-hand delta และ open bridge ของแต่ละคลัง
- Unit/performance regression ผ่าน `48 tests / 213 assertions`; MySQL read-only integration ผ่าน `3 tests / 14 assertions`, Blade compile, Pint และ `git diff --check` ผ่าน
- ขั้นนี้ไม่มี migration, Installer schema หรือ ERP seed data ใหม่; งานถัดไปคือ Purchase Return full/partial และแยก `NON_RETURN`

Purchase Return Bridge verification วันที่ 2026-09-09:

- ยืนยันเส้นทางจริง 3 แบบ: Full Return สร้าง immutable reversal ที่มี `parent_allocation_id`, Partial Return สร้าง `PURCHASING/ISSUE/OUT` ตาม `return_date`, และ Credit Note `NON_RETURN` ลง AP เท่านั้นโดยไม่สร้าง Movement/Allocation
- เพิ่ม semantic metadata `purchase_return_mode=FULL|PARTIAL` และ `credit_note_mode=RETURN` ที่ movement writer โดย reuse `StockMovementService`; ไม่สร้าง Purchase Return costing algorithm ซ้ำ
- `CostDirectBridgeResolver` จำแนก Full Return เป็น `PURCHASE_RETURN_FULL`; ส่วน Partial Return replay ผ่าน AVG/FIFO timeline เดิมและ `CostImpactClassifier` ส่งไป `PURCHASE_RETURN_CONSUMED`
- เพิ่ม safety gate: หากข้อมูลผิดปกติสร้าง Stock node ที่ระบุ `NON_RETURN` ระบบ Shadow จะหยุดด้วย `NON_RETURN_STOCK_EVENT_FORBIDDEN` และไม่ fallback เป็น inventory event
- Unit/performance regression ผ่าน `50 tests / 220 assertions`; MySQL integration เฉพาะ fixture ที่พร้อมผ่าน `2 tests / 14 assertions` โดยยืนยัน `NON_RETURN` ไม่มี Stock side effect และ FIFO Partial Return พร้อม semantic metadata
- MySQL AVG fixture บางเคสถูก block โดย baseline development data มีต้นทุนติดลบ (`Costing value ต้องเป็นเลขทศนิยมไม่ติดลบ`) ซึ่งเกิดก่อนเข้า Purchase Return writer จึงไม่แก้กลบใน Phase นี้
- ขั้นนี้ไม่มี migration, Installer schema หรือ ERP seed data ใหม่; งานถัดไปคือ Production material issue หลายใบ → finished receipt หลาย output พร้อม deterministic residual

Production Bridge foundation verification วันที่ 2026-09-09:

- เพิ่ม `wms_production_receipt_sources` ที่ผูก Receipt กับ Source Cost Allocation โดยตรง เก็บ issue document/line, consumed quantity/value และ deterministic position; ไม่พึ่ง fixed ID
- migration backfill เอกสารเดิมจาก `source_issue_id` ทีละ 100 เอกสารและใช้ upsert เพื่อรันซ้ำได้โดยไม่ duplicate; index รองรับค้นจาก source allocation และ issue line
- `ProductionFinishedReceiptController` รับ Source Issue ได้หลายใบผ่าน request contract, snapshot FIFO/AVG source allocations ลง bridge เมื่อสร้างหรือแก้ Draft, กันใช้ allocation ซ้ำ และล้าง bridgeเมื่อเอกสารถูกลบ; `source_issue_id` คงไว้เป็น legacy pointer ของใบแรก
- Gate C รวมต้นทุนจาก bridge ทุกใบและตรวจ source allocation revision; ถ้าต้นทุนถูก recost หลังสร้าง Draft จะหยุดด้วย `SOURCE_COST_REVISION_CHANGED` ให้บันทึก Draft ใหม่ก่อน Post
- `ProductionBridgeResolver` batch-read เฉพาะ columns ที่ใช้, จำกัด fan-out 1,000, ไม่ใช้ `whereDate` และตรวจ source over-consumption, quantity/value ratio, receipt value reconciliation และ output-before-input
- canonical Shadow รวม delta จากหลาย material allocations ต่อ output เดียวก่อน replay และเดินต่อ Finished Goods → Transfer/downstream โดยไม่ double count terminal WIP
- deterministic residual ลง output สุดท้ายตาม `business_date → receipt_document_id → output_allocation_id`; partial consumption คง delta ที่เหลือไว้ใน WIP
- Installer Prepare Database ตรวจ table/columns ใหม่ก่อนประกาศ Database Ready; ไม่มี ERP seed data ใหม่
- Unit/performance/migration regression ครอบคลุมหลาย source allocations, หลาย receipts, หลาย outputs, partial/over-consumption, idempotent legacy backfill และ Production → Transfer recursive replay
- งานถัดไปคือขยาย UI ให้เลือกหลาย Source Issue และระบุ partial consumption จริง จากนั้นรัน MySQL integration บน schema ใหม่ก่อนปิด checklistหลัก

### Phase 7 — Classified Apply และ Accounting Events

- [x] ขยาย `wms_cost_revaluation_deltas` ด้วย `impact_bucket`, `target_event`, target Warehouse/Branch และ `stock_projection_delta_value`; migration `2026_09_09_020000` อยู่ใน Installer Prepare Database พร้อม schema guard และรันบน development `new_erp` batch 196 แล้ว
- [x] Apply ใช้ `stock_projection_delta_value` ของแต่ละ recursive partition เท่านั้น ไม่ใช้ raw movement delta ปรับ Stock ทุก node; rollback-only MySQL ยืนยัน terminal delta ไม่เปลี่ยน Stock และ ending on-hand delta เปลี่ยน Stock ถูกต้อง
- [x] เพิ่ม classified event/mapping contract สำหรับ COGS, Issue Expense, Sales/Issue/Material Return, WIP, Finished Goods, Rounding และ Purchase Return Cost Varianceแล้ว; Standard COA เพิ่ม `ISSUE_EXPENSE`/`PURCHASE_RETURN_VARIANCE` และ Apply System Update ผ่าน Installer เป็น `accounting.chart_of_accounts v1.5`; bucket matrix และ Apply→Journal→Reconciliation ผ่าน rollback-only MySQL แล้ว
- [x] Generic/Material Issue และ Issue/Material Return สร้าง Stock/Cost/Journal ใน transaction เดียว, ผูก immutable Journal proof ครบทุก FIFO allocation, retry-safe และ reversal ของ Issue Return ย้อนครบทุก split; event `inventory.issue`, `inventory.issue_return`, `production.material_issue`, `production.material_return` เป็น LIVE
- [x] Transfer เป็น `NO_GL`: allocation ใหม่ปิด lifecycle เป็น POSTED หลัง movement สำเร็จ และ Shadow/Preflight ไม่เรียกร้อง Journal proof จาก legacy Transfer bridge ที่ PENDING
- [x] Classified Journal writer เก็บ allocation-line proof และ immutable posting metadata (`source run`, `impact bucket`, `impact date`, `posting date`, mapping provenance); Transfer bridge เป็น `NO_GL`; rollback-only MySQL ผ่าน 10 accounting routes พร้อม reconciliation
- [x] closed period ใช้ `Run.posting_date` ในงวดเปิดถัดไปโดยไม่แก้ journal เดิม พร้อม direct range predicate

Exit gate: Apply→GL→reconciliation ผ่าน MySQL integration ทุก bucket โดย feature flags ยังปิด


ผลล่าสุด 2026-09-09: Direct Parent + Classified Revaluation ผ่าน `12 tests / 83 assertions` (skip legacy Production fixture 1 รายการ โดย normalized many-source/many-output fixture ผ่าน); Apply System Update ผ่าน Installer, `accounting.chart_of_accounts v1.5`, feature flags ยังปิด และ transaction fixture ถูก rollback ทั้งหมด

### Phase 8 — Async Scale, Concurrency และ Recovery

- [~] queue `cost-propagation`, keyset chunk, checkpoint, heartbeat และ bounded time/node budget (Apply cooperative yield ทำแล้วโดย commit ทีละ Delta; Calculation/Bridge continuation ทำแล้ว; เหลือ benchmark production-sized graph และวัด worst-case query/page latency)
- [x] transaction event dispatcher แบบ `afterCommit`, parent-run multi-line fan-out และ expected/completed/failed partition counters; wire เข้ากับ WMS, Purchase Invoice/Reversal, Purchase Return, Landed Cost, HS/IV, Sales Return และ HS/IV cancellation แล้ว
- [x] business stop, cooperative yield และ hard circuit breaker พร้อมสถานะ `WAITING_CONTINUATION`, `LIMIT_REACHED`, `REQUIRES_REVIEW`, `FAILED_RETRYABLE` รวม Recovery UX ระดับ Run
- [x] unique root revision, active partition lease และ stale revision preflight ก่อนคำนวณ/ก่อน finalize; claim/finalize ถือ row lock เฉพาะ transaction สั้น งานคำนวณ graph อยู่นอก transaction และ lease ที่หมดอายุ reclaim ได้
- [x] retry/resume/cancel queued run โดยไม่ duplicate; active lease ห้าม Resume, expired lease ส่งกลับ queue เดิมได้ และห้าม Cancel หลังมี Delta `APPLIED`
- [x] compensating run เมื่อ reversal เกิดหลัง revaluation
- [x] สร้าง Manual Recovery Console ที่ reuse Trigger Planner/Dispatcher/Run/Job เดิม พร้อม document preview, bounded scope/search, Resume/Cancel และ audit
- [~] Recovery สำหรับเอกสาร Issue/Return legacy ที่ Post ก่อนมี Journal proof: เพิ่ม `LegacyIssueAccountingProofRecoveryService` และหน้า `/wms/stock-valuation/legacy-accounting-proof` สำหรับ preview/recovery แบบ bounded, ตรวจ Posted document, allocation, mapping และงวด OPEN ก่อนเรียก `IssueAccountingPostingService`; ใช้ `posting_date` ที่ผู้ดูแลระบุโดยคง `document_date` เดิมและเก็บ `LEGACY_ORIGINAL_PROOF` metadata; ใช้ permission recovery เดิม, rollback-only MySQL smoke และ UAT read-only ผ่าน โดยพบ Issue #29/#32 พร้อมกู้คืน; ไม่ auto-backfill Journal ด้วย SQL/ปลอมวันที่บัญชี เหลือ approval เพื่อกู้คืนจริงและ runbook
- [x] manual trigger ของ document หลาย line สร้าง partition ครบ และกดซ้ำ reuse Batch/Run identity เดิมโดยไม่ duplicate
- [x] permission แยก View/Trigger/Approve/Post และ Emergency rebuild ต้อง typed confirmation: เพิ่ม `wms.cost-revaluation.emergency-rebuild`, Installer `core.rbac@1.3`, route/UI ระดับสาขา, typed confirmation `EMERGENCY REBUILD {BRANCH_CODE}`, queue ผ่าน bounded scope planner และ Audit Log แล้ว; เพิ่ม `wms.cost-allocation-reviews.approve` แยกจาก View พร้อม Contract และ HTTP/Role negative-permission test และเพิ่ม Revaluation lifecycle contract + HTTP/Role negative-permission test ครบ Trigger/Approve/Post/Recover/Cancel/Emergency รวม positive admin/role-scoped integration test แล้ว
- [x] benchmark ภายในหนึ่งปีบัญชี หลายคลัง หลายสาขา และ high fan-out (ตาม policy ระบบปิดงบทีละปี จึงไม่ต้องทดสอบข้ามหลายปี; ฐาน `new_erp_benchmark` มี 2 branches, 10 warehouses, 10 partitions และ bounded fan-out 100 nodes/partition จาก Purchase Invoice authority; benchmark read-only วันที่ 2026-09-12 พบ 1,000 nodes / 821.170 ms / peak 34 MB / queue lag 0 / ไม่พบ blocker)

Phase 8 runtime foundation verification วันที่ 2026-09-09:

- เพิ่ม migration `2026_09_09_030000_add_runtime_checkpoint_to_wms_cost_revaluation_runs.php` สำหรับ `runtime_checkpoint`, `nodes_scanned`, `heartbeat_at` และ recovery index; Installer Prepare Database ตรวจ schema ชุดนี้แล้ว และ migration รันบน development `new_erp` แล้ว
- `ApplyCostRevaluation` และ `PostCostRevaluationJournal` ใช้ queue `cost-propagation`; Apply ใช้ keyset `delta.id`, จำกัด chunk สูงสุด 1,000 (default 250), จำกัดเวลาต่อ chunk และสถานะ `WAITING_CONTINUATION`
- Apply preflight โหลดเฉพาะ Delta ของ chunk ปัจจุบัน จึงไม่ eager-load Delta ทั้ง Run ใน worker; synchronous service contract เดิมยังทำงานครบทุก chunk
- เพิ่ม `CalculateCostRevaluation` queued boundary: สร้าง Run ด้วย stable source revision ก่อนคำนวณ, worker บันทึก Canonical Shadow/checkpoint/heartbeat และจบเป็น `PENDING_APPROVAL`, `REQUIRES_REVIEW`, `LIMIT_REACHED` หรือ `FAILED_RETRYABLE`; HTTP/trigger caller ไม่ต้องคำนวณ graph ใน request
- เพิ่ม `calculatePartitionChunk()` ที่อ่าน AVG/FIFO ทีละ keyset page และคืน checkpoint เฉพาะ cursor, resolver state, source revision และ cumulative node count; resume ไม่ replay node เดิม และไม่เก็บ timeline rows ทั้งหมดใน checkpoint
- แต่ละ chunk ตรวจ stale source revision, root/method/date identity, node limit และ memory growth budget; Direct/Production bridge edges ถูก resolve ต่อ page เพื่อเตรียม persisted frontier
- เพิ่ม migration `2026_09_09_040000_add_partition_counters_to_wms_cost_revaluation_runs.php` สำหรับ `expected_partitions`, `completed_partitions`, `failed_partitions`; Installer Prepare Database ตรวจครบ และ migration รันบน development `new_erp` แล้ว
- Calculation worker persist classified Delta ทีละ chunk, รวม Direct Transfer/Production bridge delta เป็น child frontier, de-duplicate partition root, เก็บ current/pending partition ใน checkpoint และ finalization ต้องรอ `completed === expected` โดยไม่มี failed partition
- hard stop ครอบคลุม global node budget จาก config, memory growth, depth 64, partition roots 1,000, cycle และ invalid bridge cost; continuation job dispatch หลัง commit และ retry ไม่สร้าง Delta ซ้ำด้วย idempotency key ต่อ allocation
- Unit/Installer contract ผ่าน `8 tests / 73 assertions`; rollback-only MySQL queued/keyset continuation ผ่าน `4 tests / 23 assertions` รวมหลาย chunk และ ledger counts ไม่เปลี่ยน; feature flags ยังปิด
- Async runtime checklist ยังเป็น `[~]` เพราะเหลือ partition concurrency/stale lock, cooperative time-budget yield และ recovery UX

Phase 8 document trigger/parent batch verification วันที่ 2026-09-09:

- เพิ่ม retry-safe migration `2026_09_09_050000_create_wms_cost_revaluation_batches.php`; Parent Batch มี stable document revision identity, immutable trigger snapshot, expected/resolved root lines และ aggregate partition counters; child Run มี `batch_id + partition_key` unique และ Installer Prepare Database ตรวจ schema ครบ
- `CostPropagationTriggerDispatcher` reuse read-only planner, query allocation cost/revision แบบ batch, สร้าง Parent/Child ทั้งชุดใน transaction และ queue `CalculateCostRevaluation` ด้วย `afterCommit`; rollback ไม่เหลือ Batch/Run และ retry revision เดิม reuse identity เดิม
- child calculation หนึ่ง run ต่อ `Warehouse + Item + Base UOM + Method` และ checkpoint เก็บ root override ทุก allocation ใน partition; resolver รับหลาย root พร้อมกันแทนการใช้ line แรก
- `CostRevaluationBatchService` aggregate expected/completed/failed จาก child runs และห้าม Parent เป็น `PENDING_APPROVAL` จนทุก child calculation ผ่าน; trigger snapshot เปลี่ยนภายใต้ document revision เดิมหยุดเป็น `REQUIRES_REVIEW`
- Trigger planner จำกัด root query ตาม node budget, ไม่ `get()` แบบไร้ขอบเขต และ document date หายเป็น blocker
- migration รันบน development `new_erp`; Unit/Installer/Planner ผ่าน `14 tests / 108 assertions`, MySQL dispatcher/retry/rollback/counter และ queued continuation รวมผ่าน `7 tests / 40 assertions`; feature flags ยังปิด
- เพิ่ม flag `ERP_REVALUATION_AUTO_TRIGGER_ENABLED` ซึ่งปิดโดย default; guard อยู่ใน dispatcher จุดเดียวและเมื่อปิดจะไม่ query planner, ไม่สร้าง Batch/Run และไม่ queue job
- wire dispatcher ภายใน transaction ปลายทางของ WMS core แล้ว: Opening Balance, Issue/Material Issue, Issue/Material Return + Reversal, Transfer dispatch/accept/reject, Inventory Adjustment document + Reversal และ Manual Finished Receipt + Reversal
- Transfer ใช้จำนวน immutable events เป็น source revision และวันที่ event ล่าสุดเป็น trigger document date; Adjustment/Finished Receipt reversal ใช้ reversal date; Issue Return reversal resolve movement ทุก cost split ผ่าน parent allocation ไม่ตกเพียง split แรก
- WMS regression ขณะ gate ปิดผ่าน `16 tests / 101 assertions`; writer-hook จริงเปิด gate ชั่วคราวใน rollback-only Opening Balance ผ่านพร้อม Parent/Child/afterCommit proof และ Dispatcher/Opening suite รวม `5 tests / 32 assertions`; ไม่มี test data ค้าง
- Purchase/Sales trigger wiring วันที่ 2026-09-09: hook เฉพาะ writer ที่สร้าง Posted Stock Movement จริง; Purchase/Sales Credit Note แบบ `NON_RETURN` ไม่ trigger, Purchase Invoice เป็น canonical receipt owner, Full Return ใช้ credit-return reversal root และ Partial Return ใช้ Purchase Return root
- Landed Cost trigger ใช้ receipt parent allocation พร้อมต้นทุนใหม่แบบสะสม `original unit cost + RECOST ถึง business_date / quantity`; ไม่ใช้ delta unit cost เป็น replacement cost และบังคับ effective date จาก Landed Cost document
- Sales Return planner resolve movement ผ่าน normalized `pos_sales_return_inventory_links` จึงรองรับทั้ง partial return และ full cancellation โดยไม่พึ่ง JSON/source reference ที่เปลี่ยนตาม reversal
- Root discovery ใหม่ใช้ direct predicates, selected columns และ bounded limit ตาม node budget; ไม่มี `whereDate` หรือ unbounded transaction scan เพิ่มใน trigger path
- Rollback-only MySQL verification: Purchase Invoice/Landed Cost/HS/IV/Sales Return ผ่าน `6 tests / 69 assertions`, Full/Partial Purchase Return ผ่าน `2 tests / 19 assertions`; unit/contract ผ่าน `27 tests / 209 assertions`. Physical Sale cancellation legacy fixture ยังถูก baseline negative-cost guard หยุดก่อนถึง trigger และต้องแก้ fixture แยก
- งานถัดไปคือ recovery/cancel UX ตาม Phase 8
- Active partition concurrency วันที่ 2026-09-09: reuse `status + heartbeat_at + runtime_checkpoint` เป็น expiring lease จึงไม่เพิ่ม lock table/migration; worker claim และ finalize ด้วย transaction สั้นเฉพาะ Run row, คำนวณ Shadow/Graph นอก transaction, token ป้องกัน stale worker เขียนทับ worker ที่ reclaim งาน และตรวจ root revision แบบ batch ก่อนเริ่มกับก่อน persist/finalize; mismatch จบ `REQUIRES_REVIEW` ด้วย `STALE_ROOT_REVISION`
- Lease default 120 วินาที (บังคับขั้นต่ำ 90 วินาที ซึ่งมากกว่า calculation job timeout 60 วินาที), final calculation ไม่ eager-load Delta ทั้ง Run; rollback-only MySQL ผ่าน `6 tests / 31 assertions` รวม active lease, expired reclaim และ stale stop ก่อนสร้าง Delta; Unit/Installer contract ผ่าน `12 tests / 126 assertions`
- Apply lock-duration hardening วันที่ 2026-09-09: ยกเลิก transaction ครอบทั้ง chunk 20–50 วินาที เปลี่ยนเป็น short claim → chunk preflight นอก transaction → atomic transaction ทีละ Delta → short finalize; ทุก Delta commit checkpoint/heartbeat พร้อม immutable RECOST + Stock Projection, retry จึงเริ่มต่อจาก `last_delta_id` โดยไม่ duplicate, active Apply lease ใช้ backoff 5 วินาที และไม่ eager-load Delta ทั้ง Run; rollback-only MySQL continuation/Stock Projection ผ่าน `2 tests / 14 assertions`
- Run Recovery/Cancel วันที่ 2026-09-09: เพิ่ม `CostRevaluationRecoveryService` โดย lock เฉพาะ Run row ใน transaction สั้น, Resume ใช้ Calculation/Apply Job เดิมและปฏิเสธ active lease หรือ Apply gate ที่ปิด, Cancel ได้เฉพาะก่อนมี Delta `APPLIED`; หลังเริ่มกระทบ Stock/Cost ต้องใช้ compensating run แทน พร้อม typed confirmation, warehouse scope, Audit Log และ permission `approve/post/recover/cancel` แยกกัน
- Installer `core.rbac` เป็น `v1.1` เพื่อส่ง permission ใหม่ผ่าน Apply System Update แบบ versioned/idempotent โดยไม่มี migration หรือ manual seed; Unit/Installer ผ่าน `13 tests / 144 assertions`, Blade compile ผ่าน และ rollback-safe MySQL Recovery/Cancel ผ่าน `2 tests / 7 assertions`
- Document-level Manual Trigger Console วันที่ 2026-09-09: เพิ่ม `/wms/stock-valuation/manual-trigger` พร้อม permission `wms.cost-revaluation.trigger`; รองรับค้นหา 12 document contracts แบบ warehouse-scoped, prefix search และจำกัด 20 รายการ, Preview root lines/allocations/partitions/blockers แล้ว enqueue ผ่าน `CostPropagationTriggerDispatcher` เดิมเท่านั้น กดซ้ำจึง reuse identity เดิม และทุกครั้งมี Audit Log
- Scope-level Manual Trigger วันที่ 2026-09-09: เพิ่มทางเลือกคำนวณใหม่ตั้งแต่ `business_date` ตามสาขา, หลายคลัง/ทุกคลัง และหลายสินค้า/ทุกสินค้า โดยตรวจสิทธิ์จาก `user_warehouse`, freeze horizon ด้วย allocation id, วางแผนแบบ keyset สูงสุด 50 partitions ต่อ job และ fan-out ผ่าน `CostRevaluationApplyService` เดิม; HTTP ทำเฉพาะ preview/create batch/enqueue, cursor และ progress เก็บใน batch snapshot จึง retry ได้โดยไม่สร้าง Run ซ้ำ
- Landed Cost ใช้ `LandedCostPropagationCostResolver` ร่วมกันทั้ง automatic posting และ manual trigger เพื่อคง cumulative parent cost contract; HTTP ไม่เรียก Shadow graph synchronously และไม่มี migration ใหม่; Installer `core.rbac` ขยับเป็น `v1.2`
- Verification: Unit/Installer `14 tests / 155 assertions`, Blade/route/syntax ผ่าน, MySQL rollback-only manual trigger multi-root/idempotency/audit `1 test / 6 assertions`; read-only option query ทั้ง 12 ประเภทผ่านโดยเลือกเฉพาะคอลัมน์จำเป็น และ fixture ที่ lineage ไม่ครบแสดง blocker แทนการ enqueue
- Compensating Reversal Run วันที่ 2026-09-10: reversal allocation ที่มี `parent_allocation_id` resolve ต้นทุนมีผลจริงจาก Applied/GL Posted Revaluation Delta ของต้นทาง ณ `business_date` ของรายการกลับเท่านั้น แล้ว enqueue ผ่าน Parent Batch/Child Run/Shadow/Apply pipeline เดิม; Completed Run เดิมไม่ถูกแก้ไข, retry revision เดิม reuse Batch/Run identity และ snapshot เก็บ Source Run/Delta lineage สำหรับ drill-down
- Resolver อ่าน root/parent/delta/run แบบ bounded ตาม Trigger root plan, เลือกเฉพาะคอลัมน์จำเป็น, ไม่เปิด transaction ยาว และหยุดเป็น `REQUIRES_REVIEW` เมื่อ effective date หายหรือต้นทุนติดลบ; MariaDB `EXPLAIN` ใช้ `wms_cost_revaluation_deltas_allocation_id_foreign` แบบ `ref` ไม่ full-scan; ไม่มี migration หรือ Installer schema/default update เพราะ reuse `parent_allocation_id`, Delta และ `trigger_snapshot` เดิม
- Verification: Unit/Installer contract `17 tests / 182 assertions`, Blade compile ผ่าน, MySQL rollback-only effective-cost/date-authority `1 test / 5 assertions` และ Trigger Dispatcher regression รวม compensating document E2E `5 tests / 27 assertions`; ledger counts หลัง rollback ไม่เปลี่ยน
- Blocker fix วันที่ 2026-09-10: `CostImpactClassifier` ไม่ block allocation ที่เป็น Issue Return reversal pair แบบ `NO_GL_REVERSAL_PAIR` ด้วย `ALLOCATION_NOT_POSTED` อีกต่อไป เพราะคู่กลับรายการนี้ไม่มี Journal proof โดย policy; กรณีอื่นยังคงใช้ lifecycle/accounting proof gate เดิม
- Verification บน development `new_erp`: Run `#170` จาก ADJ-237 เป็นผลจาก contract เดิมและถูกเก็บไว้เป็น historical run; ห้ามนำกลับมา Apply หลังแก้ canonical quantity
- Canonical AVG split-quantity correction วันที่ 2026-09-10: `CostTimelineReader` เพิ่ม `pool_quantity` โดยอิง Posted Movement `base_quantity` เพียงครั้งเดียวต่อ Movement ขณะที่ Cost Allocation split ยังรวม `value` แยกแถวครบ; `AvgPoolResolver` ใช้ `pool_quantity` เฉพาะการเปลี่ยนจำนวนใน pool และคง allocation value/lineage เดิม. เพิ่ม regression test split allocation และเปลี่ยน contract เป็น `cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof`
- Verification หลังแก้บน development `new_erp`: Shadow จาก Allocation `#2101` (ADJ-237, `2026-09-01`) ได้ Allocation จ่ายออก `#1659` ต้นทุนใหม่ `78.34782390` (`78.35` ตาม Global Setting) จากเดิมที่ replay ซ้ำเป็น `78.42659182`; Run `#171` ที่สร้างก่อน legacy-proof policy ถูกเก็บเป็น stale review และห้าม Apply
- Legacy accounting proof hardening วันที่ 2026-09-10: Issue/COGS allocation ที่ไม่มี Journal proof ถูกจัดเป็น `ACCOUNTING_PROOF_PENDING` แม้ allocation จะ POSTED; Transfer bridge และ Issue Return reversal ที่ policy ระบุ NO_GL ยัง exempt ตามเดิม. กรณีนี้ต้องเข้า Legacy Review ก่อน ไม่ปล่อยให้ Apply ผ่านโดยอัตโนมัติ
- Manual Trigger proof-aware idempotency วันที่ 2026-09-10: เพิ่ม fingerprint ของ `allocation.updated_at`, จำนวน allocation ที่มี Journal link และจำนวน allocation ใน scope เข้า trigger identity; หลัง Accounting กู้คืน Journal proof แล้ว Manual Scope Trigger จะสร้าง Batch/Run รุ่นใหม่ ไม่ reuse Run เก่าที่ถูกกักกันด้วย contract/proof state เดิม
- Journal-proof loading fix วันที่ 2026-09-10: `CostDirectBridgeResolver` และ Transfer replay เลือก `journal_entry_id`, `status` และ `cost_status` ให้ครบก่อนส่งเข้า `CostImpactClassifier`; ป้องกัน false `ACCOUNTING_PROOF_PENDING` หลัง Accounting กู้คืน Journal แล้ว และ bump scope fingerprint เป็น `legacy-proof-v4-journal-aware-timeline` เพื่อบังคับสร้าง Run ใหม่จากข้อมูล proof ปัจจุบัน
- Development verification วันที่ 2026-09-10: Scope Batch `#41` สร้าง Run `#194` (AVG item 1) เป็น `PENDING_APPROVAL`, `READY_FOR_REVIEW`, 16 nodes affected / 18 scanned, ไม่พบ blocker; Run `#195` ของ Production partition ยังถูกกักกันด้วย `ALLOCATION_2059:ALLOCATION_NOT_POSTED` ตาม lifecycle gate จึงยังห้าม Apply ทั้ง Batch
- Pre-Apply reconciliation checkpoint วันที่ 2026-09-10: Run `#194` ผ่านการคำนวณและ Approval แต่ `CostRevaluationReconciliationService` ยังรายงาน `run_status`, `delta_count`, `allocation_status`, `inventory_value` เป็น BLOCKED เพราะ `applied_count=0` และ `journal_count=0`; ถือเป็นสถานะตามลำดับก่อน Apply ไม่ใช่ ledger discrepancy และห้ามกด Complete ก่อนเปิด staged Apply/GL gate
- Apply dispatch UX วันที่ 2026-09-10: เพิ่ม route/controller/UI สำหรับส่ง Run `APPROVED` เข้า `ApplyCostRevaluation` queue โดยตรวจ Feature Gate และ Preflight ก่อน dispatch; Apply ยังไม่เปิดใน Development จนกว่า Accounting sign-off และ staged gate จะได้รับอนุมัติ
- Development Apply verification วันที่ 2026-09-10: เปิดเฉพาะ `ERP_REVALUATION_APPLY_ENABLED=true` และคง GL gate เป็น `false`; Run `#194` Apply สำเร็จเป็น `STOCK_PROJECTED`, 16/16 Planned Delta เป็น `APPLIED`, Stock Projection ถูกปรับแล้ว, ยังไม่มี Journal และ RECOST allocation ที่มี Accounting event ยังเป็น `PENDING` ตาม lifecycle ก่อน GL Posting; ห้ามเปิด GL gate ก่อน Accounting sign-off
- GL posting blocker วันที่ 2026-09-10: ตรวจ Run `#194` แล้วพบว่า `CostRevaluationJournalPostingService` เดิมอ่านค่า absolute จาก RECOST allocation ทำให้ Delta ติดลบบางรายการ (เช่น Issue Return) ถูกลงบัญชีผิดด้าน; เปลี่ยนให้ใช้ signed `CostRevaluationDelta.delta_value` เป็น source of truth และกัก Run ไว้ที่ `GL_POSTED` จนกว่าจะมี compensating correction/reversal ของ Journal ที่ลงไปแล้ว. หลัง correction และ reconciliation ผ่าน จึงเปิด `ERP_REVALUATION_GL_POSTING_ENABLED=true` เฉพาะ development; production ยังคงต้องเปิดผ่าน staged feature gate หลัง Accounting sign-off
- Immutable Journal correction วันที่ 2026-09-10: เพิ่ม `CostRevaluationJournalCorrectionService` สำหรับตรวจ Journal ต่อ Delta, reverse Journal เดิม และ post correction ใหม่แบบ idempotent โดยไม่แก้ Posted ledger เดิม; Run `#194` แก้ 10 mismatched journals และข้าม 6 รายการที่ถูกต้อง/เป็น NO_GL bridge. Reconciliation เปลี่ยนเป็นเทียบ Inventory Journal กับ signed event impact และปัดเศษต่อ Delta 2 ตำแหน่งให้ตรงกับ GL; Run `#194` ผ่านทุก check และปิดเป็น `COMPLETED` แล้ว (`expected_inventory_journal_value=5091.83000000`, `journal_inventory_value=5091.83000000`, balanced)
- Calculation contract idempotency: เพิ่ม contract version ใน Run idempotency key เพื่อไม่ reuse delta จาก algorithm รุ่นเก่าเมื่อ canonical replay เปลี่ยน
- Legacy Run UX วันที่ 2026-09-10: หน้า Revaluation Detail ตรวจ `calculation_contract_version` และแยก Run เก่า (`PENDING`/ไม่มี Canonical Shadow Snapshot) เป็น `REQUIRES_REVIEW` พร้อมคำแนะนำให้สร้าง Run ใหม่ จึงไม่ทำให้ข้อความ “ส่งเข้าคิวแล้ว” ถูกตีความว่าอนุมัติหรือพร้อม Apply
- Stock Card reconciliation fix วันที่ 2026-09-10: Running Value เคยสูงกว่า Stock Balance `0.6875` เพราะ query อ่านเฉพาะ Cost Allocation `POSTED`; Transfer bridge และ Issue Return reversal ที่มี Movement `POSTED`/Cost `FINAL` แต่ allocation `PENDING` ถูกตัดออกทั้งที่เป็น no-GL lifecycle ตาม policy. ปรับ read path ให้รวมสถานะ `POSTED/PENDING` เฉพาะ `cost_status=FINAL` และคงการตัด `REVERSED`; ผลตรวจ Movement/Allocation เทียบ Historical Valuation ตรงกันที่ `234024.02500000` โดยไม่แก้ ledger
- Stock Card average-cost correction วันที่ 2026-09-10: Historical Valuation เดิมรวม `CostAllocation.quantity` ทำให้ split allocation ของ Movement เดียวถูกนับซ้ำและได้ `final_quantity=2910`; เปลี่ยน quantity source เป็น Posted Movement ledger แบบ grouped `warehouse + item` และคง allocation เป็น value source. ผลใหม่ `final_quantity=2912`, `final_value=234024.02500000`, average `80.36539492` (`80.37` ตาม Global Setting); query ใช้ qualified predicates และไม่เพิ่ม unbounded read
- Final-issue zero residual fix วันที่ 2026-09-11: เมื่อ AVG/FIFO/Revaluation ทำให้ `on_hand=0` ระบบบังคับ `inventory_value=0` และ `average_unit_cost=0` เพื่อไม่ให้เศษจากการปัดทศนิยมค้าง; Stock Card running value/average ใช้กติกาเดียวกันเมื่อ running quantity ปัดที่ 8 ตำแหน่งเป็นศูนย์. เพิ่ม regression test `CostingCalculatorTest::test_final_average_issue_clears_quantity_value_and_unit_cost`
- Stock Card opening-value fix วันที่ 2026-09-11: เมื่อเลือก `date_from` ให้ running value/average เริ่มจาก Historical Valuation ของวันก่อนหน้าเช่นเดียวกับ opening quantity; ป้องกันยอดติดลบ/ต้นทุนติดลบใน DataTable ทั้งที่ยอดยกมาถูกต้อง
- Engine terminal issue fix วันที่ 2026-09-11: `AvgPoolResolver` บังคับ state เป็น quantity/value/average ศูนย์เมื่อ Final Issue ทำให้ quantity เหลือศูนย์; Stock Card ใช้ opening quantity/value แยกกันและ zero running value เมื่อยอดจำนวนหลังรายการเป็นศูนย์. กรณี `ISSUEHQ2609000005` จึงต้องแสดงยอดคงเหลือ `0 / 0.00 / 0.00` ไม่ใช่ running value ติดลบจากการหักมูลค่าซ้ำ
- Historical valuation terminal-zero fix วันที่ 2026-09-11: `InventoryCostAllocationService::historicalValuationQuery` บังคับ `final_value=0` เมื่อ Posted Movement quantity รวมเหลือศูนย์ เพื่อให้ `/wms/stock` และ Stock Card ใช้กติกาเดียวกัน; กรณี Item #1 ต้องตรวจ ณ วันที่ก่อน ADJ-272 จึงได้ terminal `0/0`, ส่วน ณ 2026-09-10 หลัง ADJ-272 ต้องได้ `12,000/240,000`
- Stock list summary cards วันที่ 2026-09-11: `/wms/stock` ส่งยอดรวม On-hand/Reserved/Available พร้อม Average Unit Cost และ Inventory Value ใน DataTable response; หน้า list แสดง cards ครบ ไม่ปล่อยค่า `-` เมื่อเลือกคลังและมีข้อมูล
- Stock summary BigDecimal formatter fix วันที่ 2026-09-11: แปลงยอดรวมจาก `BigDecimal` เป็น decimal string ก่อนส่ง `WmsDecimal::format` ป้องกัน `Object of class Brick\\Math\\BigDecimal could not be converted to float`
- Terminal pool reset verification วันที่ 2026-09-11: rebuild scope Warehouse 1 / Item 1 / UOM 1 แล้วได้ `on_hand=12,000`, `inventory_value=240,000`, `average=20.00`; Stock Card endpoint แสดง ISSUEHQ2609000005 เป็น `0/0/0` และ ADJ-272 เป็น `12,000/20/240,000` ตรงกับ Historical Valuation

### Phase 9 — UAT และ Production Rollout

#### Stock Balance Projection Reconciliation checkpoint

- [x] เพิ่ม read-only reconciliation ระดับ `warehouse + item + uom` ที่เทียบ persisted Stock Balance กับ Posted Movement, non-reversed Cost Allocation และ OPEN reservation โดยยึด `business_date` และไม่ mutate ledger/projection ใน read path
- [x] คืนสถานะ `MATCHED`, `DIFFERENCE` หรือ `REBUILD_REQUIRED` พร้อม actual/expected/delta และ evidence count เพื่อส่งต่อให้ bounded rebuild/revaluation job
- [x] เปิด endpoint `GET /wms/stock/{item}/reconciliation?uom_id=...&as_of=...` และเพิ่ม contract test ป้องกันการเขียนข้อมูลใน reconciliation path
- [x] เพิ่ม UI card ใน Stock detail, รองรับการเลือก UOM/as-of และลิงก์ส่งต่อไป Manual Trigger เพื่อสร้าง bounded rebuild หลังตรวจสอบ scope; read path ไม่ซ่อมยอดเอง
- [x] ทำ benchmark scope ใหญ่ภายในหนึ่งปีบัญชี หลายสาขา/หลายคลัง และ high fan-out; ไม่บังคับข้ามหลายปีตาม policy ปิดงบรายปี และผล 1,000 nodes / 821.170 ms / peak 34 MB / queue lag 0 ผ่านแล้ว

- [x] เปิด Development staged rollout ระยะ Shadow/Queue-only โดย `AUTO_TRIGGER=true`, `APPLY=false`, `GL_POSTING=false`; health check ผ่านและ queue pending/failed/stale/review เป็นศูนย์
- [x] เปิด staged rollout ระยะ Apply โดย `AUTO_TRIGGER=true`, `APPLY=true`, `GL_POSTING=false`; health check/gate readiness ผ่านและ queue pending/failed/open run เป็นศูนย์
- [x] เปิด staged rollout ระยะ GL Posting โดย `AUTO_TRIGGER=true`, `APPLY=true`, `GL_POSTING=true`; controlled suite ผ่าน 15 tests / 148 assertions (skip 1 operational evidence), health/gate readiness ผ่าน และ queue pending/failed/open/stale/review เป็นศูนย์
- [ ] Accounting ตรวจ Stock Card รายสินค้า/คลังตาม `business_date` และ drill-down ไป Source/Target/Revaluation Run ได้
- [ ] Accounting ตรวจ Stock Valuation และ GL ใน as-of date, Branch และ Warehouse scope เดียวกัน
- [x] Accounting review delta/journal ทุก bucket และ GL จริง sign-off สำหรับ Development/UAT controlled rollout หลัง Allocation ↔ Projection ↔ GL reconcile ผ่าน; Production sign-off ยังต้องทำหลังเปิดผ่าน Configuration Center
- [~] Accounting UAT readiness: Purchase Invoice → Journal → Movement → Cost Allocation, Issue/Return, Purchase Credit Note, Sales Return และ automated Revaluation Journal impact matrix ผ่านใน `new_erp_benchmark`; เหลือ Accounting ตรวจหลักฐานจริงและ sign-off ระบบรวม
- [x] Shadow blocker review: Run `#171` เป็น stale review หลังเพิ่ม legacy accounting proof gate; Shadow ใหม่ยังแสดงต้นทุนจ่ายออกถูกต้อง แต่ Apply ถูก block จน Accounting ตรวจหลักฐาน Journal
- [ ] Stock Card แยก Original Value, Revaluation Delta, Effective Value, running unit cost และ cost/run status ชัดเจน
- [ ] ห้าม sign-off เมื่อมี Pending/Running/Partial/Requires Review, missing lineage, unresolved residual หรือ reconciliation difference
- [ ] monitoring dashboard, failed-run alert และ recovery runbook
- [x] เปิด Apply และ GL แยก flag แบบ staged rollout ใน Development/UAT; Production ต้องเปิดผ่าน Configuration Center หลัง Accounting sign-off
- [ ] หลังผ่าน UAT จึงเปิดตาม Company/Module ผ่าน Configuration Center

## Approved Implementation Sequence

ลำดับทำงานรอบถัดไปให้เริ่มจาก calculation core และไม่แตะ write path ก่อน:

1. สร้าง `EffectiveDocumentDateResolver` เพื่อ resolve วันที่เอกสารและตรวจ movement/allocation snapshot; `created_at` ใช้ audit เท่านั้น
2. สร้าง immutable `CostImpact` DTO, `CostImpactBucket` enum และ `CostImpactClassifier` ใต้ `app/Modules/Wms` โดย map source/event ที่รู้จักแบบ explicit; unknown ต้อง throw blocker
3. สร้าง read-only `CostPropagationTriggerPlanner` ที่อ่านทุก Posted stock line/allocation และคืน parent-run partition plan ครบถ้วนก่อนมี queue writer
4. สร้าง `CostTimelineReader` โหลด partition เดียวแบบ keyset พร้อม anchor state และ normalize event order
5. สร้าง `AvgPoolResolver` แบบ pure/read-only รับ opening quantity/value + ordered movements แล้วคืน before/after state และ classified impacts
6. เชื่อม resolver ใหม่เข้า `CostShadowCalculationService` หลัง interface เดิม โดยยังคงหน้า UI และ route เดิมเพื่อลดผลกระทบ
7. แก้ summary/reconciliation ให้แยก source/on-hand/terminal/bridge/residual และ block Apply service หาก snapshot ไม่มี `calculation_contract_version` ใหม่
8. เพิ่ม Unit test ของ date authority, multi-line fan-out, arithmetic/order/rounding และ MySQL read-only regression สำหรับ `ADJHQ2609000005` → `ISSUEHQ2609000004`; test ต้องยืนยัน row count/checksum ไม่เปลี่ยน
9. เมื่อ AVG exit gate ผ่าน จึงทำ pure `FifoLayerResolver` แล้วเพิ่ม bounded Historical FIFO Layer Anchor/Shadow integration ก่อนทำ bridge resolver ตาม Phase 6
10. หลังทุก read-only resolver ผ่านเท่านั้นจึงออก migration สำหรับ classified delta/parent-run counters; migration ใหม่ต้องถูกตรวจใน Installer Prepare Database และต้อง retry-safe

ไฟล์เดิมที่ต้อง reuse: `StockCostLayerService`, `InventoryCostAllocationService`, `CostLineageExplorer`, `CostShadowCalculationService`, `wms_cost_revaluation_runs`, `wms_cost_revaluation_deltas` และ `wms_cost_allocation_journal_lines` ห้ามสร้าง costing ledger ชุดที่สอง

## Tests ที่ต้องมี

- Backdated Adjustment Gain → AVG pool → Issue วันถัดไป
- Backdated Purchase Receipt/Invoice → Sale/Issue/Transfer ที่เกิดภายหลัง
- Same-day ordering ตาม `(business_date, movement_id, allocation_id)`
- Document date authority และ `DATE_MISMATCH` blocker; เปลี่ยน `created_at` แล้วผล calculation ต้องไม่เปลี่ยน
- Multi-line source document fan-out ครบทุก allocation/partition และ finalize ไม่ได้เมื่อขาดหนึ่ง line
- หลาย line ของ Item/UOM/Warehouse เดียวกันถูกรวม partition โดยไม่เสีย root identity
- Trigger retry ของ document revision เดิมต้องได้ run/child identity เดิม
- Manual trigger และ automatic trigger ของ source revision เดียวกันต้อง resolve เป็น Run identity เดียวกันและไม่สร้าง Delta/Journal ซ้ำ
- Manual trigger ของเอกสารหลาย line ต้อง preview และ enqueue root partition ครบทุก line
- ผู้ไม่มี permission ต้อง Trigger/Approve/Post/Emergency rebuild ไม่ได้ และทุก manual action ต้องมี Audit Log
- Manual HTTP request ต้อง enqueue งานเท่านั้นและไม่ประมวลผล graph ขนาดใหญ่ใน request
- Trigger dispatch ต้องเกิดหลัง commit และ rollback ต้องไม่ทิ้ง queued run
- Stop เมื่อ old/new pool state กลับมาเท่ากัน
- Terminal node และ zero-delta ต้องหยุดโดยไม่ enqueue child เพิ่ม
- Chunk limit/time budget/memory threshold ต้อง Yield แล้ว resume จาก cursor โดยไม่คำนวณซ้ำ
- Cycle, max depth, max nodes, max partitions และ max fan-out ต้องหยุด Run อย่างปลอดภัยและห้าม Completed
- Missing anchor/date mismatch/pending cost/unsupported event ต้องหยุดเฉพาะ partition โดย worker ไม่ crash
- Finalizer ต้องปฏิเสธ Run ที่ outstanding jobs หรือ partition counters ยังไม่ reconcile
- Recost Receipt → Material Issue
- Recost Material Issue → Finished Receipt
- Material Issue หลายใบ → Finished Receipt หลาย output
- Finished Receipt → Transfer Out → Transfer In
- Transfer In → Sale/COGS
- Generic Issue → Issue Return → Issue Return reversal
- Material Issue → Material Return → Finished Receipt
- Sale/COGS → partial Sales Return → ใช้/ขายสินค้าที่คืนต่อ
- Purchase Receipt → partial/full Purchase Return
- Purchase Credit Note `NON_RETURN` ต้องไม่มี Stock/Cost node
- Stock Count → Adjustment โดยไม่สร้าง node ซ้ำ
- AVG multi-level propagation
- FIFO multi-layer propagation
- Cross-branch transfer
- Partial quantity propagation
- Multiple downstream consumers
- Rounding residual
- Closed period
- Duplicate trigger
- Concurrent runs
- Job retry after failure
- Missing lineage
- Cycle detection
- Reversal after propagation
- Rollback ของ Node เดียว
- Run resume หลัง worker หยุด
- Stock/Allocation/GL reconcile เป็นศูนย์
- Stock Card effective value หลัง Revaluation ตรงกับ Historical Stock Valuation ณ as-of date/scope เดียวกัน
- Stock Card แสดงประเภท `กลับรายการ` พร้อม Movement ต้นทาง และไม่ตีความ reversal เป็น Receipt/Issue ปกติ
- Stock Card คอลัมน์เลขที่เอกสารต้อง resolve จาก `source_type + source_id` ไปยัง `document_number` ของเอกสารต้นทาง ไม่ใช้ Movement ID หรือเลขอ้างอิงภายในเป็นเลขเอกสาร; legacy ที่ map ไม่ได้จึง fallback เป็น source reference
- Stock Card running unit cost คำนวณจาก running value / running quantity ไม่ใช้ Movement unit cost แทน
- Stock Card แสดง original/delta/effective โดยผลรวม `original + delta = effective` และ drill-down ถึง Revaluation Run ได้
- Accounting sign-off ถูก block เมื่อมี Pending Cost, Run ยังไม่จบ, blocker หรือ reconciliation difference
- Source delta = ending on-hand + terminal + open bridge + residual
- ห้าม double count intermediate Transfer/Production node
- unsupported source/event ต้อง block ไม่ fallback เป็น `inventory.recost`
- Terminal pool reset: เมื่อ replay ตาม `business_date, movement_id` แล้ว quantity เป็นศูนย์ ให้ value/average เป็นศูนย์ใน projection, historical valuation และ Stock Card; receipt ถัดไปต้องเริ่ม pool ใหม่ และห้ามรวม value ของ pool เดิมกลับมา
- Stock Card/valuation consistency: `StockBalanceProjectionService::rebuild()`, `historicalValuationQuery()` และ running display ต้องใช้กติกา terminal reset เดียวกัน; ห้ามใช้ `SUM(value)` แบบ scope ตรง ๆ เพราะทำให้ receipt หลัง final issue inherit มูลค่าเก่า

### Legacy Review correction — Review #3 และ #6 (2026-09-11)

Accounting ยืนยันให้แก้ข้อมูล Legacy ตาม Date Authority contract โดย `document_date` เป็น effective date เสมอ:

- Review #3 / Allocation #1535 / `IV-2026-000002`: controlled repair เปลี่ยน `wms_stock_movements.business_date` และ `wms_cost_allocations.business_date` จาก `2026-08-29` เป็น `2026-08-28` ผ่าน `LegacyAllocationReviewCorrectionService` พร้อม Audit Log; ไม่แก้ Journal Posted #816
- Review #6 / Allocation #2059 / `ADJHQ2609000003`: movement date ตรง `document_date=2026-09-07` อยู่แล้ว จึงบันทึก immutable `LEGACY_DUPLICATE` correction #6 ให้ duplicate generic receipt allocation ชี้ canonical Allocation #2060 และไม่สร้าง Journal ซ้ำ
- ทั้งสอง Review ถูกปิดเป็น `RESOLVED`; ต้องสร้าง Manual Document/Scope Trigger รุ่นใหม่ แล้วตรวจ Shadow, Stock Card และ GL reconciliation ก่อน sign-off
- `CostTimelineReader` และ bounded anchor ตัด allocation ที่มี correction record ด้วย indexed `whereNotExists` เช่นเดียวกับ reconciliation path เพื่อให้ duplicate #2059 ไม่ถูกนับกลับเข้า AVG/FIFO replay
- `CostPropagationTriggerPlanner` ใช้ correction ledger เดียวกันตัด duplicate ออกจาก root plan; การ Trigger ซ้ำหลัง Review #6 จึงไม่ส่ง allocation #2059 เข้า classifier และไม่สร้าง `ALLOCATION_NOT_POSTED` blocker จากข้อมูลที่ถูกแก้แล้ว
- Verification หลัง Manual Trigger: Batch #47 ของ Production Receipt คำนวณเป็น `PENDING_APPROVAL` (4 nodes scanned/1 affected, ไม่มี blocker). Batch #44 ของ POS ถูกหยุดเป็น `REQUIRES_REVIEW` ด้วย `DIRECT_BRIDGE_QUANTITY_EXCEEDED` ที่ allocations RECOST #2484/#2486/#2487/#2489/#2490 จาก Run #194; ถือเป็น legacy downstream recost lineage ที่ต้องทำ compensating/stale-run review ก่อน Apply เพื่อป้องกัน double propagation
- Root cause fix: `CostDirectBridgeResolver` ตัด `RECOST` ออกจาก child bridge เพื่อไม่ให้ผลลัพธ์ Revaluation เดิมถูก replay เป็น source propagation ซ้ำ และ `CostPropagationTriggerPlanner` เพิ่ม `business_date` ใน proof identity เพื่อบังคับ Run ใหม่หลัง Legacy date correction
- Verification หลังแก้: สร้าง POS Batch #48 / Run #204 ใหม่สำเร็จเป็น `PENDING_APPROVAL`, 2 partitions, 49 nodes scanned / 33 affected, ไม่มี blocker และ queue ว่าง; Production Batch #47 เป็น `PENDING_APPROVAL` เช่นกัน. ยังไม่ Approve/Apply จน Accounting ตรวจ Stock Card, Shadow Delta และ GL reconciliation ของทั้งสอง scope
- Development recovery/E2E (2026-09-11): Legacy Run #202 ของ Batch #44 ติด `DIRECT_BRIDGE_QUANTITY_EXCEEDED` และไม่มี Delta/Applied row จึงถูก `CANCELLED` พร้อม Audit โดยไม่แก้ Posted ledger; Clean Run #204 ของ Batch #48 ผ่าน 33 Delta, GL Posting 25 Journal proofs, Debit/Credit balance เป็นศูนย์ และ Reconciliation ผ่านก่อนปิดเป็น `COMPLETED`; Batch #48 ถูก sync กลับเป็น `PENDING_APPROVAL` ตาม lifecycle ของ batch ส่วน Batch #44 คง `REQUIRES_REVIEW` เพื่อเป็นหลักฐาน quarantine
- Stock Card discrepancy root fix: `InventoryCostAllocationService::historicalValuationQuery()` และ `StockController::data()` ตัด corrected allocation ผ่าน `whereNotExists` เช่นเดียวกับ Engine; กรณี Production Receipt #2059 duplicate จึงไม่ทำให้ Receipt แสดงมูลค่าเป็นสองเท่า และ Historical Valuation ตรงกับ Stock Balance
- UAT contract hardening (2026-09-11): รัน MySQL readiness suite รวม `29 tests / 172 assertions` ผ่าน โดยครอบคลุม Classified Journal/Reconciliation, Apply เฉพาะ ending on-hand และ continuation checkpoint, Transfer Accept direct bridge/replay, multi-line trigger partition, after-commit dispatch/rollback safety และ performance/index contract; แก้ reconciliation ให้ `production.revaluation.finished_goods` นับเป็น Inventory impact ตาม COA control type `INVENTORY`; test fixture ใช้ current canonical calculation contract และไม่เลือก RECOST allocation เป็น Transfer source; skip 2 กรณีเพราะ development ไม่มี Issue Return/Material Issue fixture ที่ตรงเงื่อนไข
- Operational readiness (2026-09-11): เพิ่ม `docs/qa/wms-cost-revaluation-runbook.md` ครอบคลุม staged feature gates, bounded queue worker, queue/run health checks, Accounting sign-off sequence, Legacy/RECOST recovery policy และ rollback evidence; ยังคง feature flags ปิดเป็นค่าเริ่มต้น โดยงานค้างจริงคือ benchmark production-sized graph, alert/monitoring implementation และ Accounting/Production sign-off
- Benchmark command (2026-09-11): เพิ่ม Artisan `wms:cost-benchmark` แบบ read-only สำหรับเลือก top partitions แบบ bounded และวัด latency/rows-per-second/peak memory/queue lag โดยไม่สร้าง Run หรือแก้ ledger; Development baseline `1:1:1:AVG` 86 nodes ใช้ 73.733 ms (1,152.80 rows/sec), queue `cost-propagation` = 0, peak memory 32 MB; partition `1:32:64:AVG` มี `ALLOCATION_NOT_POSTED` จึงบันทึกเป็น fixture blocker และยังไม่ถือเป็น production-sized benchmark sign-off
- Benchmark fixture (2026-09-12): migrate/seed ฐานแยก `new_erp_benchmark` และสร้าง 10 partition ด้วย `wms:inventory-ops-smoke` prefix `OPS-SMOKE-B01` ถึง `B10`; ทุกชุด reconcile ตรงกัน และ benchmark read-only ผ่าน 10 partitions / 10 nodes / 81.679 ms / peak 32 MB / queue lag 0. แก้ namespace ของ `InventoryOpsSmokeContract` ใน `ProcurementSourceBuilder` ที่ทำให้ smoke writer เดิม resolve class ไม่ได้; คงสถานะงานเป็น partial เพราะยังต้องเพิ่ม high fan-out nodes และเก็บผลหลายปี/หลายสาขาก่อน production sign-off
- Monitoring command (2026-09-11): เพิ่ม Artisan `wms:cost-health --json` แบบ read-only ให้ external monitor ตรวจ queue pending/failed, stale calculation lease, open/review Run, oldest pending age และ feature-gate state; คืน non-zero เมื่อมี stale lease, failed job หรือ unresolved review/limit/retryable Run. Development baseline พบ 20 `REQUIRES_REVIEW` Run, queue pending/failed = 0 และ stale lease = 0; ยังไม่ผูก scheduler/notification จนกว่าจะยืนยันช่องทางแจ้งเตือน Production
- Accounting sign-off report (2026-09-11): เพิ่ม Artisan `wms:cost-signoff-report --run={id} --json` แบบ read-only ให้ Accounting ตรวจ source document, Preflight blockers, Reconciliation blockers และ totals ก่อนตัดสินใจ; Run #149 ของ `ADJHQ2609000005` รายงาน `BLOCKED` ด้วย legacy calculation contract/variance/posting period และไม่มี Delta/Applied/Journal จึงยืนยันเส้นทาง quarantine → Clean Run โดยไม่ mutate ledger
- Staged gate readiness (2026-09-11): เพิ่ม Artisan `wms:cost-gate-readiness --json` ตรวจ evidence ก่อนเปิด Auto Trigger/Apply/GL และคืน non-zero เมื่อมี unresolved review, failed job หรือ stale lease; Development พบ 20 `RUN_REQUIRES_REVIEW`, queue/failed/stale เป็น 0 จึงยังไม่พร้อมทั้ง 3 gate ขณะที่ `.env` มี Apply/GL=true จากการทดสอบก่อนหน้า — ห้ามถือเป็น production-ready จนผู้ดูแลตัดสินใจปิดกลับหรือ approve scope อย่างเป็นทางการ
- Accounting UAT Issue/Return, Purchase Credit Note และ Sales Return (2026-09-12): รัน readiness suite บน `new_erp_benchmark` ผ่าน 11 tests / 97 assertions โดย skip 1 เฉพาะ operational evidence; ครอบคลุม FIFO issue/return split และ retry, Purchase Credit Note แบบ full/partial return, NON_RETURN ที่ไม่สร้าง Stock/Cost reversal, Sales Return แบบ HS refund และ IV credit note, journal link, rollback และ idempotency. ระหว่าง UAT พบ root cause ว่า `WmsDocumentSequenceSeeder` สร้างเฉพาะ sequence ระดับคลัง แต่ service ต้องใช้ global sequence จึงเพิ่ม global `INVENTORY_ISSUE`, `INVENTORY_RETURN`, `PURCHASE_RETURN`, `PURCHASE_CREDIT_NOTE` แล้ว seed ยืนยันผลซ้ำผ่าน; เพิ่ม Opening Balance fixture แบบ rollback-safe สำหรับ Sales Return ด้วย; ยังไม่ใช่ Accounting sign-off ครบทุก bucket จนกว่าจะตรวจ Revaluation Journal impact
- Revaluation Journal impact UAT (2026-09-12): รัน `CostRevaluationJournalMySqlIntegrationReadinessTest` บน `new_erp_benchmark` ผ่าน 4 tests / 51 assertions ครอบคลุม 10 classified cases ได้แก่ `INVENTORY_ON_HAND`, `COGS_CONSUMED`, `ISSUE_EXPENSE_CONSUMED`, `WIP_CONSUMED`, `FINISHED_GOODS_BRIDGE`, Sales/Issue/Material `RETURN_BRIDGE`, `PURCHASE_RETURN_CONSUMED` และ `ROUNDING_RESIDUAL`; ตรวจ account routing, debit/credit balance, allocation-to-journal proof, stock projection เฉพาะ ending on-hand, idempotent posting และ checkpoint resume แล้ว. ทุก case เป็น rollback-only fixture และ feature gate ยังปิด; เหลือให้ Accounting ตรวจ Journal หลักฐานจริงตาม scope และอนุมัติ staged rollout
- GL rollout no-op guard (2026-09-12): ระหว่าง controlled smoke หลังเปิด GL พบ Run ที่ไม่มี Planned Delta (`delta_count=0`) ถูกคำนวณเป็น `PENDING_APPROVAL`; เพิ่ม Preflight guard และ Journal Posting guard ให้ no-op Run ถูก block ก่อน Approve/Apply/GL. Run #61 ที่สร้างก่อนแก้เป็น benchmark artifact ไม่มี Delta/Journal effect และไม่ถือเป็น Accounting completion; หลังแก้ Journal matrix 4 tests / 51 assertions ยังผ่าน

## Definition of Done

- Recost ต้นทางสามารถ propagate ไปยัง downstream transaction ที่เกี่ยวข้อง
- รองรับ Warehouse/Branch transfer
- ไม่แก้ไข Posted row เดิม
- ทุก Delta มี Source/Target lineage
- Job retry/resume ได้โดยไม่ duplicate
- Stock Valuation แสดงยอดหลัง Revaluation ถูกต้อง
- Stock Card แสดงผลตามวันที่เอกสารและแยก Original/Revaluation/Effective Cost พร้อมสถานะและ lineage
- ฝ่ายบัญชีตรวจ Stock Card → Stock Valuation → Cost Lineage/Revaluation → GL ตาม as-of date และ scope เดียวกันได้
- Cost Allocation, Stock Projection และ Journal/GL reconcile เป็นศูนย์ก่อน Accounting sign-off
- มี Audit Log และ recovery guidance
- มี Manual Recovery Console สำหรับ Preview/Trigger/Resume/Retry ที่ใช้ engine และ idempotency contract เดียวกับ automatic trigger
- เปิดใช้งานได้ผ่าน configuration/feature flag โดยไม่แก้ Source Code ต่อ Customer
