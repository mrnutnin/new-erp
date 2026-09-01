# Asset Phase 5 — Maintenance and alerts

## Manual QA sign-off

- [x] สร้างและตรวจใบแจ้งซ่อมครบ OPEN, ASSIGNED, IN_PROGRESS, WAITING_PARTS, COMPLETED และ CANCELLED
- [x] มอบหมายผู้รับผิดชอบด้วย Select2/AJAX และตรวจสิทธิ์การเปลี่ยนสถานะ
- [x] ตรวจ priority, warranty, downtime, ค่าใช้จ่าย และเอกสารอ้างอิง
- [x] ตรวจแผนบำรุงรักษา: ใกล้ครบกำหนด, เกินกำหนด, ตามแผน และปิดใช้งาน
- [x] ตรวจคำสั่งแจ้งเตือนรายวันไม่สร้างใบแจ้งซ่อมหรือ Journal ซ้ำ
- [x] ตรวจสถานะ UNDER_REPAIR เมื่อเริ่มซ่อมและคืน ACTIVE เมื่อปิด/ยกเลิกงาน
- [x] อัปโหลด/ดาวน์โหลด/ลบหลักฐานแนบ
- [x] ตรวจ Dashboard แยกโหลด section, KPI, กราฟ Chart.js และ cache ระยะสั้น
- [x] ตรวจรายงานงานซ่อม Filter, server-side DataTable, ยอดรวม และ Excel export
- [x] ตรวจเวลา Audit Trail เป็นเวลาไทย (UTC+7)

## Unit tests

- `AssetMaintenanceContractTest`
- `AssetMaintenanceScheduleContractTest`
- `AssetMaintenanceDashboardContractTest`
- `AssetAttachmentContractTest`

ผลล่าสุด: ผ่านทั้งหมดตาม test suite ที่เกี่ยวข้อง; PHP syntax, Pint, route cache และ view cache ผ่านแล้ว

เจ้าของระบบยืนยันผ่าน Phase 5: 1 กันยายน 2026
