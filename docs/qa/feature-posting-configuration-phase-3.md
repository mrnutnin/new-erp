# Manual QA — Feature Posting Configuration / Phase 3

## Dataset ก่อนทดสอบ

- [x] สร้าง Event Mapping สำหรับ `supplier_invoice.expense`: `ACCOUNTS_PAYABLE` และ `DEFERRED_INPUT_VAT`
- [x] สร้าง Event Mapping สำหรับ `supplier_invoice.inventory`: `ACCOUNTS_PAYABLE`
- [x] สร้าง Event Mapping สำหรับ `inventory_adjustment`: `INVENTORY`, `ADJUSTMENT_GAIN`, `ADJUSTMENT_LOSS`
- [x] บันทึก mapping ID/version, Warehouse, User และงวดบัญชีที่ใช้ทดสอบ

หลักฐาน ณ 02/09/2026: System Administrator (ID 1), คลัง HQ-WH (ID 1),
งวดสิงหาคม 2026 (ID 1, OPEN); mapping ทุกตัว version 1:

- `supplier_invoice.expense`: PURCHASE_EXPENSE #19, ACCOUNTS_PAYABLE #20, DEFERRED_INPUT_VAT #21
- `supplier_invoice.inventory`: INVENTORY #22, ACCOUNTS_PAYABLE #23
- `inventory_adjustment`: INVENTORY #24, ADJUSTMENT_GAIN #25, ADJUSTMENT_LOSS #26

## Purchase

- [x] Post ใบตั้งหนี้ค่าใช้จ่าย NONE VAT และ VAT IN; ตรวจ AP/TAX subledger, metadata และ retry
- [x] เปลี่ยน mapping แล้ว Post เอกสารใหม่; ยืนยัน Journal เก่าไม่เปลี่ยน และ metadata เป็น version ใหม่
- [x] Post ใบลดหนี้ซื้อจาก Invoice ที่ Post แล้ว; ตรวจ AP และ VAT ใช้บัญชีจาก Journal ต้นทาง
- [x] Inventory Purchase ทดสอบเฉพาะเมื่อ feature/preflight/three-way-match/reconciliation gate พร้อม; ตรวจ Item inventory subledger และ AP mapping metadata

## Inventory Adjustment

- [x] Post Gain และ Loss; ตรวจ Inventory ITEM subledger กับ Gain/Loss mapping และ metadata
- [x] Retry เดิมต้องไม่สร้าง Journal/Movement/Allocation ซ้ำ และ reversal ใช้ Journal เดิม
- [x] ตรวจ allocation/cost/GL reconciliation เป็นศูนย์

## Gate

- [x] ยืนยัน `inventory.recost` และ `inventory.receipt` ยังไม่สามารถ Post ได้จนกว่าจะผ่าน release gate ของแต่ละ Event

Owner sign-off: ผ่านการตรวจรับเมื่อ 02/09/2026
