# WMS Issue / Manual Production Receipt Feature Checklist

อัปเดตล่าสุด: 8 กันยายน 2026

## เป้าหมาย

ให้ WMS ใช้งานได้แม้บริษัทไม่ได้เปิด Module Production โดยรองรับรายการผลิตแบบ Manual:

- เบิกวัตถุดิบผลิต (`Material Issue`)
- รับสินค้าผลิตเสร็จ (`Finished Goods Receipt`)
- เบิกสินค้าใช้งานทั่วไป (`General Issue`)

รายการทั้งหมดต้องใช้ Stock Movement, AVG/FIFO, Cost Layer, Allocation และ Audit contract เดียวกับ WMS เดิม ห้ามแก้ยอด Stock โดยตรง

## ขอบเขต MVP

### Document Boundary และ Scope

- [x] 1 เอกสารต้องมี `branch_id` และ `warehouse_id` เดียวกันตลอดอายุเอกสาร โดย `HasDocumentBranch` snapshot สาขาจากคลัง
- [x] ตรวจว่า Warehouse อยู่ภายใต้ Branch ที่ผู้ใช้กำลังทำงานอยู่ ผ่าน `warehouse` middleware และ scope ของ detail/action
- [x] ตรวจว่า Warehouse อยู่ในสิทธิ์ของผู้ใช้ก่อนแสดงรายการและก่อนบันทึก
- [x] ไม่อนุญาตให้เอกสารเดียวเบิกจากหลาย Warehouse; Issue/Return/Finished Receipt ใช้ selected warehouse เดียว
- [x] หากต้องเบิกหลาย Warehouse ให้สร้างเอกสารแยก หรือใช้ Parent Request ในอนาคต
- [x] ใบรับคืนต้องใช้ Warehouse เดียวกับใบเบิกต้นทางโดยอัตโนมัติ และตรวจซ้ำก่อน Post
- [x] รับผลิตเสร็จต้องระบุ Warehouse รับเข้าอย่างชัดเจน และตรวจ branch/warehouse ก่อน action
- [ ] แยกประเภทเอกสาร `GENERAL`, `PRODUCTION` และ `RETURN` ด้วย Stable Code

### Feature Separation

- [x] General Issue: แยกหน้าและ DataTable สำหรับเบิกสินค้าใช้งานทั่วไป
- [x] Production Material Issue: Fix ประเภทเอกสารเป็น `PRODUCTION` ทั้ง route และ server action
- [ ] Production Finished Receipt: แยก Route, Controller, View และ DataTable จาก Inventory Adjustment
- [x] Production Issue Return: แยกการรับคืนจากการเบิกทั่วไป และอ้างอิง Source Issue ที่เป็น `PRODUCTION` เท่านั้น
- [x] ไม่แชร์ Controller/Blade ของ General Issue กับ Production Material Issue โดยใช้ Controller และ Blade ชุด Production เฉพาะ
- [x] Detail ของ Production Material Issue แสดง Source/Target Document และ Drill-down ใบรับคืน/ใบรับผลิต
- [x] Action ของ Production Material Issue แสดงตามสถานะและ Permission จริง

- [x] เพิ่มเมนูและเส้นทางใน WMS สำหรับ Manual Production Movement โดย reuse permission ของ Issue/Adjustment ใน Wave 1
- [x] เพิ่ม document context สำหรับ Finished Goods Receipt ('PRODUCTION_RECEIPT') และ reuse issue type 'PRODUCTION'
- [ ] เลือกสาขาและคลังตามสิทธิ์ผู้ใช้ โดยตรวจสาขา–คลังให้สัมพันธ์กัน
- [ ] เลือก Item และ Stock UOM พร้อมแสดงยอดคงเหลือก่อนทำรายการ
- [ ] Material Issue รองรับเฉพาะ Inventory Item และห้ามติดลบเมื่อ Global Setting ไม่อนุญาต
- [ ] Finished Goods Receipt รองรับ Inventory Item และกำหนดต้นทุนต่อหน่วยอย่างชัดเจน
- [ ] รองรับเหตุผลผลิต, หมายเหตุ และวันที่เอกสาร
- [x] สร้าง Draft → Approve ตาม permission ด้วยเอกสาร WMS เดิม; Post ของ Finished Receipt ถูก block จนกว่า Production accounting mapping จะพร้อม
- [ ] Posted แก้ไขไม่ได้; แก้ด้วย Reversal/Corrective Document เท่านั้น
- [ ] ป้องกัน Post ซ้ำด้วย idempotency key และ source identity
- [ ] บันทึก Movement → Cost Layer → Allocation → Journal linkage ตาม contract กลาง

