# Production Module Planning

> สถานะ: แผนที่ปรับให้เข้ากับสถาปัตยกรรม ERP ปัจจุบันแล้ว ยังไม่ใช่คำสั่งให้เริ่ม Implement
>
> ชื่อทางเทคนิค: `production`
>
> ชื่อใน UI: `การผลิต`
>
> ชื่อภาษาอังกฤษ: `Production`

## 1. ข้อสรุปที่ตกลงร่วมกัน

ระบบการผลิตแบ่งเป็น 2 ระดับที่อยู่ร่วมกันได้และไม่แทนที่กัน

### 1.1 WMS ผลิต/ประกอบแบบ Manual

เป็นความสามารถพื้นฐานของ WMS และต้องใช้งานได้แม้ไม่ได้เปิด Production Module เหมาะกับธุรกิจที่มีการผลิตหรือประกอบไม่ซับซ้อน เช่น ร้านเบเกอรี่ ครัวกลาง งานแบ่งบรรจุ งานจัดชุด และงานประกอบเป็นครั้งคราว

ความสามารถเดิมยังคงอยู่:

- เบิกวัตถุดิบเข้าผลิตแบบ Manual
- รับคืนวัตถุดิบที่ยังไม่ได้ใช้จากการผลิต
- รับเศษวัตถุดิบที่มีมูลค่ากลับเข้า Stock ผ่านเอกสารรับเศษผลิต (ต้องเพิ่มเป็น shared WMS flow)
- รับสินค้าผลิตเสร็จแบบ Manual
- ตัดและรับ Stock ผ่าน WMS
- คำนวณต้นทุนผ่าน Cost Allocation ของ WMS
- ลงบัญชี Inventory/WIP ผ่าน Accounting services กลาง
- ยกเลิกหรือกลับรายการตาม lifecycle เดิม

WMS Manual Production:

- อยู่ใต้ `program:wms`
- ใช้ permission ของ WMS เดิม
- **ไม่ขึ้นกับ** `production_enabled`
- **ห้าม** ใส่ `capability:production` ครอบ Routes เดิม `/wms/production/*`
- อาจปรับชื่อเมนูเป็น `ผลิต/ประกอบแบบ Manual` เพื่อไม่ให้สับสนกับ Production Module

### 1.2 Production Module

เป็น Optional Module สำหรับธุรกิจที่ต้องบริหารการผลิตอย่างเป็นระบบ โดยเพิ่ม BOM, Production Order และการควบคุมงานเหนือ WMS เดิม

เปิดใช้เมื่อ:

```text
business_profile = MANUFACTURING
production_enabled = true
```

Production Module ไม่สร้าง Stock หรือ Accounting engine ใหม่ แต่เป็นผู้วางแผนและควบคุมให้ WMS/Accounting ทำงานผ่านบริการกลาง

สำหรับบริบทธุรกิจไทย ให้ **Made to Order จากใบสั่งขายเป็น flow หลักของ MVP** โดยผู้ใช้สร้าง Production Order จากรายการใน Sales Order ที่ยืนยันแล้ว ระบบไม่สร้าง WO อัตโนมัติทันทีที่ยืนยันใบสั่งขาย เพื่อป้องกันงานผลิตผิดรายการหรือผิดกำหนดส่ง

```text
Confirmed Sales Order
-> Production Demand Queue แสดงบรรทัดที่รอผลิต
-> Create Production Order ใน Production Module
-> เบิก/คืน/รับเศษ/รับสินค้าสำเร็จรูปจากหน้า WO เดียวกัน
-> Backend เรียก WMS และ Accounting services กลาง
-> Reserve finished goods for the Sales Order
-> Production workflow จบโดยไม่ต้องสลับหน้าไป WMS/Accounting
-> POS continues delivery/invoice/fulfillment
```

ยังคงสร้าง WO แบบไม่อ้าง Sales Order ได้สำหรับ Make to Stock, ทดลองผลิต หรือ Rework ที่ได้รับอนุญาต

```text
POS owns: Sales Order, customer demand, selling price, delivery and fulfillment
Production owns: BOM, Production Order, execution state, progress
WMS owns: reservation, stock document, movement, balance, inventory cost
Accounting owns: account mapping, fiscal period, journal, reversal
Purchasing owns: procurement document and supplier flow
```

## 2. หลักการสถาปัตยกรรมที่ห้ามละเมิด

1. Production Order เป็น Aggregate หลักของ Production Module
2. Confirmed Sales Order เป็น demand source หลักของ Made to Order แต่ POS ยังคงเป็นเจ้าของ commercial lifecycle
3. การ Complete WO ห้ามเปลี่ยน Sales Order เป็น `FULFILLED`; การส่งมอบ/ขายจริงยังจบใน POS
4. WMS Manual Production ต้องอยู่ได้โดยไม่เปิด Production Module
5. Production Module ต้องเรียกใช้ WMS documents/services เดิม ไม่สร้าง stock tables หรือ movement engine ซ้ำ
6. Production Module ห้ามสร้าง Journal Entry โดยตรง
7. Posted Stock Movement และ Posted Journal ห้ามแก้ทับ ต้องใช้ reversal
8. BOM/WO snapshot ที่ถูก Release แล้วต้องไม่เปลี่ยนตาม Master หรือ Sales Order ภายหลัง
9. ทุกคำสั่งที่อาจถูกส่งซ้ำต้องมี deterministic idempotency key
10. Branch และ Warehouse scope ต้องตรวจฝั่ง Server ทุกครั้ง
11. ปิด Module แล้วต้องไม่สูญเสียข้อมูลและไม่กระทบ WMS Manual Production หรือ POS ปกติ
12. ผู้ใช้ฝ่ายผลิตต้องทำ workflow ตั้งแต่รับ demand จน Complete WO ได้ใน Production Module โดยไม่ต้องสลับหน้าไป POS, WMS หรือ Accounting
13. การรวมหน้าจอไม่เปลี่ยน data/service ownership แต่สิทธิ์ของผู้ใช้ต้องเป็น `production.*`; พนักงาน Production ไม่ต้องได้รับสิทธิ์เข้า WMS/Accounting
14. Production permission อนุญาตได้เฉพาะเอกสารที่เชื่อมกับ WO ใน Branch/Warehouse scope เดียวกัน ห้ามใช้จัดการเอกสาร WMS/Journal ทั่วไป
15. ใช้ของกลางของระบบก่อนเพิ่ม service หรือตารางใหม่

## 3. Source of Truth และบริการกลาง

| เรื่อง | Source of Truth | สิ่งที่ Production ต้องทำ |
|---|---|---|
| Sales Order และลูกค้า | POS `SalesOrder`/`SalesOrderLine` | อ่านเฉพาะ `CONFIRMED`; เก็บ linkage และ snapshot ที่จำเป็น ห้าม copy ราคา/ภาษีมาคำนวณใน Production |
| Item/Product | `wms_items` | อ้าง `item_id`; ห้ามสร้าง Product master ซ้ำ |
| UOM | `wms_uoms` และ UOM conversion | ใช้หน่วย Stock ที่ WMS รองรับ |
| Branch | Branch context | ใช้ Branch เป็น Plant ใน MVP |
| Warehouse | Warehouse context | ระบุคลังเบิกและคลังรับใน WO |
| Available/Reserved Stock | WMS stock balances | อ่านผ่าน WMS service; ห้ามคำนวณ balance เอง |
| Reservation | WMS stock reservations | จอง/ปล่อย/consume ผ่าน service กลาง |
| Stock Movement | WMS stock movements | เกิดจากการ Post เอกสาร WMS เท่านั้น |
| Inventory Cost | WMS cost allocations/layers | อ่าน Actual Cost จาก allocation ที่ Posted |
| Journal | Accounting journal entries | ให้ WMS posting flow เรียก Accounting service |
| Account mapping | Accounting account mappings | ใช้ event code เดิม |
| Document number | Document sequence | ขอเลขผ่าน `DocumentSequenceService` |
| Audit | Platform audit log | บันทึกผ่าน `AuditLogger` |
| Permission | RBAC กลาง | ใช้ permission `production.*` |

### 3.1 Services กลางที่ต้อง reuse

#### บริบทและ Optional Module

- `ModuleCapability`
- `EnsureModuleCapability` ผ่าน middleware alias `capability:production`
- `EnsureProgramSelected` ผ่าน `program:production`
- `BranchContext`
- `WarehouseContext`

Routes ของ Production Module ต้องอยู่หลัง middleware อย่างน้อย:

```php
['auth', 'program:production', 'capability:production', 'warehouse']
```

WMS Manual Production ยังคงใช้ `program:wms` และไม่ใช้ capability guard ของ Production

#### POS Sales Order / Made to Order

