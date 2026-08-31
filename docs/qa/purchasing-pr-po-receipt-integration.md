# Purchasing PR → PO → Goods Receipt Draft Integration QA

อัปเดต: 22 สิงหาคม 2026

ขอบเขตนี้ตรวจเฉพาะการเชื่อมเอกสารจัดซื้อและ boundary ก่อน Inventory Post:

`Approved PR → สร้าง PO → Goods Receipt Draft/Approved/Void → Purchase Document Inventory Post boundary`

PO รองรับ 2 กรณี: สร้างจาก PR ที่ Approved หรือสร้างโดยตรงจากเมนู Purchase Order โดยไม่ต้องมี PR; กรณีสินค้าคงคลังยังต้องผ่าน Receipt allocation และ 3-way matching เดียวกัน

ไม่รวม Goods Receipt Post, Stock Movement, Cost Layer, Journal หรือ Inventory Post จาก Receipt โดยตรง

## ผลตรวจ local

- [x] PR ที่ไม่ใช่ `APPROVED` สร้าง PO ไม่ได้
- [x] PR ถูก lock ก่อนสร้าง PO และใช้ `purchase_requisition_id` เป็น source identity
- [x] PR หนึ่งใบสร้าง PO ได้อย่าง idempotent; ถ้า PO เดิมเป็น `VOID` จะไม่สร้างทับประวัติ
- [x] ตรวจ Supplier, Item, UOM และ Warehouse scope ก่อนสร้าง PO
- [ ] Standalone PO ไม่อ้าง PR ได้ และยังบังคับ Supplier/Item/UOM/Warehouse ครบ
- [x] PO line เก็บ `purchase_requisition_line_id` และคำนวณ remaining quantity แบบ decimal-safe
- [x] ป้องกัน over-order และ quantity ที่รับเกิน PO line ใน Goods Receipt Draft
- [x] Receipt line ซ้ำใน Receipt เดียวกันถูก reject ด้วย validation/unique constraint
- [x] `idempotency_key` เดิมของ Goods Receipt คืนรายการเดิม ไม่สร้าง Draft ซ้ำ
- [x] Goods Receipt Draft/Approved/Void ไม่มี posting route และไม่เรียก Journal/Movement/Cost Allocation
- [x] Inventory intent จาก Receipt ที่อนุมัติใช้ source identity แบบ `GOODS_RECEIPT` + receipt line และ idempotency key ต่อบรรทัด เพื่อกัน duplicate posting ที่ writer ในอนาคต
- [x] Receipt conversion snapshot ตรวจซ้ำกับบรรทัดจริงทั้ง Purchase UOM, Stock UOM, factor และ business date ก่อนส่งต่อ boundary
- [x] Receipt quantity ตรวจว่า `stock_quantity = purchase_quantity × factor` ด้วย decimal scale 8
- [x] Receipt cost ตรวจว่า `total_cost = stock_unit_cost × stock_quantity + rounding_delta` และส่ง `rounding_delta` ไปใน metadata เพื่อ reconciliation/recost
- [x] Goods Receipt inventory writer ที่ยังไม่มี route ใช้ source `GOODS_RECEIPT` และ idempotency key ต่อ receipt line; retry ตรวจ cost/UOM, rounding, event และ conversion snapshot เดิมก่อน reuse movement
- [x] Purchase Document `Inventory Post` ยังคงเป็น boundary เดียวของ Inventory→GL MVP
- [x] Goods Receipt migration ใช้ชื่อ index `grl_receipt_po_line_uq` ไม่เกิน MySQL identifier limit และรองรับ recovery เมื่อ migration ค้างหลัง DDL บางส่วน
- [x] PR/PO route และ RBAC/Sidebar source มี permission แยกตาม action
- [x] Goods Receipt มี browser routes สำหรับ DataTable, Draft, Edit, Approve และ Void พร้อม permission แยกตาม action
- [x] มี optional `PurchasingGoodsReceiptMockupSeeder` สำหรับ local review: PR Approved → PO Approved → Receipt Draft รับบางส่วน 4/10, ใช้ same-UOM factor 1 และไม่สร้าง Journal/Movement/Cost Layer
- [x] PO → Goods Receipt ตรวจ supplier/warehouse/item/UOM และยอดรับสะสมไม่เกิน PO line แล้ว
- [x] Purchase Document ปกติแยก service/expense path ชัดเจน (`supplier_invoice.expense`); Inventory path รองรับเฉพาะ Invoice, `NONE_VAT`, stock item ทุกบรรทัด และยังปิด feature gate
- [x] มี pure `PurchaseThreeWayMatchContract` สำหรับ preflight แบบ read-only: ตรวจ Supplier/Warehouse, explicit PO line identity, Item/UOM, ordered/received/invoiced quantity, PO/Receipt/Invoice value variance และ stable idempotency/source-of-truth key โดยไม่เขียน Stock/Cost/GL

