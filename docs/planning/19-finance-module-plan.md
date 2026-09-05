# Finance Module Development Plan

## Objective

พัฒนา Finance ให้เป็นศูนย์กลางธุรกรรมเงินสด ลูกหนี้ เจ้าหนี้ การรับ–จ่าย และ Subledger โดยส่งผลกระทบไปยัง Accounting ผ่าน Posting Contract ที่ตรวจสอบย้อนกลับได้

Finance เป็นเจ้าของเอกสารปฏิบัติการและยอดคงค้าง ส่วน Accounting เป็นเจ้าของ GL, Journal, Fiscal Period, Tax Report และงบการเงิน

## Current state

มีโครงสร้าง Finance แล้ว ได้แก่:

- Finance Dashboard และ Workflow Center
- AR/AP Open Items และ Aging
- Settlement รับเงิน/จ่ายเงิน พร้อม Approve, Post, Void และ Reverse
- Payment Voucher และการส่งต่อไป Settlement
- Advance Deposit foundation
- Bank/Cash Accounts
- Payment Terms และ Other Income/Expense Categories
- Commission Payout และ Payment Request
- Payment Activity Report
- VAT/WHT realization และ Account Mapping integration

Accounting ที่เชื่อมต่ออยู่แล้ว:

- Chart of Accounts
- Journal Entry / Journal Approval / Journal Reverse
- Journal Book
- Fiscal Year / Period Close
- General Ledger, Trial Balance และ Financial Reports
- Bank Reconciliation และ AR/AP Reconciliation

## Priority and scope

| Priority | Feature | เป้าหมาย |
|---|---|---|
| P0 | Finance Dashboard | เห็นสถานะเงินสด AR/AP และรายการค้างดำเนินการจากหน้าเดียว |
| P0 | Petty Cash | ควบคุมเงินสดย่อย ตั้งวงเงิน เบิกจ่าย เติมเงิน เคลียร์ และกระทบยอด |
| P0 | Employee Advance | เบิกเงินทดรอง เคลียร์ค่าใช้จ่าย คืนเงิน หรือเบิกเพิ่ม พร้อมยอดคงเหลือ |
| P1 | AR/AP hardening | ปิดช่องว่าง allocation, due date, reversal และ reconciliation |
| P1 | Cash and Bank Operations | โอนเงินระหว่างบัญชี ตรวจยอด และเชื่อม Bank Reconciliation |
| P1 | Finance Reports | เพิ่มรายงานเงินสด สถานะชำระ และยอดค้างที่ใช้ปฏิบัติงานจริง |
| P2 | Finance controls | Approval policy, audit, limits, period gate และ exception monitoring |

## Phase 1 — Finance Dashboard (P0)

### Sections

- Cash/Bank balance ตามสาขาและคลัง
- เงินสดย่อยคงเหลือและรายการที่ต้องเคลียร์
- เงินทดรองพนักงานคงค้างและเกินกำหนด
- AR/AP outstanding และ Aging summary
- รายการรับ–จ่ายที่รอ Post
- Payment Voucher ที่รอ Submit/Approve/Settle
- Quick links ไปยังรายการที่ต้องดำเนินการ

### Requirements

- โหลดข้อมูลเป็น section แยกกัน เพื่อไม่ให้ Dashboard query หนักครั้งเดียว
- ใช้ server-side endpoint หรือ service summary ที่มี scope เดียวกับผู้ใช้
- แสดง Empty, Loading, Error และ Permission state ครบ
- ทุกตัวเลขต้อง drill-down ไปยังเอกสารต้นทางได้
- ไม่สร้างตัวเลขทางบัญชีซ้ำ ให้ใช้ Finance subledger และ Accounting report ตามขอบเขต

## Phase 2 — Petty Cash (P0)