- ใช้ `SalesOrder` และ `SalesOrderLine` เดิม ไม่สร้าง customer order table ใน Production
- สร้าง WO ได้เฉพาะ Sales Order สถานะ `CONFIRMED`
- ใช้ `source_line_id` ของ Physical Sale เพื่อเชื่อมการส่งมอบกลับไปยัง Sales Order line และ finished-goods reservation
- Production มีหน้า `คำสั่งขายรอผลิต` ซึ่งอ่าน eligible lines จาก Sales Order ที่ยืนยันแล้ว เพื่อให้ฝ่ายผลิตเริ่มงานโดยไม่ต้องเข้า POS
- หน้า Sales Order แสดงสถานะผลิตและ shortcut ไปหน้า Production ได้เมื่อเปิด Module แต่ shortcut นี้ไม่ใช่ขั้นตอนบังคับของ workflow
- Production อ่าน customer/item/quantity/specification จาก Sales Order แต่ห้ามแก้ราคา ส่วนลด ภาษี หรือสถานะขาย
- ต้องเพิ่มวันที่ลูกค้าต้องการรับสินค้าเป็น field ของ POS Sales Order เช่น `required_delivery_date`; ห้ามใช้ `valid_until` แทน เพราะเป็นคนละความหมาย
- การสร้าง WO จาก Sales Order ต้องเป็นคำสั่งที่ผู้ใช้กด ไม่สร้างอัตโนมัติจาก Model observer

#### เลขเอกสารและ Audit

- ใช้ `DocumentSequenceService` สำหรับเลข Production Order
- เพิ่ม document type `PRODUCTION_ORDER` ใน `SystemDocumentSequenceSeeder`
- ใช้ `AuditLogger` สำหรับ create, update, release, start, complete, cancel และการเปลี่ยน BOM revision
- ห้ามสร้าง running number หรือ audit table ชุดใหม่

#### Stock และ Reservation

- อ่าน Stock ผ่าน `StockBalanceService`
- จองและปล่อย Stock ผ่าน `StockReservationService`
- ให้เอกสาร WMS เป็นผู้สร้าง movement ผ่าน `StockMovementService`
- ให้ WMS ใช้ `StockBalanceProjectionService`, AVG/FIFO และ Cost Allocation เดิม
- ใช้ `WmsDecimal` และฐานทศนิยมเดิม ห้ามใช้ float สำหรับ business calculation

`StockReservationService` ปัจจุบันมี `reserve()` และ `release()` แต่ยังไม่มี contract สำหรับ consume แบบเต็ม/บางส่วน ก่อน WO ใช้ reservation จริงต้องเพิ่ม method กลางใน WMS สำหรับ consume reservation อย่าง atomic ห้ามให้ Production หรือ POS แก้ `wms_stock_balances` หรือสถานะ reservation โดยตรง

Shared consume contract ต้องรับ source identity และทำอย่างน้อย:

- lock reservation และ stock balance
- ตรวจ warehouse/item/UOM/source/remaining quantity
- ลด `reserved` ตามจำนวนที่ใช้
- เปลี่ยนสถานะเป็น partial/consumed ตามผลจริง
- ป้องกัน reservation ของ Sales Order อื่นถูก consume
- ใช้ idempotency key เพื่อไม่ consume ซ้ำ
- ทำใน outer transaction เดียวกับ Stock Movement ที่เกี่ยวข้อง

Material reservation ใช้ source ของ WO/material line ส่วน finished-goods reservation ของ Made to Order ใช้ source ของ Sales Order line เพื่อให้ POS ส่งมอบสินค้าให้คำสั่งซื้อที่ถูกต้อง

#### Material Issue, Unused Return และ Recoverable Scrap

ใช้ flow ของ `IssueReturnService` เฉพาะการคืนวัตถุดิบที่ยังเป็น Item เดิมและยังไม่ได้ใช้:

- `createIssue()`
- `approve()`
- WMS posting flow เดิม
- `createReturn()`
- `postReturn()`
- `reverseReturn()`

Production application service ทำหน้าที่จัด payload และสร้าง linkage เท่านั้น ห้ามเรียก Controller ของ WMS และห้าม insert Stock Movement เอง

เศษวัตถุดิบที่เกิดจากกระบวนการผลิตไม่ใช่ Issue Return เพราะอาจเปลี่ยนสภาพหรือกลายเป็น Scrap Item คนละรหัส ต้องเพิ่ม shared WMS document context `PRODUCTION_SCRAP_RECEIPT` และ application service กลาง โดย reuse `StockMovementService`, Cost Allocation, Account Mapping, Journal Posting และ reversal contract เดิม ห้ามบันทึกเป็น Inventory Adjustment Gain ทั่วไป เพราะบัญชีคู่ตรงข้ามต้องเป็น WIP ไม่ใช่กำไรจากการปรับปรุงสินค้า

#### Finished Goods Receipt

- ใช้ document context `PRODUCTION_RECEIPT`
- ใช้ `ManualProductionReceiptPostingContract`
- Post ผ่าน `ManualProductionReceiptPostingService`
- Reverse ผ่าน `ProductionFinishedReceiptReversalService`

ปัจจุบันการสร้าง/อนุมัติ Finished Receipt ยังผูกกับ Controller มากกว่าบริการกลาง ก่อน Production Module เรียกใช้ ให้แยกเฉพาะ create/update/approve logic ที่จำเป็นเป็น WMS application service กลาง แล้วให้ทั้งหน้า Manual และ Production Module เรียก service เดียวกัน ห้าม copy logic ไปไว้ใน Production

#### Accounting

ใช้ event code เดิม:

| Event | Debit | Credit |
|---|---|---|
| `production.material_issue` | WIP | Inventory |
| `production.material_return` | Inventory | WIP |
| `production.scrap_receipt` | Scrap Inventory | WIP |
| `production.finished_receipt` | Finished Goods | WIP |

บริการที่ต้อง reuse:

- `IssueAccountingPostingService`
- `ManualProductionReceiptPostingService`
- `JournalPostingService`
- `AccountMappingService`
- Fiscal period/backdated movement gates เดิม

Production ห้าม:

- insert `journal_entries` หรือ `journal_entry_lines` เอง
- resolve Account ID จาก code เอง
- คำนวณ AVG/FIFO เอง
- update Inventory balance เอง
- แก้ Posted journal/movement

#### Production-local Workflow Orchestration

ผู้ใช้ต้องทำงานจบใน Production Module แม้ข้อมูลปลายทางยังเป็นเอกสารของ WMS/Accounting:

- หน้า `คำสั่งขายรอผลิต` ใช้เลือก Confirmed Sales Order line และสร้าง WO
- หน้า WO มี form/action สำหรับจองวัตถุดิบ, สร้าง/อนุมัติ/Post ใบเบิก, คืนวัตถุดิบ, รับเศษ, รับสินค้าสำเร็จรูป และ Complete
- แสดง Stock readiness, WIP cost, posting blockers, WMS document status และ GL preview ในหน้า WO
- ใช้ AJAX/partial/modal ภายใน Production layout; ห้าม redirect ผู้ใช้ไปหน้า WMS/Accounting เพื่อทำ next action ปกติ
- Backend route อยู่ใต้ Production capability ใช้ `production.*` permission และเรียก WMS/Accounting application services กลาง ห้ามเรียก Controller ข้าม Module
- ไม่ตรวจ `wms.*` หรือ `accounting.*` permission บน Production workflow เพราะจะบังคับให้พนักงานได้รับสิทธิ์ Module อื่นโดยไม่จำเป็น
- Route/Policy ของ Production ต้อง lock WO และตรวจว่า WMS document/Journal เชื่อมกับ WO นี้ อยู่ใน Branch/Warehouse เดียวกัน และ action ตรง lifecycle ก่อนเรียก service กลาง
- หากผู้ใช้ Production ไม่มีสิทธิ์อนุมัติ/Post ให้สร้าง Draft/ส่งต่อได้ และ Production Supervisor เปิด WO เดิมเพื่อดำเนินการต่อ
- WMS Manual routes ยังคงตรวจ `wms.*`; Accounting configuration routes ยังคงตรวจ `accounting.*` ตามเดิม การใช้ service ร่วมกันไม่ได้ทำให้สิทธิ์ข้ามกัน
- Related document links มีไว้ดูหลักฐานเชิงลึกเท่านั้น และอาจต้องใช้สิทธิ์ Module เจ้าของ แต่ workflow หลักห้ามพึ่ง link เหล่านี้
- GL preview ในหน้า WO ใช้ `production.orders.gl.view` และ shared renderer/query โดย endpoint ต้องคืนได้เฉพาะ Journal ที่ผูกกับ WMS documents ของ WO นั้น ห้ามเปิดสิทธิ์ดู Accounting ทั้งระบบ

Production อาจมี orchestration service บาง ๆ เพื่อครอบ transaction/linkage/idempotency แต่ห้ามคัดลอก stock, costing หรือ journal logic จากโมดูลเจ้าของ

## 4. การเชื่อม WMS Manual กับ Production Order

WMS documents ต้องรองรับต้นทาง 2 แบบในเชิงธุรกิจ:

```text
MANUAL
PRODUCTION_ORDER
```

ไม่จำเป็นต้องเพิ่ม mode ลงทุกตาราง WMS หากใช้ตาราง linkage ของ Production ได้ชัดกว่า

ตารางแนะนำ:

