# แผนปรับ Operational Context: สาขาและคลัง

อัปเดตล่าสุด: 30 สิงหาคม 2026

## เป้าหมาย

เปลี่ยน operational context ของระบบจากการเลือก **คลัง** เป็นการเลือก **สาขา** โดยเอกสารขายและซื้อทุกประเภทบันทึก `branch_id` เสมอ ส่วน `warehouse_id` บังคับเฉพาะเอกสารหรือรายการที่กระทบสินค้า การส่งมอบ และต้นทุน

กติกาอ้างอิงหลักอยู่ที่ [01-product-architecture.md](01-product-architecture.md#กติกากลาง-สาขาและคลังในเอกสารปฏิบัติการ) เอกสารนี้ระบุลำดับดำเนินงานและเกณฑ์ตรวจรับเท่านั้น

## ขอบเขต

| ส่วนงาน | ผลที่ต้องได้ |
| --- | --- |
| Platform | ผู้ใช้เลือกสาขา, session/top bar ใช้สาขาเป็น context หลัก |
| POS | เอกสารขายทุกชนิดมี `branch_id`; คลังในสินค้า/ส่งมอบจำกัดตามสาขา |
| Purchasing | เอกสารซื้อทุกชนิดมี `branch_id`; คลังรับสินค้า/ส่งมอบจำกัดตามสาขา |
| WMS | ยอดคงเหลือและต้นทุนยังแยกตาม `warehouse_id`; อ่านสาขาจากเอกสารต้นทางเพื่อ audit/report |
| Finance/Accounting | อ่านสาขาจาก source document; ไม่เปลี่ยน warehouse-based costing หรือ journal lineage เดิม |

## ลำดับดำเนินงานและ Checklist

### Phase 0 — สำรวจข้อมูลและล็อก Data Contract

- [x] จัดทำ inventory ตารางเอกสาร POS, Purchasing, WMS, Finance/Accounting ที่มีหรือควรมี `branch_id` และ `warehouse_id`
- [x] จัดกลุ่มเอกสาร: บังคับสาขาเท่านั้น / บังคับสาขาและคลัง / เก็บสาขาจาก source แบบ read-only
- [x] ตรวจทุกคลัง active ต้องมี `branch_id` ที่ active และรายงานข้อมูลผิดปกติ
- [x] ตรวจเอกสารเดิมที่ `warehouse_id` ไม่มีค่า, คลังถูกลบ, หรือคลังไม่สัมพันธ์กับสาขา
- [ ] ระบุ query path ที่ต้องมี index `branch_id`, `warehouse_id`, วันที่ และสถานะร่วมกัน
- [x] อนุมัติ data contract ก่อนเริ่ม migration

**เกณฑ์ผ่าน:** ไม่มีการเดาสาขาใน migration; รายการ exception ต้องระบุเอกสารและวิธีแก้โดยเจ้าของข้อมูล

ผลสำรวจ local ณ 30 สิงหาคม 2026: เอกสาร header ที่มี `warehouse_id` และอยู่ใน scope migration คือ Sales (`sales_documents`, `sales_intakes`, `sales_rfqs`, `sales_quotations`, `sales_orders`, `pos_physical_sales`, `pos_sales_returns`), Purchasing (`purchase_documents`, `purchase_orders`, `purchase_requisitions`, `goods_receipts`), WMS (`wms_inventory_adjustment_documents`, `wms_stock_count_documents`, `wms_issue_documents`, `wms_issue_returns`) และเงินรับล่วงหน้า (`finance_advance_deposits`) รวม 109 แถวที่มีข้อมูล; backfill จาก `warehouses.branch_id` สำเร็จครบ, `missing=0`, `mismatch=0` และไม่มี exception.

หลักฐานตรวจซ้ำแบบ read-only ณ 31 สิงหาคม 2026: 109 แถว, `branch_id` ว่าง 0, คลังหาย 0 และ branch ไม่ตรงคลัง 0 — ดู [branch-warehouse-context-signoff.md](../qa/branch-warehouse-context-signoff.md)

### Phase 1 — Platform: เลือกสาขาและสิทธิ์

- [x] เพิ่ม/ตรวจ branch assignment ของผู้ใช้ และกำหนด policy เมื่อผู้ใช้มีสิทธิ์เฉพาะคลัง — ระยะ compatibility ใช้สาขาที่ derive จากคลัง active ที่ผู้ใช้ได้รับสิทธิ์
- [x] เปลี่ยน `/select-warehouse` เป็น flow เลือกสาขา โดยมี redirect รองรับ URL เดิมชั่วคราว
- [x] เก็บ `selected_branch_id` ใน session และเปลี่ยน middleware ให้ตรวจสิทธิ์สาขาทุก request
- [x] กำหนดคลังเริ่มต้น active ของสาขาเมื่อหน้า/เอกสารต้องใช้คลัง
- [x] ปรับ top bar ให้แสดงเฉพาะ “สาขาปัจจุบัน” และไม่เปิดตัวเลือกคลังระดับ global
- [x] ปรับ Settings program ให้คง company scope และไม่บังคับเลือกสาขา
- [x] บันทึก audit เมื่อเปลี่ยนสาขาหรือคลัง context

**เกณฑ์ผ่าน:** ผู้ใช้เลือกหรือเรียก URL ข้ามสาขาไม่ได้ แม้แก้ request/session ด้วยตนเอง

### Phase 2 — Schema, Backfill และ Compatibility

- [x] เพิ่ม nullable `branch_id` พร้อม foreign key/index ในตารางเอกสารเป้าหมายก่อน
- [x] Backfill `branch_id` จาก `warehouses.branch_id` เฉพาะแถวที่พิสูจน์ความสัมพันธ์ได้
- [x] เก็บ exception report สำหรับข้อมูลที่ backfill ไม่ได้ และแก้ข้อมูลด้วย action ที่ audit ได้ — local ไม่มี exception
- [ ] เมื่อข้อมูลสะอาด จึงเปลี่ยนเอกสารใหม่ให้ require `branch_id` ฝั่ง server
- [ ] เพิ่ม composite validation/constraint ตาม table ที่รองรับ เพื่อกันคลังข้ามสาขา
- [ ] Migration ต้อง reversible เฉพาะ schema; ห้าม rollback ทับข้อมูล Posted หรือ ledger
- [x] ทำ migration rehearsal บนสำเนาข้อมูล local ก่อน rollout

**เกณฑ์ผ่าน:** เอกสาร legacy เปิดดู/พิมพ์/รายงานได้เหมือนเดิม และไม่มีข้อมูล Posted ถูกแก้ทับอย่างเงียบ ๆ

### Phase 3 — POS และ Purchasing

- [~] POS: Intake, RFQ, Quotation, Sales Order, HS/IV, Sales Return และ Advance Deposit snapshot `branch_id` จากคลังทุกครั้งที่ save แล้ว; DataTable/PDF/detail/report scope ตามสาขาแล้ว และ Post ยังคงใช้คลังที่ผูกกับเอกสาร; ปุ่มรับชำระหนี้จาก IV/ลูกหนี้ใช้คลังของ IV สำหรับ Open Item, บัญชีรับเงิน, เลขที่ และ GL; Receipt แบบเลือกหลายเอกสารเลือกบัญชีรับเงินก่อน แล้วกรอง IV/ออกเลขที่/ลง GL ในคลังของบัญชีนั้น (ห้ามรวมข้ามคลังในใบเดียวกัน); source propagation audit ยังอยู่ระหว่างทำ
- [~] Purchasing: PR, PO, Purchase Invoice/Credit Note และ Goods Receipt snapshot `branch_id` จากคลังทุกครั้งที่ save แล้ว; list/detail/PDF ของ Purchasing scope ตามสาขา และ Goods Receipt ใช้คลังของ PO ที่เลือกจริง; Supplier flow, report, source propagation และ Purchase Return/credit flow ยังอยู่ระหว่างทำ
- [ ] UI เอกสารใช้สาขาจาก context เป็นค่าเริ่มต้นและไม่เปิดให้เลือกข้ามสิทธิ์
- [~] ตัวเลือกคลังในสินค้า, รับเข้า, ส่งมอบ และ fulfillment filter ด้วย `branch_id` ของเอกสาร — เริ่มที่ HS/IV: เลือก “คลังจัดส่งสินค้า” ได้เฉพาะคลัง active ที่ผู้ใช้มีสิทธิ์ในสาขาปัจจุบัน; จุดรับเข้า/ส่งมอบอื่นยังอยู่ระหว่างทำ
- [~] Server validate `warehouse.branch_id === document.branch_id` — POS header model guard ป้องกันทุก save แล้ว; endpoint/line-level validation และ Purchasing ยังอยู่ระหว่างทำ
- [ ] เอกสารที่ไม่มี stock movement ไม่บังคับ `warehouse_id` แต่ยัง require `branch_id`
- [ ] Snapshot สาขา/คลังของเอกสาร Posted และห้ามแก้ทับ

**เกณฑ์ผ่าน:** สาขาหนึ่งมีหลายคลังได้, เลือกคลังย่อยได้เฉพาะจุดที่จำเป็น และไม่สามารถส่ง warehouse ข้ามสาขาผ่าน API ได้

### Phase 4 — WMS, Finance และ Accounting Boundary

- [x] WMS รับ `branch_id` จาก source document และคง `warehouse_id` เป็น key ของ balance/cost layer/allocation
- [x] Transfer ตรวจสาขาและคลังต้นทาง/ปลายทาง พร้อม lineage/audit เดิม
- [x] Finance/Accounting อ่าน `branch_id` จาก source document สำหรับ scope/reporting โดยไม่เปลี่ยน journal ที่ Posted แล้ว
- [ ] COGS/Inventory reconciliation ยังคง aggregate ตามคลังได้ และเพิ่ม filter/summarize ตามสาขา
- [x] ตรวจ document sequence, bank/cash account และ policy ที่เดิม scope ด้วยคลัง ว่าต้องคงคลังหรือย้ายเป็นสาขาเป็นราย policy — คงเป็น warehouse-specific point-of-use configuration

**เกณฑ์ผ่าน:** Stock balance/costing ไม่รวมข้ามคลังโดยไม่ได้ตั้งใจ และรายงานตามสาขาตรงกับเอกสารต้นทาง

### Phase 5 — UX, รายงาน และการตรวจรับ

- [ ] ปรับคำอธิบาย/label ให้ชัด: สาขาเป็นบริบททำงาน, คลังเป็นจุดเก็บ/ส่งมอบสินค้า
- [x] หน้ารายการ WMS ที่เป็นจุดเริ่มต้นของ Transfer, Adjustment และ Stock Count ใช้ตัวเลือกคลังกลาง; ตัวเลือกจำกัดเฉพาะคลัง active ที่ผู้ใช้มีสิทธิ์ในสาขาปัจจุบัน
- [ ] ทุก Select2/AJAX endpoint รับและตรวจ `branch_id`; ไม่มี dropdown คลังทั้งหมดโดยไม่ scope
- [ ] เพิ่ม filter สาขาใน POS/Purchasing/WMS/Finance reports และคง filter คลังเป็นรายละเอียดรอง
- [ ] ทดสอบสิทธิ์: ผู้ใช้หนึ่งสาขา, หลายสาขา, หลายคลังในสาขา, สิทธิ์ถูกถอน และสาขา/คลัง inactive
- [x] ทดสอบ flow: purchase → receipt → stock → sale → return → COGS/GL และ transfer
- [~] ทดสอบ Draft, Posted, Cancel/Reversal, PDF, DataTable, API และข้อมูล legacy — contract/quality gate และ read-only legacy audit ผ่านแล้ว; เหลือ browser owner sign-off ตาม [checklist](../qa/branch-warehouse-context-signoff.md)
- [x] ทำ local manual owner sign-off และบันทึก migration/reconciliation evidence — ผ่าน 31 สิงหาคม 2026; production operational sign-off ต้องทำซ้ำบน environment ปลายทาง

**เกณฑ์ผ่าน:** ไม่มีการเลือกหรือ Post ข้ามสาขา, การกระทบสต็อก/GL reconcile ได้, และ UX ไม่บังคับผู้ใช้เลือกคลังเมื่อเอกสารไม่เกี่ยวกับสินค้า

## ลำดับ Rollout

1. Phase 0 และ 1 บน local
2. Phase 2 migration rehearsal และแก้ exception
3. Phase 3 แยก POS กับ Purchasing ทำขนานได้หลัง data contract ผ่าน
4. Phase 4 หลัง source document ของแต่ละ flow ส่ง `branch_id` ครบ
5. Phase 5 ก่อนเปิดใช้จริง

## ข้อห้าม

- ห้าม infer สาขาจาก session เพื่อเขียนทับเอกสาร Posted
- ห้ามอนุญาต `warehouse_id` ที่อยู่คนละสาขากับเอกสาร แม้ UI ซ่อนตัวเลือกแล้ว
- ห้ามเปลี่ยน warehouse-based stock balance, cost layer หรือ journal lineage เป็น branch-based โดยไม่มี contract ใหม่
- ห้ามปิดสาขาหรือคลังที่ยังมีเอกสาร/งานค้างโดยไม่ผ่าน guard และ audit