> Foundation + workflow + backend API (2026-09-04): มี Fund/Voucher/Line schema, `PettyCashVoucherService`, warehouse-scoped JSON/Yajra API, หน้าตั้งค่า “วงเงินสดย่อย” แยกจากธุรกรรมและลบได้เฉพาะเมื่อไม่เคยถูกอ้างอิง, route/RBAC และ document sequence `PETTY_CASH` แล้ว. Top-up มี `PettyCashTopUpService`, route/RBAC, warehouse-scoped Yajra DataTable, Excel export และ AJAX UI แล้ว. Clearing มี schema/model และ workflow Draft → Submit → Approve → Void, คำนวณ expected จาก Top-up/Voucher ที่ Post แล้ว, เก็บ actual/variance/reason, Audit Log, RBAC, sidebar และ Yajra/Excel UI แล้ว. มี posting policy `petty_cash_clearing` (GENERAL) สำหรับ `PETTY_CASH_VARIANCE_GAIN` และ `PETTY_CASH_VARIANCE_LOSS`; การลง Journal จะเปิดในขั้นถัดไปหลังตรวจ mockup workflow.

### Functional scope

- สร้างกองเงินสดย่อยแยกตามบริษัท/สาขา/คลัง/ผู้ดูแล
- กำหนดวงเงินตั้งต้น วงเงินคงเหลือ และ GL mapping
- เติมเงินสดย่อยจาก Bank/Cash account
- สร้างใบเบิกเงินสดย่อยพร้อมหมวดค่าใช้จ่าย ผู้รับเงิน และเอกสารแนบ
- รองรับหลายรายการค่าใช้จ่ายในเอกสารเดียว
- เคลียร์เงินสดย่อยด้วยใบสำคัญและยอดจริง
- รองรับเงินเหลือคืน และกรณีเบิกเกิน/จ่ายเพิ่ม
- ปิดรอบและกระทบยอดยอดตามบัญชีจริงกับยอดในระบบ
- Workflow: Draft → Submitted → Approved → Paid → Cleared / Voided

### Accounting boundary

- Finance เก็บ Petty Cash ledger และ source document
- Accounting รับ Journal จากการเติมเงิน จ่ายค่าใช้จ่าย เงินคืน และส่วนต่าง
- ห้ามให้ผู้ใช้สร้าง Journal แทน Petty Cash โดยไม่มี source document
- Posted แล้วแก้ไขด้วย reversal/adjustment เท่านั้น

### Controls

- ห้ามจ่ายเกินยอดคงเหลือ เว้นแต่มีสิทธิ์และเหตุผลตาม policy
- ห้ามเคลียร์ยอดเกินยอดที่เบิกโดยไม่มีการคืนหรืออนุมัติเบิกเพิ่ม
- ป้องกัน duplicate posting และ duplicate attachment
- รองรับ period close และ branch/warehouse authorization

## Phase 3 — Employee Advance (P0)

### Functional scope

- สร้างคำขอเงินทดรองผูกกับ Employee/ผู้รับเงิน
- ระบุวัตถุประสงค์ จำนวนเงิน วันที่ต้องคืน และ Cost Center/Project ถ้ามี
- Workflow: Draft → Submitted → Approved → Paid → Clearing / Returned / Closed
- สร้างรายการเคลียร์ค่าใช้จ่ายหลายบรรทัดพร้อมเอกสารแนบ
- รองรับสามกรณี: ใช้ครบ, คืนเงินเหลือ, ค่าใช้จ่ายเกินและขอเบิกเพิ่ม
- แสดงยอดเงินทดรองคงค้างรายบุคคลและรายสาขา
- แจ้งเตือนรายการใกล้ครบกำหนดและเกินกำหนด
- รองรับยกเลิกก่อนจ่าย และ reversal หลัง Post

### Accounting boundary

- Finance เป็นเจ้าของ Employee Advance subledger และ outstanding balance
- Accounting รับ Journal ตอนจ่ายเงินทดรอง ตอนเคลียร์ค่าใช้จ่าย และตอนคืนเงิน
- ค่าใช้จ่ายจะเข้าบัญชีเมื่อมีเอกสารเคลียร์ที่อนุมัติแล้ว ไม่ใช่ตอนเบิกเงินทดรอง
- ต้อง link Employee Advance → Settlement/Payment Voucher → Journal → Clearing document

### Controls

- พนักงานต้องไม่สร้างหรืออนุมัติรายการของตนเองตาม approval policy
- ห้ามมีเงินทดรองค้างเกิน policy โดยไม่แสดง warning/exception
- ยอดเคลียร์รวมกับยอดคืนต้องเท่ากับยอดที่จ่าย เว้นแต่มี approved adjustment
- ป้องกันการนำเงินทดรองรายการเดียวกันไปเคลียร์ซ้ำ

