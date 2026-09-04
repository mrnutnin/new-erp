# Landed Cost — แผนพัฒนาต้นทุนแฝงของสินค้า

## 1. เป้าหมาย

รองรับการนำต้นทุนที่เกี่ยวข้องกับการนำเข้าสินค้า เช่น ค่าขนส่ง ค่าประกันภัย ภาษี และค่าธรรมเนียม มาเพิ่มและกระจายเข้าต้นทุนสินค้าที่รับเข้าแล้วอย่างตรวจสอบย้อนกลับได้ โดยไม่แก้ทับ Stock Ledger, Cost Allocation หรือ Journal เดิม

หลักการแบ่งความรับผิดชอบ:

| ส่วนงาน | ความรับผิดชอบ |
|---|---|
| Purchasing | สร้างและอนุมัติเอกสาร Landed Cost, เลือก source และค่าใช้จ่าย |
| WMS | ตรวจรับ/จับคู่สินค้า, คำนวณ allocation, สร้าง recost และปรับต้นทุนปลายทาง |
| Accounting | สร้าง Journal delta/reversal และตรวจงวดบัญชี |
| Finance | จัดการเจ้าหนี้/การจ่ายเงินจริงของค่าใช้จ่าย เมื่อมีเอกสารต้นทาง |

## 2. ขอบเขต MVP

### ทำใน MVP

- ผูก Landed Cost กับ Goods Receipt ที่ `POSTED` แล้ว
- รองรับหลาย Goods Receipt และหลายรายการสินค้าในเอกสารเดียว
- รับค่าใช้จ่ายจาก Credit Purchase/ใบตั้งหนี้ซื้อ หรือระบุเป็นรายการที่มีบัญชีค่าใช้จ่าย
- กระจายต้นทุนตามมูลค่าสินค้า, จำนวน หรือ น้ำหนัก โดยเลือก policy ต่อเอกสาร
- แสดง preview ก่อน Post: source, ค่าใช้จ่าย, allocation ต่อ item และผลกระทบโดยประมาณ
- Post แบบ idempotent พร้อมเลขเอกสาร, ผู้อนุมัติ, เหตุผล และ audit history
- สร้าง immutable cost delta และส่งเข้า Recost pipeline ที่มีอยู่
- กระจายผลกระทบต่อ Stock Valuation, COGS, WIP/transfer downstream และ GL ตาม dependency graph
- รองรับการ Void ก่อน Post และ reversal หลัง Post ตาม open-period policy

### ยังไม่ทำ

- กระจายต้นทุนอัตโนมัติด้วย AI หรือ heuristic ที่ผู้ใช้ตรวจสอบไม่ได้
- แก้ไข Journal/Stock Movement/Cost Allocation เดิมโดยตรง
- Landed Cost ข้ามบริษัทหรือข้าม costing pool
- การนำเข้าไฟล์ขนาดใหญ่/queue สำหรับเอกสารที่ยังไม่มี benchmark
- การสร้างค่าใช้จ่ายซ้ำ หากมี Credit Purchase/AP source อยู่แล้ว

## 3. Workflow ผู้ใช้

`Draft → Submitted → Approved → Posted`

1. ผู้ใช้ Purchasing สร้าง Landed Cost และเลือก Warehouse/บริษัท/วันที่
2. เลือก Goods Receipt ที่ Post แล้ว ซึ่งยังไม่ถูกปิดงวดและยังมี quantity/value ให้จัดสรร
3. เพิ่มรายการค่าใช้จ่าย โดยเลือก source จาก Credit Purchase หรือบัญชีค่าใช้จ่ายตามสิทธิ์
4. เลือก allocation basis และตรวจ preview
5. Submit/Approve ตาม approval policy
6. ตอน Post ระบบ lock source และ period แล้วทำใน transaction เดียว:
   - ตรวจ source identity และยอดรวม
   - สร้าง cost delta สำหรับรายการรับเข้า
   - สร้าง Journal delta ที่ link กลับ Landed Cost และ allocation
   - สร้าง `cost_recalculation_request` จาก movement แรกที่ได้รับผลกระทบ
   - บันทึก audit/idempotency key