## Evidence

- `PurchasingPrPoReceiptBoundaryAuditTest`: focused boundary checks ผ่าน
- Focused rerun หลังเพิ่ม reconciliation boundary: **17 tests / 69 assertions ผ่าน** (`GoodsReceiptConversionContract`, Receipt boundary, Inventory intent contract, PR state และ optional mockup audit)
- `PurchasingGoodsReceiptMockupSeeder` ยังไม่ถูกเรียกจาก `DatabaseSeeder`; ให้ Master เป็นผู้ตรวจและ execute เมื่อพร้อมเท่านั้น
- `GoodsReceiptConversionContractTest`: conversion, same-UOM, duplicate/missing conversion, zero factor และ decimal-safe cost
- `GoodsReceiptInventoryPostAuditTest`: ไม่มี Receipt post route และ adapter ไม่ claim snapshot-ready
- UI static audit: Goods Receipt DataTable ใช้ AJAX/Yajra, status/date เป็น human-readable, action แสดงตาม permission, PO/PO line ใช้ Select2 และ Stock Card ใช้ DataTable AJAX + Select2 Item filter
- Local migration status: migrations `2026_08_22_324000_create_goods_receipts_tables` และ `2026_08_22_324000_link_purchase_orders_to_requisitions` เป็น `Ran`
- `php artisan view:cache`: ผ่าน
- `vendor/bin/pint --test`: ผ่านสำหรับไฟล์ที่แก้

## Deferred / blocker

## Manual UI notes (ยังไม่ใช่ sign-off)

- [ ] เปิด Purchasing → Goods Receipt ตรวจว่า DataTable แสดง Receipt number, PO, Supplier, วันที่ตาม company format และ badge `Draft/Approved/Void`
- [ ] สร้าง Receipt จาก PO `APPROVED`; ตรวจ Select2 แสดงเฉพาะ PO ใน Warehouse context และ line option แสดงยอดคงเหลือ
- [ ] รับบางส่วนสองครั้ง; ครั้งที่สองต้องเห็นยอดคงเหลือใหม่ และจำนวนเกินต้องถูกปฏิเสธโดยไม่สร้างรายการซ้ำ
- [ ] แก้ Draft แล้วตรวจ snapshot/UOM/cost ที่ส่งจาก form; Approve และ Void ต้องเห็นเฉพาะผู้มี permission และปุ่มถูก disable ระหว่าง request
- [ ] เปิด WMS → Stock Card ตรวจ Item Select2, วันที่/ประเภท/ทิศทางแบบ human-readable, DataTable AJAX และยอด On-hand/Reserved/Available
- [ ] ตรวจว่า Receipt UI ไม่มีปุ่มหรือ route Receipt Post และข้อความแจ้งว่าไม่สร้าง Movement/Cost Layer/GL
- [ ] เปิด Workflow Center ของ WMS → Procure-to-Pay ตรวจ card `ตรวจ 3-way match ก่อน Post`: ต้องบอกจุดตรวจ PO/Receipt/Credit Purchase, quantity/value variance และวิธีแก้ Draft/Void/Reverse โดยไม่แก้ Journal เอง