## Phase 4 — AR/AP and Cash hardening (P1)

- ตรวจ allocation ให้รองรับ partial allocation, overpayment และ unapplied cash
- แสดง due date จาก Payment Terms และจัดลำดับรายการค้างชำระ
- รองรับ settlement reversal ที่คืนยอด Open Item และ VAT/WHT realization อย่างถูกต้อง
- เพิ่ม internal transfer ระหว่าง Bank/Cash Accounts พร้อมสองฝั่งของรายการ
- เชื่อม Finance cash movement กับ Bank Reconciliation
- เพิ่ม reconciliation status และ exception reason ที่ตรวจสอบย้อนหลังได้

## Phase 5 — Finance Reports (P1)

- Cash Position รายวัน/ช่วงเวลา
- Expected Collection และ Expected Payment
- AR/AP Outstanding Summary
- Aging Detail พร้อม bucket และ drill-down
- Petty Cash Outstanding/Clearing Report
- Employee Advance Outstanding/Clearing Report
- Payment Voucher Status Report
- Settlement and Allocation Report
- Finance-to-GL Reconciliation Summary

ทุกรายงานต้องมี branch/warehouse/date/status filter, permission scope, export ที่เหมาะสม และลิงก์กลับเอกสารต้นทาง

## Shared requirements

- ใช้ `finance.*` route และ permission namespace
- ใช้ branch/warehouse/company scope เดิม
- ใช้ Global Document Sequence
- ใช้ DataTable server-side สำหรับรายการจำนวนมาก
- ใช้ Ajax form ตาม pattern ของระบบ
- ทุก Post ต้อง atomic, idempotent, retry-safe และเขียน audit log
- เอกสาร Posted ห้ามแก้ทับ ให้ใช้ reversal หรือ adjustment
- ไม่ให้ Finance query หรือเขียน GL โดยตรงนอก Posting Contract
- ไม่สร้างเมนูรายได้/รายจ่ายอื่นเพิ่มนอกเหนือจาก Category และ Source Document ที่จำเป็น

## Test gates

- Dashboard section loading, permission และ scope isolation
- Petty Cash balance, top-up, payment, clearing, return และ over-limit guard
- Petty Cash duplicate Post, reversal, period close และ reconciliation
- Employee Advance approval, self-approval guard และ employee scope
- Employee Advance full clearing, partial clearing, refund และ additional advance
- Finance source document → Journal → Open Item/Subledger linkage
- VAT/WHT treatment และ rounding ตาม Global Settings
- AR/AP allocation, reversal, idempotency และ concurrent update
- Bank transfer และ Bank Reconciliation linkage
- Permission, audit, document sequence และ branch/warehouse scope

## Checklist