7. Worker/recost คำนวณ downstream impact และเปลี่ยนสถานะเป็น `COMPLETED` หรือ `FAILED`

## 4. กติกาธุรกิจ

- Source ต้องเป็น Goods Receipt ที่ `POSTED` และอยู่ในบริษัท/warehouse context เดียวกัน
- ห้ามจัดสรรเกิน quantity หรือมูลค่าของ source และห้ามผูก source เดิมซ้ำเกิน policy ที่กำหนด
- ยอด allocation หลัง rounding ต้องเท่ากับยอดค่าใช้จ่ายทุกครั้ง; rounding delta ให้ลงรายการสุดท้ายตาม deterministic rule
- ค่าใช้จ่ายหนึ่งรายการต้องระบุ currency, amount, tax treatment, account และ source reference ครบ
- ค่าใช้จ่ายที่เป็น VAT/WHT ต้องส่งต่อ contract ของ Purchasing/Accounting ห้ามคำนวณซ้ำใน WMS
- หากงวดของ source ปิดแล้ว ห้ามแก้ต้นทุนย้อนหลัง ให้ Post adjustment ในงวดเปิดและ link กลับ source เดิม
- เอกสาร `POSTED` แก้ไขหรือลบไม่ได้; ใช้ reversal/contra record พร้อมเหตุผลและผู้ทำ
- การ retry ต้องไม่เพิ่ม cost หรือ Journal ซ้ำ โดยใช้ stable identity/idempotency key
- หาก recost มีสถานะ `PENDING` หรือ `FAILED` ต้องแสดง valuation เป็น provisional และ block period close ตามกติกาเดิม

## 5. โครงสร้างข้อมูลขั้นต่ำ

### เอกสารใหม่ใน Purchasing

`purchasing_landed_costs`

- `id`, `document_no`, `company_id`, `warehouse_id`
- `business_date`, `status`, `currency`
- `allocation_basis`, `total_amount`, `posted_at`, `posted_by`
- `source_hash`, `idempotency_key`, `metadata`

`purchasing_landed_cost_lines`

- `landed_cost_id`, `expense_source_type`, `expense_source_id`
- `account_id`, `amount`, `tax_code_id`, `description`

`purchasing_landed_cost_receipts`

- `landed_cost_id`, `goods_receipt_id`, `selected_value`, `allocation_value`

ควรใช้ตาราง allocation/detail แยกจาก document เพื่อเก็บผลคำนวณที่ immutable และรองรับ revision ไม่ควรเก็บผลลัพธ์ทั้งหมดไว้ใน JSON อย่างเดียว

### การใช้ข้อมูล WMS/Accounting เดิม

- ใช้ `wms_stock_movements` และ `wms_stock_cost_layers` เป็น source ledger
- ใช้ `wms_cost_allocations` สำหรับ cost delta/revision โดยอ้าง `parent_allocation_id`
- ใช้ `wms_cost_recalculation_requests` สำหรับ propagation ไปยัง movement ที่ได้รับผลกระทบ
- ใช้ `wms_cost_allocation_journal_lines` เป็นหลักฐาน allocation → Journal line
- ใช้ Account Mapping เดิมสำหรับ inventory, landed-cost clearing/expense และ adjustment

## 6. Service และ route boundary

### Purchasing

- `LandedCostController`: index/create/show/submit/approve/post/void
- `LandedCostService`: validation, source selection, preview และ lifecycle
- routes/permissions ภายใต้ `purchasing.landed-costs.*`

### WMS

- เพิ่ม operation ใน service ที่มีอยู่สำหรับสร้าง delta allocation และ dispatch recost
- ห้ามให้ Controller ของ Purchasing เขียน Stock Movement หรือ Journal โดยตรง
- เพิ่ม read-only detail สำหรับ cost lineage และ recost status