```text
production_order_wms_documents
- id
- production_order_id
- document_type       MATERIAL_ISSUE | MATERIAL_RETURN | SCRAP_RECEIPT | FINISHED_RECEIPT
- document_id
- created_at
- created_by
```

Constraints ที่ต้องมี:

- Unique `(document_type, document_id)` เพื่อไม่ให้เอกสาร WMS หนึ่งใบผูกหลาย WO
- Index `(production_order_id, document_type)`
- ตรวจ Branch/Warehouse ของ WO และ WMS document ให้ตรงกัน
- Linkage ต้องถูกสร้างใน transaction เดียวกับคำสั่งที่สร้างเอกสาร

กติกา:

1. เอกสาร Manual เดิมไม่ต้องมี linkage
2. ห้ามผูกเอกสาร Posted เดิมเข้ากับ WO ย้อนหลังโดยอัตโนมัติ
3. ห้าม Production สร้าง movement ซ้ำเมื่อ WMS document ถูก Post แล้ว
4. การเปิด Production Module ภายหลังไม่เปลี่ยนเอกสาร Manual เดิม
5. เมื่อปิด Production Module เอกสาร WMS และ Journal ที่เกิดจาก WO ยังเปิดดูได้จากโมดูลเจ้าของข้อมูล

## 5. การจัดลำดับ Feature

### 5.1 MVP — ต้องมี

MVP หมายถึง vertical slice ที่ใช้งานจริงได้ตั้งแต่ Confirmed Sales Order -> BOM -> Production Order -> รับสินค้าสำเร็จรูปกลับมารอส่งมอบ โดยไม่สร้าง Stock และ Journal ซ้ำ

#### A. Optional Module และ Security

- Program code `production`
- Entry route จริง `production.index`
- `ModuleCapability::PRODUCTION`
- `program:production` และ `capability:production` ทุก Production Route/API
- Branch/Warehouse scope ฝั่ง Server
- Permission `production.*`
- ซ่อน Program/Sidebar เมื่อปิด Module
- ปิด Scheduler/Queue เฉพาะ Production เมื่อ Module ปิด
- WMS Manual Production ต้องไม่ถูกปิดตาม

#### B. Sales Order / Made to Order

- Production มีหน้า `คำสั่งขายรอผลิต` แสดง Sales Order line สถานะ `CONFIRMED` ที่ยังต้องผลิต พร้อม filter กำหนดส่ง ลูกค้า และสินค้า
- หน้า Sales Order อาจมี shortcut `สร้างใบสั่งผลิต` ไปยัง Production แต่ฝ่ายผลิตไม่ต้องเข้า POS เพื่อเริ่ม workflow
- ผู้ใช้เลือกเฉพาะ line ที่ต้องผลิต; service/non-stock line และ line ที่ไม่มี Active BOM ต้องไม่ถูกเลือก
- Sales Order หนึ่งใบที่มีสินค้าผลิตหลาย line สร้าง WO แยกต่อ line เพื่อให้สถานะและต้นทุนตรวจสอบได้
- MVP มี Active WO ได้หนึ่งใบต่อ Sales Order line; retry ต้องคืน WO เดิม ไม่สร้างซ้ำ
- หาก WO เดิมถูกยกเลิก สามารถสร้าง replacement revision ได้ โดยยอด WO ที่ยังไม่ยกเลิกรวมกันต้องไม่เกิน Sales Order line quantity
- ค่าเริ่มต้นของ WO มาจาก Sales Order line: item, quantity, UOM, customer specification และ required delivery date
- MVP รองรับเมื่อ Sales Order UOM ตรงกับ Stock/Base UOM เท่านั้น กรณีอื่นต้อง block พร้อมข้อความจนกว่าจะมี conversion contract ที่ทดสอบแล้ว
- ระบบต้อง snapshot เลข Sales Order, line, ลูกค้า, item description/specification และ required delivery date ตอนสร้าง WO
- ห้าม copy ราคาขาย ส่วนลด ภาษี หรือยอดลูกหนี้เข้า Production
- การ Complete WO ไม่ทำให้ Sales Order เป็น `FULFILLED`; POS เป็นผู้จบการส่งมอบและเอกสารขาย
- เมื่อ Sales Order ถูกยกเลิก ห้าม reverse WO/Stock อัตโนมัติ ให้สร้าง exception `SALES_ORDER_CANCELLED` พร้อมแจ้งผู้รับผิดชอบเพื่อเลือกยกเลิก WO, หยุดงาน หรือเก็บสินค้าที่ผลิตแล้วเป็น Stock
- WO แบบ Manual/Make to Stock ยังสร้างจากหน้า Production ได้โดยไม่ต้องมี Sales Order

#### C. BOM Master แบบใช้งานจริงขั้นต่ำ

- BOM header
- BOM revision
- BOM lines
- Finished item หนึ่งรายการต่อ BOM
- Component item, quantity และ UOM
- Effective date
- สถานะ `DRAFT`, `ACTIVE`, `INACTIVE`
- Revision ที่ Active ห้ามแก้ทับ
- Copy revision เพื่อแก้ไข
- ป้องกัน component ซ้ำใน revision เดียว
- ป้องกัน finished item เป็น component ของตัวเอง
- ป้องกัน BOM cycle แม้ MVP ยังไม่ทำ automatic multi-level explosion

ยังไม่ต้องมี:

- Material substitute
- Formula/yield optimizer
- Engineering change workflow
- Approval หลายชั้น

#### D. Production Order

ข้อมูลขั้นต่ำ:

- เลข WO
- ประเภท `MAKE_TO_ORDER` หรือ `MAKE_TO_STOCK`
- Sales Order/Sales Order line เมื่อเป็น Made to Order
- Branch
- Finished item
- Planned quantity และ UOM
- BOM revision
- Issue warehouse
- Receipt warehouse
- Planned start date
- Planned finish date/required delivery date
- Responsible user
- Customer specification snapshot
- Note

เมื่อ Release ต้อง snapshot:

- Finished item/UOM/quantity
- BOM revision identity
- Material item/UOM/required quantity
- Issue/receipt warehouses
- Sales Order source, customer specification และ required delivery date เมื่อเป็น Made to Order

WO ห้ามอ่าน Material requirement จาก BOM ล่าสุดหลัง Release และห้ามเปลี่ยนตาม Sales Order ที่แก้ภายหลัง

#### E. WO Lifecycle แบบสั้น

ใช้สถานะหลักเพียง:

```text
DRAFT
RELEASED
IN_PROGRESS
COMPLETED
CANCELLED
```

Transitions:

```text
DRAFT -> RELEASED -> IN_PROGRESS -> COMPLETED
DRAFT -> CANCELLED
RELEASED -> CANCELLED เฉพาะเมื่อยังไม่มี Posted WMS document
```

กติกา:

- `DRAFT`: แก้ไขและลบร่างได้
- `RELEASED`: snapshot แล้วและเริ่มจอง/เบิกได้
- `IN_PROGRESS`: มี Material Issue ที่ Posted หรือเริ่มผลิตแล้ว
- `COMPLETED`: Finished Receipt ถูก Post แล้วและไม่มี WIP/เอกสารค้างตามกติกา MVP
- `CANCELLED`: เก็บประวัติ ห้ามลบ
- ไม่มี `PLANNED`, `SCHEDULED`, `READY`, `HOLD`, `CLOSED` ใน lifecycle หลักของ MVP
- Material readiness เป็นค่าคำนวณแยก ไม่ใช่ WO status

#### F. Material Requirement และ Readiness

ต่อ material line ต้องแสดง:

- Required quantity
- Reserved quantity
- Issued quantity
- Returned quantity
- Net issued quantity
- Available quantity
- Shortage quantity
- สถานะ `NOT_READY`, `PARTIAL`, `READY`, `ISSUED`

คำนวณจาก WMS data จริง ห้ามเก็บ available quantity เป็นค่าถาวรใน Production

Release WO ต้องไม่จำเป็นต้องมี Stock ครบ แต่ต้องแจ้ง shortage ชัดเจน การเบิกจริงต้องผ่านกฎ available/negative stock ของ WMS

#### G. Reservation

- จองผ่าน `StockReservationService`
- `source_type = PRODUCTION_ORDER`
- `source_id = production_order_id`
- Idempotency key ต่อ WO material line
- Release reservation เมื่อยกเลิก WO
- Consume/reduce reservation เมื่อ Material Issue ถูก Post
- Reservation และ Issue ต้องไม่ทำให้ยอด `reserved` ติดลบ

#### H. Material Issue/Unused Return ผ่าน WMS

จากหน้า WO ผู้ใช้สามารถ:

- สร้างใบเบิกวัตถุดิบ WMS จากยอดที่ยังต้องเบิก
- เปิดดูเอกสาร WMS ที่สร้างแล้ว
- สร้างใบคืนวัตถุดิบที่ยังไม่ได้ใช้จาก Material Issue ที่ Posted

กติกา:

