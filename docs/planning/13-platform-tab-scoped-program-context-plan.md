# Platform: Tab-scoped Program Context

## เป้าหมาย

รองรับผู้ใช้คนเดียวเปิดหลายโปรแกรมพร้อมกัน เช่น Accounting และ Asset คนละแท็บหรือหน้าต่าง โดยบริบทของแต่ละแท็บไม่เขียนทับกัน และยังคงตรวจสิทธิ์ โปรแกรม สาขา และคลังทุก request ตามเดิม

## ปัญหาปัจจุบัน

ระบบเก็บโปรแกรมที่กำลังใช้งานไว้ใน Session key เดียวคือ `selected_program_id` เมื่อผู้ใช้เลือกโปรแกรมใหม่จากอีกแท็บ ค่าใน Session จะถูกเขียนทับ ทำให้แท็บเดิมถูก middleware มองว่าอยู่ผิดโปรแกรมและ redirect ไปหน้าเลือกโปรแกรม

สิทธิ์ของ System Admin ไม่ได้แก้ปัญหานี้ เพราะ permission ใช้ตอบว่า “เข้าได้หรือไม่” ขณะที่ selected program ใช้ตอบว่า “request นี้อยู่ในโปรแกรมใด”

## หลักการออกแบบ

- ใช้ context token ที่ผูกกับแท็บ/หน้าต่าง แทนการพึ่ง `selected_program_id` ค่าเดียว
- ทุก request ต้องตรวจ token กับ user, program และสถานะโปรแกรมที่เปิดใช้งาน
- ห้ามให้ token ข้าม user หรือใช้เปิดโปรแกรมที่ไม่มีสิทธิ์
- Branch และ Warehouse context ต้องอยู่ในขอบเขตเดียวกับ program context
- การเปลี่ยนโปรแกรมในแท็บหนึ่งต้องไม่เปลี่ยนบริบทของแท็บอื่น
- รองรับ backward compatibility สำหรับ session เดิมระหว่าง migration
- Audit การเลือก/เปลี่ยนโปรแกรมต้องระบุ context token หรือ context id ที่ใช้

## แนวทางที่เลือก

เพิ่ม `program_contexts` เป็น server-side context registry และส่งค่า context id ผ่าน signed cookie หรือ query fallback ตามแท็บ โดยไม่เก็บสิทธิ์ไว้ใน client

ข้อมูลขั้นต่ำ:

- `id` หรือ opaque token
- `user_id`
- `program_id`
- `branch_id` nullable
- `warehouse_id` nullable
- `last_seen_at`
- `expires_at`
- `revoked_at` nullable

Middleware `EnsureProgramSelected` จะ resolve context ตามลำดับ:

1. context token ของ request ปัจจุบัน
2. session เดิมเพื่อ backward compatibility
3. redirect ไปหน้าเลือกโปรแกรมเมื่อไม่พบหรือหมดอายุ

หน้าเลือกโปรแกรมจะสร้าง context ใหม่แทนการเขียนทับ `selected_program_id` โดยตรง และลิงก์เมนู/การ handoff ข้ามโปรแกรมต้องแนบ context ใหม่ของปลายทาง

## ขอบเขตงาน

### Phase A — Foundation

- migration/model/repository สำหรับ `program_contexts`
- service สำหรับ create, resolve, touch และ revoke context
- signed context cookie พร้อมอายุและ rotation
- ปรับ `EnsureProgramSelected`
- เพิ่ม unit tests เรื่อง user isolation, expired/revoked context และ required program

### Phase B — Navigation และ handoff

- ปรับหน้า Select Program ให้เปิด context ใหม่
- ปรับ sidebar, program switcher และ cross-program handoff
- คงปุ่ม “เปลี่ยนโปรแกรม” ให้เปลี่ยนเฉพาะแท็บปัจจุบัน
- ปรับ Accounting ↔ Asset reconciliation handoff
- เพิ่มข้อความแจ้งเมื่อ context หมดอายุ

### Phase C — Branch/Warehouse และ hardening

- ผูก branch/warehouse กับ context อย่างชัดเจน
- ตรวจ branch isolation และ permission ทุก route
- ป้องกัน token fixation, replay หลัง revoke และการเดา id
- เพิ่ม cleanup job สำหรับ context ที่หมดอายุ
- วัดจำนวน context ที่ active และอัตรา resolve failure

## สิ่งที่ไม่ทำในงานนี้

- ไม่เปลี่ยนระบบ permission/RBAC
- ไม่อนุญาตให้ข้ามสาขาหรือคลังที่ผู้ใช้ไม่มีสิทธิ์
- ไม่เก็บ program permission หรือข้อมูลลับไว้ใน cookie
- ไม่แก้ business flow ของ Asset, Accounting หรือ WMS นอกเหนือจาก context handoff

## Acceptance criteria

- System Admin เปิด Accounting และ Asset คนละแท็บพร้อมกันได้ โดยแท็บหนึ่งไม่ redirect เพราะอีกแท็บเปลี่ยนโปรแกรม
- ผู้ใช้ทั่วไปเปิดได้เฉพาะโปรแกรมที่มีสิทธิ์
- Required program middleware ปฏิเสธ context ที่อยู่ผิดโปรแกรม
- เปลี่ยนสาขา/คลังในแท็บหนึ่งไม่เปลี่ยนอีกแท็บ
- context หมดอายุหรือถูก revoke แล้วต้องเข้าสู่หน้าเลือกโปรแกรมอย่างปลอดภัย
- Cross-program handoff จาก Asset reconciliation ไป Accounting ทำงานโดยไม่สูญเสีย period/account/Asset scope
- ผ่าน unit tests, feature tests และ manual QA หลายแท็บ/หลายหน้าต่าง

## แผนทดสอบ

- Unit tests: context lifecycle, authorization และ expiry
- Feature tests: concurrent tabs, required program, branch/warehouse isolation
- Manual QA: Accounting + Asset, Asset + Purchasing, refresh/back button, logout/login ใหม่ และ context หมดอายุ
- Security QA: ปลอม/แก้ cookie, replay token, เปลี่ยน user id และเปิด URL ข้ามโปรแกรม

## สถานะ

ยังไม่เริ่มพัฒนา เป็นงาน Platform แยกจาก Phase 7 ของ Asset และควรทำหลังมีข้อสรุปเรื่องการจัดการ context กลางของทุกโปรแกรม