### Accounting

- เพิ่ม posting event เช่น `landed_cost.adjustment`
- ตรวจ open period, account mapping, balancing และ reversal contract
- Journal ต้อง link กลับ Landed Cost, source receipt และ allocation revision

## 7. Acceptance criteria

- สร้าง Draft และ preview allocation ได้โดยไม่เปลี่ยน Stock/GL
- Post เอกสารที่มีหลาย GR และหลาย item แล้ว allocation รวมตรงกับค่าใช้จ่าย
- ทดสอบ allocation basis อย่างน้อย value และ quantity พร้อม rounding decimal
- Post ซ้ำด้วย request เดิมไม่สร้าง movement, allocation หรือ Journal ซ้ำ
- Recost ไหลจาก receipt ไป issue/transfer/COGS และ reconcile เป็นศูนย์
- Source หรือ period ไม่ถูกต้องต้องหยุดทั้ง transaction พร้อมข้อความที่แก้ไขได้
- Posted document แก้ไขไม่ได้ และ reversal สร้าง delta ที่ trace ได้
- ตรวจสิทธิ์, warehouse scope, audit และเลขเอกสารครบ
- period close block เมื่อมี recost ที่กระทบงวดและยัง `PENDING/FAILED`

## 8. แผนทดสอบ

1. Unit: allocation basis, rounding, validation, route/permission และ linkage contract (9 tests / 33 assertions)
2. Feature: Draft → Approve → Post, rollback เมื่อ Journal/recost สร้างไม่สำเร็จ
3. MySQL integration: GR → Landed Cost Draft → Submit → Approve → Post → RECOST allocation → request → Journal → retry (1 test / 18 assertions)
4. Recost integration: receipt → issue → transfer → downstream COGS/WIP
5. Manual UI: preview, permission, status transition, error recovery และ detail lineage
6. Regression: Purchase/GR, Inventory Costing, Recost, Reconciliation และ Period Close

## 9. ลำดับส่งมอบ

- [ ] ยืนยัน business policy: source ที่อนุญาต, allocation basis, tax/WHT และ approval
- [ ] ยืนยัน Account Mapping และ posting event กับ Accounting
- [x] ออกแบบ migration/index/identity และ source uniqueness rule
- [x] ทำ Purchasing document foundation + lifecycle service + preview allocation
- [x] เชื่อม WMS cost delta + recost request
- [x] เชื่อม Accounting Journal delta/reversal ผ่าน RecostGlPostingService เดิม
- [~] เพิ่ม permissions, audit, gated Post endpoint และ UI/detail lineage (Workflow Center/report catalog ยังไม่ทำ)
- [~] ปิด Unit/Feature/MySQL integration และ Manual QA (เพิ่ม UX pass และ auto document number ผ่าน Global Setting sequence `LANDED_COST` แล้ว; เหลือ manual QA และ workflow/report catalog)
- [ ] Owner sign-off และเปิดใช้งานด้วย feature flag

## 10. Release gate

ยังไม่เปิดใช้งาน production จนกว่าจะมีหลักฐานครบว่า:

- Inventory value, allocation และ GL reconcile เป็นศูนย์ในกรณีปกติและกรณี rounding
- Retry/reversal ไม่สร้างยอดซ้ำ
- ปิดงวดและสิทธิ์ผู้ใช้ทำงานถูกต้อง
- downstream movement ทุกประเภทที่รองรับมี lineage ย้อนกลับถึง Landed Cost ได้
- มีวิธี recovery สำหรับ `FAILED` recost และไม่ต้องแก้ข้อมูล ledger แบบ manual

อ้างอิงสัญญาที่ต้องใช้ร่วมกัน: [Accounting & Inventory](02-accounting-inventory.md), [Module Workflows](05-module-workflows.md), [Core Feature Checklist](06-core-feature-menu-checklist.md)