- ใช้ WMS document lifecycle เดิม
- WO ไม่ถือว่าเบิกแล้วจน WMS document เป็น `POSTED`
- Return ต้องอ้าง Issue line จริง เป็น Item/UOM เดิม และคืนได้ไม่เกินยอดที่เบิกแล้วยังไม่เคยคืน
- Return ใช้ต้นทุนจาก source allocation เดิม ห้ามให้ผู้ใช้กำหนดต้นทุนคืนเอง
- เศษที่ผ่านการใช้ ตัด เจาะ หลอม ผสม หรือเปลี่ยนเป็น Item อื่นห้ามบันทึกเป็น Material Return
- ห้ามเบิกสะสมเกิน Required quantity ใน MVP; tolerance เป็น 0
- หากต้องเบิกเกิน ให้แก้ WO material override ขณะยังไม่มี Finished Receipt พร้อม audit reason
- Posted issue/return แก้ไม่ได้ ต้อง reverse ตาม WMS contract

#### I. Recoverable Material Scrap

ต้องแยกเศษจากการผลิตเป็น 3 กรณี:

1. `UNUSED_MATERIAL` — วัตถุดิบเดิมที่ยังไม่ได้ใช้: ใช้ Material Return เดิม
2. `RECOVERABLE_SCRAP` — เศษที่เก็บ ใช้ต่อ หรือขายได้: รับเข้า Stock ด้วยใบรับเศษผลิต
3. `NON_RECOVERABLE_SCRAP` — ของเสียที่ไม่มีมูลค่า/ทิ้ง: บันทึกปริมาณและเหตุผลใน WO แต่ไม่รับ Stock

ใบรับเศษผลิตแบบ `RECOVERABLE_SCRAP` ต้องมี:

- WO และ optional source material line
- Scrap item ซึ่งเป็น WMS stock item ที่ active
- Warehouse, UOM และ quantity
- Recovery unit value/total value ที่ผู้มีสิทธิ์อนุมัติ
- Reason และผู้รายงาน
- WMS movement/allocation/journal linkage
- Idempotency key ต่อ WO/scrap report/revision

กติกา MVP:

- Scrap item อาจเป็น Item เดิมหรือ Item คนละรหัส แต่ต้องมี Inventory account พร้อม
- ห้ามรับเศษเข้า Stock โดยไม่มีมูลค่า เพราะ WMS/Accounting contract ปัจจุบันต้องมี Cost Allocation มากกว่าศูนย์
- Recovery value ใช้มูลค่าที่คาดว่าจะใช้หรือขายคืนได้อย่างสมเหตุสมผล ไม่ใช้ต้นทุนวัตถุดิบเต็มจำนวนโดยอัตโนมัติ
- Recovery value สะสมต้องไม่เกิน Available WIP ของ WO
- เมื่อ Post: Dr Scrap Inventory / Cr WIP ผ่าน event `production.scrap_receipt`
- Posted Scrap Receipt แก้ไม่ได้ ต้อง reverse ผ่าน service กลาง
- เศษที่ไม่มีมูลค่าให้เป็น `NON_RECOVERABLE_SCRAP`; ต้นทุนยังคงอยู่ใน WIP และไปรวมในต้นทุนสินค้าสำเร็จรูป
- ใบรับเศษผลิตเป็น shared WMS flow ใช้ได้ทั้ง Manual Production และ WO; เฉพาะ linkage/หน้าจอ WO เท่านั้นที่ขึ้นกับ Production capability

MVP ไม่ทำ By-product/Co-product cost allocation; สิ่งนั้นเป็นคนละกรณีกับเศษวัตถุดิบที่มีมูลค่าคงเหลือ

#### J. Finished Goods Receipt ผ่าน WMS

MVP ใช้นโยบายง่ายเพื่อลดความเสี่ยงทางต้นทุน:

- หนึ่ง WO มี Finished Receipt ที่ Posted ได้หนึ่งชุดเมื่อจบงาน
- รับได้ไม่เกิน Planned quantity
- ต้องมี Material Issue ที่ Posted อย่างน้อยหนึ่งรายการ
- ต้องจัดการ Material Return และ Recoverable Scrap Receipt ให้เสร็จก่อนรับงานจบ
- Finished Receipt ต้องสร้างและ Post ผ่าน WMS service เดิม
- Actual finished value มาจาก Net Posted Material Cost ของ WO ไม่ใช่ค่าที่ผู้ใช้กรอกเอง

สูตร MVP:

```text
Available WIP Material Cost
= Posted Material Issue Allocation Value
- Posted Material Return Allocation Value
- Posted Recoverable Scrap Receipt Value
- Posted Finished Receipt Value
```

เมื่อรับงานจบ ให้ Finished Receipt consume Available WIP Material Cost ทั้งหมด

สำหรับ `MAKE_TO_ORDER` หลัง Finished Receipt ถูก Post ต้องจองสินค้าสำเร็จรูปให้ Sales Order line ผ่าน `StockReservationService` เพื่อไม่ให้เอกสารขายอื่นนำสินค้าไปใช้ การ Post Physical Sale ที่มี `source_type = SALES_ORDER` ต้อง consume reservation ของ `source_line_id` เดียวกันผ่าน service กลางอย่าง atomic; ห้ามให้ POS update reservation หรือ stock balance โดยตรง

การ Post Finished Receipt กับการสร้าง finished-goods reservation ต้องอยู่ใน outer transaction เดียวกัน หากจองให้ Sales Order ไม่สำเร็จต้อง rollback Receipt/Movement/Journal ทั้งชุด เช่นเดียวกับ Physical Sale ที่ต้อง consume reservation และ Post stock ใน transaction เดียวกัน

MVP ยังไม่รวม:

- Partial Finished Receipt หลายครั้ง
- Labor cost
- Machine cost
- Overhead allocation
- Standard cost variance

หาก WIP cost เป็นศูนย์/ติดลบ หรือ mapping/period ไม่พร้อม ต้อง block การ Post

#### K. Progress ขั้นต่ำ

เก็บเฉพาะข้อมูลที่ใช้ตัดสินใจได้:

- Planned quantity
- Completed quantity
- Finished output reject quantity
- Non-recoverable material scrap quantity/reason
- Recoverable scrap แสดงจาก Posted Scrap Receipt; ห้ามนับซ้ำกับ non-recoverable scrap
- Started at/by
- Completed at/by
- Note

MVP ไม่ต้องมี operation-by-operation reporting

#### L. หน้าจอ

1. Production Demand Queue: คำสั่งขายรอผลิตและ action สร้าง WO
2. Sales Order detail มี production status/shortcut เป็น utility ไม่ใช่ workflow หลัก
3. Production Dashboard แบบย่อ
   - WO ร่าง
   - WO กำลังผลิต
   - WO ใกล้กำหนด/เลยกำหนด
   - Material shortage
   - WO พร้อมรับงานจบ
4. BOM list/detail/form
5. Production Order list/create/detail
6. Material readiness ในหน้า WO
7. Embedded Material Issue/Return, Recoverable/Non-recoverable Scrap และ Finished Receipt
8. Related Sales Order, WMS documents และ Accounting proof
9. Audit timeline

หน้ารายการใช้ AJAX server-side DataTable ตามมาตรฐาน ERP และต้องมี Search, filter, paging และ Excel export ตามขอบเขตที่ระบุชัดเจน

หน้า WO detail ต้องแสดง:

- เลข WO และสถานะ
- Next valid action
- Material summary
- WMS documents
- Actual material cost/WIP
- ประวัติ

#### M. Permission ขั้นต่ำ

```text
production.dashboard.view
production.boms.view
production.boms.create
production.boms.update
production.boms.activate
production.orders.view
production.orders.create
production.orders.update
production.orders.release
production.orders.start
production.orders.complete
production.orders.cancel
production.execution.prepare
production.execution.approve
production.execution.post
production.execution.reverse
production.orders.cost.view
production.orders.gl.view
production.reports.view
```

Permission boundary:

- `production.execution.prepare`: จองวัตถุดิบและสร้าง Draft Issue/Return/Scrap/Finished Receipt ที่ผูกกับ WO
- `production.execution.approve`: อนุมัติเอกสาร execution ที่ผูกกับ WO
- `production.execution.post`: เรียก WMS/Accounting posting services สำหรับเอกสารที่ผูกกับ WO
- `production.execution.reverse`: กลับรายการตาม dependency chain พร้อมเหตุผล
- `production.orders.cost.view`: ดู WIP และต้นทุนของ WO
- `production.orders.gl.view`: ดูเฉพาะ GL ที่เกิดจากเอกสารของ WO

สิทธิ์เหล่านี้ไม่ให้ผู้ใช้เข้า WMS/Accounting Program และไม่อนุญาตให้จัดการเอกสารอื่นนอก WO scope การตั้ง Account Mapping, เปิดงวด และแก้ Master Accounting ยังคงต้องใช้สิทธิ์ Accounting ของผู้ดูแลระบบ

Role template ขั้นต่ำ:

- `Production Owner/Manager`: BOM/WO, approve/post/reverse, cost และ scoped GL preview
- `Production Supervisor`: release/start, approve/post execution และ complete
- `Production Operator`: ดูงาน/เตรียมงาน/รายงานผล และ `production.execution.prepare`; ไม่มี approve/post/reverse/cost/GL โดยอัตโนมัติ

