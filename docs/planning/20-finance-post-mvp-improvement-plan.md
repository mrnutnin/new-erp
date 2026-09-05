# Finance Post-MVP Improvement Plan

## เป้าหมาย

ยกระดับ Finance MVP ที่ผ่าน Final QA ให้รองรับกรณีใช้งานจริงที่ซับซ้อนขึ้น โดยรักษา boundary เดิม: Finance เป็นเจ้าของ source document/subledger และ Accounting เป็นเจ้าของ GL, fiscal period และ Bank Reconciliation

## หลักการส่งมอบ

- ใช้ Posting Contract กลางเดิม ห้ามสร้างเส้นทางเขียน GL ใหม่
- ทุกเอกสารใช้ branch/warehouse scope, RBAC, audit log, document sequence และ period gate เดิม
- Posted แก้ไขไม่ได้ ใช้ reversal/adjustment เท่านั้น
- DataTable ใช้ server-side, filter แยก section, AJAX form และ SweetAlert ตาม UI pattern กลาง
- แต่ละ phase ต้องมี unit/contract test และอย่างน้อยหนึ่ง MySQL integration readiness test เมื่อแตะ workflow หรือ posting

## Phase 1 — Employee Advance Clearing แบบหลายครั้ง (P0)

สถานะ: `[~]` — core implementation เสร็จแล้ว; รอ MySQL integration และ browser QA

- รองรับ clearing หลายเอกสารต่อ Employee Advance เดียวกัน
- รองรับ partial clearing และคำนวณ outstanding จากรายการที่ Post/Cleared แล้ว
- ป้องกันยอด clearing, refund และ additional รวมเกินยอดที่อนุมัติ เว้นแต่มี policy อนุมัติ
- ปรับสถานะ Advance เป็นคงค้างบางส่วน/เคลียร์แล้วอย่างถูกต้อง
- เพิ่ม self-approval guard ตามผู้สร้าง/ผู้รับเงินและ approval policy
- เพิ่มรายงานและ dashboard ให้แสดงยอดคงเหลือจากหลาย clearing

Acceptance:

- Advance 1 รายการมี clearing ได้มากกว่า 1 รายการโดยไม่เกิดยอดซ้ำ
- Post ซ้ำหรือ retry ไม่สร้าง Journal ซ้ำ
- ยอด expense + refund - additional คำนวณ outstanding ถูกต้อง
- มี audit event และ link กลับทุกเอกสารต้นทาง

ความคืบหน้า: เพิ่ม `is_final` สำหรับแยก partial/final clearing, คำนวณยอดสะสมจาก clearing ที่ Post/Cleared แล้ว, ป้องกัน partial เกินวงเงิน, ปรับ Journal ให้ release เฉพาะยอดของ clearing และปรับสถานะ Advance เป็น `PARTIAL`/`CLEARED` รวมถึงรองรับ reversal แล้ว

Test readiness: MySQL integration ผ่านกรณี Advance 1 รายการมี partial clearing 40 บาท และ final clearing 60 บาท โดยไม่ลงยอดซ้ำ, Journal balance เท่ากัน และ reversal คืนสถานะเป็น `PARTIAL`

ข้อค้นพบเดิม: service เคยคำนวณ refund/additional จากยอดสะสม แต่การ Post ยังสร้าง Journal โดยเครดิตยอด Advance เต็มจำนวนต่อ clearing จึงต้องล็อกกติกา partial clearing และ journal allocation ก่อนปรับ implementation

## Phase 2 — AR/AP Allocation Hardening (P1)

สถานะ: `[~]` — timeline allocation และ employee advance report coverage เสร็จ; รอ AR/AP browser QA และ exception/report coverage ที่เหลือ

- partial allocation หลายครั้ง
- overpayment และ unapplied cash
- ตรวจ party, branch, warehouse, ledger type และ balance side ทุกครั้ง
- reversal คืนยอด Open Item และ VAT/WHT realization ถูกต้อง
- รองรับ concurrent allocation ด้วย lock/idempotency

ความคืบหน้า: Advance/Deposit application ตรวจยอด Open Item ด้วย timeline เดียวกับ AR/AP allocation แล้ว โดยไม่นับรายการวันที่อนาคตเกินจริง และยังคง lock ตามลำดับเพื่อป้องกัน concurrent over-allocation; MySQL integration เดิมผ่านครบ

เพิ่มการกรอง soft delete ใน employee advance report และให้รายงานแสดง Advance สถานะ `PARTIAL` เพื่อไม่ให้ยอดคงค้างหายจากรายงานหลังเคลียร์บางส่วน

เริ่มเพิ่ม exception signal ใน Finance-to-GL reconciliation โดยแสดงจำนวน source document ที่ยังไม่มี Journal และไม่นับเอกสารที่ถูก soft delete

เพิ่มการตรวจ `unbalanced_journal_count` แบบราย Journal ก่อน aggregate ตาม source type เพื่อป้องกันยอดผิดดุลถูกหักล้างจนดูเหมือนสมดุล