## Material Issue

- [ ] ตรวจจำนวนที่เบิกไม่เกิน Available Stock ตาม policy
- [ ] General Issue และ Production Material Issue ใช้ Issue Type ที่ fix ต่างกันและไม่ให้ผู้ใช้เปลี่ยนข้ามประเภท
- [ ] ใช้ AVG/FIFO cost allocation engine เดียวกับ Issue ปกติ
- [ ] ระบุบัญชีปลายทางเป็น WIP หรือ Production Consumption ตาม mapping
- [ ] ใช้ `production.material_issue` เป็น Accounting event
- [ ] หากยังไม่มี Production ให้ใช้เหตุผล Manual และไม่สร้าง Work Order/BOM ปลอม

## Finished Goods Receipt

- [ ] รับเข้าเฉพาะคลังที่ผู้ใช้มีสิทธิ์
- [ ] แสดง Source Material Issue ก่อนรายการรับผลิต พร้อมจำนวนและต้นทุนรวมต้นทาง
- [ ] บังคับจำนวนมากกว่า 0 และ Stock UOM ถูกต้อง
- [ ] บังคับต้นทุนรวม/ต้นทุนต่อหน่วยตามรูปแบบที่เลือก
- [ ] แสดงยอดรวมมูลค่ารับผลิตและต้นทุนต่อหน่วยแบบ Realtime
- [ ] ตรวจว่าต้นทุนไม่เป็นลบและปัดเศษตาม company precision
- [x] หากมี residual จากการปัดเศษ ให้ปรับเข้ารายการสุดท้ายและยอดรวมต้องตรงกับต้นทุนต้นทาง
- [x] ใช้ `production.finished_receipt` เป็น Accounting event แยกจาก `inventory_adjustment`
- [ ] รองรับ WIP, Finished Goods และ Production Variance mapping
- [ ] หากไม่มี WIP/Production mapping ให้หยุด Post พร้อมข้อความแก้ไขที่ชัดเจน

## Costing และ Reconciliation Gate

- [ ] Material Issue ใช้ Cost Layer จริงและไม่สร้าง allocation ซ้ำ
- [x] Finished Receipt สร้าง Cost Layer พร้อม source document ครบ และก่อน Post ตรวจ Source Movement/Allocation ของใบเบิกต้นทาง
- [ ] AVG/FIFO valuation ตรงกับ Stock Balance
- [x] Allocation ตรงกับ Movement และ Journal Line ใน posting transaction เดียว
- [x] ไม่มี pending cost หรือ unlinked allocation ก่อน Post (Gate C ตรวจและกัน pending/unlinked allocation, รวมถึง reuse AVG receipt allocation เดิมเพื่อไม่สร้าง allocation ซ้ำ)
- [x] รองรับ reversal แบบ immutable และ audit trail ครบ (สร้าง reversal Journal/Movement/Allocation ใหม่, link กลับทุกบรรทัด, idempotent retry และ exact source-cost delta)
- [x] Gate C: allocation, stock projection และ GL control account ต่างกันเป็นศูนย์ (ตรวจใน transaction ก่อนเปลี่ยนเอกสารเป็น POSTED; ตรวจ quantity/value projection, allocation↔journal line และ Debit/Credit balance)

## UX/UI

- [ ] หน้า list แยก Filter กับ DataTable ตามมาตรฐาน WMS
- [ ] แสดงสถานะ Draft, รอตรวจสอบ, Approved, Posted, Reversed แบบ semantic badge
- [ ] ปุ่ม action เรียงมาตรฐาน: ดูรายละเอียด → แก้ไข Draft → อนุมัติ → Post → Reverse
- [ ] แสดง On-hand/Available ณ วันที่เอกสารก่อนยืนยัน
- [ ] แจ้ง blocker ระดับบรรทัดและลิงก์ไปหน้าตั้งค่าที่เกี่ยวข้อง
- [ ] ใช้ AJAX, SweetAlert และป้องกันการกดซ้ำ
- [ ] รองรับ DataTable search, filter, pagination และ export ตาม shared contract

## Permission / RBAC