บริษัทพนักงานน้อยสามารถรวม Manager/Supervisor ได้โดยเพิ่ม Production permissions เท่านั้น ไม่ต้องแจก WMS/Accounting permissions

#### N. Audit, Concurrency และ Idempotency

- Lock WO และ material rows ก่อน release/start/complete/cancel
- ใช้ DB transaction กับทุก lifecycle command
- Idempotency key ต้อง deterministic เช่น:

```text
sales-order-line:{sales_order_line_id}:production-order:{source_revision}
production-order:{order_id}:material:{line_id}:reservation
production-order:{order_id}:material-issue:{revision}
production-order:{order_id}:finished-receipt:{revision}
sales-order-line:{sales_order_line_id}:finished-goods-reservation:{order_id}
```

- Retry ด้วย key เดิมต้องคืนผลเดิมหรือ reject เมื่อ payload ต่างกัน
- Posted source document ต้อง immutable
- Audit ต้องเก็บ before/after, actor, time, reason และ source document

### 5.2 ควรมี — หลัง MVP เสถียร

เพิ่มเมื่อ Single-WO flow, Stock reconciliation และ Accounting reconciliation ผ่าน Production จริงแล้ว

#### A. Partial Production

- Partial Material Issue
- Partial Finished Receipt หลายครั้ง
- WIP cost allocation ต่อ receipt
- Over/under production tolerance ที่ตั้งค่าได้
- Reopen เฉพาะด้วย permission และ audit reason

ต้องออกแบบ cost allocation ให้ deterministic ก่อนเปิดใช้ ห้ามเฉลี่ยต้นทุนแบบ ad hoc ใน Controller

#### B. Routing Lite และ Operations

- Routing revision
- Operation sequence
- Work center
- Planned setup/run time
- Start/pause/complete operation
- Operation status แยกจาก WO status
- Default operation `ผลิต` สำหรับธุรกิจที่ไม่ต้องการ Routing รายละเอียด

#### C. Basic QC

- Inspection checklist
- Pass/Fail
- Reason/remark
- Block WO completion เมื่อ QC ที่กำหนดยังไม่ผ่าน

ยังไม่ถือเป็น Stock Quarantine จนกว่า WMS จะมี lot/disposition foundation

#### D. Material Substitute และ Controlled Override

- Substitute ที่กำหนดไว้ใน BOM
- Override Material ใน WO
- บังคับ reason และ permission
- ไม่แก้ BOM Master ย้อนหลัง

#### E. Hold และ Exception

- Hold flag/reason แยกจาก lifecycle หลัก
- Material shortage
- Overdue
- Abnormal reject/scrap
- Missing account mapping
- WIP mismatch

#### F. Reports

- WO status and due date
- Material usage vs BOM
- Yield and scrap
- WIP by WO
- Production output
- Stock/Journal reconciliation

รายงานต้องอ่านจาก source module ไม่สร้างยอดซ้ำใน Production

### 5.3 มีก็ดี — ทำเมื่อมีผู้ใช้และ use case จริง

รายการต่อไปนี้มีประโยชน์ แต่ไม่ควรอยู่ใน MVP:

- Tablet Shop Floor UI
- Barcode/QR scan เพื่อเลือก WO และ Material
- Work instructions และไฟล์แนบ
- Shift calendar
- Production Planning Board (รายละเอียดใน 5.5; ระยะแรกไม่ทำ drag-and-drop)
- Downtime logging
- Notification เมื่อ shortage/overdue
- Multi-level BOM tree สำหรับดูโครงสร้าง
- Manual child WO linkage
- Purchase Requisition suggestion โดยยังให้ Purchasing เป็นเจ้าของเอกสาร
- Basic production batch reference

หากเพิ่มไฟล์แนบหรือ Work Instruction ต้องใช้ `<x-platform::file-uploader>` และ `FileStorageService` ตามมาตรฐาน private object storage ห้ามทำ uploader หรือ public URL ใหม่

### 5.4 Shop Floor Operator Experience — ลำดับแรกหลัง MVP

พนักงานหน้างานควรใช้หน้าจอแบบ Operator Cockpit แยกจากหน้า WO ของ Supervisor แต่ใช้ Production services และสิทธิ์เดิมร่วมกัน ไม่สร้าง stock/accounting engine หรือ kiosk authentication ใหม่

**เป้าหมาย:** ใช้งานบนโทรศัพท์/Tablet ได้ด้วยมือเปียกหรือถุงมือ และรู้ว่าต้องทำอะไรต่อภายในไม่กี่วินาที

#### ขอบเขตระยะแรก

- Route แยก เช่น `/production/shop-floor` ภายใต้ `program:production`, `capability:production`, `warehouse`
- ใช้ permission เฉพาะ `production.shop_floor.use` สำหรับพนักงาน Operator; ไม่ต้องมีสิทธิ์ WMS, Accounting, GL หรือ Production Supervisor
- Operator ทำ Issue, Approve/Post, Return, Scrap และ Finished Receipt ได้ครบใน Shop Floor menu เดียว โดยยังเรียก WMS/Accounting service กลางและบันทึก Audit ตามเดิม
- Queue แบบการ์ด ไม่ใช้ DataTable เป็นหน้าหลัก แสดงเฉพาะ WO ที่เกี่ยวข้องกับคลัง/สาขาและยังทำงานอยู่
- ค้นหาด้วยเลข WO หรือสแกน QR ของ WO; ต้องมีช่องค้นหา/เลือกแบบปกติเป็น fallback เมื่อกล้องใช้ไม่ได้
- การ์ด WO แสดงสินค้า, จำนวน, กำหนดส่ง, สถานะ, shortage และปุ่ม Next action เดียวที่แตะได้ชัดเจน
- ฟอร์มสั้นสำหรับ Material Issue, Material Return, Scrap และ Finished Receipt โดยซ่อนต้นทุน บัญชี และฟิลด์ที่ Operator ไม่ต้องกรอก
- Flow ต้องจบได้ใน Cockpit เดียว ตั้งแต่สร้าง/อนุมัติ/ลง Stock โดยไม่ redirect ไป WMS หรือ Accounting
- UI ใช้สีและ badge ที่สื่อขั้นตอนชัดเจน ปุ่มใหญ่ ภาษาไทยง่าย และลดศัพท์เทคนิคสำหรับผู้ใช้หน้างาน
- แสดงผลสำเร็จ/ข้อผิดพลาดในหน้าเดียว และรองรับ retry ด้วย idempotency เดิม ไม่ให้กดซ้ำสร้างเอกสารซ้ำ
- ปุ่มขั้นต่ำ 44px, รองรับ portrait/landscape, action group ไม่ล้นจอ, input ใช้ native control และตัวเลขอ่านง่าย
- ใช้ online-only ในระยะแรก; เมื่อ network หลุดต้องแจ้งชัดเจนและห้ามเดาข้อมูล Stock ล่าสุด
- ต้องมี permission/role test แยกจาก WMS/Accounting และ runtime test ครบทั้ง flow

#### ไม่ทำในระยะแรก

- ไม่ทำ offline queue หรือ local stock cache
- ไม่ทำ Full MES/Kiosk authentication
- ไม่ทำ Routing/Operation/Time tracking ใน Cockpit จนกว่า Routing Lite จะถูกออกแบบ
- ไม่ให้ Operator แก้ BOM, WO quantity, customer data, account หรือวันที่ย้อนหลัง
- ไม่สร้าง mobile app แยก; เริ่มด้วย responsive Blade/AJAX ภายใน ERP เพื่อใช้ของกลางและลด maintenance

#### Acceptance criteria

- Operator ที่ไม่มี WMS/Accounting permission เปิด Cockpit และสร้าง Draft execution ที่ผูกกับ WO ได้
- ทุก action ตรวจ Branch/Warehouse/WO ownership ฝั่ง server
- เปิดบน viewport มือถือได้โดยไม่ต้อง zoom และ next action เห็นในหน้าจอแรก
- เมื่อทำรายการสำเร็จกลับไปการ์ด WO เดิมพร้อมสถานะล่าสุด โดยไม่ redirect ไป WMS/Accounting
- Supervisor เห็น Draft เดิมและทำ Approve/Post ต่อได้
- ทดสอบซ้ำ/refresh/network retry แล้วไม่สร้าง Issue/Return/Scrap/Receipt ซ้ำ

### 5.5 Production Planning Board — สำหรับหัวหน้าฝ่ายผลิต

เป็นหน้าวางแผนแบบ Calendar/Gantt เบื้องต้น ใช้ WO และวันที่ที่มีอยู่แล้ว ไม่เพิ่มสถานะ `PLANNED`/`SCHEDULED` และไม่ทำ finite-capacity scheduling ในระยะแรก

#### ขอบเขตระยะแรก

