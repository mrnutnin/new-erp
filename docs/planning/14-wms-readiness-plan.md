# WMS Readiness Development Plan

เอกสารนี้เป็นแผนพัฒนา WMS จากสถานะ Workflow Center ให้พร้อมใช้งานจริง โดยเปิด Inventory Posting แบบมี gate และตรวจสอบย้อนหลังได้

## เป้าหมาย

- ใช้ AVG หรือ FIFO ตาม Global Settings ระดับบริษัท
- รองรับ Opening Balance, Receipt, Issue, Transfer และ RECOST
- เชื่อม Stock Movement → Cost Allocation → Journal Line แบบทำซ้ำไม่ได้
- กระทบยอด Inventory/COGS กับ GL ก่อนปิดงวด
- รองรับ Count/Adjustment, Reversal และ Audit Log

## ลำดับงานและเกณฑ์รับมอบ

### 1. Account Mapping

- [x] ตั้งค่า Inventory Account และ COGS Account สำหรับสินค้า Stock และ WMS event
- [x] ตรวจประเภทบัญชี, active และ postable
- [x] แสดง readiness ตาม mapping จริง

เกณฑ์ผ่าน: Inventory/COGS Mapping เป็น `พร้อมทำ` (Receipt/Recost mapping ตั้งแล้ว; การเปิด event ยังถูกควบคุมด้วย feature policy)

### 2. Opening Balance

- [x] วางโครงสร้าง Batch/Line สำหรับ Opening Balance แยกจาก Stock Movement
- [x] เพิ่ม Service สำหรับสร้าง Draft และ Post แบบ idempotent พร้อมสร้าง Movement/Cost Layer/Allocation
- [x] บันทึกจำนวน หน่วย ต้นทุน วันที่เริ่มต้น พร้อมเลือกสาขาและคลังตามสิทธิ์ผู้ใช้
- [x] ดาวน์โหลด Excel Template สำหรับ Opening Balance (มีตัวอย่างและ Data Dictionary)
- [x] อัปโหลดไฟล์แบบ Stage → Validate → Preview
- [x] Approve → Commit จาก Import Batch หลังผ่าน validation
- [x] รองรับการเลือกสาขา/คลังในไฟล์และตรวจสิทธิ์ให้ตรงกับผู้ใช้งาน
- [x] ตรวจ SKU ซ้ำในคลังเดียวกัน, หน่วยฐาน, จำนวน, ต้นทุนรวม และทศนิยมก่อน Commit
- [x] รายงานข้อผิดพลาดรายแถวและดาวน์โหลด Error Workbook
- [x] รองรับไฟล์ขนาดใหญ่ด้วย chunk reader และ validate ระหว่างอ่าน (queue เป็นทางเลือกเมื่อเปิดใช้งาน worker จริง)
- [x] ทำให้ import idempotent ด้วย batch และ stable row key ป้องกันการนำเข้าซ้ำ
- [x] รองรับ AVG/FIFO ตาม Global Settings
- [x] ป้องกันรายการยอดยกมาซ้ำในคลัง/วันที่เดียวกัน
- [x] ตรวจยอดกับ Stock Ledger และ Cost Layer ก่อน Post

เกณฑ์ผ่าน: มียอดยกมาที่ link กับ Stock Ledger ได้ถูกต้อง

### 3. Purchase Event Wiring

- [x] เชื่อม Purchase/Receipt กับ Inventory Movement (ผ่าน Production Smoke Test)
- [x] ตรวจ Item, UOM, Warehouse และต้นทุนผ่าน Inventory Purchase Contract
- [x] เพิ่ม source identity และ idempotency contract
- [x] เปิด feature flag ได้เฉพาะเมื่อ preflight ผ่านเท่านั้น

เกณฑ์ผ่าน: Receipt จาก Purchase สร้าง Movement และ Cost Allocation ได้หนึ่งครั้ง

### 4. Stock Movement และ Cost Allocation

- [x] Receipt, Issue และ Transfer (ผ่าน Unit/MySQL Integration Test)
- [x] คำนวณ AVG/FIFO ตาม policy
- [x] ตรวจ negative stock policy และตั้งค่า fallback ให้ครบ (block เมื่อไม่อนุญาต; ใช้ CURRENT_AVERAGE/LAST_KNOWN/STANDARD เมื่ออนุญาต)
- [x] ตรวจ Pending Cost และ Unlinked Allocation
- [x] รองรับ RECOST (ยังต้องตั้งค่า negative fallback ก่อนทดสอบกรณีสต็อกติดลบ)

เกณฑ์ผ่าน: Stock Balance, Allocation และ Valuation ตรงกัน

### 5. Count / Adjustment

- [x] Stock Count
- [x] Adjustment เพิ่ม/ลด (ผู้ใช้สร้างเองเมื่อเห็นว่าผลต่างต้องปรับ)
- [x] Approval ตามสิทธิ์
- [x] ป้องกันการแก้เอกสาร Posted
- [x] Reversal และ Audit Log

