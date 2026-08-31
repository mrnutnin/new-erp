# Wave 6 Final QA — Local MVP

วันที่ตรวจ: 23 สิงหาคม 2026  
ขอบเขต: static/source/unit QA และการยืนยันสถานะ local `new_erp`; ไม่ทำ Production operational sign-off

## ผ่านแล้ว

- Route ที่เปลี่ยน state (`POST`, `PUT`, `PATCH`, `DELETE`) ใน module routes มี permission middleware; workflow/read-only entry routes เป็นข้อยกเว้นตาม UX contract
- RbacSeeder มี permission `wms.cost-allocation-reviews.view`; Legacy Review list/data/show ตรวจ permission ซ้ำที่ route
- Sidebar WMS จัด Legacy Review ไว้ในกลุ่มตรวจสอบสต็อก และซ่อนตาม permission
- Stock Valuation Preflight แสดงคำแนะนำ human-readable และลิงก์ไป Legacy Review เมื่อมี unresolved review (เฉพาะผู้มี permission)
- Legacy Review DataTable ใช้ server-side processing, shared defaults และ HTML5 DataTables Excel export
- Feature flag purchase posting อ่านจาก `.env` เป็น `false`; adjustment posting ใช้ค่า default `false` เมื่อไม่กำหนด
- Blade cache compile ผ่าน
- Unit tests ทั้งชุดผ่าน: 333 tests / 1,596 assertions (มี integration test ที่ต้องเปิด MySQL โดยเฉพาะถูก skip ตาม policy)
- Focused tests หลัง Wave 6 ผ่าน: 7 tests / 32 assertions
- Dedicated MySQL rollback evidence แยกจาก persistent OPS-SMOKE แล้ว: Advance/Deposit opt-in ผ่าน 1 test / 30 assertions และคืน baseline หลัง rollback; ไม่ seed ถาวรและไม่เปิด feature flag ค้าง
- Persistent/non-rollback evidence มีเฉพาะ chain ที่ได้รับอนุญาตใน local `new_erp`; ต้องอ่านร่วมกับ release-gate checklist และยังไม่ถือเป็น global/production readiness

## ยังต้องทำก่อน release

- Migration/seed บน local `new_erp` ถูกตรวจแล้วว่า migration ทั้งหมดสถานะ `Ran`; smoke/reconciliation evidence อยู่ใน `docs/qa/inventory-gl-release-gate-local.md`
- Manual UI sign-off ของ owner สำหรับทุก module และ DataTable/Select2/badge บน browser จริง
- Production operational sign-off ทำครั้งเดียวหลัง MVP modules พร้อมครบ ตาม CHECKLIST ไม่ใช่ใน Wave นี้
- Legacy Review allocation 2/4 ถูกซ่อมตามคำสั่งผู้ดูแล มี audit และสถานะ unresolved เป็นศูนย์; feature flags ยังคงปิดไว้จนถึง final operational sign-off
- Badge บางหน้า Finance/Accounting/Settings/WMS ยังใช้ชื่อ Bootstrap `text-bg-*` ใน source แต่ shared `public/css/app.css` map ให้แสดงผลด้วย pastel semantic tokens เดียวกับ `app-status-*`; browser sign-off ยังต้องตรวจภาพจริง
- ตรวจ browser จริงว่า DataTable export/Select2 และสิทธิ์ผู้ใช้แต่ละ role แสดงผลตรงกับ source contract

## Release blockers ปัจจุบัน

1. ยังไม่มี owner manual UI sign-off ครบทุก module
2. ยังไม่มี Production operational sign-off (ตั้งใจเลื่อนไว้ท้ายสุดตามนโยบาย)
3. ต้องเก็บ non-rollback evidence และตัดสินใจเปิด feature flag หลัง owner ตรวจ release gate ครบ

ห้ามเปิด feature flag ถาวรหรือสรุป Production-ready จากเอกสารนี้เพียงอย่างเดียว