- มุมมองรายวัน/รายสัปดาห์/รายเดือนของ WO ตาม `planned_start_date`, `planned_finish_date` และ `required_delivery_date`
- แสดงเลข WO, สินค้า, จำนวน, สถานะ, คลัง, ผู้รับผิดชอบ, shortage, overdue และ Sales Order เมื่อมี
- Filter ตาม Branch/Warehouse, สถานะ, ผู้รับผิดชอบ, สินค้า และช่วงวันที่
- ใช้ข้อมูล WO เดิมเป็น source of truth; แก้วันที่ผ่าน edit WO ที่มี permission ไม่แก้ข้อมูลด้วยการลากแล้วบันทึกเงียบ ๆ
- คลิกการ์ด/แถบเวลาเปิด WO detail พร้อม next action และ blockers
- หัวหน้าฝ่ายผลิตเห็นงานชนกำหนดส่ง/วัตถุดิบขาด/งานค้างได้จากสีและข้อความ ไม่สื่อสารด้วยสีอย่างเดียว
- รองรับ mobile/tablet แบบอ่านและกรองได้; การแก้แผนหลักเริ่มจาก desktop/tablet ไม่บังคับ drag-and-drop บนมือถือ
- ใช้ `production.orders.view` สำหรับดู และ `production.orders.update` สำหรับแก้วันที่; ทุก query ตรวจ Branch/Warehouse scope

#### ไม่ทำในระยะแรก

- ไม่คำนวณ capacity, machine load, labor load หรือ optimal schedule
- ไม่สร้าง Shift/Work Center master ใหม่
- ไม่เปลี่ยน lifecycle status ของ WO จากหน้า Planning Board
- ไม่ส่งงานอัตโนมัติหรือสร้าง WO อัตโนมัติ

#### Acceptance criteria

- หัวหน้าฝ่ายผลิตเห็น WO ที่ใกล้กำหนด/เกินกำหนดและ shortage ในหน้าเดียว
- Filter แล้วผลลัพธ์ยังคง scope Branch/Warehouse และช่วงวันที่ถูกต้อง
- การแก้ planned date ผ่าน permission และ audit เดิมของ WO
- Planning Board ไม่สร้าง Stock Movement, Reservation หรือ Journal
- เปิดจากมือถือ/Tablet แล้วอ่านการ์ดและเปิด WO detail ได้โดยไม่ต้อง zoom

## 6. Feature ที่ตัดออกจากแผนปัจจุบัน

รายการเหล่านี้ไม่อยู่ใน Roadmap จนกว่าจะมี requirement และข้อมูลจริงรองรับ การใส่ไว้ตอนนี้ทำให้ Module ใหญ่เกินความจำเป็นและเสี่ยงสร้างระบบซ้ำ

| Feature ที่ตัด | เหตุผล |
|---|---|
| APS optimizer | ต้องมีข้อมูล capacity/lead time/constraint ที่เชื่อถือได้ก่อน และเกินความต้องการ MVP |
| Advanced MRP/automatic netting | ซ้ำซ้อนและเสี่ยงกับ Purchasing/Inventory หาก source contract ยังไม่ชัด |
| Auto-create multi-level child WO | ทำให้ dependency, reservation และ cancellation ซับซ้อนเกินไป |
| Automatic Purchase Order | Purchasing ต้องเป็นเจ้าของ approval และ supplier flow; อนาคตเสนอ PR draft ได้เท่านั้น |
| OEE | ต้องมี machine signal, shift และ downtime data ที่มีคุณภาพก่อน |
| Full MES/Kiosk authentication | เพิ่ม security/session model โดยยังไม่มีความจำเป็นใน MVP |
| Full QMS | ต้องมี sampling, disposition, quarantine และ lot foundation ก่อน |
| Full lot/serial genealogy | WMS ยังไม่มี canonical lot/serial model; ทำใน Production อย่างเดียวจะ trace ผิด |
| Unlimited recursive execution | Schema ป้องกัน cycle ได้ แต่ execution อัตโนมัติรอ use case จริง |
| Machine maintenance | เป็น Asset/Maintenance domain ไม่ควรแทรกใน Production MVP |
| Subcontract production | ต้องเชื่อม PO, service receipt, supplied material และ tax/accounting เพิ่ม |
| By-product/co-product costing | ต้องมี cost allocation policy ที่ชัดเจนก่อน |
| Labor/machine/overhead absorption | Actual source ยังไม่มี การให้กรอกเองทำให้ต้นทุนไม่น่าเชื่อถือ |
| Standard cost and variance posting | ใช้ Actual Material Cost ก่อน ลด reconciliation risk |
| Phantom BOM execution | ยังไม่จำเป็นหากไม่มี automatic BOM explosion |
| AI/automatic scheduling optimization | ไม่มีข้อมูลและ business constraint ที่เพียงพอ |
| Production network visualization | รายงานสวยแต่ยังไม่มี dependency data ที่เชื่อถือได้ |
| Offline Shop Floor mode | เสี่ยงข้อมูล stock/status เก่าและ conflict; ระบบ ERP ต้อง online |

หากธุรกิจต้องการรายการที่ตัด ต้องเขียนเอกสารแยกพร้อม source of truth, posting contract, reversal contract และ acceptance criteria ก่อนนำกลับเข้า Roadmap

## 7. Data Model ขั้นต่ำของ MVP

### 7.1 `production_boms`

```text
id
branch_id
code
name
finished_item_id
base_uom_id
is_active
created_by
updated_by
timestamps
soft_deletes
```

Unique: `(branch_id, code)`

### 7.2 `production_bom_revisions`

```text
id
bom_id
revision_number
status                 DRAFT | ACTIVE | INACTIVE
effective_from
effective_to
notes
activated_at
activated_by
created_by
updated_by
timestamps
```

Unique: `(bom_id, revision_number)`

Active revision ห้ามแก้ lines โดยตรง

### 7.3 `production_bom_lines`

```text
id
bom_revision_id
line_number
component_item_id
uom_id
quantity
notes
timestamps
```

Unique: `(bom_revision_id, line_number)` และ `(bom_revision_id, component_item_id)` สำหรับ MVP

### 7.4 `production_orders`

```text
id
branch_id
issue_warehouse_id
receipt_warehouse_id
document_number
order_type             MAKE_TO_ORDER | MAKE_TO_STOCK
status
sales_order_id         nullable
sales_order_line_id    nullable
source_revision        nullable
required_delivery_date nullable
customer_specification nullable
finished_item_id
uom_id
planned_quantity
completed_quantity
reject_quantity
bom_revision_id
planned_start_date
planned_finish_date
responsible_user_id
notes
released_at
released_by
started_at
started_by
completed_at
completed_by
cancelled_at
cancelled_by
cancellation_reason
created_by
updated_by
timestamps
soft_deletes
```

Unique: `document_number` และ `(sales_order_line_id, source_revision)` เมื่อเป็น Made to Order

การสร้าง replacement WO ต้อง lock Sales Order line และตรวจยอด Planned quantity ของ WO ที่ยังไม่ `CANCELLED` ไม่ให้เกิน quantity ใน Sales Order line ห้ามพึ่ง unique index อย่างเดียว

`customer_specification` เป็น snapshot ของคำอธิบายที่จำเป็นต่อการผลิต ไม่ใช่สำเนาราคา ส่วนลด หรือภาษี

### 7.5 `production_order_materials`

เป็น immutable requirement snapshot หลัง Release

```text
id
production_order_id
line_number
source_bom_line_id
item_id
uom_id
required_quantity
override_reason
created_at
updated_at
```

ยอด reserved/issued/returned ไม่ควรเป็น source of truth ถาวร ให้ aggregate จาก WMS และ linkage; หากทำ projection เพื่อ performance ต้อง rebuild/reconcile ได้

### 7.6 `production_order_scraps`

```text
id
production_order_id
source_material_line_id nullable
scrap_type              RECOVERABLE_SCRAP | NON_RECOVERABLE_SCRAP
scrap_item_id           nullable
uom_id
quantity
recovery_unit_value     nullable
recovery_total_value    nullable
reason
status                  DRAFT | REPORTED | POSTED | REVERSED
reported_by
reported_at
created_at
updated_at
```

- `scrap_item_id` และ recovery value บังคับเมื่อเป็น `RECOVERABLE_SCRAP`
- `NON_RECOVERABLE_SCRAP` ไม่มี Stock Movement/Cost Allocation
- เอกสาร WMS ของ recoverable scrap เชื่อมผ่านตาราง linkage ไม่เก็บ Stock balance ซ้ำในตารางนี้

### 7.7 `production_order_wms_documents`

ใช้เชื่อม WO กับเอกสาร WMS ตาม Section 4

### 7.8 `production_order_events`

เก็บ business timeline ที่ผู้ใช้ต้องเห็น เช่น release, start, WMS document linked และ complete โดย Audit Log กลางยังเป็นหลักฐานการเปลี่ยนข้อมูล

```text
id
production_order_id
event_type
source_type
source_id
payload
occurred_at
created_by
```

ไม่ต้องสร้าง event sourcing framework; ตารางนี้เป็น timeline/read model แบบง่าย

## 8. Scope และ validation

### 8.1 Branch/Plant

MVP กำหนด:

```text
Plant = Branch
```

ยังไม่สร้าง Plant master เพิ่ม