- [ ] `wms.production-movements.view`
- [ ] `wms.production-movements.create`
- [ ] `wms.production-movements.update`
- [ ] `wms.production-movements.approve`
- [ ] `wms.production-movements.post`
- [ ] `wms.production-movements.reverse`
- [ ] ผูก permission กับ RbacSeeder และ role template
- [ ] ซ่อน action ตามสิทธิ์จริงทั้ง Sidebar, List และ Detail

## Installer / System Update

- [ ] Seed Issue Type `GENERAL` และ `PRODUCTION` แบบ idempotent ระดับองค์กร ไม่ผูก Warehouse/Branch
- [ ] Seed Document Context ของ General Issue, Production Material Issue, Finished Receipt และ Issue Return
- [ ] Seed Document Sequence แยกตาม Feature และกำหนด Monthly Reset ตามรูปแบบมาตรฐาน
- [ ] Seed Permission และ Role Assignment สำหรับ View/Create/Update/Approve/Post/Reverse
- [ ] Seed Production Posting Event และ Account Mapping: WIP, Finished Goods, Production Variance
- [ ] เพิ่ม Seeder Version และ Apply System Update สำหรับลูกค้าเดิม
- [ ] ตรวจว่า Fresh Install และ Retry Installer ไม่สร้างข้อมูลซ้ำ
- [ ] ห้ามใช้ SQL/Seeder/manual deployment เพื่อเตรียมข้อมูลลูกค้า

## ความสัมพันธ์กับ Production Module

- [ ] เมื่อ `production_enabled = false` ให้เปิด Manual path ได้
- [ ] เมื่อเปิด Production ให้แยก source เป็น `MANUAL` หรือ `PRODUCTION_ORDER`
- [ ] Production Order ต้องส่ง Item, UOM, Warehouse, Quantity และ Cost contract มายัง WMS
- [ ] ห้ามสร้าง Manual และ Production Order movement ซ้ำรายการเดียวกัน
- [ ] ปิด/ซ่อน Manual path ได้ผ่าน policy โดยไม่แก้ Source Code

## Tests และ UAT

- [ ] Unit: validation, UOM, quantity, negative stock และ cost precision
- [ ] Unit: idempotency และ duplicate Post
- [ ] Unit: reversal ไม่แก้ Posted row เดิม
- [ ] Integration rollback: Material Issue → Movement → Allocation → GL
- [x] Integration rollback: Finished Receipt → Movement → Cost Layer → GL (local MySQL rollback-only test ผ่าน 1 test / 5 assertions)
- [x] AVG scenario: receipt → manual issue → finished receipt (local MySQL end-to-end ผ่าน)
- [x] Finished Receipt reversal integration: Post → Reverse → Stock/Cost/GL กลับสมดุล และ retry ไม่สร้าง Journal ซ้ำ (local MySQL 1 test / 14 assertions)
- [x] FIFO scenario: หลาย cost layers → manual issue → finished receipt (local MySQL multi-layer integration ผ่าน)
- [x] Reconciliation: Gate C ผ่านเมื่อข้อมูลถูกต้อง (ใช้ InventoryReconciliationCalculator/Gate และ rollback transaction เมื่อพบ variance; local MySQL integration ผ่าน 1 test / 5 assertions)
- [ ] Permission isolation: create/approve/post/reverse แยกตาม role
- [ ] Manual UI: แสดง stock คงเหลือ, blocker และ recovery guidance

## Definition of Done

- [ ] บริษัทที่ไม่เปิด Production สามารถเบิกวัตถุดิบและรับสินค้าผลิตเสร็จผ่าน WMS ได้
- [ ] รายการ Post สร้าง Stock Movement และ Cost Layer ถูกต้องเพียงครั้งเดียว
- [ ] AVG/FIFO และ negative stock policy ถูกบังคับจริง
- [ ] Inventory/Allocation/GL reconcile เป็นศูนย์
- [ ] Posted แก้ไขย้อนหลังไม่ได้ และ Reversal ตรวจสอบย้อนหลังได้
- [ ] Production Module เปิดภายหลังได้โดยไม่ต้องเปลี่ยนเอกสารเดิมหรือแก้ Source Code

## สิ่งที่ไม่รวมใน MVP

- [ ] BOM/Recipe และ revision
- [ ] Work Order และ routing
- [ ] Material planning/MRP
- [ ] Multi-level production และ by-product allocation
- [ ] Automatic planned-vs-actual production costing

## Implementation Note