- [x] Finance Dashboard P0 — asynchronous summary, posted cash trend, AR/AP aging, work queue, and scoped Yajra recent activity with section permissions (2026-09-04)
- [x] ยืนยัน Finance ownership และ boundary กับ Accounting: Finance เป็นเจ้าของ source document/subledger; Accounting เป็นเจ้าของ GL, period และ financial reports
- [x] สร้าง Finance Dashboard summary contract และแบ่งโหลดเป็น sections
- [x] เพิ่ม Dashboard cards, empty/loading/error state และ drill-down
- [x] ออกแบบ Petty Cash schema และ immutable voucher contract
- [x] สร้าง Petty Cash workflow service, backend API, RBAC และ UI/DataTable (Draft → Submit → Approve → Post → Reverse/Void)
- [x] รองรับหลายวงเงินสดย่อยต่อบัญชีเงินสดเดียวกัน โดยแยกยอดตาม `petty_cash_fund_id` และผู้ดูแล/หน่วยงาน
- [x] Petty Cash posting/reversal และ clearing posting ผ่าน `JournalPostingService` พร้อม audit/idempotency/period gate
- [x] Employee Advance workflow, permission, Post GL/Reversal และ Clearing Post/Reversal (รองรับ full clearing ในรอบแรก)
- [~] Employee Advance partial/multiple clearing, refund/additional advance edge cases และ self-approval policy hardening
- [~] เชื่อม Petty Cash/Employee Advance กับ Accounting Journal ผ่าน Posting Contract; ต้องเก็บ integration/reconciliation evidence เพิ่ม
- [~] เพิ่ม AR/AP allocation และ reversal hardening (partial, overpayment, unapplied)
- [x] Bank Reconciliation workflow มีอยู่ใน Accounting พร้อมนำเข้า CSV, จับคู่ Journal และยืนยันกระทบยอด; Finance ไม่สร้าง workflow ซ้ำ
- [x] เพิ่ม internal transfer ระหว่าง Bank/Cash Accounts และเชื่อมผลกับ Bank Reconciliation ผ่าน Journal กลาง พร้อม document sequence, audit, RBAC และ UI
- [x] Finance operational reports: มีรายงานหลักรวม Cash Position และ Expected Collection/Payment พร้อม branch/warehouse/date scope
- [x] Finance unit/contract tests ชุดปัจจุบันผ่าน `68 tests / 356 assertions`
- [~] เพิ่ม Feature/MySQL integration readiness สำหรับ Finance reports, Internal Transfer, Petty Cash, Employee Advance, Top-up และ Clearing แล้ว (`6 tests / 53 assertions` ผ่าน); ยังเหลือ integration workflow อื่นและ browser manual QA ตาม test gates
- [~] ตรวจ UX/UI, DataTable, Ajax, performance และ permission: pattern หลักผ่านแล้ว เหลือ manual QA ทุกหน้า
- [x] อัปเดต `docs/planning/06-core-feature-menu-checklist.md` หลังจบแต่ละ phase
- [ ] Clean up ไฟล์ route/controller/view/model/reference ที่ไม่ได้ใช้แล้ว
- [ ] Owner ทดสอบ flow จริงและอนุมัติ release readiness

## Recommended next step

งานถัดไปที่แนะนำ: ปิดด้วย Feature/MySQL integration และ owner manual QA ทุก report และ workflow

> Phase 1 Dashboard (2026-09-04): เพิ่ม dashboard แบบ section loading แยก `summary`, `cash-trend`, `aging`, `work` และ `activities`; cache aggregate 30 วินาทีตามผู้ใช้/สาขา/คลังที่ได้รับสิทธิ์, ใช้ยอด Open Item หลังหัก allocation/advance application, Trend นับเฉพาะ Settlement `POSTED`, และ Recent Activity เป็น Yajra server-side DataTable พร้อม Excel export. ผ่าน contract tests 2 tests / 20 assertions, syntax, route, Blade cache และ diff check.

> Petty Cash Phase 3 (2026-09-04): เพิ่ม warehouse-scoped controller API, Yajra DataTable, Fund create/update/deactivation guard, routes, RBAC (`finance.petty-cash.*`), sidebar และ responsive AJAX UI สำหรับ Fund/Voucher; top-up/clearing และ reconciliation ยังไม่เริ่มใน Phase นี้.

> Petty Cash Top-up foundation (2026-09-04): เพิ่มเอกสารเติมเงินแยกจาก Voucher, warehouse-scoped, state `DRAFT → SUBMITTED → APPROVED → POSTED → REVERSED/VOID`, snapshot บัญชี BANK ต้นทางและ CASH ปลายทาง, sequence/audit/idempotent Journal post/reverse และ period-close gate ผ่าน `JournalPostingService`. ใช้ event `petty_cash_top_up` เพราะ `expense_payment` เป็นค่าใช้จ่าย ไม่ใช่การโอนเงิน; UI/RBAC/route และ clearing/reconciliation ยังไม่เริ่ม.

> Employee Advance Phase 2 (2026-09-05): เพิ่ม additive central Posting Contract `employee_advance`/role `EMPLOYEE_ADVANCE`, mapping บัญชีสินทรัพย์ 12600, atomic/idempotent Post GL, reversal, Audit Log, ดู GL และ RBAC/action routes; clearing/refund workflow ยังไม่เริ่ม.

> Employee Advance Clearing Phase 3 (2026-09-05): เพิ่ม sequence `EMPLOYEE_ADVANCE_CLEARING` (`EAC`), mapping `employee_advance_clearing`/`EMPLOYEE_ADVANCE` ไปบัญชี 12600, คำนวณ VAT/WHT snapshot, เงินคืน/เบิกเพิ่ม และ Post/Reversal แบบ atomic ผ่าน contract กลาง; รองรับ workflow เคลียร์เต็มใบในรอบแรก.
