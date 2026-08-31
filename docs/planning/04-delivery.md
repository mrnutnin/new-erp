# Delivery Plan

## 0. Scalability guardrail

ทุก Phase ต้องผ่านหลัก “เล็กเริ่มง่าย ใหญ่ขยายได้”:

- Small: ค่าเริ่มต้นน้อย, optional module ปิดได้ และไม่บังคับขั้นตอนที่ไม่จำเป็น
- Medium: เปิด approval, batch, รายงาน และ warehouse scope เพิ่มตาม policy โดยใช้ service/ledger เดิม
- Enterprise: รองรับ volume ด้วย server-side query, queue, scheduler, locking และ reconciliation ที่มีหลักฐาน
- ห้ามทำ feature ระดับ Enterprise เป็นเงื่อนไขของ core flow; capability และ readiness ต้องตรวจแยกกัน
- งานใหม่ต้องระบุ safe default, upgrade path และเกณฑ์วัดก่อนเพิ่ม infrastructure หรือ abstraction
- ทุก workflow ต้องมีโหมดทีมเล็ก: ผู้ใช้ 1–2 คนทำงานครบวงจรได้ตาม policy โดยไม่ติด approval chain หรือการมอบหมายที่ไม่มีผู้รับ
- เมื่อเพิ่มการควบคุมสำหรับทีมใหญ่ ต้องเป็น policy/capability ที่เปิดเพิ่มได้ ไม่ทำให้ขั้นตอนพื้นฐานของทีมเล็กซับซ้อนขึ้น

### Small-team review backlog (ต้องตรวจงานเดิมก่อนเปิดใช้จริง)

- ตรวจทุก transition ที่มี `approve`, `submit` หรือ `maker-checker` ใน Finance, Accounting, Purchasing/WMS และ Sales/POS ว่าบริษัทเล็กสามารถกำหนดผู้ใช้คนเดียวให้ทำครบตาม policy ได้ หรือมีทางเลือก self-approval ที่ audit ได้
- ตรวจ Workflow Center และข้อความ blocker ไม่ให้เขียนว่า “ต้องมีผู้อนุมัติครบ” เป็นเงื่อนไขตายตัว หาก approval policy ของบริษัทไม่ได้เปิดใช้
- หากพบ route หรือ service บังคับผู้อนุมัติคนที่สอง ให้เปลี่ยนเป็น policy gate ที่เปิดเพิ่มได้ พร้อมข้อความแก้ไขในหน้า readiness; ห้ามลด audit trail และห้ามอนุมัติข้ามสิทธิ์
- เกณฑ์ปิดรายการ: มี manual QA อย่างน้อยหนึ่ง flow ต่อ moduleด้วยผู้ใช้ 1 คน และอีกหนึ่ง flow ที่เปิด maker-checker สำหรับบริษัทใหญ่

## 11. Delivery roadmap

### Document number reuse and date changes

- The default policy is `NEVER_REUSE`: an issued document number remains reserved even when a draft is deleted or voided.
- Companies may opt into `REUSE_DELETED_DRAFT_ONLY`, but reuse must be performed by a controlled deletion workflow and is forbidden after approval, posting, Journal, Open Item, or financial audit history exists.
- A Draft whose document date changes receives a new number from the target reset period. The former number is retained in `finance_document_sequence_histories` as `SUPERSEDED`; it is never silently erased.
- Posted documents cannot change date or number. Corrections use the domain document/reversal policy instead.
- Number history is written for Sales, Purchasing, and Finance settlement documents so auditors can trace every number transition.

### Automated testing policy

- ตามข้อกำหนดเจ้าของระบบ automated tests เขียนเฉพาะ Unit Test ด้วย PHPUnit; ไม่บังคับ Laravel Feature Test, HTTP test, browser test หรือ integration test
- Unit Test เน้น pure/isolated business logic ที่มีความเสี่ยง: AVG/FIFO calculation, cost allocation, BOM explosion/cycle, UOM/rounding, tax, journal balance/mapping, status transition และ idempotency-key generation
- code ที่ต้องพึ่ง Eloquent/database/queue/filesystem ให้แยก calculation/rule ส่วนที่เหมาะสมออกมาทดสอบแบบ unit โดยไม่สร้าง abstraction เกิน use case
- migration/fresh install, foreign key/unique constraint, transaction/locking/concurrency, route/Form Request/Policy, AJAX/SweetAlert UI, queue/scheduler, GCS และ end-to-end accounting reconciliation ตรวจด้วย repeatable manual QA/release checklist เพราะ Unit Test ไม่สามารถยืนยัน integration เหล่านี้ได้
- CI รัน Unit Test, Pint, fresh migration smoke check และตรวจ local asset manifest/file; smoke/manual checks ไม่เรียกว่า automated integration test
- หากเกิด production defect จาก integration ซ้ำ ให้บันทึก defect และขอ owner อนุมัติก่อนเพิ่ม test ระดับอื่น ห้าม Agent เพิ่ม Feature Test เองโดยไม่เปลี่ยนนโยบายนี้

### Phase 0 — Discovery and foundation decisions

