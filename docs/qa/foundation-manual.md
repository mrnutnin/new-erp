# Foundation Manual QA

ใช้รายการนี้หลัง fresh migration, เมื่อแก้ auth/RBAC/Settings/shared UI หรือก่อนส่ง Foundation release

## เตรียมระบบ

- [ ] `php artisan migrate --seed` ผ่านบน MySQL `new_erp`
- [ ] `php artisan serve --host=127.0.0.1 --port=8010` ทำงาน
- [ ] เปิด `/login` และ local CSS/JavaScript/DataTables assets ได้ HTTP 200

## Authentication และ Context

- [ ] Login `admin` สำเร็จและ session regenerate
- [ ] รหัสผ่านผิดแสดง inline validation โดยไม่ใช้ popup ซ้ำซ้อน
- [ ] Select Program แสดงเฉพาะโปรแกรมที่ user ได้รับ
- [ ] โปรแกรมทั่วไปบังคับ Select Warehouse เฉพาะเมื่อ session ยังไม่มี Warehouse ที่ user มีสิทธิ์และยัง active
- [ ] เปลี่ยน Program แล้ว reuse Warehouse เดิมและเข้า Dashboard ของ Module ใหม่ทันที
- [ ] Settings ข้าม Select Warehouse ไป Dashboard แต่ยังแสดง Warehouse/Branch session เดิมบน top bar
- [ ] Top bar แสดงสาขาและคลังปัจจุบัน กดเปลี่ยนแล้วกลับ Dashboard ของ Program ปัจจุบัน
- [ ] เมื่อคลังถูกปิดหรือถอนสิทธิ์ request ถัดไปต้องล้าง context เดิมและบังคับเลือกใหม่
- [ ] Logout สำเร็จและ route ที่ต้อง auth กลับไป login

## User / Role / Permission

- [ ] User DataTable โหลดแบบ server-side พร้อม search, pagination และ page length
- [ ] เพิ่ม/แก้ไข user พร้อม Role, Program และ Warehouse assignment
- [ ] ปิด user ตนเองถูกปฏิเสธ 422 ที่ `is_active`
- [ ] ถอด Settings program ของตนเองถูกปฏิเสธ 422 ที่ `program_ids`
- [ ] ถอด admin role ของตนเองถูกปฏิเสธ 422 ที่ `role_ids`
- [ ] Admin role เปลี่ยน code หรือปิดใช้งานไม่ได้
- [ ] User ที่มี view อย่างเดียวไม่เห็นปุ่ม create/edit และยิง mutation route ได้ 403
- [ ] User ที่ไม่มี Settings permission เข้า Settings route ได้ 403
- [ ] ทุกเมนูใหม่มี permission ใน `RbacSeeder`, route middleware, Sidebar visibility และถูกผูกกับ role `admin`; หลัง seed แล้ว Admin เห็นเมนู/เข้า route ได้จริง

## Company / Branch / Warehouse

- [ ] Company Setting บันทึกผ่าน AJAX, action button lock ระหว่าง request และ SweetAlert แสดง `status`/`msg`
- [ ] Company Setting บังคับเหตุผลและวันที่มีผล, เพิ่ม version/snapshot/audit ใน transaction เดียวกัน และหน้าแสดง version ใหม่หลัง refresh
- [ ] Readiness แจ้งชื่อค่าที่ยังขาด และหายไปเมื่อกรอก Accounting/Inventory/Documents/Operations ครบ
- [ ] เปิดสต็อกติดลบโดยไม่เลือกต้นทุนชั่วคราวถูกปฏิเสธ 422; ปิดสต็อกติดลบแล้วล้างค่าต้นทุนชั่วคราว
- [ ] แก้ Global Setting แล้ว request ถัดไปอ่าน version ใหม่จาก cache; transaction ล้มเหลวต้องไม่ invalidate ค่าเดิม
- [ ] Branch/Warehouse list ไม่มี row dataset จาก `index()` และโหลดผ่าน Yajra data route
- [ ] Branch/Warehouse DataTable มี search, pagination และ page length
- [ ] Branch/Warehouse code ซ้ำถูกปฏิเสธด้วย inline validation
- [ ] Warehouse เลือกได้เฉพาะ active branch
- [ ] ปิด branch ที่ยังมี active warehouse ถูกปฏิเสธ 422 ที่ `is_active`
- [ ] ปิด warehouse แล้วจึงปิด branch ได้
- [ ] ไม่มี hard-delete action ใน User/Role/Branch/Warehouse UI

