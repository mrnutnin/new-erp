# Platform: Route-scoped Program Authorization

## เป้าหมาย

ให้ผู้ใช้คนเดียวเปิดหลายโปรแกรมพร้อมกัน เช่น Accounting และ Asset คนละแท็บหรือหน้าต่างได้ โดยแต่ละ route ตรวจสิทธิ์จากโปรแกรมที่ route กำหนดไว้ ไม่พึ่ง `selected_program_id` ใน Session เพื่อระบุโปรแกรมของ request

`branch_id` และ `warehouse_id` ยังคงเก็บและทำงานผ่าน Session ตามระบบเดิม ไม่มีการเปลี่ยนแปลงในงานนี้

## ปัญหาปัจจุบัน

ระบบใช้ Session key เดียวคือ `selected_program_id` เมื่อผู้ใช้เลือกโปรแกรมใหม่จากอีกแท็บ ค่าใน Session ถูกเขียนทับ ทำให้แท็บเดิมถูก middleware มองว่าอยู่ผิดโปรแกรมและ redirect ไปหน้าเลือกโปรแกรม

สิทธิ์ของ System Admin ไม่ได้แก้ปัญหานี้ เพราะ permission ตอบว่า “เข้าได้หรือไม่” แต่ `selected_program_id` ถูกใช้เป็นทั้งตัวเลือกโปรแกรมและตัวระบุบริบทของ request

## หลักการออกแบบ

- route ของแต่ละ module ระบุ required program อย่างชัดเจน เช่น `program:accounting` หรือ `program:asset`
- middleware ตรวจ user, program assignment, `is_enabled` และ `ModuleCapability` ทุก request
- ห้ามอนุญาตให้ user เข้า route ของโปรแกรมที่ไม่มีสิทธิ์ แม้เป็น System Admin หากโปรแกรมถูกปิดใช้งาน
- ไม่ใช้ cookie, token, `program_contexts` หรือ query parameter ใหม่
- `selected_program_id` อาจคงไว้เพื่อจำโปรแกรมล่าสุดและ navigation แต่ไม่ใช้เป็นหลักในการ authorize route
- branch และ warehouse ใช้ Session เดิมต่อไป
- ไม่เปลี่ยนระบบ permission/RBAC และไม่เปลี่ยน business flow ของ module ใด

## แนวทางที่เลือก

เพิ่มหรือปรับ middleware กลาง เช่น `EnsureProgramAccess` ให้รับ required program จาก route middleware:

```php
Route::middleware(['auth', 'program:accounting'])->group(function () {
    // Accounting routes
});
```

ลำดับการตรวจ:

1. user ต้อง authenticated
2. required program ต้องมีอยู่และเปิดใช้งาน
3. user ต้องมี program assignment ที่ใช้งานได้
4. `ModuleCapability` ต้องอนุญาตให้บริษัทเปิดโปรแกรมนี้
5. หากไม่ผ่าน ให้ตอบ `403` หรือ redirect ไปหน้าเลือกโปรแกรมตามชนิด request

หน้า Select Program ยังเขียน `selected_program_id` ได้เพื่อใช้เป็นค่าเริ่มต้นของ navigation แต่การเปิด route ในแท็บอื่นจะไม่ถูกตัดสินจากค่านี้

## ขอบเขตงาน

### Phase A — Authorization middleware

- สร้างหรือปรับ middleware ตรวจ required program จาก route parameter
- ผูก middleware กับ route ของ Accounting, Asset, Finance, POS, Purchasing และ WMS/Inventory ตาม code จริง
- คง `EnsureProgramSelected` สำหรับ flow เลือกโปรแกรม/entry route ที่จำเป็น โดยไม่ใช้เป็น authorization หลักของ module route
- รักษาการตรวจ permission ราย action และ branch/warehouse Session เดิม

### Phase B — Navigation, handoff และ regression

- ปรับ Select Program, sidebar และ cross-program handoff ให้ทำงานกับ route-scoped authorization
- handoff ยังส่ง query/filter เดิม เช่น period, account และ asset scope โดยไม่ต้องมี context token
- เพิ่มข้อความ error ที่เหมาะสมเมื่อ user ไม่มีสิทธิ์หรือโปรแกรมถูกปิด
- เพิ่ม unit/feature tests และ manual QA สำหรับหลายแท็บ

## สิ่งที่ไม่ทำในงานนี้

- ไม่สร้างตาราง `program_contexts`
- ไม่สร้าง context token, signed cookie หรือ query token
- ไม่แยก branch/warehouse ตามแท็บ
- ไม่เปลี่ยนวิธีเก็บ `selected_branch_id` และ `selected_warehouse_id`
- ไม่เปลี่ยน permission/RBAC
- ไม่แก้ business flow ของ Asset, Accounting, Finance, POS หรือ WMS นอกเหนือจาก middleware และ handoff

## Acceptance criteria

- [x] System Admin เปิด Accounting และ Asset คนละแท็บพร้อมกันได้ โดยแท็บหนึ่งไม่ redirect เพราะอีกแท็บเปลี่ยนโปรแกรม (Manual QA ผ่านโดย Owner)
- [x] ผู้ใช้ทั่วไปเข้าได้เฉพาะ route ของโปรแกรมที่ตนมีสิทธิ์
- [x] required program middleware ปฏิเสธ route ที่ user ไม่มีสิทธิ์หรือโปรแกรมถูกปิดใช้งาน
- [x] permission ของ action และ branch/warehouse isolation ยังทำงานเหมือนเดิม
- [x] cross-program handoff จาก Asset reconciliation ไป Accounting ยังรักษา period/account/asset scope
- [x] refresh, back button, logout/login และ request แบบ JSON ได้ผลลัพธ์ที่ปลอดภัย
- [x] ผ่าน unit tests, feature tests และ manual QA หลายแท็บ/หลายหน้าต่าง

## แผนทดสอบ

- Unit tests: middleware authorization, required program, disabled program และ capability denial
- Feature tests: concurrent tabs, wrong-program route, permission denial และ JSON/HTML response
- Regression: Select Program, Entry route, branch/warehouse selection และ Accounting ↔ Asset handoff
- Manual QA: เปิด Accounting + Asset พร้อมกัน, เปลี่ยนโปรแกรม, เปลี่ยนสาขา/คลัง และ refresh แต่ละแท็บ

## สถานะ

ปิด Phase B แล้ว เป็นงาน Platform แยกจาก Phase 7 ของ Asset โดยใช้ route-scoped program authorization ตามมติล่าสุด และคง branch/warehouse ใน Session เดิม