- [ ] ทำ process inventory เทียบ controller/model/report ของ `minterp` กับ 8 MVP modules
- [ ] ยืนยันคำศัพท์ธุรกิจ สถานะเอกสาร และ accounting event matrix ครบทุก recognition/reversal event
- [x] ยืนยัน company-wide AVG/FIFO policy เดียว; operational balance/layer แยก Warehouse เพื่อ scope/reconciliation โดยไม่เป็น policy แยกคลัง
- [ ] ยืนยัน provisional negative-cost settlement, unlimited-open-period backdate และ policy-change procedure
- [ ] ยืนยัน BOQ construction fields, BOM multi-output allocation, substitution approval และ planned/actual production cost
- [ ] ให้ผู้ทำบัญชีไทยทบทวนบัญชี 5 เล่ม, chart template, tax point และรูปแบบรายงานขั้นต่ำ
- [ ] ขอไฟล์ export ตัวอย่างที่ปกปิดข้อมูลจากลูกค้า Express/WinSpeed และกำหนด cutover control totals เพื่อทำคู่มือ mapping เข้า ERP Excel template โดยไม่ผูก importer กับ vendor/version
- [ ] ยืนยัน MySQL version, GCP/on-prem topology และ backup/restore target
- [ ] ขยาย production-volume target จาก baseline >200 concurrent users และ ≥1,000 stock movements: peak/hour, items, warehouses/branches, transfer depth, posting/recost SLA และ queue/shared-lock backend
- [ ] ยืนยัน PHP 8.2 runtime; เครื่องปัจจุบันชี้ไปที่ PHP 7.4 ที่รันไม่ได้และต้องแก้ก่อน scaffold
- [ ] สร้าง data dictionary และ permission matrix รุ่นแรก

**Gate:** decision log ครบ ไม่มีคำหลักที่ตีความต่างกันระหว่าง module

### Phase 1 — Application skeleton

- [x] scaffold Laravel 12, PHP 8.2, database, queue, scheduler และ test environment
- [x] module route/service-provider convention
- [x] Laravel 12 reference Platform use case ที่แสดง PSR-4, Form Request, Eloquent, focused flow rule, migration, route/view registration และ Unit Test ในตำแหน่งมาตรฐาน
- [x] Login → Select Program → Select Warehouse → Dashboard foundation พร้อม session regeneration, assigned-context validation และ middleware recheck
- [x] แยก `Settings` module, company-profile Global Setting ขั้นต้น และ User Management สำหรับค้นหา/แบ่งหน้า/เพิ่ม/แก้ไข/active/program/warehouse assignment พร้อม self-lockout guard
- [x] Settings RBAC, Role/User assignment, permission middleware, branch/warehouse administration และ append-only audit foundation
- [ ] ขยาย branch/warehouse scope และ Policies ไป operational modules เมื่อเริ่มแต่ละ use case
- [ ] Accounting kernel schema: COA, fiscal periods, 5 journal books, entries/lines และ posting contract
- [ ] typed Global Settings registry/resolver, cache invalidation, audit/version/effective-date convention และ readiness check
- [ ] canonical Migration Import batch/staging contract, versioned Excel-template convention, checksum, stable row key, approval/audit และ idempotency โดยยังไม่ผูก vendor format
- [x] Bootstrap 5 shared monochrome/rounded layout, design tokens, `public/css/app.css` และ Blade root/module templates ที่ทุกหน้า reuse โดยไม่มี CSS ราย Blade
- [x] jQuery AJAX action-lock/error contract และ Yajra DataTables v12 User reference screen ที่มี DataTables HTML5 Excel export, pagination, page length, search และ server-side data route
- [ ] pinned `public/vendor` manifest, official unmodified library styles, shared UI initializer/alert adapter และ reference components สำหรับ Select2, Flatpickr, Dropzone, DataTables Buttons และ SweetAlert2 โดยไม่มี vendor asset/CSS override ซ้ำตาม module; ระบบรันด้วย `php artisan serve` โดยไม่ใช้ npm/Vite
- [ ] GCS disk config, Platform `FileStorageService`, attachment metadata/policy และ reference upload/download/delete flow พร้อม local/manual-QA fake disk
- [ ] CI: Composer install, migration, Unit Tests, Pint และ local-asset checksum/HTTP smoke check โดยไม่มี frontend build
- [ ] queue/scheduler health check, failed-job visibility และ reference idempotent after-commit job
- [ ] code review checklist ตรวจชื่อที่สื่อความหมายและ comment ของ accounting/stock/concurrency/security logic ที่อ่านจาก code อย่างเดียวไม่ได้

**Gate:** fresh install จาก empty MySQL database ผ่าน CI, accounting Unit Tests ผ่าน และ manual QA ยืนยัน branch/warehouse permission isolation

### Phase 2 — Global settings and masters

- [ ] ขยาย singleton company/global settings จาก company profile ขั้นต้นให้ครบ PAE/NPAE profile, branches, warehouses, company-wide periods, retention/SLA/license/module settings, sequences, audit/version และ dependency validation
- [ ] party, item, UOM, tax, currency และ accounting masters
- [ ] ERP Excel templates และ staged validate/preview/import สำหรับ masters กับ accounting/stock/asset opening data พร้อม error workbook และ reconciliation
- [ ] approval framework เฉพาะ behavior ที่ PR/PO และ finance ต้องใช้
- [ ] manual GJ, opening balances, 5 journal views, GL และ trial balance รุ่นแรก

