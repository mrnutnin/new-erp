# Inventory Reports Manual QA

ขอบเขต: Stock Card (movement), Current Stock Valuation และ Historical Valuation/Reconciliation ใน WMS MVP. รายงานใช้ DataTables/Yajra แบบ server-side; ปุ่ม Excel เป็น HTML5 export ของแถวที่ browser โหลดตาม MVP contract.

## Preconditions

- ผู้ใช้มี `wms.stock.view` หรือ `wms.stock-valuation.view` ตามหน้าที่ทดสอบ
- เลือก Warehouse ที่ผู้ใช้ได้รับมอบหมายแล้ว และมี Item/UOM ที่ใช้งานอยู่
- มี Stock Movement สถานะ `POSTED` อย่างน้อยหนึ่งรายการ และมีรายการ Cost Allocation สำหรับ Historical valuation หากต้องตรวจ reconciliation

## Manual checks

- [ ] Stock Card ไม่โหลดแถวหลักใน `index()`; เปิดหน้าแล้ว DataTable เรียก `wms.stock.data` ผ่าน AJAX และมี search, page length, pagination, HTML5 Excel button
- [ ] Stock Card บังคับเลือก Item ก่อน query; เมื่อเลือก Item และวันที่แล้วแสดง Movement ที่ `business_date <= as_of` และยอด On-hand/Reserved/Available เป็นตัวเลขอ่านง่าย
- [ ] Current Valuation เรียก `wms.stock-valuation.data` ผ่าน AJAX, scope ตาม Warehouse session และกรอง Item ได้โดยไม่โหลดทั้งตารางเข้า Blade
- [ ] Historical Valuation และ Historical Reconciliation เรียก data route แยก, ส่ง `as_of_date` แบบ `Y-m-d`, ไม่ใช้ current Stock Balance เป็นแหล่ง Historical Final
- [ ] วันที่แสดงตาม company date format; สถานะเป็นคำอธิบาย (`Final`, `รอ Recost`, `ต้องตรวจสอบ`, `ตรงกัน`); ค่าว่างแสดง `-`; จำนวน/มูลค่ามี comma และทศนิยมสม่ำเสมอ
- [ ] เปลี่ยน Warehouse แล้วข้อมูลทุกตารางเปลี่ยนตาม context; ผู้ใช้ที่ไม่มี warehouse assignment ไม่สามารถอ่านข้อมูลของคลังอื่นผ่าน query parameter ได้
- [ ] ผู้ใช้ไม่มี `wms.stock.view` เข้า Stock Card/data route ไม่ได้ และไม่มีเมนู; ผู้ใช้ไม่มี `wms.stock-valuation.view` เข้า Valuation/data routes ไม่ได้และไม่มีเมนู
- [ ] เมื่อ DataTable AJAX ล้มเหลว UI แสดงข้อความที่เป็นมิตร ไม่เปิด SQL/debug payload
- [ ] Excel export ใน MVP ตรวจเพียงว่าปุ่มและ `excelHtml5` ถูก register; ไม่ต้องตรวจไฟล์ที่ดาวน์โหลด

## Static verification evidence (2026-08-22)

- `StockController::index()` และ `StockValuationController::index()` คืนเฉพาะ Blade view; row dataset ใช้ `data()` routes แยก
- Routes แยกสำหรับ Stock Card, Current Valuation, Historical Valuation และ Historical Reconciliation และทุก route มี `auth`, `program:wms`, `warehouse` พร้อม permission ของหน้าตนเอง
- Blade ทุกตารางใช้ `window.erpDataTableDefaults`, AJAX `data` route และ `window.erpExcelButton(...)`; filters reload ตารางเดิมโดยไม่ reinitialize
- Item filters ใช้ shared `window.erpInitSelect2` พร้อม remote search/pagination; Historical tabs เรียก `columns.adjust()` เมื่อเปิดแท็บเพื่อไม่ให้ตารางที่เริ่มในพื้นที่ซ่อนถูกบีบหรือคำนวณความกว้างผิด
- Stock Card ใช้ company `date_format` สำหรับ `business_date`; Valuation format ตัวเลขและสถานะเป็น human-readable
- Current Valuation pending-layer query ใช้ `StockCostLayer` model โดยตรง เพื่อไม่ผูก query กับ table ของ `StockBalance` ผิด domain

## Known boundaries

- HTML5 export ของ server-side DataTable ส่งออกเฉพาะแถวที่ browser โหลด ตาม MVP decision; full-dataset/queued export ยังไม่อยู่ใน scope
- Historical reconciliation แสดง current Stock Balance เป็น projection และระบุข้อจำกัดใน UI; historical balance แบบ replay/snapshot ยังไม่ใช่ source ใน MVP
- รายงานต้นทุนสินค้าเทียบราคาขายยังรอ Sales/POS item-stock และ selling-price source contract
