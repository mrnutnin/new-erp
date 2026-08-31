# แผนปรับปรุงก่อนเริ่ม Asset

สถานะ: วางแผน — รอเริ่มพัฒนา

## เป้าหมาย

1. ย้ายเมนู **รูปแบบรหัสเอกสาร** จาก Finance ไปอยู่ใน Global Settings โดยใช้กติกากลางทั้งระบบ
2. เพิ่มข้อมูลผู้ใช้ขั้นต่ำในลักษณะ Employee Profile โดยไม่สร้าง HRM domain: รหัสพนักงานและสาขาหลัก
3. เพิ่มหน้า **My Profile** ให้ผู้ใช้ดูและแก้ข้อมูลส่วนตัวได้อย่างปลอดภัย

## กติกาหลักที่ยืนยันแล้ว

- รูปแบบเลขเอกสารกำหนดจาก Global Settings เพียงจุดเดียว ตามประเภทเอกสาร
- ตัวนับเลขแยกตาม `document_type + branch_id` ไม่แยกตามคลัง
- รหัสสาขาใช้ `branches.code` และรองรับ token `{BRANCH}`
- รองรับ token: `{PREFIX}`, `{BRANCH}`, `{YY}`, `{YYYY}`, `{MM}`, `{NUMBER:n}`
- รูปแบบตัวอย่าง: `IV{BRANCH}{YYMM}{NUMBER:6}` → `IVBKK2608000001`
- เลขที่ออกไปแล้วเป็นประวัติ ห้ามเปลี่ยนหรือใช้ซ้ำ; การตั้งค่าใหม่มีผลเฉพาะเลขที่ออกหลังจากบันทึก
- ทุกเอกสารเก็บ `branch_id` เป็น source of truth ส่วนคลังใช้เฉพาะการปฏิบัติการสินค้า/ส่งมอบ

## Phase 1 — โครงสร้างเลขเอกสารกลาง

- [ ] เพิ่มตารางตัวนับระดับสาขา (`document_sequence_id`, `branch_id`, `next_number`, `last_reset_key`) พร้อม unique constraint
- [ ] เปลี่ยน `finance_document_sequences` ให้เป็น template กลางต่อประเภทเอกสาร ไม่ผูก warehouse
- [ ] Migration ข้อมูลเดิม: เลือกรูปแบบ active ของแต่ละประเภทเป็น template, สร้าง counter ให้ทุกสาขาจากเลขสูงสุดเดิมของสาขานั้น, และเก็บแถวเดิมเป็นประวัติ/ไม่ใช้ในการออกเลขใหม่
- [ ] ตรวจความต่างของรูปแบบเดิมก่อน migration; หากชนิดเดียวกันมีรูปแบบต่างกัน ให้ใช้ template กลางที่ผู้ดูแลเลือก/ยืนยัน ไม่ merge แบบเงียบ ๆ
- [ ] ปรับ `DocumentSequenceService` ให้ issue/replace/format รับ Branch และแทนค่า `{BRANCH}`, `{YY}`
- [ ] ปรับทุกจุดออกเลขใน POS, Finance, Purchasing และ WMS ให้ resolve template กลางและ counter ของสาขาเอกสาร
- [ ] เพิ่ม unique guard ระดับเอกสารตามเลขที่ออกจริง และคง `DocumentSequenceHistory` สำหรับ audit/reuse policy

### เกณฑ์ตรวจรับ Phase 1

- [ ] สาขา BKK ออก IV สองใบ: `IVBKK2608000001`, `IVBKK2608000002`
- [ ] สาขา CNX ออก IV ใบแรก: `IVCNX2608000001`
- [ ] ออก Purchase Order / Receipt / HS / IV / Return จากคนละคลังในสาขาเดียวกันแล้วเลขรันต่อกัน
- [ ] การเปลี่ยนปี/เดือนทำงานตาม reset rule และเอกสารย้อนหลังไม่ชนเลข
- [ ] เอกสารเดิมยังเปิดดู/พิมพ์ได้ และไม่มีการเปลี่ยนเลขย้อนหลัง