**Gate:** master data เพียงพอให้สร้าง transaction โดยไม่ hard-code business ใด และ manual/opening journals ออกรายงาน GL/Trial Balance ที่ balanced

### Phase 3 — WMS inventory kernel

- [ ] stock movement ledger, balance projection, `AVG` engine, FIFO cost layers/allocations, recost และ stock card
- [~] AVG/FIFO pure costing foundation ให้ module อื่น reuse ได้; persisted layers, recost และ GL integration ยังรอ
- [~] เพิ่ม persisted FIFO cost layers และ locked issue allocation พร้อม AVG inventory value fields; policy resolver, recost, negative layers และ GL integration ยังรอ
- [~] เชื่อม AVG receipt/issue กับ Stock Movement Post แบบ trusted unit cost และ balance row lock; FIFO Post adapter ยัง block จนครบ contract
- [~] เชื่อม FIFO receipt/issue กับ Stock Movement Post และเพิ่ม Pending/Recost request foundation; MVP ให้ Queue/Scheduler ครอบคลุมเฉพาะ Recost และงานที่จำเป็นต่อความถูกต้องของ Inventory/GL ส่วน GL/COGS reconciliation และรายงานหนักทั่วไปยังไม่บังคับเป็น background job
- [~] receipt/issue/transfer/adjust/count พร้อม Unit Test ของ allocation/rule และ manual concurrency checklist (Receipt Draft Intent แบบ warehouse-scoped/Yajra/Select2 และ source/item/UOM/date/idempotency validation เริ่มแล้ว; Draft edit, approval/post, inventory costing และ transfer/adjust/count UI ยังรอ)
- [~] issue/issue-return, stock-count/variance และ stock-policy/issue-type foundations ลงแล้ว; ต้องปิด local MySQL integration, FIFO multi-layer return และ wiring issue type ก่อนเปิด operational gate
- [~] Min/Max policy รองรับ item dimension/unique `(warehouse_id,item_id)`, Dashboard alert ที่หัก reserved/open approved PO พร้อมแปลง Purchase UOM → Stock UOM และลิงก์สร้าง PR แบบผู้ใช้ยืนยันแล้ว; เหลือ manual UI/role QA และ bulk prefill
- [ ] Goods Receipt ต้องรองรับ Purchase UOM ที่ต่างจาก Stock UOM โดย snapshot conversion factor ณ วันที่รับ, แปลงจำนวนด้วย decimal-safe arithmetic และคำนวณต้นทุนต่อ Stock UOM ก่อนสร้าง Movement/Cost Layer
- [ ] inventory/WIP/COGS posting และ GL reconciliation ด้วย cost allocations ชุดเดียว
- [ ] paired dispatch/accept/reject pending-transfer cost lineage โดยไม่มี GIT account และ source-adjustment propagation ข้ามหลายสาขา
- [ ] chunked idempotent recost job, scheduler safety net, status/admin retry และ pending-cost close/report gate
  - [x] Recost dispatcher มี bounded batch, `withoutOverlapping`, `onOneServer`, health/SLA และ admin retry; ยังไม่เปิด Recost-to-GL delta จนกว่าจะมี mapping/reversal integration

**Gate:** scenario เดียวกันผ่าน Unit Tests ของ expected AVG/FIFO values และ manual valuation/COGS/GL reconciliation ก่อน module อื่นเขียน stock

### Phase 4 — Commercial operations

- [~] Purchasing flow (PR → PO → Goods Receipt foundation พร้อม; Partial Receipt/Inventory Post integration และ Manual UI sign-off ยังรอ)
- [ ] POS sales flow
- [~] POS inventory-line foundation: มี read-only contract ตรวจ item/UOM/warehouse และคำนวณจำนวน Stock UOM แล้ว; ห้ามเปิด Stock ISSUE/COGS/GL จนกว่าจะมี source document, immutable conversion snapshot, idempotency และ final AVG/FIFO allocation ครบ
- [ ] Logistics delivery flow ขั้นพื้นฐาน
- [ ] `PJ/SJ/CR/CP` posting, AR/AP open items และ tax subledgers จาก commercial flows

ทำ parallel ได้หลัง contract ของ stock movement, party, item และ document number ถูก freeze แล้ว; Sales/POS จะเปิดเฉพาะ service/revenue flow จนกว่า inventory posting gate จะผ่าน

### Phase 5 — Production (optional module)

- [ ] BOQ revision/approval/conversion และ BOM/Recipe versioning, multi-level explosion, cycle validation และ work-order snapshot
- [ ] MTS/MTO, material requirement/reservation/issue/return/accounting-approved substitution, multi-output/by-product receipt, WIP และ planned-vs-actual costing
- [ ] production/WIP/variance posting ผ่าน `GJ`