- Manual UI sign-off ของ Goods Receipt ยังรอการตรวจใน browser แม้ routes และหน้าจอพร้อมแล้ว
- ยังไม่เปิด Receipt → Inventory Post และไม่สร้าง Movement/Cost Layer/Journal จาก Receipt
- Readiness review: Recost/valuation foundation มี queue, stale/retry safety และ reconciliation read path แล้ว แต่ยังไม่ถือว่าเปิด Stock/Cost writer ได้ เพราะ Receipt ยังไม่มี Post route และ Purchase Inventory adapter ยังปิด feature gate
- ก่อนเปิด live Recost ต้องยืนยัน policy เรื่องวันที่: `StockRecostService::resolveFromReceipt()` จับ pending layer ตาม warehouse/item/UOM; release test ต้องยืนยันว่าจะจำกัด pending layer ตาม business date หรืออนุญาตให้ receipt ย้อนแก้ผลกระทบตาม dependency chain
- Historical reconciliation ใช้ Stock Balance เป็น `CURRENT_PROJECTION` ตามที่แสดงใน UI/เอกสาร จึงยังไม่ใช่ historical stock snapshot สำหรับ period close
- 3-way matching (PO ↔ Goods Receipt ↔ Purchase Document) **ยังไม่เปิดใช้**: schema foundation เพิ่ม nullable `purchase_order_line_id` บน Purchase Document line และ `purchase_document_receipt_allocations` สำหรับจัดสรรหนึ่ง invoice line ไปหลาย receipt lines แล้ว แต่ยังไม่มี route/runtime allocation หรือ variance approval; `PurchaseReceiptSourceValidator` ยังเป็น guard แบบ Purchase Invoice ↔ Posted Inventory Journal/Movement
- Credit Purchase/Credit Note ตรวจ original posted invoice, supplier/warehouse เดียวกัน, AP open item และ ceiling แยกตามบัญชีแล้ว; Gate 2 เพิ่ม full-line linkage ไป Goods Receipt และ stock reversal แบบ immutable/idempotent พร้อม persistent local evidence (`CN-OPS-GATE2-20260824-N9OTKFEGWMXD`) แล้ว แต่ยังปิด feature flag จนกว่าจะผ่าน owner operational sign-off
- ยังไม่มี live variance gate ที่ผูกกับ posting สำหรับ PO quantity/receipt quantity/invoice quantity และ PO price/receipt cost/invoice price; pure contract มีไว้ทำ preflight เท่านั้น ขณะที่ runtime ปัจจุบันยังมีเพียง PO over-receipt guard และ Purchase Document line-total arithmetic
- `PurchaseThreeWayMatchContract` และ `PurchaseThreeWayMatchService` เป็น foundation สำหรับ variance preflight และ schema linkage พร้อมใช้เป็น read model แล้ว แต่ยังไม่เชื่อมกับ route/posting จนกว่าจะมี runtime allocation และ policy approval ที่ผูกกับผู้อนุมัติ
- `PurchaseThreeWayMatchService` ตรวจ persisted receipt allocation จริงแบบ read-only แล้ว และคืน `CLEAR`/`BLOCKED`/`APPROVAL_REQUIRED`; ยังไม่มี controller/request หรือ runtime allocation และไม่อนุมัติ variance เอง
- `PurchaseThreeWayMatchGate` ถูกเรียกตอน Approve และ Credit Purchase Post แบบ bounded; inventory line ที่ไม่มี allocation จะถูกปฏิเสธ, expense/service line ผ่านได้ และ gate ไม่สร้าง Stock/Cost/GL
- Duplicate stock ปัจจุบันถูกเลี่ยงด้วยการไม่เปิด Receipt → Inventory Post และแยก expense event ออกจาก inventory event; ก่อนเปิดจริงต้องเลือก source of truth เดียว (แนะนำ Purchase Document + explicit receipt-line allocation) และบังคับ idempotency/variance gate เพื่อไม่ให้ Invoice และ Receipt สร้าง Stock ซ้ำ
- `GoodsReceiptInventoryService` เป็น closed service boundary ที่สร้าง Movement/Cost ได้ภายใน transaction เมื่อถูกเรียกโดยโค้ดภายใน แต่ยังไม่มี route และยังไม่ผูก `purchase_document_receipt_allocations`; ห้ามเปิด route จนกว่าจะตัดสิน source-of-truth ระหว่าง Purchase Document Inventory Post กับ Goods Receipt writer และเพิ่ม 3-way allocation/reconciliation gate
- `GoodsReceiptInventoryPostingContractTest` มี baseline blocker ใน test bootstrap เมื่อทดสอบ `ValidationException` (ไม่มี Laravel app bindings เช่น `config`/`validator`); boundary audit และ focused contract checks ผ่านแล้ว ส่วนการแก้ bootstrap ให้ Master จัดการแยกต่างหาก
- Production operational sign-off ทำรวมครั้งเดียวหลัง MVP modules พร้อมครบทั้งหมด