- BOM scope ตาม Branch
- WO scope ตาม selected Branch
- Sales Order, WO และ Issue/receipt warehouse ต้องอยู่ใน Branch เดียวกัน
- Item/UOM ต้อง active
- ผู้ใช้ต้องมีสิทธิ์ใน Branch/Warehouse นั้น
- Made to Order ต้องอ้าง Sales Order line จริงและสถานะ Sales Order ต้องเป็น `CONFIRMED` ณ เวลาสร้าง WO

### 8.2 Item classification

ห้ามเปลี่ยนความหมาย `wms_items.item_type` ซึ่งปัจจุบันเป็น `GOODS`/`SERVICE`

MVP ใช้ Item ที่:

- active
- เป็น stock item ตามกฎ WMS
- มี base UOM

ยังไม่เพิ่ม RAW/WIP/FG เป็น item type ใหม่ หากต้องการ label ภายหลังให้เพิ่ม manufacturing role แยกจาก `item_type`

### 8.3 Decimal และ UOM

- ใช้ precision ของ WMS
- ใช้ `WmsDecimal`
- Business calculations ใช้ decimal library/BCMath ตาม pattern เดิม ห้ามใช้ float
- MVP ใช้ Stock UOM ใน BOM/WO/WMS handoff เพื่อลด conversion risk
- UOM conversion ขั้นสูงค่อยเพิ่มเมื่อมี test ครบ

## 9. Accounting และ WIP Contract

### 9.1 Material Issue

```text
Dr WIP
Cr Inventory
```

จำนวนและมูลค่ามาจาก WMS Posted Movement/Cost Allocation เท่านั้น

### 9.2 Material Return

```text
Dr Inventory
Cr WIP
```

ต้องอ้าง Material Issue line/allocation ต้นทางเพื่อคืนต้นทุนเดิมตาม WMS contract

### 9.3 Recoverable Scrap Receipt

```text
Dr Scrap Inventory
Cr WIP
```

มูลค่ามาจาก approved recovery value และต้องไม่เกิน Available WIP ของ WO การรับเศษจึงลดต้นทุน WIP ที่จะโอนไป Finished Goods ส่วน non-recoverable scrap ไม่ลง Stock/Journal และยังคงเป็นต้นทุนของงานผลิต

### 9.4 Finished Receipt

```text
Dr Finished Goods
Cr WIP
```

MVP รับต้นทุน Net Material Actual หลังหัก Material Return และ Recoverable Scrap Receipt แล้วทั้งหมดของ WO

### 9.5 Preflight ก่อน Post

ต้องผ่านอย่างน้อย:

- Production capability เฉพาะคำสั่งจาก Production Module
- Branch/Warehouse scope
- WMS document status ถูกต้อง
- Fiscal period เปิด
- Account mapping พร้อม
- Stock/cost allocation พร้อม
- WIP value reconcile ได้
- Idempotency identity ตรงกัน
- ไม่มี previous receipt ที่ conflict

### 9.6 Reversal

ใช้ service กลางเดิมและย้อนจากปลายทางกลับต้นทาง:

1. Reverse Finished Receipt
2. Reverse Recoverable Scrap Receipt
3. Reverse Material Return ที่ Posted หากต้องย้อนทั้งงาน
4. Reverse/return Material Issue ตามกฎ WMS
5. Release reservation ที่เหลือ
6. จึง Cancel WO ได้

ห้ามเปลี่ยน WO เป็น `CANCELLED` หากยังมี Posted WMS document ที่ยังไม่ reverse

## 10. UX หลัก

### 10.1 Production Demand Queue และ Create WO

#### Made to Order — flow หลัก

เริ่มจากหน้า `คำสั่งขายรอผลิต` ใน Production Module:

1. เลือก Confirmed Sales Order line ที่มี Active BOM และยังไม่มี Active WO
2. กด `สร้างใบสั่งผลิต`
3. ระบบเติม item, quantity, specification และ required delivery date
4. ผู้ใช้เลือก BOM revision, issue warehouse, receipt warehouse และ planned start
5. Responsible user เริ่มต้นเป็นผู้สร้าง
6. บันทึก WO Draft และแสดง source Sales Order ในหน้า WO

Shortcut จาก POS ต้องเปิดหน้าเดียวกันนี้พร้อม preselect line เท่านั้น ไม่สร้าง flow แยกใน POS

ต้องแสดงเลข Sales Order, ลูกค้า, line quantity และกำหนดส่งเด่นชัด แต่ไม่แสดงราคาเป็นข้อมูลควบคุมการผลิต

#### Make to Stock/Manual

สร้างจากหน้า Production ได้โดยกรอก:

1. Finished item
2. Quantity
3. BOM revision
4. Issue warehouse
5. Receipt warehouse
6. Planned start
7. Planned finish
8. Responsible user

### 10.2 WO Detail และ Embedded Execution

หน้า WO เป็น workspace หลัก ผู้ใช้ต้องไม่ต้องเปิดหน้า WMS/Accounting เพื่อทำ workflow ปกติ

เรียงส่วนดังนี้:

1. เลข WO, status และ next action
2. Sales Order และลูกค้า เมื่อเป็น Made to Order
3. ข้อมูลแผน
4. Material readiness
5. Embedded actions: เบิก/คืนวัตถุดิบ, รับเศษ, รับสินค้าสำเร็จรูป
6. WMS document statuses, posting blockers และ `ดู GL`
7. Output, finished-goods reservation และ Actual material cost
8. Audit/history

Actions เรียงตามมาตรฐาน ERP:

1. กลับหน้ารายการ
2. แก้ไข เมื่อเป็น Draft
3. Workflow action เดียวที่ควรทำต่อ
4. ยกเลิกเอกสาร
5. ลบร่าง เมื่อเป็น Draft

### 10.3 Mobile

- Action group ต้อง wrap
- ปุ่ม workflow แตะง่าย
- ตารางกว้าง scroll ได้
- Shop Floor UI แยกต่างหากเฉพาะเมื่อเข้าส่วน “ควรมี”

## 11. Optional Module behavior

เมื่อปิด Production Module:

1. ไม่แสดง Production Program และ Sidebar
2. Production Route/API ตอบ 403 หรือ redirect ตาม middleware กลาง
3. ห้ามสร้าง/แก้ BOM และ WO
4. Scheduler/Queue ของ Production หยุดทำงาน
5. ไม่ลบ Production data
6. WMS Manual Production ใช้งานต่อได้ตามปกติ
7. WMS documents และ Accounting journals ที่เคยเกิดจาก WO ยังดูได้จากโมดูลเจ้าของ
8. POS Sales Order ยังใช้งานตามปกติ แต่ไม่แสดง action สร้าง WO
9. การ consume/release finished-goods reservation ที่สร้างไว้แล้วต้องยังทำงาน เพราะเป็น data-integrity contract ของ POS/WMS ไม่ใช่ UI capability ของ Production
10. เปิด Module กลับมาแล้ว WO เดิมและ Sales Order linkage ต้องใช้งานต่อได้

Read-only history ไม่ควรสร้างช่องทาง bypass capability ใหม่; ใช้ links จาก WMS/Accounting ไปยังเอกสารเจ้าของที่ผู้ใช้มี permission อยู่แล้ว

## 12. Installer, Migration และ Defaults

เมื่อ Implement ต้อง:

- ใช้ migration ปกติและ reversible `down()`
- เพิ่มทุกตาราง/column/index สำคัญใน `DatabasePreparationService::requiredSchema()` รวม `sales_orders.required_delivery_date`
- อัปเดต Sales Order model/request/form สำหรับ required delivery date โดย POS ยังเป็นเจ้าของ field
- เพิ่ม Program entry route เป็น `production.index`
- เพิ่ม document sequence `PRODUCTION_ORDER` และ `PRODUCTION_SCRAP_RECEIPT`
- เพิ่ม account mapping event `production.scrap_receipt` สำหรับ `SCRAP_INVENTORY`/`WIP`
- เพิ่ม permissions และ role templates ผ่าน versioned defaults
- เพิ่ม default version ใน `SystemDefaultOrchestrator`
- Seeder ต้อง idempotent และไม่ทับค่าที่ลูกค้าปรับแล้ว
- Fresh install และ upgrade install ต้องได้ schema เหมือนกัน
- การติดตั้ง schema ไม่ได้แปลว่าเปิด capability; `production_enabled` ยังเป็นตัวควบคุมการใช้งาน

## 13. Tests ขั้นต่ำ

### 13.1 Optional Module

- ปิด Module แล้ว Production Program ไม่แสดง
- ปิด Module แล้ว Production Route/API เข้าไม่ได้
- ปิด Module แล้ว WMS Manual Production ยังเข้าได้
- เปิด Module แต่ไม่มี permission ต้องเข้าไม่ได้
- Production workflow ปกติไม่ redirect ไป POS/WMS/Accounting
- Embedded action ตรวจ `production.*` permission และปฏิเสธ WMS document/Journal ที่ไม่เชื่อมกับ WO
- ผู้ใช้ที่ไม่มี WMS/Accounting Program ยังทำ Production workflow ตาม Production role ได้
- Production permission ไม่ทำให้เข้าหน้า WMS/Accounting หรือจัดการเอกสารทั่วไปได้