**Gate:** เมื่อเปิดใช้ Production ต้องใช้ Inventory AVG/FIFO API เดียวกับ module อื่น, BOM revision ย้อนตรวจได้ และ material/WIP/finished goods/variance reconcile กับ GL; ห้ามแก้ stock table โดยตรง หาก Production ปิดอยู่ บริษัทซื้อมาขายไปต้องผ่าน core Purchasing/WMS/Sales/Finance/Accounting flow ได้โดยไม่ติด gate นี้

### Phase 6 — Finance and Accounting

- Finance แยกเป็น Module สำหรับธุรกรรมรับ/จ่ายและ subledger; ใช้ Accounting posting contract เป็นช่องทางลง GL กลาง และทุกเมนูใหม่ต้องเพิ่ม permission/route middleware/Sidebar visibility พร้อมกัน
- Finance master data ต้องทำก่อน receipt/payment: Bank/Cash Account, Payment Term, Other Income/Expense และ Document Sequence/Format โดยทุกตัวต้องมี permission/audit/soft delete และเชื่อม GL/tax ตามชนิดข้อมูล
- Report catalogue รุ่นแรกกำหนดไว้ใน `docs/planning/02-accounting-inventory.md` ครอบคลุม Accounting/Tax, Finance/AR/AP, Sales/POS และ Inventory; ก่อนปิด module ต้องมี route, permission, scoped query และ manual QA ของตัวรายงาน โดยไม่รวม QA ไฟล์ Export ใน MVP
- Manual UI sign-off และ Production operational sign-off ของ Recost, Inventory Reports และ Period Close เป็น final pre-release/deployment gate ทำภายหลังสุด ไม่เป็น blocker ของการพัฒนา local MVP และไม่ควรหยุดการพัฒนา module อื่นระหว่างนี้
- [ ] Finance open items, cash/bank, receipt/payment และ reconciliation
- [~] Payment Voucher line allocation → Settlement contract ถูกกำหนดแล้ว; implementation รอ voucher line snapshot และ advance/deposit subledger gate
- [ ] Accounting tax reports, 5 journal reports, GL, trial balance, P&L และ balance sheet
- [ ] period close/reopen, reversal, comparative reports และ control-account reconciliation
- [ ] posting adapters และ accounting event matrix ครบทุก operational module

Wave 2 invoice integration ถูก lock ไว้ดังนี้:

- [x] แยก Sales/Purchase document services และใช้ `DRAFT -> APPROVED -> POSTED`; Void ได้เฉพาะเอกสารที่ยังไม่ Post
- [x] เพิ่ม typed mappings `SALES_AR`, `SALES_REVENUE_DEFAULT`, `PURCHASE_AP`, `PURCHASE_EXPENSE_DEFAULT` ก่อนเปิด Post
- [x] เพิ่ม sequence types `SALES_INVOICE`, `SALES_CREDIT_NOTE`, `PURCHASE_INVOICE`, `PURCHASE_CREDIT_NOTE` และ issued-document guard ให้ครบทุก domain
- [x] ส่งมอบ service/expense invoice และ credit note แบบ `NONE_VAT`, Purchase `VAT_IN` และ Sales `VAT_OUT` (ลง Deferred VAT); credit note สร้าง/allocate contra Open Item กับ invoice ต้นทาง
- [x] Settlement รับ/จ่ายแบบ `NONE_VAT`, `WHT=0` ลง Journal + Receipt/Payment Open Item และจัดสรรตาม intent แบบ atomic/idempotent
- [x] เพิ่ม typed Account Mapping สำหรับ deferred/actual VAT และ WHT (`DEFERRED_INPUT_VAT`, `DEFERRED_OUTPUT_VAT`, `INPUT_VAT`, `OUTPUT_VAT`, `WHT_RECEIVABLE`, `WHT_PAYABLE`); เปิดเฉพาะ Purchase VAT_IN ที่ลง Deferred Input VAT ส่วน VAT/WHT realization ยังรอ
- [x] เพิ่ม pure VAT realization calculator สำหรับ partial allocation และ final rounding remainder พร้อมเชื่อม Finance Settlement allocation
- [x] เพิ่ม Tax Snapshot ใน Finance Open Item และ immutable `finance_tax_realizations` ledger ต่อ Allocation; เชื่อม Journal realization ใน Settlement POST แล้ว
- [x] เพิ่ม Journal-line builder สำหรับ Deferred VAT → Actual VAT พร้อม Tax Point/Settlement Date และเชื่อมใน Settlement Journal
- [x] เพิ่ม Purchase line tax snapshot schema/calculator สำหรับ VAT IN แบบ inclusive/exclusive และเปิด Purchase VAT_IN POST ด้วย Deferred Input VAT สำหรับ expense/service
- [x] เพิ่ม pure WHT realization calculator สำหรับ partial allocation และ final rounding remainder; ยังไม่เชื่อม posting จน source document มี WHT snapshot ครบ
- [x] เพิ่ม WHT snapshot columns สำหรับ Sales/Purchase lines และ Finance Open Item พร้อม Tax Code WHT แบบ Select2 AJAX และ validation ฐาน/อัตราบน Invoice; Credit Note ยังปิดไว้
- [x] เพิ่ม WHT snapshot/realization ใน OpenItem และ Settlement Journal แบบ partial/final allocation แล้ว; รายงาน WHT และ certificate ยังเป็นงานถัดไป และห้ามใช้ `withholding_amount` รวมของ Settlement เป็นฐานโดยลำพัง
- [x] เพิ่มรายงาน WHT ค่าใช้จ่ายและรายงานภาษีถูกหัก ณ ที่จ่าย จาก `finance_withholding_realizations` แบบ Warehouse-scoped และ human-readable
- [x] เพิ่ม WMS Item/Category master แบบ company scope พร้อม GL account validation และ Select2 AJAX
- [x] เพิ่ม WMS UOM master และ Unit Conversion พร้อม factor validation; stock ledger ยังรอ
- [~] เพิ่ม Immutable WMS Stock Movement Ledger foundation สำหรับ receipt/issue/transfer/adjustment/count พร้อม idempotent intent/post; balance projection และ costing ยังรอ
- [~] เพิ่ม pure Stock Balance calculator สำหรับ Posted movement aggregation และ reserved/available arithmetic; persisted balance projection และ reservation workflow ยังรอ
- [~] เพิ่ม StockBalanceService สำหรับอ่านยอดตาม Warehouse/Item/UOM และ as-of business date; persisted projection และ reservation document ยังรอ
- [~] เพิ่ม persisted WMS balance และ atomic reservation/release foundation; Stock Card UI, consumed reservation และ negative-stock policy ยังรอ
- [~] เพิ่ม Stock Card read-only UI พร้อม Item Select2, as-of filter และยอด On-hand/Reserved/Available แบบ human-readable; valuation/GL reconciliation ยังรอ
- [ ] เพิ่ม tax-report reconciliation (VAT realization และ WHT ledger) ให้ครบก่อนปิดงานภาษี
- [ ] ก่อนเปิด source reversal ต้องรักษา tax dimensions ใน reversal Journal และมี Open Item reversal contract; รอบนี้ใช้ credit note แทน