## CRUD interaction contract

- [ ] หน้า register form ด้วย `erpAjaxForm()` และปิด submit button ระหว่างรอ AJAX เพื่อกัน request ซ้ำ
- [ ] Update สำเร็จและปิด SweetAlert แล้วอยู่หน้าเดิมเมื่อ `reload: false`
- [ ] Create สำเร็จ redirect ไปหน้า Edit เฉพาะเมื่อกำหนด `redirect: true`
- [ ] `reload: true` refresh หน้า และ `reload: '#table-id'` reload DataTable โดยคง pagination
- [ ] Controller ของ CRUD save คืน `status`, `msg` และ `redirect` เมื่อจำเป็น
- [ ] Row ที่ domain อนุญาตให้ลบใช้ page-specific delete selector, SweetAlert confirm และ AJAX `DELETE`
- [ ] แต่ละหน้า register delete button ผ่าน `erpAjaxDelete()` และผู้ไม่มี delete permission ไม่เห็นปุ่ม/ยิง route ได้ 403
- [ ] Delete สำเร็จ reload DataTable โดยคงหน้าปัจจุบัน และ delete ล้มเหลวแสดง `msg`
- [ ] ลบตนเอง/admin, admin role, role ที่มี user, branch ที่มี warehouse และ active warehouse ถูกปฏิเสธ 409
- [ ] Master/history-linked model ใช้ SoftDelete หรือปิด delete ตามกฎ domain ไม่มี hard delete โดยพลการ

## Backoffice layout

- [ ] Desktop แสดง Sidebar ชิดซ้ายเต็มความสูง viewport และ user/logout อยู่ด้านล่าง
- [ ] Settings และ Accounting แสดง Sidebar เพียงชุดเดียวโดยเมนู active ถูกต้อง
- [ ] Top bar อยู่เหนือ workspace แสดง Program และสาขา/คลังโดยไม่บีบความกว้าง DataTable
- [ ] Workspace ใช้ความกว้างที่เหลือทั้งหมดและ DataTable ไม่ถูกบีบด้วย Bootstrap sidebar column ซ้ำ
- [ ] จอเล็กแสดงเมนูแนวนอนเลื่อนได้โดย content และ logout ยังเข้าถึงได้
- [ ] Login, Select Program, Select Warehouse และ Dashboard ที่ไม่มี Sidebar ยังใช้ header layout เดิม

## Audit

- [ ] Company/User/Role/Branch/Warehouse create/update สร้าง audit row ใน transaction เดียวกัน
- [ ] Audit DataTable search/pagination/page length ทำงาน
- [ ] Audit JSON และ Excel ไม่มี `password`, `password_confirmation` หรือ `remember_token`
- [ ] Audit ไม่มี update/delete action และ record ไม่มี SoftDeletes

## Small-team approval QA

- [ ] ผู้ใช้คนเดียวที่ได้รับ create/submit/approve/post ตาม policy ทำ Payment Voucher และ Settlement ครบโดยไม่ต้องสร้างผู้อนุมัติคนที่สอง
- [ ] ผู้ใช้คนเดียวทำ Purchase และ Sales จาก Draft → Approved → Post ได้ครบเมื่อมี mapping/period พร้อม
- [ ] ผู้ใช้คนเดียวทำ Journal จาก Draft → Validated → Posted ได้ครบตาม permission
- [ ] เปิด maker-checker แล้ว maker ไม่มี approve/post และ checker ไม่มี edit; ระบบแสดง blocker ที่บอกวิธีแก้ ไม่ใช่ error กว้างๆ
- [ ] Workflow Center ไม่แสดงข้อความว่าต้องมีผู้อนุมัติหลายคน หาก approval policy ของบริษัทไม่ได้เปิด

## Automated checks

- [ ] `vendor/bin/pint --test` ผ่าน
- [ ] `php artisan test --testsuite=Unit` ผ่าน
- [ ] `php artisan route:list --except-vendor` ผ่าน
- [ ] `php artisan view:cache` ผ่าน แล้ว clear cache เมื่อจบ QA หากต้องการ
- [ ] `composer validate --strict` ผ่านด้วย Composer 2
- [ ] `public/vendor/manifest.json` parse ได้และ checksum ตรงกับทุก pinned asset

บันทึกวัน ผู้ทดสอบ environment และข้อผิดพลาดที่พบใน release evidence ของรอบนั้น ห้ามติ๊ก use case ว่าเสร็จถ้ายังขาด permission isolation, audit หรือ validation path ที่เกี่ยวข้อง