เกณฑ์ผ่าน: มีหน้าปลายทางและตรวจสอบรายการย้อนหลังได้

### 6. Inventory / COGS Reconciliation

- [x] Stock กับ Allocation (Stock Balance ↔ Allocation พร้อมผลต่าง)
- [x] Allocation กับ GL (Inventory control account และ item subledger)
- [x] แยก Mapping Gap, Pending RECOST, Rounding และ Unlinked ใน Preflight/Reconciliation
- [x] Gate B: Movement → Allocation → Journal Line
- [x] Gate C: Reconciliation Difference ต้องเป็นศูนย์
- [x] Corrective contract: legacy duplicate allocation ใช้ immutable correction record อ้าง duplicate/canonical/Journal line และเก็บ Audit Log โดยไม่แก้หรือลบ POSTED row
- [x] Gate C production check: warehouse 1 ผ่าน allocation-vs-GL, balance-vs-allocation, linkage, pending, rounding และ legacy review blockers เป็นศูนย์ (05/09/2026)

เกณฑ์ผ่าน: เปิด Inventory Posting ได้เมื่อผลต่างเป็นศูนย์

### 7. Period Close และ Control

- [x] ตรวจรายการค้างก่อนปิดงวด (Journal, Inventory/RECOST, Asset และเอกสารปฏิบัติการ)
- [x] ป้องกัน Post ข้ามงวด (OPEN เท่านั้น; SOFT_CLOSE/LOCKED ถูกปฏิเสธ)
- [x] Reversal ก่อนปิดงวด และตรวจ Journal linkage ของรายการ Posted
- [x] Audit Log และ Posting Exception สำหรับรายการที่ปิดงวดต้องกักไว้

### 8. End-to-End QA

- [x] Opening Balance → Receipt → Issue (Opening Balance boundary ผ่าน dedicated MySQL rollback E2E; ต่อ Receipt/Issue ใช้ชุด lineage เดียวกัน)
- [x] Purchase → Receipt → Inventory → GL (MySQL rollback integration)
- [x] AVG และ FIFO (Transfer cost-lineage integration)
- [x] Negative Stock อนุญาต/ไม่อนุญาต (runtime + insufficient-stock gate)
- [x] RECOST (runtime positive/negative delta และ period-close gate)
- [x] Transfer ระหว่างคลัง (lineage, partial accept/retry และ rollback)
- [x] Count/Adjustment (gain/loss, idempotency และ reversal)
- [x] Reversal และ Period Close (credit purchase, adjustment และ recost)

### 9. Operational UI/UX Standard

- [x] ใบเบิกสินค้า: แยก Filter section จาก DataTable และกรองสถานะ/วันที่แบบ server-side
- [x] ใบรับคืนจากการเบิก: แยก Filter section จาก DataTable และกรองสถานะ/วันที่แบบ server-side
- [x] ตรวจและปรับ Filter section ให้ครบสำหรับ Stock Count, Adjustment, Transfer และ Opening Balance
- [x] ตรวจและปรับ badge ให้ใช้ semantic pastel status classes ในหน้าปฏิบัติงานหลัก
- [x] จัด action order กลางของ DataTable ให้เป็น pattern เดียวกันทุกหน้าปฏิบัติงาน
- [x] ใช้ SweetAlert และ shared page length กับหน้าปฏิบัติงาน WMS
- [x] WMS Dashboard: แยก summary/work/trend เป็น sections แบบ lazy AJAX และ cache ตามคลัง
- [x] WMS Dashboard: ตาราง Low Stock และ Stock Movement ใช้ server-side DataTable และ pageLength 5
- [x] WMS Workflow: แยกขั้นตอน Receipt, Issue, Transfer, Count และ Adjustment พร้อม pending count/blocker ตามคลัง
- [x] WMS Workflow: decision gate ใช้ runtime readiness จริง, มีปุ่มตรวจสอบตามสิทธิ์ และไม่แสดง blocker ว่าง
- [x] WMS Workflow: Gate A/B/C ผูกกับ Inventory Preflight/Reconciliation จริง และมีลิงก์ตรวจ blocker ตามคลัง
- [x] WMS Preflight: จำกัด Gate A/B ให้ตรวจเฉพาะ local Inventory→GL MVP source และไม่ปน POS sales_cogs contract

## สถานะเริ่มต้น

- นโยบาย AVG/FIFO: พร้อมทำ
- Item / Category / UOM: พร้อมทำ
- Inventory / COGS Mapping: พร้อมทำ (ผ่าน mapping contract และ MySQL integration)
- Opening Balance: พร้อมทำ (ผ่าน idempotent MySQL rollback E2E และ chunked import)
- Purchase Event Wiring: พร้อมทำ (ผ่าน Purchase/Receipt integration; เปิดใช้จริงยังขึ้นกับ feature flag)
- Reconciliation Gates: พร้อมตรวจสอบตามข้อมูลจริง; ยังคง block เมื่อมี pending/unlinked/rounding difference และแสดงสาเหตุ/หน้าตรวจสอบใน workflow