Accounting kernel เปิดใช้ตั้งแต่ Phase 1; Phase 6 เป็นการทำ integration/reconciliation/reporting/close ให้ครบ ไม่ใช่เริ่มสร้างบัญชีในช่วงท้าย

### Phase 7 — Asset and complete integration

- [ ] asset lifecycle/depreciation/disposal
- [ ] end-to-end scenarios ทั้ง 6 flow
- [ ] dashboard/report ขั้นต่ำสำหรับผู้บริหารและผู้ปฏิบัติงาน

### Phase 8 — Commercial hardening and pilot

- [ ] security review, permission isolation, audit completeness
- [ ] performance/load test ที่ document sequence, stock และ posting
- [ ] costing benchmark สำหรับ AVG/FIFO, transfer chain และ upstream adjustment propagation พร้อม queue lag/lock/memory metrics
- [ ] backup/restore drill, monitoring, health check และ queue failure handling
- [ ] ทดสอบ GCS IAM/Uniform bucket access, signed URL, lifecycle/retention, CORS, orphan cleanup และ restore procedure
- [ ] ขยาย import tool สำหรับ optional open purchase/sales documents และ migration mapping guide จาก `minterp`, Express และ WinSpeed ตาม sample ที่ได้รับ
- [ ] manual pilot cutover/reconcile จาก ERP templates ด้วยข้อมูลตัวอย่าง Express และ WinSpeed รวม invalid row, duplicate retry, rollback before commit, correction after commit และ large-batch cases
- [ ] configurable branding, module/package toggles และ onboarding checklist
- [ ] separate-installation license/subscription lifecycle และ vendor-managed custom fields/templates/branding
- [ ] pilot กับธุรกิจเมทัลชีท แล้วทดสอบกับโรงงานชิ้นส่วน solar cell และบริษัทรับเหมาก่อสร้าง
- [ ] วางแผน PHP 8.3/Laravel 13 ก่อนพ้นระยะ security support ของ Laravel 12

## 12. Agent work packages

| Package | Ownership | Depends on | Deliverable |
|---|---|---|---|
| A — Platform | `Platform`, auth/context, audit, file storage/attachments | Phase 0 | foundation + Unit Tests + manual QA checklist |
| A1 — Settings | `Settings`, company/global settings, users, RBAC/access administration | A | typed settings + administration screens + audit/readiness |
| A2 — Accounting Kernel | `Accounting` COA, 5 books, entries/lines, posting contract, periods | A interfaces + event matrix | balanced/idempotent GL kernel + reports |
| B — UI system | Bootstrap 5 Blade layouts/components, jQuery/AJAX, local vendor library adapters, Yajra DataTables, tokens | A interfaces | reference CRUD/list/upload screen + accessibility checks |
| C — WMS | `Inventory` | A, A2, masters | ledger/costing/GL reconciliation |
| D — Purchasing | `Wms` | A, A2, C | procure-to-receipt + PJ/AP handoff |
| E — POS | `Pos` | A, A2, C | order-to-invoice + SJ/CR handoff |
| F — Production | `Production` | A, A2, C | work-order flow + WIP/GJ costing |
| G — Finance/Accounting Completion | `Finance`, Accounting reports/close | A2 + operational events | subledgers, tax, reconciliation, statements |
| H — Logistics/Asset | `Logistics`, `Asset` | A, A2, C, E/G contracts | delivery, asset lifecycle and posting |
| I — Migration/Integration/QA | generic Excel migration framework/templates, Unit Test fixtures, manual cross-module QA, CI | A/A2 + each domain import contract | staged/idempotent import, cutover reconciliation, repeatable end-to-end checklist and release evidence |

