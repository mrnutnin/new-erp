# Min/Max Stock — Local QA Handoff

ขอบเขตนี้เป็นเพียงสัญญาณบน Dashboard และ prefill ใบ PR ผู้ใช้ต้องตรวจสอบและกดบันทึกเอง ระบบไม่สร้าง PR อัตโนมัติ

## ผลตรวจ

- [x] Policy ผูกกับ `warehouse_id + item_id` และตรวจ `max >= min`
- [x] Dashboard แสดง on-hand, reserved, available, PO ที่อนุมัติแล้วและยังรับไม่ครบ
- [x] จำนวนแนะนำ = `max - (available + open approved PO)`; ถ้าสต็อกกับ PO ครบถึง Min จะไม่แจ้งเตือนซ้ำ
- [x] Draft/void PO ไม่ถูกนับเป็นสินค้ารอเข้า
- [x] PO ที่ใช้ Purchase UOM ต่างจาก Stock UOM ถูกแปลงด้วย conversion ที่มีผลตามวันที่เอกสาร; conversion ที่หายหรือซ้อนกันจะไม่ถูกนับจนกว่า GR จะตรวจไม่ผ่าน
- [x] Alert และ open PO ถูกจำกัดตามคลังที่เลือก ไม่ปนข้อมูลจากคลังอื่น
- [x] ลิงก์ไปสร้าง PR เป็นเพียง prefill; ผู้ใช้ยืนยัน/แก้ไขก่อนบันทึก
- [x] Input precision ใช้ Global Settings ผ่าน `WmsDecimal`
- [x] policy scope ใช้คลังจาก session และ restore policy ที่ soft-deleted เดิมแทนการสร้างซ้ำ

## หลักฐานการทดสอบ

```text
DB_CONNECTION=mysql DB_DATABASE=new_erp ERP_RUN_MYSQL_INTEGRATION=1 \
php vendor/bin/phpunit --filter StockMinMaxMySqlIntegrationReadinessTest --testdox

OK (3 tests, 13 assertions)
```

ทดสอบ fixture: on-hand 50, reserved 10, available 40, approved open PO 30, Min 80, Max 100 และได้จำนวนแนะนำ 30; ทดสอบ Purchase UOM conversion และแยก warehouse scope เพิ่มเติม โดยทดสอบภายใน transaction แล้ว rollback.

## เหลือก่อน operational sign-off

- Manual UI ตรวจ Dashboard/Policy/PR prefill ในแต่ละ warehouse
- ตรวจสิทธิ์ Admin และ role ผู้ใช้งานจริงบน local
- ยังไม่เปิดการสร้าง PR อัตโนมัติ และยังไม่มี bulk PR หลายรายการใน MVP