- Material Issue ใช้ IssueDocument เดิม โดยกำหนด issue_type=PRODUCTION จึงยังผ่าน stock availability, cost allocation และ audit flow เดิม
- Finished Goods Receipt มี Route, Controller, View และ DataTable เฉพาะของ Production แล้ว; ส่วน Stock/Cost engine ต้องรักษา lineage ของ `document_context=PRODUCTION_RECEIPT` และบังคับทิศทางรับเข้า
- Wave 2 UI guard: DataTable จะไม่เสนอปุ่ม Post สำหรับ PRODUCTION_RECEIPT ขณะที่ server guard ยังคงบังคับซ้ำ
- Wave 3 preflight: รายละเอียด Finished Receipt ตรวจ readiness ของ event production.finished_receipt และลิงก์ไป Account Mapping ได้; event ถูกเปิดเป็น LIVE เมื่อ mapping ครบ
- Wave 4 contract layer: เพิ่ม ManualProductionReceiptContract สำหรับบังคับ context, รับเข้าเท่านั้น, quantity/value > 0 และ preflight blocker ก่อนต่อ posting workflow
- Wave 5 posting plan: เพิ่ม write-free deterministic plan สำหรับ Movement Intent, Receipt Cost Allocation, Journal roles, idempotency key และ posting hash; ยังไม่เปิด transaction posting
- Wave 6 service boundary: เพิ่ม preflight/feature gate service สำหรับ atomic posting; flag และ event ยังปิดอยู่จนกว่า integration workflow จะเสร็จ
- Costing safety gate: Finished Receipt ส่งทั้ง trusted unit cost และ exact receipt total value เข้า Cost Layer เพื่อไม่ให้ qty × rounded unit cost ทำให้ต้นทุนคลาดเคลื่อน
- Rounding policy: ปัด unit cost ที่ 8 ตำแหน่งและย้าย residual เข้า line สุดท้าย โดยรักษาต้นทุนรวมเอกสารเท่าเดิม; ค่าใช้จ่ายยังแก้ได้ใน Draft เท่านั้น
- MA separation: แยก Material Issue และ Finished Receipt เป็น route/view เฉพาะ (`/wms/production/material-issues` และ `/wms/production/finished-receipts`) แยกกลุ่มเมนู ผลิตแบบ Manual ออกจาก เบิก–รับสินค้า และ นับ–ปรับปรุงสินค้า โดยบังคับประเภทเบิก Production ฝั่ง server และ reuse เฉพาะ engine กลางที่ไม่มี UI/Controller coupling
- Production Material Issue separation: ย้าย route, DataTable, item options, create/detail และ approve/post/cancel/delete actions ไป `ProductionMaterialIssueController` กับ `Views/production/material-issues/*`; General Issue ไม่ใช้ Blade หรือ Controller ของ Production แล้ว
- Production Issue Return separation: แยก `ProductionIssueReturnController` กับ `Views/production/issue-returns/*`; Generic Issue Return ไม่แสดงและไม่รับต้นทาง `PRODUCTION` อีกต่อไป และทุก action ตรวจ source issue/branch/warehouse
- Finished Goods Receipt ใช้ posting service เฉพาะของ WMS แล้ว โดยสร้าง Stock Movement, Cost Allocation และ Journal ใน transaction เดียว; ต้องทำ UAT Post/Retry/Reversal ให้ครบก่อนปิด Wave
- Boundary hardening: เพิ่มการตรวจ `branch_id` + `warehouse_id` ของเอกสารกับบริบทปัจจุบันใน Issue/Issue Return/Finished Receipt detail และก่อน Post เพื่อกันการใช้เอกสารข้ามสาขา/คลัง
- Gate C costing hardening: Finished Receipt preflight ตรวจ source issue เป็น Production/Posted, warehouse เดียวกัน, movement Posted, allocation Final, ต้นทุนรวมตรงที่ precision 8 และกัน receipt-side Movement/Allocation/Journal ซ้ำหรือ linkage ผิดก่อนเปิด Post; transaction posting ตรวจ allocation/stock projection/GL จริงก่อนเปลี่ยนเป็น POSTED และ reuse AVG allocation เดิมเพื่อกัน pending allocation ซ้ำ
- การสร้าง migration ยังต้องทำผ่าน Installer/Prepare Database เท่านั้น ไม่ใช้ manual deployment หรือแก้ข้อมูลตรงในฐานข้อมูล