### Parallel-work protocol

1. Agent รับงานหนึ่ง package/use case ที่มี acceptance criteria ชัดเจน
2. ก่อนแก้ shared contract ให้ประกาศผลกระทบและ update planning/decision ก่อน
3. Agent เป็นเจ้าของเฉพาะ directory/module ที่มอบหมาย; shared files เช่น `bootstrap/app.php`, base layout และ CI ต้องมีผู้ประสานหนึ่งราย
4. Commit/handoff ต้องระบุ migration, route, permission, setting, posting event, Unit Test และ manual QA ที่เพิ่ม
5. ห้ามสร้าง temporary compatibility layer เพื่อแก้ conflict โดยไม่บันทึก debt/owner
6. Integration Agent เป็นผู้รวม end-to-end flow ไม่ให้ operational module เขียน workaround ข้าม boundary

## 13. Definition of Done

งานหนึ่ง use case เสร็จเมื่อ:

- acceptance criteria และ allowed status transitions ผ่าน
- authorization ครบทั้ง permission และ branch/warehouse scope
- validation อยู่ที่ trust boundary และข้อความผิดพลาดใช้งานได้
- transaction, lock, idempotency และ decimal precision เหมาะกับความเสี่ยง
- migration rollback/fresh migration ใช้งานได้ และไม่มี manual schema step
- โครงสร้างและชื่อเป็น Laravel 12/PSR-4 convention, shared framework files อยู่ตำแหน่งมาตรฐาน, module เป็น domain grouping เท่านั้น และ legacy-name exception มี mapping/reason
- Controller/Blade/JavaScript ไม่มี business-critical logic; validation/access/CRUD/transaction อยู่ใน Form Request, Policy, Eloquent และ focused service ตามหน้าที่ โดยไม่มี generic BaseController/Repository/Service
- accounting event หรือเหตุผลที่ไม่มี accounting impact ถูกระบุ พร้อม Unit Test ของ balanced/idempotent/reversal rule และ manual reconciliation เมื่อมีผลบัญชี
- inventory-affecting use case ผ่าน Unit Test ของ costing rule ที่รองรับและ manual QA reconcile cost allocation กับ stock valuation/GL
- transfer/recost รักษา cost lineage ถึงทุกสาขาปลายทาง, job retry ไม่ทำยอดซ้ำ และ report/period close ไม่ผ่านเมื่อมี cost run ที่เกี่ยวข้องค้างหรือ failed
- production use case เก็บ BOQ/BOM/work-order revision snapshot, planned-vs-actual material และ source trace ครบ
- Unit Test ครอบคลุม calculation/rule/invariant สำคัญที่แยกทดสอบได้; happy path, forbidden integration และ database behavior อยู่ใน manual QA checklist
- สำหรับ POS/Sales ให้ใช้ Unit Test เป็น gate ระหว่างพัฒนาเป็นหลัก และเลื่อน local smoke test แบบข้าม module ไปไว้ท้ายสุดหลัง Sales Core, migration/seed และ capability ที่เปิดใช้เสร็จ; ไม่รัน smoke ซ้ำทุก feature เว้นแต่ shared contract/schema/route/posting เปลี่ยน
- UI ใช้ shared root layout/components/CSS/tokens, responsive และ keyboard usable; ไม่มี inline `<style>`/`style` หรือ page/module stylesheet ใน Blade และไม่มี CSS override หน้าตา public library
- Blade/jQuery/AJAX/DataTables ใช้ project convention เดียวกัน พร้อม manual QA ของ JSON validation/error/permission paths; DataTable มี Excel, pagination, page length, search และ growing database list ใช้ server-side
- DataTable ใช้ `excelHtml5` ก่อนเสมอ; server-side table export เฉพาะแถวที่ browser โหลดอยู่ ส่วน full-dataset/queued export เพิ่มภายหลังเฉพาะ requirement ที่เจ้าของระบบอนุมัติ
- AJAX action lock ป้องกัน user กดซ้ำและคืนสถานะได้ทุก response path; mutation สำคัญยังมี server idempotency/state protection พร้อม Unit Test ของ rule ที่แยกทดสอบได้
- feedback ใช้ระดับการรบกวนเหมาะสม: field validation/inline status/direct success redirect ก่อน; popup/confirm/toast ที่จำเป็นใช้ SweetAlert2 adapter กลาง ไม่มี native alert/confirm/prompt, popup success ซ้ำซ้อน หรือ success ก่อน server response
- UI ใช้ shared component/baseline public library จาก pinned `public/vendor`; ไม่มี npm/Vite requirement, widget ที่เขียนซ้ำ, CDN รายหน้า หรือ vendor asset ซ้ำตาม module
- ไฟล์ใช้ Platform storage contract, private-by-default access, metadata/policy/audit และ fake-disk manual QA; ไม่มี GCS credential, hard-coded URL หรือ direct bucket call ใน module
- logic ซับซ้อนมี comment อธิบายเหตุผล/invariant/edge case ที่ยังตรงกับ implementation และไม่มี obvious comment หรือ commented-out code
- audit/source reference ทำให้ trace เอกสารย้อนกลับได้
- migration import ใช้ versioned ERP template, stage/validate/approve/commit, checksum/stable row idempotency/audit และ source→stage→ERP reconciliation; commit ผ่าน domain contract และไม่มี direct vendor-database read/write
- ไม่มี business-varying policy/default/threshold/format hard-code ใน module; ใช้ typed Global Setting/resolver พร้อม permission/audit/version และ readiness validation ตามผลกระทบ
- `vendor/bin/pint --test`, Unit Test suite, fresh migration smoke check และ local-asset HTTP smoke check ผ่าน พร้อมแนบผล manual QA ที่เกี่ยวข้อง
- ไม่มี secret, production data, debug endpoint หรือ commented-out implementation
- เอกสารนี้ได้รับการอัปเดตเมื่อ scope/contract/assumption เปลี่ยน

