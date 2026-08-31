# Purchasing Legacy Repair Impact Audit

ขอบเขตนี้เป็น read-only audit สำหรับ allocation เดิมที่ยัง `PENDING` แต่มี Journal อ้างอยู่ ห้าม repair ด้วยการแก้ยอดหรือเปลี่ยนสถานะเอกสารจัดซื้อโดยตรง

## ผลกระทบและ guard ที่ต้องคงไว้

| ข้อมูล | ห้ามทำระหว่าง repair | Guard ที่ต้องตรวจซ้ำ |
| --- | --- | --- |
| Purchase Invoice / Credit Purchase | ห้ามเปลี่ยน `status`, ยอด, วันที่, supplier หรือ warehouse เพื่อให้ผ่าน gate | เอกสาร `POSTED` ต้อง immutable; ใช้ Void/Reverse ตาม domain เท่านั้น และตรวจ Journal/Open Item identity |
| Purchase Document line | ห้ามเปลี่ยน `item_id`, `uom_id`, `quantity`, `unit_price`, `account_id` หรือ `purchase_order_line_id` | ยอด header ต้องคำนวณตรงกับ lines และ inventory line ต้องมี PO link + receipt allocation |
| `purchase_document_receipt_allocations` | ห้ามเขียนทับ `allocated_quantity`, `allocated_amount`, receipt identity หรือ idempotency key เดิม | allocation ต้องชี้ GR line/PO line เดิม, ไม่ซ้ำ, quantity ไม่เกิน receipt และ amount ตรงกับ source value |
| Goods Receipt | ห้ามแก้/void GR เพื่อแก้ allocation legacy | GR ที่ถูก active Purchase Document อ้างต้อง void ไม่ได้; ตรวจ warehouse/supplier/PO scope และ conversion snapshot |
| Purchase Order | ห้ามเพิ่ม/ลด PO quantity หรือย้าย supplier/warehouse เพื่อให้ 3-way ผ่าน | PO line identity, status และยอดต้องตรงกับ Invoice/GR เดิม |
| Cost allocation เดิม | ห้ามตั้ง `FINAL` หรือเปลี่ยน value เพียงเพราะพบ Journal | ตรวจ movement, item/UOM, warehouse, business date, signed value, source identity, posting hash, Journal status และ immutable line linkage ครบ |
| Journal / GL | ห้ามแก้ Journal เดิมหรือลบ line | หากผิดให้สร้าง reversal/correction พร้อม reason, actor, parent/revision และ idempotency ใหม่ |

## 3-way matching boundary

- Repair ไม่ควรทำให้เอกสารที่เดิมไม่ผ่าน `PurchaseThreeWayMatchGate` กลายเป็นพร้อม Post โดยอัตโนมัติ
- ต้องตรวจ PO → GR → Invoice line linkage, active status, quantity, price/value, UOM conversion factor และ warehouse/supplier scope ใน snapshot เดียวกัน
- หาก allocation เดิมมี `allocated_amount=0` หรือไม่สามารถพิสูจน์ amount จาก GR/Invoice ได้ ให้คงสถานะไม่พร้อมและแสดง blocker; ห้ามเติมยอดจาก current stock balance
- เอกสาร `APPROVED` เป็นเพียงสถานะอนุมัติ ไม่ใช่หลักฐานว่า Inventory/GL พร้อม Post

## Safe repair checklist

- [ ] คำสั่ง repair เป็น read-only โดย default และบังคับ `--dry-run` ก่อนทุกครั้ง
- [ ] แสดง allocation/document/GR/PO identity, source hash, revision และ reason ให้ตรวจสอบได้
- [ ] ตรวจจำนวนรายการ/ยอดรวมก่อนและหลัง; repair ห้ามเปลี่ยน Purchase/GR/Invoice rows
- [ ] ตรวจ allocation↔Journal-line linkage แบบ exact (account, subledger, item, signed amount, warehouse, business date, revision)
- [ ] Journal `POSTED` แต่ allocation `PENDING` ต้องเข้า `REVIEW_REQUIRED`; ห้าม auto-promote
- [ ] Journal `REVERSED` ต้องคง pending หรือสร้าง reversal transition ตาม contract; ห้าม update เดิม
- [ ] หลัง repair ที่ได้รับอนุมัติ ต้องรัน 3-way, reconciliation, retry/idempotency และ rollback evidence ใหม่
- [ ] ต้องมี audit record ของ actor, reason, before/after และ approval; ไม่ใช้ direct SQL update

## คู่มือผู้ใช้: เมื่อเอกสารเก่าผิดสถานะหรือจับคู่ไม่ครบ

ให้ถือเอกสาร/รายการนั้นเป็น **กักกัน (Quarantine / Review Required)** ชั่วคราว ไม่กด Post ซ้ำและไม่แก้ฐานข้อมูลเอง แล้วทำตามลำดับนี้:

1. เปิดรายละเอียด PI/PO/GR และจดเลขเอกสาร, Warehouse, Supplier, วันที่, Item/UOM, ยอด และรายการที่อ้างอิง
2. เรียก `wms:legacy-repair-report --dry-run` ให้ผู้ดูแลตรวจ source identity, Journal status, Movement, allocation, revision และ hash
3. ถ้า PI เป็น `POSTED` แต่ไม่มี PO/GR link ให้หยุดการนำเข้าสต็อกของเอกสารนั้น ตรวจ AP/GL และให้ผู้มีอำนาจทำ Void/Reverse แล้วสร้างเอกสารใหม่ผ่าน flow ปกติ; ห้ามเติม link ย้อนหลังเพื่อให้ผ่าน 3-way
4. ถ้า PO/GR คนละ Supplier หรือ Warehouse, หรือ UOM/conversion/date ไม่ตรง ให้หยุดการรับ/ตั้งหนี้และให้จัดซื้อแก้ด้วยเอกสารใหม่หรือ Void ตามสิทธิ์; ห้ามเปลี่ยน master/source เดิมเพื่อกลบ mismatch
5. ถ้า allocation เป็น `PENDING` แต่ Journal `POSTED` หรือ `REVERSED` ให้คง `REVIEW_REQUIRED`; ส่งหลักฐานให้ Accounting ตรวจ reversal/revision และห้ามเปลี่ยนเป็น `FINAL` เอง
6. หลังผู้อนุมัติยืนยันการแก้ ให้ตรวจ 3-way (`ready`, variance, blockers), reconciliation, retry/idempotency และ audit ก่อนปลดกักกัน

ผลลัพธ์ที่อนุญาตมีเพียง: ผ่านทุก gate แล้วจึงไปต่อ, หรือสร้าง reversal/correction/recreate ที่ trace กลับได้; ห้ามลบ/แก้ยอดเอกสารที่มีประวัติแล้ว

## Current blocker

รายงาน read-only `php artisan wms:legacy-repair-report --dry-run` พบ allocation เดิม 2 รายการ:

- Allocation `2`: `PENDING`, `FINAL`, value `1000.00`, Journal `11` = `REVERSED`, source `PURCHASING/supplier_invoice.inventory/3`
- Allocation `4`: `PENDING`, `FINAL`, value `-1000.00`, Journal `13` = `POSTED`, source `journal.reversal/reversal:purchase:3:movement:3:revision:1`
- ทั้งสองรายการอยู่ Warehouse `HQ-WH`, Item `1`, มี Movement `POSTED` และ immutable Journal-line link แต่ยังต้อง `REVIEW_REQUIRED`; ห้าม auto-promote เป็น `FINAL/POSTED`
- Purchase Document `PI-INVENTORY-MOCK-001` (id `3`) เป็น `POSTED` แต่ line ไม่มี `purchase_order_line_id` และไม่มี receipt allocation; `PurchaseThreeWayMatchGate` จึงให้ `ready=false` พร้อม blockers `inventory_line_po_linkage_required`, `purchase_order_not_approved`, `supplier_or_warehouse_mismatch`, `purchase_order_lines_required`, `purchase_document_line_identity_required`

เดิมรอบแรกพบ parse error ที่ `routes/console.php:36`; ตอนนี้ syntax ถูกแก้แล้วและรายงาน dry-run ทำงานได้โดยไม่ mutation. รายการข้างต้นยืนยันว่า repair ต้องตรวจ source/reversal linkage และ 3-way contract ใหม่ ไม่ควรเปลี่ยนยอดหรือสถานะ Purchase/GR/Invoice เพื่อกลบ blocker

## Allocation 2/4 impact review (local read-only)

- Allocation `2` เป็น Receipt, `PENDING/FINAL`, quantity `10`, value `1000.00`, Movement `3` (`POSTED`, source id `3`, business date `2026-08-21`) และ Journal `11` (`REVERSED`, `supplier_invoice.inventory`, source id `3`)
- Allocation `4` เป็น Receipt, `PENDING/FINAL`, quantity `10`, value `-1000.00`, Movement `5` (`POSTED`, source id `3`, business date `2026-08-22`) และ Journal `13` (`POSTED`, `journal.reversal`, source `reversal:purchase:3:movement:3:revision:1`)
- ทั้งคู่มี Inventory item/warehouse lineage และ Journal-line proof แต่ไม่ได้ผูกกับ `purchase_document_receipt_allocations`; Purchase Document id `3` (`PI-INVENTORY-MOCK-001`) เป็น `POSTED`, line id `8` ไม่มี `purchase_order_line_id` และไม่มี GR allocation
- GR ที่มีอยู่แยกต่างหาก: `GR-20260822145011` เป็น `APPROVED` และ `GR-20260822122643` เป็น `VOID`, ทั้งคู่ PO `PO-2026-000005`; ไม่มีหลักฐานว่าเป็น source ของ allocation `2/4`

ข้อเสนอ repair guard เฉพาะชุดนี้: คง allocation `2/4` เป็น `REVIEW_REQUIRED`, ห้าม promote หรือแก้ value/status; ตรวจว่าการ reversal ของ Journal `11` และ Movement `3` ถูกหักล้างด้วย Movement `5`/Journal `13` ตาม source revision เดียวกันก่อนสร้าง transition ใหม่ และห้ามนำ allocation ชุดนี้ไปทำให้ Invoice/GR/PO ผ่าน 3-way matching. หากต้องแก้ ให้สร้าง reversal/correction พร้อม parent allocation, revision, reason และ audit ใหม่เท่านั้น