เพิ่ม filter `เฉพาะรายการผิดปกติ` ในหน้า Reconciliation สำหรับค้นหาเอกสารที่ไม่มี Journal หรือมี Journal ไม่สมดุลโดยตรง

เพิ่มลิงก์ drill-down จากประเภทเอกสารในรายงานกลับไปยัง DataTable ต้นทางของ Petty Cash, Employee Advance และ Clearing

Acceptance:

- ยอดจัดสรรรวมไม่เกินยอดคงเหลือ
- เงินเกินไม่ทำให้ Open Item ติดลบ และแสดงเป็น unapplied ได้
- Reversal แล้วคงเหลือกลับมาตรงกับก่อนจัดสรร

## Phase 3 — Reports และ Reconciliation (P1)

สถานะ: `[~]` — reconciliation coverage เริ่มแล้ว; รอ report completeness และ export/drill-down QA

- Finance-to-GL reconciliation ครบทุก source type
- Petty Cash และ Employee Advance clearing report แบบหลายรายการ
- Settlement/Allocation report พร้อม drill-down
- ทุก report มี branch, warehouse, date, status filter และ export
- แสดง exception เช่น Journal ขาด, ยอดไม่สมดุล, เอกสารไม่มี source link
- Bank Reconciliation ยังคงอยู่ใน Accounting; Finance แสดงเฉพาะลิงก์/สถานะติดตาม ไม่สร้าง workflow ซ้ำ

ความคืบหน้า: รายงาน Reconciliation เทียบจำนวน source document กับ Journal, ตรวจ Journal ไม่สมดุลรายรายการ, filter เฉพาะ exception และ drill-down ไปยังรายการต้นทางได้แล้ว

เพิ่ม status filter ในรายงาน Employee Advance และรองรับการแสดง `PARTIAL`/`CLEARED` เพื่อให้รายงานติดตามวงจรเอกสารหลังการเคลียร์ได้ครบ

เพิ่ม status filter ในรายงาน Settlement และ filter สถานะวงเงิน `ACTIVE`/`INACTIVE` ในรายงาน Petty Cash พร้อมส่งค่า filter ไปกับ export

เพิ่ม date range และประเภท รับ/จ่ายใน Payment Activity รวมถึง filter Cash/Bank ใน Cash Position พร้อมส่งค่าไปกับ export

เพิ่มรายงาน Settlement และ Allocation แยกต่างหาก พร้อม filter สาขา/ประเภท/วันที่/สถานะ และ drill-down ไปยัง Settlement กับ Open Item

เพิ่ม permission/menu เฉพาะรายงาน Settlement และ Allocation และเพิ่ม filter คลังตาม scope ของผู้ใช้

เพิ่ม MySQL integration readiness test ตรวจ linkage ระหว่าง Settlement, Allocation Intent, Open Item และ warehouse/party scope; ผ่าน `3 tests, 92 assertions`

Acceptance:

- ตัวเลขรายงาน reconcile กับ subledger และ GL ได้
- ทุกแถว drill-down กลับเอกสารต้นทางได้
- จำกัดข้อมูลตามสิทธิ์สาขา/คลัง

## Phase 4 — Controls และ Operational UX (P1/P2)

สถานะ: `[~]` — ดำเนินการ 4 งานตาม scope ที่เลือก; ข้าม Approval Control ตามที่กำหนด

- approval limit ตามวงเงินและประเภทเอกสาร
- แจ้งเตือนเงินทดรองใกล้ครบกำหนด/เกินกำหนด
- exception monitoring สำหรับ posting/reversal/reconciliation
- ตรวจ duplicate document, duplicate import และ invalid mapping ให้ชัดเจน
- ปรับ performance query/cache เมื่อข้อมูลมากขึ้น
- cleanup route/controller/view/reference ที่ไม่ได้ใช้งาน

ความคืบหน้า: เพิ่ม Dashboard control signals สำหรับ Journal หาย/ไม่สมดุล, เงินทดรองครบกำหนดและใกล้ครบกำหนด, รวมถึง duplicate receipt groups โดยจำกัดตาม branch/warehouse และ permission เดิม; ใช้ dashboard cache 30 วินาทีและ DataTable server-side/page length 5 เป็น baseline ของ Performance/UX

หมายเหตุ: Approval Control ยังไม่ดำเนินการตามลำดับความสำคัญที่ผู้ใช้กำหนด

## Test และ Release Gate

- [ ] Unit/contract tests ของแต่ละ phase ผ่าน
- [ ] MySQL integration readiness ผ่าน พร้อมตรวจ idempotency และ reversal
- [ ] Browser QA ครบทุกสถานะ: Draft, Submitted, Approved, Posted, Reversed/Voided
- [ ] Permission และ branch/warehouse isolation ผ่าน
- [ ] ตรวจ Audit Log, Document Sequence และ GL link
- [ ] Owner sign-off สำหรับแต่ละ phase

## ลำดับเริ่มงาน

เริ่มจาก Phase 1 โดยล็อก contract ของ multiple clearing และ outstanding calculation ก่อนแก้ UI จากนั้นจึงเพิ่ม test และรายงานที่ใช้ข้อมูลชุดเดียวกัน