## 14. Key risks and controls

| Risk | Control |
|---|---|
| ยก technical debt จาก `minterp` มาทั้งชุด | trace behavior แล้วเขียนใหม่พร้อม Unit Test/manual QA; ห้าม copy directory wholesale |
| code ใหม่เหมือน legacy `minterp` มากจนไม่ใช่ Laravel 12 หรือแตก pattern คนละแบบจนทีมดูแลยาก | Laravel convention เป็น technical baseline + reference module/code-review checklist; รักษาเฉพาะ business vocabulary/flow และ module/UI familiarity จาก `minterp` |
| แต่ละ module ทำ stock/accounting/costing เอง | Inventory movement/cost allocation และ Accounting posting contract เป็น single write path |
| AVG/FIFO ให้ยอด stock card, COGS และ GL ไม่ตรงกัน | immutable movements/layers/allocations, deterministic recost, Unit Tests และ manual scenario reconciliation ของทั้งสองวิธี |
| แก้ BOM แล้ว work order เก่าเปลี่ยนตาม | approved version + effective date + immutable snapshot ตอน release |
| BOQ/BOM ทำให้จองหรือตัด stock ซ้ำ | definition ไม่กระทบ stock; conversion idempotent และ stock เปลี่ยนผ่าน Inventory service เมื่อ release/issue เท่านั้น |
| transfer ข้ามสาขาแล้วต้นทุนหายหรือถูกสร้างใหม่ | paired dispatch/accept/reject movements + exact company-wide allocation lineage + pending-transfer reconciliation |
| แก้ต้นทุนต้นทางแต่สาขาปลายทาง/production/COGS ไม่เปลี่ยน | dependency graph + earliest-dirty recost request + versioned propagation manual QA A→B→C |
| recost ช้าหรือล็อกตารางเมื่อข้อมูลโต | pool-scoped unique jobs, keyset chunks/checkpoints, indexes, parallel pools และ production-volume benchmark |
| worker/scheduler หยุดแล้วรายงานยังแสดงเป็น final | persisted run status, monitoring, scheduler safety net และ block close/final report เมื่อ pending/failed |
| ทำบัญชีไว้ท้ายโครงการจน source documents post ไม่ได้ | สร้าง Accounting Kernel ใน Phase 1 และ freeze event contract ก่อน operational modules |
| งบตรงแต่รายละเอียด AR/AP/Stock ไม่ตรง | บังคับ control-account reconciliation และ period-close gate |
| ผูก importer กับ format/รุ่นของ Express หรือ WinSpeed จนดูแลไม่ไหว | ใช้ versioned ERP Excel template เป็น canonical format; vendor export มีเพียง mapping guide และเพิ่ม adapter เมื่อมีหลักฐานความคุ้มค่า |
| import opening data บางส่วนแล้ว stock/subledger/GL ไม่ตรง | stage ทั้ง batch, validate control totals/dependencies, approve แล้ว commit ผ่าน domain contracts พร้อม source→stage→ERP reconciliation |
| retry/import ซ้ำสร้าง master, stock movement หรือ opening journal ซ้ำ | file checksum + stable row key/external reference + batch idempotency และ duplicate report |
| import ย้อนกลับด้วยการลบ record ที่ post แล้วทำ audit ขาด | rollback เฉพาะ staged/uncommitted; committed financial/stock data ใช้ reversal/correction หรือ controlled pre-go-live reset |
| รองรับหลายธุรกิจช้าเกินแก้ | configurable masters/settings, vendor-managed extensions และ pilot กับ solar-parts/construction; ห้าม hard-code metal-sheet fields ใน core |
| global settings กลายเป็น key-value ที่ตรวจสอบไม่ได้ | ค่าหลักใช้ typed columns/model; key-value ใช้เฉพาะ optional extension พร้อม validation |
| แต่ละ module hard-code policy/default ของธุรกิจเอง | setting decision rule + typed registry/resolver + code review/DoD + readiness check |
| เปลี่ยน Global Setting แล้วเอกสารเก่า/queue job เปลี่ยนผลตาม | effective-date/version/snapshot + after-commit cache invalidation และ immutable posted history |
| parallel agents แก้ shared files ชนกัน | directory ownership + contract freeze + integration owner |
| การ upload กระจาย logic/URL/credential และเปิดไฟล์สาธารณะโดยไม่ตั้งใจ | Platform storage contract, private GCS, IAM, signed URL, attachment Policy และ audit |
| UI plugin คนละรุ่น/คนละค่าในแต่ละ module | pinned `public/vendor` manifest + root-layout include + component/initializer กลาง และห้ามสำเนา vendor asset |
| CSS ราย Blade ทำให้ UI/UX แต่ละหน้าไม่เหมือนกัน | root template + shared components/design tokens/CSS เท่านั้น; review ห้าม inline/page CSS และแก้ reusable pattern ที่ส่วนกลาง |
| HTML5 export ของ server-side DataTable ได้เฉพาะหน้าปัจจุบัน | แสดงขอบเขตนี้เป็น contract; เพิ่ม backend/queued full-dataset export เฉพาะหน้าที่เจ้าของระบบอนุมัติ |
| เอกสาร/ยอดซ้ำเมื่อ retry | idempotency key, unique source reference และ transaction |
| stock ติดลบหรือ sequence ซ้ำจาก concurrency | row lock + database constraint + manual concurrency/load verification |
| negative stock ทำให้ AVG/FIFO เป็นราคาชั่วคราวแล้วไม่ถูกแก้ | provisional allocation/layer, pending status, receipt-triggered recost และ block period close จน resolve |
| retention global setting ถูกตั้งต่ำกว่ากฎหมาย | validated legal minimum ตาม accounting profile และ permission/audit สำหรับการเปลี่ยนค่า |
| Unit Test อย่างเดียวไม่พบ route/policy/DB/queue/GCS/UI integration defect | repeatable manual QA/release checklist และ production monitoring; เปลี่ยนนโยบาย test เมื่อ owner อนุมัติ |
| PDF เอกสารใช้กับ Dot Matrix ไม่ได้หรือโลโก้ไม่ตรงบริษัท | ทำ shared print/PDF renderer พร้อม A4/Dot Matrix profiles, company-logo setting และ manual print QA แยกอุปกรณ์ |
| Laravel 12 อายุ support สั้น | จำกัด MVP ที่ PHP 8.2 และกำหนด PHP 8.3/Laravel 13 upgrade gate ก่อน commercial rollout ระยะยาว |