### 13.2 Sales Order / Made to Order

- สร้าง WO ได้เฉพาะ Sales Order `CONFIRMED`
- Action ไม่แสดงเมื่อปิด Production Module หรือไม่มี permission
- Retry บน Sales Order line เดิมคืน Active WO เดิม
- Concurrent requests ไม่สร้าง Active WO ซ้ำหรือเกิน line quantity
- Sales UOM ที่ไม่ใช่ Stock/Base UOM ถูก block ใน MVP
- Complete WO ไม่เปลี่ยน Sales Order เป็น `FULFILLED`
- Cancel Sales Order สร้าง Production exception แต่ไม่ reverse Stock/WO อัตโนมัติ
- Finished Receipt จองสินค้าสำเร็จรูปให้ Sales Order line
- Physical Sale จาก Sales Order consume reservation ของ `source_line_id` เดียวกัน
- Physical Sale ของ Sales Order อื่นนำ reserved output นี้ไปใช้ไม่ได้
- Production Demand Queue แสดงเฉพาะ eligible Confirmed Sales Order lines ใน Branch scope
- Shortcut จาก POS และ Demand Queue สร้างผ่าน command เดียวกัน

### 13.3 BOM

- Active revision immutable
- WO snapshot ไม่เปลี่ยนเมื่อ BOM เปลี่ยน
- Reject direct cycle และ indirect cycle
- Reject duplicate component

### 13.4 WO Lifecycle

- Transition ที่อนุญาตและไม่อนุญาต
- Cancel ไม่ได้เมื่อมี Posted WMS document
- Complete ไม่ได้เมื่อ WIP/account mapping ไม่พร้อม
- Concurrency ไม่สร้าง WMS document ซ้ำ

### 13.5 Stock

- Reservation ลด available ผ่าน WMS service
- Consume/release reservation ถูกต้อง
- Issue/return/scrap receipt/finished receipt สร้าง movement เพียงครั้งเดียว
- Unused material return ต้องอ้าง Item/UOM และ allocation ต้นทางเดิม
- Recoverable scrap สร้าง Stock; non-recoverable scrap ไม่สร้าง Stock
- Scrap recovery value เกิน Available WIP ถูก block
- Retry ด้วย idempotency key เดิมไม่ตัด Stock ซ้ำ
- Branch/Warehouse mismatch ถูกปฏิเสธ

### 13.6 Accounting

- Material Issue: Dr WIP / Cr Inventory
- Material Return: Dr Inventory / Cr WIP
- Recoverable Scrap Receipt: Dr Scrap Inventory / Cr WIP
- Non-recoverable scrap ไม่มี Journal
- Finished Receipt: Dr Finished Goods / Cr WIP หลังหักมูลค่า scrap recovery
- Actual material value reconcile กับ Cost Allocation
- Closed period ถูก block
- Reversal ไม่สร้างยอดซ้ำและย้อน cost chain ถูกต้อง

### 13.7 Installer

- Migration up/down
- Required schema check
- Program/default version
- Document sequences สำหรับ WO และ Scrap Receipt
- Account mapping `production.scrap_receipt`
- Permission seeding

## 14. ลำดับการพัฒนา

### Step 0 — ปิดช่องว่างของ Shared Services

- เพิ่ม WMS reservation consume contract รองรับ partial/full consumption
- ให้ POS Physical Sale posting consume finished-goods reservation ตาม Sales Order `source_line_id`
- เพิ่ม shared WMS Production Scrap Receipt service/posting/reversal contract
- แยก Finished Receipt create/approve เป็น service กลาง
- ยืนยัน WMS/Accounting posting และ reversal contract ด้วย integration tests
- ห้ามเริ่ม BOM/WO ก่อน shared flow นี้ชัดเจน

### Step 1 — Optional Module Foundation

- Provider/routes/layout/sidebar
- Program entry route
- Capability/permission guards
- Production-local orchestration routes/partials โดยไม่ duplicate domain logic
- Installer/defaults/schema checks

### Step 2 — BOM

- Master/revision/lines
- Activation
- Cycle prevention
- Tests

### Step 3 — Sales Order และ Production Order

- เพิ่ม Sales Order required delivery date
- Production Demand Queue สำหรับ Confirmed Sales Order lines
- Action สร้าง WO จาก Demand Queue; POS มีเพียง shortcut
- Made to Order linkage, source revision และ concurrency guard
- Draft/create/edit
- Release snapshot
- Lifecycle
- Production status/link บน Sales Order
- List/detail/dashboard

### Step 4 — Material Flow

- Readiness
- Reservation
- Embedded WMS Material Issue/Return actions ในหน้า WO
- Recoverable/Non-recoverable Scrap reporting และ WMS Scrap Receipt
- Linkage และ idempotency

### Step 5 — Completion and Accounting

- WIP cost calculation
- Embedded WMS Finished Receipt action ในหน้า WO
- Inline posting blockers และ shared GL preview
- Finished-goods reservation ให้ Sales Order line
- POS consumption ของ reservation ตอน Post Physical Sale
- Completion and reversal chain
- Reconciliation tests

### Step 6 — Production Trial

ทดสอบ vertical slice:

```text
Create BOM
-> Confirm Sales Order with required delivery date
-> Create WO from eligible Sales Order line
-> Release WO
-> Reserve material
-> Create/Post WMS Material Issue
-> Start WO
-> Return unused material if any from WO workspace
-> Report non-recoverable scrap if any
-> Create/Post recoverable Scrap Receipt if any from WO workspace
-> Create/Post WMS Finished Receipt from WO workspace
-> Reserve output for Sales Order line
-> Complete WO without fulfilling Sales Order
-> Create/Post POS Physical Sale from that Sales Order
-> Consume finished-goods reservation
-> Reconcile Stock + WIP + Finished Goods + COGS + Journal
```

ต้องผ่าน flow ปกติ, retry, validation failure, cancellation และ reversal ก่อนเพิ่ม Feature ในหมวด “ควรมี”

## 15. Definition of Done สำหรับ MVP

MVP ถือว่าเสร็จเมื่อ:

- บริษัทที่ปิด Production Module ยังใช้ WMS Manual Production ได้ตามเดิม
- บริษัทที่เปิด Module สร้าง BOM และสร้าง WO จาก Confirmed Sales Order line ได้
- WO แบบ Made to Order เชื่อมกลับ Sales Order/customer demand และ required delivery date ได้
- Retry/concurrency ไม่สร้าง WO ซ้ำหรือเกิน Sales Order quantity
- WO ใช้ immutable BOM และ Sales Order source snapshot
- Material readiness อ่านจาก WMS จริง
- ผู้ใช้ฝ่ายผลิตทำ Demand -> WO -> Issue -> Return/Scrap -> Finished Receipt -> Complete ได้ใน Production Module โดยไม่ต้องสลับหน้า
- Embedded actions ใช้ WMS/Accounting services กลาง แต่ authorize ด้วย Production-scoped permissions และ WO linkage ไม่ duplicate logic
- Reservation, Issue, Return, Scrap Receipt และ Finished Receipt ผ่าน WMS services กลาง
- Unused return กับ recoverable/non-recoverable scrap ถูกแยกความหมายและต้นทุนถูกต้อง
- Finished output ของ Made to Order ถูกจองให้ Sales Order และ POS consume reservation ถูก line
- Complete WO ไม่ Fulfill Sales Order แทน POS
- Journal เกิดผ่าน Accounting services กลางเท่านั้น
- Actual material cost reconcile ได้ถึง WMS Cost Allocation และ Journal
- ไม่มีการตัด Stock หรือ Post Journal ซ้ำเมื่อ retry
- Cancellation/Reversal ไม่ทิ้ง WIP หรือ Stock ค้างผิดปกติ
- Route/API ถูก capability, program, permission และ Branch/Warehouse scope ป้องกันครบ
- Installer ตรวจ schema/defaults ได้
- Index/detail เป็นไปตาม UX/UI standard ของ ERP
- Contract tests, integration tests และ `git diff --check` ผ่าน

## 16. คำสั่งสำหรับ Coding Agent

ก่อน Implement ทุก Step ต้องตรวจ source code ปัจจุบันของ WMS, Accounting, Installer, Routes และ tests ก่อนเสมอ ห้ามอาศัยชื่อ service ในเอกสารเพียงอย่างเดียวหาก signature เปลี่ยนแล้ว

ให้เลือกการเปลี่ยนที่เล็กที่สุดและ reuse ของกลาง โดยเฉพาะ:

- ห้ามสร้าง inventory engine ใหม่
- ห้ามสร้าง accounting engine ใหม่
- ห้ามเรียก Controller ข้าม Module
- ห้ามแก้ Stock/Journal table โดยตรง
- ห้ามทำ APS/MRP/QMS/Lot framework เผื่ออนาคต
- เพิ่ม abstraction เฉพาะเมื่อมี caller จริงมากกว่าหนึ่ง flow หรือจำเป็นต่อ transaction boundary

เริ่ม Implement ได้ต่อเมื่อ Scope ของ MVP และ Shared Service gaps ใน Step 0 ได้รับการอนุมัติแล้ว
