# Manual QA — Feature Posting Configuration / Phase 2

## การตั้งค่า

- [ ] เปิด Accounting > การตั้งค่าการลงบัญชี แล้วตรวจ Filter card แยกจากตาราง
- [ ] กรอง Module, Event, สถานะ และ Legacy แล้วกดล้างตัวกรอง
- [ ] สร้าง Event Mapping โดยเลือก Event, บทบาทบัญชี, ค้นหาบัญชีผ่าน Select2 และระบุเหตุผลอย่างน้อย 10 ตัวอักษร
- [ ] ตรวจว่า Select2 แสดงเฉพาะบัญชีที่ตรงกับ role (เช่น AP, AR, VAT, Inventory)
- [ ] แก้บัญชี/ปิดใช้งาน 1 รายการ แล้วตรวจ version และ Audit Trail มี before/after/reason
- [ ] กดคัดลอก Legacy Mapping แล้วเลือก Event ที่มี role เดียวกัน; ยืนยันว่า Legacy row เดิมไม่ถูกแก้ไข
- [ ] เปิดด้วยผู้ใช้ view-only/create/update เพื่อตรวจสิทธิ์และปุ่มที่แสดง

## เกณฑ์ผ่าน

การตั้งค่าใหม่อยู่ใน Accounting เท่านั้น, ไม่มีการตั้งค่า Account ID ใน Global Settings,
และการสร้าง Event Mapping ยังไม่ทำให้ Feature ใดเปลี่ยนบัญชีตอน Post (Phase 3 เป็นต้นไป)