## 15. Decisions still requiring owner confirmation

สิ่งเหล่านี้ไม่ขวางการเขียน foundation แต่ต้องยืนยันก่อน module ที่เกี่ยวข้องเริ่ม:

- MySQL version และ topology ของ GCP/on-premise แต่ละแบบ
- chart/report/disclosure differences ที่ต้องส่งมอบใน PAE และ NPAE profile
- รายการเอกสาร/แบบฟอร์มภาษี, tax point, VAT/withholding cases และ e-Tax/e-Withholding scope
- chart of accounts template, recognition point, tax point และเอกสารที่ต้อง post เข้าแต่ละสมุด
- role/maker-checker matrix ภายในฝ่ายบัญชีสำหรับ Manual Journal, reopen, cost adjustment และ period close
- provisional cost fallback เมื่อ negative stock: current average, last known cost หรือ standard cost และวิธีจัดการถ้ายังติดลบตอนสิ้นงวด
- ความหมาย/field ของ lot, serial, expiry และ warranty เมื่อ user เลือกใช้แบบ optional
- BOQ construction ต้องเพิ่ม equipment/subcontract line หรือไม่; BOM multi-output/by-product ใช้ allocation weight/rate แบบใด
- mapping และความหมายของเอกสาร `ใบขอราคา`, `HS` และ `IV` เทียบกับ route/accounting event เดิมใน `minterp`
- campaign promotion ที่ซับซ้อน (โปรโมชั่นต่อรายการ/ท้ายบิล, stacking policy และ approval threshold พร้อมแล้ว)
- approval matrix และเอกสารที่ต้องใช้ digital signature
- format/numbering ของเอกสารเดิมที่ต้องรักษาเพื่อ migration จาก `minterp`
- รายการ migration MVP ที่ต้องรับนอกเหนือจาก masters/opening balances/open items เช่น historical transactions, open PR/PO/SO/WO, cheque และ tax documents ค้าง
- cutover date, source control-total/sign-off form, ผู้ approve import และนโยบายเก็บ archive จาก Express/WinSpeed
- representative anonymized Express/WinSpeed exports สำหรับทำ mapping guide และตัดสินภายหลังว่ามีรูปแบบใดซ้ำมากพอให้ทำ optional converter
- ผู้รับผิดชอบและ sign-off process ของ manual QA/release checklist
- retention legal minimum ต่อชนิดข้อมูล, subscription/license validation สำหรับ on-premise ที่อาจออกอินเทอร์เน็ตไม่ได้ และ exact peak/hour + posting/recost SLA thresholds
