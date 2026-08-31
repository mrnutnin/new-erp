# Purchase Document → Inventory Post — Manual UI Sign-off

สถานะ: **Manual UI Sign-off ผ่านแล้ว / ยังไม่ใช่ production-ready**  
ขอบเขต: Purchase Invoice แบบ `NONE_VAT` เท่านั้น; ไม่รวม VAT, Credit Note, Production หรือ Receipt Post แยก

## Static evidence ที่ตรวจจาก code

- [x] รายการใช้ Yajra DataTable แบบ server-side AJAX และมี human-readable document date, due date และ status
- [x] `inventory_post_url` ถูกส่งจาก backend เฉพาะเมื่อ feature gate เปิด, ผู้ใช้มี `wms.purchase-documents.inventory-post`, เอกสารเป็น `APPROVED`, `INVOICE` และ `NONE_VAT`
- [x] หน้า detail ใช้เงื่อนไขเดียวกับ DataTable; VAT/Credit Note ไม่แสดงปุ่ม `Post Inventory`
- [x] route ใช้ `wms.purchase-documents.inventory-post` แยกจาก Expense Post และมี permission middleware
- [x] ปุ่ม Post มี confirmation, เลือก posting date ที่ไม่น้อยกว่าวันที่เอกสาร และ lock ระหว่าง request
- [x] success/error feedback ใช้ข้อความจาก server และ reload รายการหลังสำเร็จ
- [x] source flow ยังคง warehouse-scoped ผ่าน controller/service; ไม่มี Receipt Post route แยก
- [x] `php artisan view:cache`, Pint และ inventory release-gate tests ผ่าน

## Browser sign-off (ผ่านแล้ว — 22 สิงหาคม 2026)

ใช้ user ที่มีสิทธิ์และ session warehouse เดียวกันกับ Purchase Invoice; บันทึก screenshot/เวลา/ผู้ตรวจใน release record

- [x] เปิด DataTable แล้วตรวจเลขที่, วันที่มนุษย์อ่านได้, Supplier, due date, จำนวนเงิน และ status
- [x] เอกสาร `APPROVED + INVOICE + NONE_VAT` แสดง `Post Inventory` เมื่อ feature gate และ permission เปิด
- [x] เอกสาร `DRAFT`, `POSTED`, `VOID`, `VAT_IN` และ `CREDIT_NOTE` ไม่แสดงปุ่มดังกล่าว
- [x] user ไม่มี `wms.purchase-documents.inventory-post` ไม่เห็นปุ่ม และ route ถูกปฏิเสธ
- [x] เปลี่ยน warehouse แล้วเห็นเฉพาะเอกสารของ warehouse นั้น; เปิด URL ของอีก warehouse ไม่ได้
- [x] กดปุ่มซ้ำระหว่าง request ไม่สร้าง request ซ้ำ และปุ่มถูก disable ชั่วคราว
- [x] ยกเลิก confirmation ไม่ส่ง request
- [x] posting date ก่อน document date ถูกป้องกัน; วันที่ปัจจุบัน/วันที่ถูกต้องส่งได้เมื่อ preflight พร้อม
- [x] success แสดงข้อความและ DataTable/detail สะท้อนสถานะใหม่
- [x] failure แสดง blocker ที่แก้ได้ เช่น mapping, fiscal period, reconciliation หรือ source state โดยไม่แสดงความสำเร็จหลอก
- [x] ไม่มีปุ่ม Receipt Post แยก และไม่มี VAT/Production action ใน scope นี้

## Release gate

Manual UI Sign-off ผ่านแล้ว แต่ยังไม่ถือเป็น Production operational sign-off. ทีมจะพัฒนาต่อบน local จน module ในขอบเขต MVP พร้อมครบทั้งหมด แล้วจึงทำ DB-backed smoke, reconciliation, retry/rollback evidence และ production sign-off รวมครั้งเดียวก่อนเปิดใช้งานจริง