## Phase 2 — ย้ายหน้าจอและสิทธิ์

- [ ] เพิ่ม route/menu `Settings > Global Settings > รูปแบบรหัสเอกสาร`
- [ ] เปลี่ยนชื่อหน้าและ breadcrumb เป็น Global Settings
- [ ] ย้ายเมนูออกจาก Finance sidebar; route เดิม redirect ไปหน้าใหม่ชั่วคราวเพื่อไม่ให้ bookmark เสีย
- [ ] ย้าย permission เป็น `settings.document-sequences.*` หรือ map permission เดิมระหว่าง transition เพื่อไม่ทำให้ผู้ใช้เดิมเข้าไม่ได้
- [ ] ปรับแบบฟอร์ม token help และตัวอย่างเลขแบบ live preview จากรหัสสาขาตัวอย่าง

## Phase 3 — User Employee Profile ขั้นต่ำ

- [ ] เพิ่ม `employee_code` (unique, nullable สำหรับข้อมูลเก่า) และ `primary_branch_id` (nullable FK) ใน `users`
- [ ] เพิ่ม relation `User::primaryBranch()`
- [ ] หน้าสร้าง/แก้ User ให้กรอกรหัสพนักงานและเลือกสาขาหลัก
- [ ] Validation: รหัสพนักงานไม่ซ้ำ; สาขาหลักต้อง active และผู้ใช้ต้องเข้าถึงอย่างน้อยหนึ่งคลังของสาขานั้น
- [ ] แสดงรหัสพนักงานและสาขาหลักในตาราง/export ผู้ใช้
- [ ] บันทึก audit เมื่อรหัสพนักงานหรือสาขาหลักเปลี่ยน

## Phase 4 — My Profile

- [ ] เพิ่ม route กลางที่ต้อง login เท่านั้น และลิงก์จากส่วนชื่อผู้ใช้ใน sidebar/header
- [ ] แสดงชื่อ, username, รหัสพนักงาน, สาขาหลัก, email, บทบาท และสาขา/คลังที่เข้าถึงได้
- [ ] ให้ผู้ใช้แก้ได้เฉพาะชื่อ, email และรหัสผ่านของตนเอง
- [ ] รหัสพนักงาน, สาขาหลัก, บทบาท และสิทธิ์ แก้ได้เฉพาะผู้ดูแลระบบผ่าน Settings > Users
- [ ] ใช้ validation password confirmation, audit และห้ามทำให้ account ของผู้ใช้ปัจจุบันใช้งานไม่ได้

## Phase 5 — Verification และ rollout

- [ ] Unit tests: token formatting, reset counter แยกสาขา, migration mapping, unique employee code และขอบเขต My Profile
- [ ] Feature tests: ออกเลขจากสองสาขา/หลายคลัง, สิทธิ์หน้า Global Settings และแก้ My Profile
- [ ] รัน migration กับสำเนาข้อมูลจริงก่อน, ตรวจ document sequence/history และจุดออกเลขทุก module
- [ ] UAT ผู้ดูแลตั้งรูปแบบ IV และผู้ใช้สองสาขาออกเอกสารทดสอบ
- [ ] สื่อสารวัน cutover และห้ามแก้ template ที่มีเลขออกแล้ว นอกจากชื่อ/สถานะตาม policy

## นอกขอบเขตงานนี้

- ไม่มีข้อมูล HR เช่น เงินเดือน, ตำแหน่ง, เวลางาน, วันลา หรือ payroll
- สาขาหลักไม่ได้เพิ่มสิทธิ์: สิทธิ์สาขา/คลังยังอ้างอิง assignment ที่มีอยู่
- ไม่เปลี่ยนเลขเอกสารที่ออกไปแล้ว และไม่ bulk renumber ประวัติ
