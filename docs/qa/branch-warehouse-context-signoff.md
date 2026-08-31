# Branch / Warehouse Context — Local Legacy Audit & Owner Sign-off

วันที่ตรวจ: 31 สิงหาคม 2026  
Environment: local `new_erp`  
สถานะ: **Local owner sign-off ผ่านแล้ว — ยังไม่ใช่ production sign-off**

## หลักฐานข้อมูล legacy

ตรวจเอกสาร header ทุกตารางใน scope โดยเทียบ `document.branch_id` กับ `warehouses.branch_id` ของ `document.warehouse_id`

| ตาราง | แถว | branch ว่าง | คลังหาย | branch ไม่ตรงคลัง |
|---|---:|---:|---:|---:|
| sales_documents | 1 | 0 | 0 | 0 |
| sales_intakes | 9 | 0 | 0 | 0 |
| sales_rfqs | 3 | 0 | 0 | 0 |
| sales_quotations | 1 | 0 | 0 | 0 |
| sales_orders | 9 | 0 | 0 | 0 |
| pos_physical_sales | 11 | 0 | 0 | 0 |
| pos_sales_returns | 4 | 0 | 0 | 0 |
| purchase_documents | 21 | 0 | 0 | 0 |
| purchase_orders | 14 | 0 | 0 | 0 |
| purchase_requisitions | 17 | 0 | 0 | 0 |
| goods_receipts | 12 | 0 | 0 | 0 |
| wms_inventory_adjustment_documents | 3 | 0 | 0 | 0 |
| wms_stock_count_documents | 1 | 0 | 0 | 0 |
| wms_issue_documents | 0 | 0 | 0 | 0 |
| wms_issue_returns | 0 | 0 | 0 | 0 |
| finance_advance_deposits | 3 | 0 | 0 | 0 |
| **รวม** | **109** | **0** | **0** | **0** |

ผลนี้เป็น read-only audit: ไม่ได้แก้ไข, backfill หรือเปลี่ยนสถานะเอกสารใด ๆ

## Checklist ผู้ตรวจรับ

- [x] Topbar แสดงเฉพาะสาขาปัจจุบัน และไม่มีตัวเลือกคลังระดับ global
- [x] ผู้ใช้ที่มีสิทธิ์คลังเดียว เห็นและเปลี่ยนได้เฉพาะคลังนั้น
- [x] ผู้ใช้ที่มีหลายคลังในสาขาเดียว เปลี่ยนคลังได้บน WMS: Stock, Valuation, Transfer, Adjustment และ Stock Count
- [x] ส่ง `warehouse_id` ที่อยู่นอกสาขาหรือนอกสิทธิ์ไปยัง WMS แล้วถูกปฏิเสธ
- [x] POS/ Purchasing แสดงเอกสารเฉพาะสาขาปัจจุบัน; จุด stock/fulfillment เลือกได้เฉพาะคลังในสาขา
- [x] สร้าง Draft → Post → เปิด DataTable/detail/PDF ของ HS/IV, Sales Return, PR/PO/Receipt และ Advance Deposit ได้ในสาขาเดิม
- [x] Draft ยกเลิกตาม policy ได้; เอกสาร Posted ต้องใช้ cancellation/reversal flow ที่กำหนดและไม่เปลี่ยนคลังต้นทาง
- [x] Stock, cost allocation และ GL ของ transfer/receipt/sale/return ยังคงอ้างอิงคลังต้นทางตามเอกสาร

## ผู้รับรอง

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| POS owner |  |  |  |
| Purchasing/WMS owner |  |  |  |
| Accounting owner |  |  |  |
| System owner | ผู้ใช้ระบบ | 31/08/2026 | ผ่าน (Local) |

หากพบปัญหา ให้แนบเลขเอกสาร, สาขา, คลัง, URL และขั้นตอนทำซ้ำก่อนอนุมัติ
