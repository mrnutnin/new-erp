# New ERP Checklist

อัปเดตล่าสุด: 9 กันยายน 2026

ไฟล์นี้เป็น dashboard สถานะงานสำหรับเจ้าของระบบและ Agent ทุกตัว รายละเอียด architecture และเงื่อนไขการพัฒนาอยู่ใน `PLANING.md`, `SKILL.md` และ `docs/planning/`

เช็กลิสต์เมนูและความสามารถหลักราย Module: [`docs/planning/06-core-feature-menu-checklist.md`](docs/planning/06-core-feature-menu-checklist.md)

## สัญลักษณ์

- [x] เสร็จและตรวจสอบแล้ว
- [ ] ยังไม่เริ่มหรือยังไม่เสร็จ
- [~] กำลังทำ
- [!] มี blocker หรือรอการยืนยัน

> หมายเหตุการอ่านสถานะ: รายการ Inventory/GL ที่มีคำว่า `foundation`, `adapter`, `contract` หรือ `service` ว่าเสร็จแล้ว หมายถึง implementation และ Unit contract เสร็จแล้ว ส่วน `[~]` ที่ยังเหลือหมายถึง integration verification, migration/seed, reconciliation evidence หรือ owner release sign-off ไม่ใช่การเริ่มพัฒนาใหม่
>
> นโยบายการปล่อยระบบ: การพัฒนา, migration, seed, integration smoke และ Manual UI Sign-off ทำบน local `new_erp` ต่อไปก่อน ส่วน Production operational sign-off จะทำครั้งเดียวหลัง module ที่อยู่ในขอบเขต MVP พร้อมครบทั้งหมดเท่านั้น ไม่ทำแยกเป็นราย module ระหว่างพัฒนา

## Foundation — สถานะปัจจุบัน

### Project และ UI พื้นฐาน

- [x] Laravel 12 / PHP 8.2 / MySQL `new_erp`
- [x] รันระบบด้วย `php artisan serve` โดยไม่ใช้ npm/Vite/frontend server
- [x] Bootstrap 5, jQuery, Select2 และ SweetAlert2 แบบ shared root layout
- [x] UI โทนขาว–ดำแบบ Glassmorphism คลีน โค้งมน และใช้ Bootstrap classes ก่อนเขียน CSS เพิ่ม
- [x] เพิ่ม semantic accent colors แบบ subtle สำหรับ badge/status/icon และ secondary action โดยคุม contrast และใช้ shared tokens
- [x] Badge ใช้ pastel/soft fill และตัวอักษรเข้มอ่านง่ายตาม semantic tokens; ห้ามพื้นเข้มจัด/neon/gradient
- [x] Boxicons 2.1.4 ผ่าน CDN เป็น icon family เดียว
- [x] Backoffice shell ใช้ Sidebar ชิดซ้ายเต็ม viewport และ workspace แบบ fluid สำหรับ DataTable
- [x] ทุก Module เข้า Dashboard ก่อนเสมอ และ Sidemenu วางกลับหน้าเลือกโปรแกรมไว้บนสุด
- [x] Sidebar จัด Group เมนูตาม workflow/ข้อมูลหลัก/การตรวจสอบ และซ่อน Group ที่ไม่มีเมนูตามสิทธิ์
- [x] Workflow Center ของ Settings, Purchasing/WMS, Finance และ Accounting มี cards, compact mapping, readiness และ next action; ผู้ใช้ทุกคนใน Program เข้าอ่านได้ ส่วน action ปลายทางยังตรวจ permission
- [~] Workflow Center แยกโหมด “เริ่มใช้งานครั้งแรก” และ “งานประจำวัน” พร้อม Bootstrap tabs และ mode metadata ใน Settings/Purchasing-WMS/Finance/Accounting/Sales-POS แล้ว; Production/Logistics/Asset มี catalog mode metadata และ blocker/recovery contract แล้ว แต่ module shell/UI ยังรอ
- [x] Beginner-friendly ERP UX contract: ใช้คำอธิบายภาษางานจริง, guided next action, readiness/blocker พร้อมวิธีแก้, safe defaults และไม่ให้ผู้ใช้ต้องจำลำดับเอกสารหรือรหัสบัญชีเอง
- [x] Scalable ERP UX/architecture contract: บริษัทเล็กเริ่มด้วย capability ขั้นต่ำได้ บริษัทกลาง/ใหญ่เปิด policy เพิ่มได้ โดยใช้ server-side/queue สำหรับ volume และไม่บังคับ Production หรือขั้นตอนระดับ Enterprise
- [x] Small-team operations contract: แต่ละแผนกทำงานได้ด้วยพนักงาน 1–2 คน มี safe default/ลดงานซ้ำ และ approval chain ปรับตาม policy โดยไม่สร้างผู้อนุมัติปลอม
- [~] Small-team approval audit: ตรวจ transition/Workflow Center ของ Finance, Accounting, Purchasing/WMS และ Sales/POS ไม่ให้บังคับผู้อนุมัติคนที่สองเมื่อ policy ไม่ได้เปิด; เพิ่ม manual QA Inventory/Trading สำหรับ Receipt Draft, blocker ก่อน Cost Layer/GL และ recovery ของ Posted แล้ว แต่การทดสอบฐานข้อมูลจริงและ maker-checker ยังรอ
- [x] Human-error recovery contract: error ต้องบอกจุดผิด/วิธีแก้/เมนูที่เกี่ยวข้อง; Draft/Approved ใช้ transition ที่ปลอดภัย; Posted ห้ามแก้ทับและต้องใช้เอกสารแก้ไขหรือ reversal พร้อม audit/idempotency
- [~] Workflow Center ของ Sales/POS, Production, Logistics และ Asset ใช้ Compact Mapping UI แบบเดียวกัน (Sales/POS เสร็จแล้ว; Production/Logistics/Asset มี catalog contract แล้ว แต่ UI จะทำพร้อม module shell)
- [x] กติกาเมนูใหม่: เพิ่ม permission ใน RbacSeeder, route middleware และ Sidebar visibility พร้อมกันทุกครั้ง; ต้องผูก permission ใหม่กับ role `admin` ใน Seeder เดียวกันเสมอ และตรวจยืนยันในฐานข้อมูล local ก่อน handoff
- [~] แยก Purchasing ออกจาก WMS: เพิ่ม `app/Modules/Purchasing` provider, canonical `/purchasing` routes และ Sidebar แล้ว; Supplier/PR/PO/AP/GR/PDF มี module-aware controller + route/view seams ใน namespace Purchasing และหน้า canonical ใช้ adapter ที่ยัง reuse implementation เดิมเพื่อป้องกันกฎซ้ำ; ต้องย้าย Request/Service/View เป็นราย flowก่อนลบ legacy routes
- [x] AJAX form ป้องกันกดซ้ำและแสดง validation ข้าง field
- [x] CRUD form ใช้ jQuery AJAX และ SweetAlert2 แสดงผลจาก Controller contract `status`/`msg`
- [x] Shared delete action ยืนยันด้วย SweetAlert2, ป้องกันกดซ้ำ และ reload DataTable เมื่อสำเร็จ
- [x] Page-specific jQuery/DataTable scripts ของ Settings อยู่ท้าย Blade เดียวกันใน `@push('scripts')`
- [x] Shared `erpAjaxForm()` รองรับ page options `url`, `method`, `reload`, `redirect`, `alert`; Update ไม่ reload โดยค่าเริ่มต้น
- [x] Shared `erpAjaxDelete()` พร้อม delete permissions, SoftDelete, audit และ domain guards สำหรับ User/Role/Branch/Warehouse
- [x] กติกา Select: รายการขนาดใหญ่ใช้ Select2 AJAX พร้อม search/pagination/debounce และ scope; native select ใช้เฉพาะรายการเล็ก/คงที่
- [x] Select2 AJAX implementation: Journal Entry GL, Account parent, Bank/Cash GL, Other Income/Expense GL, Customer, Supplier, Receipt/Payment party/open-item และ Item GL accounts

### Authentication และ Context

- [x] Login ด้วย username/password
- [x] Select Program
- [~] เปลี่ยน operational context จากเลือกคลังเป็นเลือกสาขา: เอกสารขาย/ซื้อบันทึก `branch_id` เสมอ, รายการที่กระทบสต็อก/ส่งมอบบันทึก `warehouse_id` และต้องอยู่ใต้สาขาเดียวกัน; ติดตาม Phase 0–5 ใน [`docs/planning/09-branch-context-migration-plan.md`](docs/planning/09-branch-context-migration-plan.md)
- [x] Settings program ข้ามการเลือก Warehouse เพราะเป็น company scope
- [x] Middleware ตรวจ program/warehouse assignment ซ้ำทุก request
- [~] เก็บ Branch context ข้าม Program และแสดงสาขา/คลังปัจจุบันใน top bar; การเลือกสาขาเป็น context หลักและการเลือกคลังเป็นบริบทย่อยกำลังปรับตามกติกากลาง
- [x] Seed admin สำหรับ local development (`admin` / `123132123`)
- [ ] Forgot/reset password flow
- [ ] Password/session policy จาก Global Settings

### Settings Module

- [x] แยก `Settings` ออกจาก `Platform`
- [x] Company Setting ขั้นต้น: ชื่อบริษัท, Tax ID, locale, timezone, base currency และ date format
- [x] User Management ขั้นต้น: เพิ่ม/แก้ไข/active, program และ warehouse assignment
- [x] Eloquent + SoftDeletes สำหรับ User, Branch, Warehouse และ Program
- [x] Delete master ใช้ SoftDelete เท่านั้น พร้อม guard ตามประวัติ/ความสัมพันธ์; ไม่มี hard-delete action
- [x] เพิ่ม Role assignment ใน User Management และ self-lockout guard
- [x] Branch Management พร้อม guard ห้ามปิดสาขาที่ยังมี active warehouse
- [x] Warehouse Management และตรวจ active branch
- [~] Typed settings: PAE/NPAE, AVG/FIFO, negative stock, fiscal period, VAT/WHT, document sequence, SLA และ retention
- [x] Settings registry/resolver, cache invalidation, version snapshot, effective date และ readiness validation

### RBAC และ Audit

- [x] Role/Permission schema และ Eloquent models
- [x] Permission middleware
- [x] Admin role และ permission seeder
- [x] Role Management เพิ่ม/แก้ไข/active และกำหนด permissions
- [x] ป้องกันแก้ code หรือปิด admin role
- [x] ผูก permission middleware กับ Settings routes และซ่อน action ตามสิทธิ์
- [x] Audit log schema/service สำหรับ Company, User, Role, Branch และ Warehouse
- [x] Audit Log DataTable พร้อม HTML5 Excel export ที่ scrub sensitive values และไม่มี mutation
- [ ] Manual QA permission isolation ด้วย user หลาย role

### DataTables Reference

- [x] DataTables 2.3.5 + Buttons 3.2.5 assets แบบ local vendor
- [x] User list server-side data/query contract
- [x] Search, pagination และ page length
- [x] Shared Export Excel ใช้ DataTables Buttons `excelHtml5` เป็นค่าเริ่มต้นและใช้ปุ่มสี soft/pastel
- [x] ติดตั้ง Yajra Laravel DataTables v12.7.2
- [x] รวม routes/root-layout/manifest และทดสอบ HTTP จริง
- [x] นำ view-only `index()` + AJAX/Yajra reference pattern ไปใช้กับ User, Branch, Warehouse, Role และ Audit Log
- [x] DataTable ทั้งระบบใช้ shared `excelHtml5` ก่อนเสมอ; server-side table export เฉพาะแถวที่โหลดอยู่ใน browser ตามข้อจำกัดของ HTML5
- [x] กติกา DataTable display: ทุก column ต้องเป็น human-readable; วันที่ใช้ company date format, datetime ใช้ company timezone, status/boolean/structured value ต้อง render เป็น label และค่าว่างใช้ `-`
- [x] กติกา DataTable UX: ส่วน Filter ต้องอยู่ใน Card แยกจาก Card ตารางเสมอ มีปุ่มควบคุมตัวกรอง (กรอง/ล้างตามความเหมาะสมของหน้า) และจัดกลุ่มฟิลด์ตามลำดับการใช้งาน โดยคง DataTable/AJAX hooks เดิม
- [x] Finance lists ที่โตได้ (Bank/Cash, Payment Term, Other Income/Expense, Document Sequence และ Settlement) ใช้ server-side DataTable พร้อม HTML5 export, scope และ permission-gated actions
- [x] General Ledger account filter เปลี่ยนเป็น Select2 AJAX และใช้ company date format; ลดการโหลดผังบัญชีทั้งชุดใน `index()`
- [x] DataTable performance audit รายการอื่น: ตรวจ `index()`/selector แล้ว ไม่พบการโหลด dataset หลักใน `index()`; reference/config และ locking paths ที่ใช้ `get()` ถูกบันทึกเป็นข้อยกเว้นใน `docs/qa/datatables-performance-audit.md` และ Recost ใช้ `chunkById(250)`
- [x] UI readiness wave: Settlement, Stock Card/Valuation และ Workflow Center core modules ใช้ AJAX/Yajra ตามขนาดข้อมูล, แสดงวันที่/สถานะ/ตัวเลขแบบ human-readable และมี empty state พร้อม recovery guidance; งาน audit จุดที่เหลือยังติดตามต่อเป็นรายหน้า
- [~] Shared form sizing contract: input/select ในทุก form ต้องมี readable minimum width ตามชนิดข้อมูล และ form table ต้อง responsive ด้วย horizontal scroll เมื่อจำเป็น; Journal Entry, Sales, Purchasing และ Settlement ผ่าน audit แล้ว เหลือ audit หน้า form อื่นตาม UI wave
- [x] Journal Books เป็น fixed 5-row client-side DataTable พร้อม HTML5 export
- [ ] Backend full-dataset export เป็น optional เฉพาะหน้าที่เจ้าของระบบระบุภายหลัง ไม่ใช่ค่าเริ่มต้นของ DataTable
- [x] MVP ไม่รวม automated/browser/manual QA เฉพาะ Export; ทดสอบเพียง shared asset และ page compilation ตามงานปกติ

### Quality และ Operations Foundation

- [x] Automated tests ใช้เฉพาะ Unit Tests ตามนโยบายเจ้าของระบบ
- [x] Laravel Pint และ Blade compile checks
- [x] Migration/Seeder สำหรับ foundation ปัจจุบัน
- [x] Manual QA checklist แบบ repeatable สำหรับ Foundation (`docs/qa/foundation-manual.md`)
- [x] Inventory→GL local MVP release gate และ migration/seed runbook: migration/seed, rollback และ non-rollback Purchase/GR + Credit Purchase reversal evidence ทำบน local `new_erp` แล้ว; Owner Release Sign-off ผ่านเมื่อ 2026-08-25 และเปิด purchase/adjustment posting เฉพาะ local แล้ว ส่วน production operational sign-off ยังรอทำท้ายสุด
- [~] Recost queue exception contract: implementation, focused contract tests และ local MySQL rollback verification ผ่านแล้ว: lifecycle `PENDING/PROCESSING/FAILED/STALE`, retry/idempotency, Period Close block, bounded dispatcher ทุก 5 นาที และ hourly stale scan; monitoring UI/health visibility ทำแล้ว เหลือ manual UI, owner release review และ production operational sign-off ซึ่งจัดไว้เป็น final pre-release gate. Dedicated Recost runtime rollback test ผ่าน `1 test / 15 assertions` และ period-close/queue safety test ผ่าน `2 tests / 22 assertions` ครอบคลุม negative-stock receipt resolve, positive/negative delta, allocation→Journal-line proof, retry, reconciliation และการบล็อกงวดปิด
- [x] Inventory→GL reconciliation/queue boundary verification: allocation-vs-GL, balance-vs-allocation และ unlinked allocation ทำให้สถานะเป็น “ต้องตรวจสอบ”; default posting gate ตรวจ pending allocation ซ้ำ และ Recost ใช้ bounded Queue/Scheduler (`everyFiveMinutes`, `withoutOverlapping`, `onOneServer`); purchase/adjustment posting เปิดเฉพาะ local ส่วน production flags ยังปิด
- [~] Inventory→GL local release-gate evidence checklist: เพิ่ม `docs/qa/inventory-gl-release-gate-local.md` ครอบคลุม MySQL migration/seed, Purchasing dependency, preflight/reconciliation, retry/rollback และ persistent non-rollback evidence; Owner Release Sign-off ผ่านเมื่อ 2026-08-25 และ local purchase/adjustment flags เปิดแล้ว เหลือ production release และ operational sign-off เท่านั้น
- [x] Inventory GL Preview UI readiness: Preflight แสดงโหมด Preview เมื่อ feature flag ปิด, อธิบาย blocker (Recost, allocation/linkage, mapping, source identity), จำกัดสิทธิ์ด้วย `wms.stock-valuation.view` และ DataTable ใช้ server-side query
- [~] Inventory Adjustment→GL readiness/posting service + bounded UI: migration, Draft→Approve→Post, ลบร่าง, Detail (Movement/Allocation/Journal/Audit), Posted reversal ที่สร้าง ledger ชุดใหม่แบบ immutable/idempotent, Select2 AJAX, DataTable/RBAC/Admin permission, GAIN/LOSS mapping, audit และ service transaction boundary; dedicated MySQL rollback/reversal/service-boundary/document multi-line gate ใน `phpunit.mysql.xml` ผ่าน `4 tests / 34 assertions`, migrations `2026_08_24_500000` + `2026_08_24_010000` + document reversal รันบน `new_erp`, full Unit ผ่าน; เปิด flag เฉพาะ local แล้วสำหรับ manual UI/owner review ส่วน production flag ยังปิด
- [x] Stock Count scope decision: Stock Count เก็บผลตรวจนับ/ผลต่างและประวัติเท่านั้น ไม่สร้างหรือเชื่อม Inventory Adjustment อัตโนมัติ และไม่เปิด Stock Count→Inventory→GL; การแก้ไขต้องสร้าง Adjustment เป็นเอกสารแยกโดยผู้ใช้
- [x] Adjustment document UX foundation: เปลี่ยนจาก 1 แถวต่อรายการเป็น Header/Lines, เลขที่เอกสาร/วันที่เอกสาร, หลายรายการต่อใบ, backfill legacy แบบไม่เปลี่ยน source identity และ Document History ระดับเอกสาร
- [x] Adjustment direction UX: เลือก เพิ่มสินค้า/ลดสินค้า ที่ Header เพียงครั้งเดียว และบังคับทุกบรรทัดใช้ทิศทางเดียวกัน พร้อม backfill เอกสารเดิมและตรวจจับเอกสารที่มีทิศทางปะปน
- [x] Adjustment document reversal foundation: reversal ระดับเอกสาร lock และย้อนกลับทุกบรรทัดใน transaction เดียว พร้อม idempotency/history (ต้องทำ local MySQL/manual sign-off ก่อนเปิดใช้งานจริงเต็มรูปแบบ)
- [x] Adjustment DataTable แสดง 1 แถวต่อเอกสาร พร้อมจำนวนรายการและสรุปสินค้า ไม่แตกเป็นหลายแถวจนทำให้เข้าใจผิด; การกลับรายการแสดงชื่อสินค้าและสาเหตุจริงเมื่อสต็อกปัจจุบันไม่พอ
- [ ] GCS private storage service และ attachment metadata/policy
- [~] Queue/scheduler health, failed-job visibility และ retry convention สำหรับ MVP เฉพาะ Recost และงานที่จำเป็นต่อความถูกต้องของ Inventory/GL; bounded scheduler ลงทะเบียนแล้ว, ส่วน Reconciliation/รายงานหนักทั่วไปยังไม่ทำเป็น Queue ใน MVP และทำ synchronous ได้เมื่อข้อมูลอยู่ในขอบเขตที่ปลอดภัย
- [ ] CI สำหรับ Composer, migration, Unit Tests, Pint และ asset checksum
- [ ] Backup/restore และ deployment checklist สำหรับ GCP/on-premise

## Accounting Kernel — ต้องทำก่อน Operational Modules

- [x] Chart of Accounts รองรับ PAE/NPAE, โครงสร้างระดับ 1–5, บัญชีรวม/บัญชีย่อย/บัญชีคุม และ staged Excel import
- [~] Fiscal year และ period close/reopen ระดับบริษัท (สร้างปี, Soft close และ Reopen พร้อมแล้ว; Period Close ตรวจ Inventory pending/unlinked/mismatched, linked Journal ต้อง POSTED/ไม่เกินวันสิ้นงวด/คลังเดียวกัน, orphan ITEM GL, recost ค้าง และ GL difference แล้ว แต่ยังไม่ใช้ current Stock Balance เป็น historical gate และ Lock ยังรอผล reconciliation เป็นศูนย์กับ posting integration)
- [x] สมุดบัญชี 5 เล่ม: ซื้อ, ขาย, รับ, จ่าย และทั่วไป
- [x] Journal Entry Draft และ Journal Lines แบบ debit = credit พร้อม Warehouse scope
- [x] Manual Journal approval และ reversal
- [x] Posting contract แบบ idempotent สำหรับทุก module
- [x] Typed Account Mapping สำหรับ Sales/Purchasing และ foundation ของ deferred/actual VAT กับ WHT พร้อม permission, audit และ Select2 AJAX
- [ ] Feature-based Posting Configuration Plan: เอกสารแผนกลางแยกไว้ใน `docs/planning/12-feature-posting-configuration-plan.md` แล้ว; ยังไม่เริ่ม refactor หรือ migration จนกว่า Owner จะอนุมัติ
- [x] Document Sequence รองรับ Sales/Purchase Invoice และ Credit Note พร้อม lock และป้องกัน reset รอบย้อนหลัง
- [~] General Ledger และ Trial Balance
- [~] รายงานเปรียบเทียบรายได้ (เพิ่มแล้ว; manual QA และรายงานชุดอื่นยังค้าง)
- [~] Profit & Loss และ Balance Sheet
- [~] VAT, withholding tax และรายงานภาษีที่ยืนยันแล้ว (Purchase/Sales Deferred VAT และ Settlement VAT realization แบบ partial/final rounding แล้ว; WHT snapshot, OpenItem snapshot และ Settlement realization journal แบบ partial/final rounding แล้ว แต่รายงานภาษี WHT ยังรอ)
- [~] Accounting/Tax reports: รายงานหลัก, เปรียบเทียบรายได้, ภาษีซื้อ, ภาษีขาย, ภาษีสินค้า, WHT ค่าใช้จ่าย, WHT ถูกหัก, รายได้, รายได้–รายจ่าย, ค่าใช้จ่าย, กำไรขาดทุน, สรุปการเงิน (รายงาน WHT ค่าใช้จ่าย/ถูกหักเริ่มใช้งานจาก WHT realization ledger แล้ว; รายงานภาษีที่มีอยู่แสดง Tax Point/Settlement Date แบบ human-readable แล้ว; รายงานชุดอื่นยังค้าง)
- [x] Control-account reconciliation foundation สำหรับ AR/AP/Inventory (AR/AP มีรายงานแล้ว; Inventory มี historical reconciliation read path, allocation/stock projection/GL comparison, ITEM subledger และ balance-drift gate แล้ว)
- [x] Control-account reconciliation local release evidence: ยืนยันข้อมูลจริงเป็นศูนย์หลัง migration/seed และก่อนเปิด Inventory→GL แล้ว; production ต้องเก็บ evidence ของ environment จริงซ้ำ

## WMS — Inventory และ Costing Kernel

- [~] Manual Production Movement สำหรับบริษัทที่ยังไม่เปิด Production: Wave 1 menu/form และ document context (PRODUCTION / PRODUCTION_RECEIPT) ใช้งานแล้ว; Wave 2 ซ่อน Post ของ Finished Receipt ทั้ง UI/server, Wave 3 เพิ่ม Mapping Preflight, Wave 4 เพิ่ม contract validation, Wave 5 เพิ่ม deterministic posting plan, Wave 6 เพิ่ม atomic posting service boundary และ costing safety gate แล้ว; Wave 7 แยก route/view/menu ของ Material Issue และ Finished Receipt ออกจาก feature ปกติ พร้อมบังคับ issue type Production ฝั่ง server, ปุ่มยกเลิกใบเบิกก่อนลง Stock แบบมีเหตุผลและ audit, ปุ่มลบร่างใบรับผลิต, Action Button ของ Detail แสดงข้อความและสีตามมาตรฐาน, ปุ่ม “สร้างใบรับผลิตเสร็จ” จากใบเบิกผลิตที่ลง Stock แล้ว, บันทึก source issue เพื่อแสดงย้อนกลับใน Detail/Edit, แสดงเอกสารรับผลิต/สถานะ/ยอดรวมย้อนหลังใน Material Issue DataTable/Detail และแสดงใบรับคืน/สถานะ/จำนวนย้อนหลังใน Issue DataTable/Detail; หน้า Finished Receipt แสดง source document/ต้นทุนต้นทาง, sum มูลค่ารับผลิตแบบ realtime และ server บังคับยอดรวมให้ตรงกับต้นทุนต้นทางเมื่อมี source; residual rounding เข้ารายการสุดท้ายและแก้ต้นทุนได้เฉพาะ Draft; Gate C transaction reconciliation ตรวจ allocation, stock projection, journal line และ Debit/Credit ก่อนเปลี่ยนเป็น POSTED แล้ว; เหลือ MySQL integration/UAT ยืนยัน
- [x] WMS Issue Type Installer Defaults: seed `GENERAL`, `PROJECT` และ `PRODUCTION` เป็นข้อมูลระดับองค์กรแบบ idempotent/versioned (`wms.issue_types`); `PRODUCTION` ยังถูกบังคับให้ใช้งานผ่าน Manual Production route แยก และไม่ทับการปรับแต่งของลูกค้า
- [~] WMS Issue Type organization scope: เพิ่ม migration เปลี่ยน `warehouse_id` เป็น nullable, backfill global copy จากข้อมูลเดิมโดยไม่ลบ legacy rows และปรับ UI/CRUD ให้ใช้ชุดประเภทเดียวกันทั้งองค์กร; migration เฉพาะรายการนี้รันแล้วใน local batch 188 เหลือ integration verification และ Apply System Update ของ seed version `1.2`
- [x] WMS Issue Type usage guard: ห้ามลบประเภทการเบิกที่มีเอกสารอ้างอิงแล้วทั้งใน UI และ server; ให้ปิดใช้งานแทนเพื่อรักษา historical/audit trail
- [x] Production Finished Receipt account mapping: Installer/Standard COA มี default mapping `FINISHED_GOODS`, `WIP`, `PRODUCTION_VARIANCE` สำหรับ `production.finished_receipt`; เพิ่ม migration idempotent และ bump seed version เป็น `1.3`
- [x] Installer system update แยก seed version `wms.production_finished_receipt_mapping` สำหรับ mapping ใบรับสินค้าผลิตเสร็จ และรันซ้ำได้แบบ idempotent
- [x] Production Finished Receipt document sequence: Installer seed `PRODUCTION_FINISHED_RECEIPT` รูปแบบมาตรฐาน `{PREFIX}{BRANCH}{YYMM}{NUMBER:6}` และ reset รายเดือน; Controller แยกจาก `INVENTORY_ADJUSTMENT`
- [~] Manual WMS Finished Receipt Posting: เปิด event แยกจาก Inventory Adjustment และต่อ Stock Movement / Cost Allocation / Journal แบบ idempotent แล้ว; local MySQL AVG + FIFO multi-layer + Gate C + reversal ผ่าน 3 tests / 21 assertions แบบ rollback-only; เหลือ UAT
- [~] WMS Recursive Cost Propagation & Revaluation Engine: Phase 0 และ Phase 1 เสร็จแล้ว; Phase 2 Shadow Calculation พร้อมในขอบเขต read-only ผ่าน `CostShadowCalculationService` และ `/wms/stock-valuation/shadow-calculation` โดยมี Source Allocation selector ที่ค้นหาเลขเอกสาร Inventory Adjustment ได้, Impact Window ตั้งแต่ `business_date`, bounded Historical Anchor, nodes scanned/affected, AVG/FIFO policy, Production Receipt output allocation ตามมูลค่า receipt line, Closed Period policy, Stock Valuation comparison, Variance Report พร้อม blocker และ FIFO Layer Evidence; หากไม่มี Anchor แสดง `REQUIRES_REBUILD` และยังไม่ Apply, รองรับ scope/chunk/bounded run ไม่ replay ตั้งแต่ transaction แรก; Unit coverage `4 tests / 19 assertions`, MySQL read-only smoke ผ่าน (64 scanned/2 affected), รายละเอียดใน `docs/planning/22-recursive-cost-propagation-revaluation-engine-plan.md`
- [~] WMS Recursive Cost Propagation & Revaluation Engine — Phase 3 boundary: เพิ่ม `wms_cost_revaluation_runs`/`wms_cost_revaluation_deltas`, `CostRevaluationApplyService` สำหรับสร้าง Shadow snapshot, Planned Delta และ immutable `RECOST` allocation + Stock Projection แบบ idempotent หลัง Approval, `CostRevaluationApprovalService` พร้อม approve/reject + audit, Approval Queue/Detail และ Preflight ตรวจ Variance, Allocation revision, Movement, Stock Projection และ Journal Link ก่อนอนุมัติ; `ApplyCostRevaluation` unique/retry-safe Job ตรวจ Preflight ซ้ำ, `CostRevaluationJournalPostingService`/Job สร้าง Journal ผ่าน `inventory.recost` mapping หลัง Stock Projection, `Post-Revaluation Reconciliation` ตรวจ Run/Allocation status, immutable Journal proof, Inventory value และ Debit/Credit ก่อนปิด Run เป็น `COMPLETED`, `ERP_REVALUATION_APPLY_ENABLED` และ `ERP_REVALUATION_GL_POSTING_ENABLED` ปิดเป็นค่าเริ่มต้น, Installer `Prepare Database` ตรวจ required tables/columns หลัง migration และยังไม่เปิด Auto Journal จนกว่า GL reconciliation gate จะครบ
- [x] WMS Cost Propagation Phase 6 Production Bridge: normalized `wms_production_receipt_sources`, idempotent legacy backfill/index/Installer guard, multi-source partial UI/Writer/Gate C, deterministic allocation/output residual, stale revision guard และ recursive Finished Goods → Transfer พร้อมแล้ว; Development migration รันเฉพาะ `2026_09_09_010000` เป็น batch 195 และ rollback-only MySQL graph 2 Material Issues → 2 Finished Receipts → 4 Outputs → Transfer OUT/IN ผ่านพร้อม ledger unchanged/residual zero; Production Bridge + Manual Receipt regression รวม `8 tests / 42 assertions` (skip 1 legacy fixture) และ Unit/contract `34 tests / 173 assertions` ผ่าน
- [~] WMS Recursive Cost Propagation & Revaluation Engine — Phase 4 Journal Revaluation hardening: Journal ใช้ `Run.posting_date` ตาม Closed Period policy แทน `Allocation.business_date`, Preflight และ Journal service ตรวจงวดบัญชี OPEN ก่อน Post, คง idempotency ผ่าน `WMS_REVALUATION` source identity และ immutable allocation→journal-line proof, ใช้ direction-adjusted delta ให้ Shadow/Stock Projection/Journal ตรงกัน (`IN = +Delta`, `OUT = -Delta`); migration `2026_09_08_010000` และ `2026_09_08_020000` รันใน Development `new_erp` แล้ว, rollback-only MySQL integration/UAT ชุดรวม `29 tests / 172 assertions` ผ่าน (skip 2 fixture ที่ไม่พร้อม), รวม Classified Journal, Apply continuation, Transfer replay, trigger หลัง commit/rollback และ performance contract; reconciliation เพิ่ม Finished Goods control account เป็น Inventory impact; เหลือ Accounting sign-off, benchmark และ staged feature gate
- [x] WMS Cost Propagation transaction matrix: วิเคราะห์ writer จริงครบ Opening Balance, Goods Receipt/Purchase Invoice, Landed Cost, Purchase Return/Credit Note แบบ RETURN/NON_RETURN, Inventory Adjustment/Stock Count, Issue/Issue Return, Material Issue/Return, Transfer partial accept/reject, Finished Receipt, HS/IV, Sales Return และ reversal; ระบุ source/target evidence, AVG/FIFO policy, impact bucket, GL target, closed-period และ reconciliation contract ใน `docs/planning/22-recursive-cost-propagation-revaluation-engine-plan.md`; ยืนยัน blocker สำคัญคือ AVG temporal lineage, Shadow double count, Apply ที่ปรับ Stock ทุก node, `inventory.recost` event เดียว, AVG anchor, purchase stock owner ซ้ำ และ Issue/Production GL lifecycle ที่ยังไม่ complete
- [ ] WMS Cost Propagation Phase 5 — Canonical Read-only Calculation: ใช้ Source Document Date เป็น effective date และ block `DATE_MISMATCH` (ห้ามใช้ `created_at` คำนวณ), เพิ่ม `CostImpact`/`ImpactClassifier`, read-only Trigger Planner ที่เก็บ root allocation ครบทุก document line และ group job plan ตาม Warehouse+Item+Base UOM+Method, AVG bounded pool replay, FIFO affected-layer replay, deterministic `(business_date, movement_id, allocation_id)`, summary แยก on-hand/terminal/bridge/residual และ regression Adjustment 01/09/2026 → Issue 07/09/2026 โดยยังไม่เขียน Stock/Allocation/Journal
- [x] WMS Cost Propagation Phase 6 — Direct Bridge Coverage: reversal/returns, transfer partial/cross-scope, purchase return, production many-input/many-output และกำหนด Purchase Invoice (`PURCHASING`) เป็น canonical stock/cost owner; Goods Receipt เป็น operational evidence, preflight บล็อก legacy owner ซ้ำ, Landed Cost resolve ผ่าน Invoice→GR allocation และ partial invoice base quantity ใช้ allocation ratio ถูกต้อง โดยไม่เพิ่ม migration/Installer seed; rollback-only MySQL Purchase/Landed Cost/Return ผ่าน `11 tests / 80 assertions` (skip 1 legacy fixture)
- [x] Sales Credit Note cost contract: Sales Document Credit Note = `NON_RETURN` ลด AR/Revenue/VAT โดยไม่มี Stock/Cost node; Sales Return = `RETURN` รับสินค้าและ reverse COGS ผ่าน immutable cost lineage; journal เก็บ stable `credit_note_mode`, validation ส่งผู้ใช้ไป Sales Return เมื่อมีการคืนสินค้า และ rollback-only MySQL HS/IV partial return ผ่าน `2 tests / 25 assertions`
- [~] WMS Cost Propagation Phase 7 — Classified Apply & GL: migration `2026_09_09_020000` สำหรับ bucket/event/scope/projection delta อยู่ใน Installer และรัน development batch 196 แล้ว; Apply ปรับ Stock เฉพาะ ending on-hand, Classified Journal route 10 accounting paths และเก็บ run/date/mapping provenance; Generic/Material Issue และ Issue/Material Return ลง Journal + ผูก allocation ทุก FIFO layer แบบ atomic/retry-safe แล้ว, reversal รับคืนย้อน Stock/Cost/GL ครบทุก layer, Transfer เป็น NO_GL และ allocation ใหม่ปิด lifecycle เป็น POSTED; Standard COA System Update เป็น `v1.5`; rollback/read-only MySQL ชุด Shadow+Issue/Return+Transfer ผ่าน `10 tests / 62 assertions` และ bucket/Apply writer ผ่าน `3 tests / 40 assertions`; development Clean Run #204 ผ่าน Apply + GL Posting + Reconciliation และปิดเป็น `COMPLETED`; เหลือ Apply System Update/production gate และ Recovery สำหรับเอกสาร legacy ที่ไม่มี Journal proof
- [~] WMS Cost Propagation Phase 8–9 — Scale/UAT/Rollout: transaction trigger/Parent Run/fan-out/checkpoint/heartbeat/hard limits, expiring partition lease + stale root revision preflight, Apply cooperative time-budget, Manual Recovery Console และ compensating reversal run พร้อมแล้ว; reversal ใช้ effective cost จาก Applied/GL Posted Delta ของ parent ณ `business_date`, เก็บ Source Run/Delta lineage, ไม่แก้ Completed Run และ retry ไม่ duplicate; manual trigger รองรับทั้ง 12 source documents และ scope ตั้งแต่ `business_date` ตามสาขา หลายคลัง/ทุกคลัง หลายสินค้า/ทุกสินค้า พร้อม authorized filter, frozen horizon, keyset planning 50 partitions/job, idempotent Batch/Run และ Audit Log โดย HTTP ไม่คำนวณ graph; Legacy Issue/Return accounting-proof recovery มี service + preview/recovery UI + rollback-only MySQL smoke แล้ว และ UAT จริงของ Issue #29/#32 ผ่าน; เพิ่ม Emergency rebuild ระดับสาขาแบบ bounded พร้อม typed confirmation, permission แยก และ audit log แล้ว; UAT Batch #34 ยืนยัน partition แยกและ blocker ไม่ทำให้ worker ล่ม; เพิ่ม `CostAccountingProofPolicy` ให้ Issue Return ที่ `REVERSED` และมี reversal pair เป็น `NO_GL_REVERSAL_PAIR` ไม่บังคับ Journal proof ซ้ำ; กักกัน Legacy Review `#3` สำหรับ DATE_MISMATCH และ `#4/#5/#6` โดยไม่ mutate ledger แล้ว; เพิ่ม WMS Cost Revaluation Runbook สำหรับ staged gates, bounded worker, queue health, Accounting review และ recovery แล้ว; เหลือ Accounting sign-off, benchmark multi-year/multi-branch/multi-warehouse, monitoring/alert implementation และ staged feature flag rollout
- [x] Cost Propagation blocker fix (2026-09-10): ปรับ `CostImpactClassifier` ให้ allocation ของ Issue Return ที่ถูกกลับรายการและมี reversal pair ซึ่งเป็น `NO_GL_REVERSAL_PAIR` ไม่ถูก block ด้วย `ALLOCATION_NOT_POSTED` ระหว่าง Shadow calculation; สร้าง Calculation Run `#170` จาก ADJ-237 (`2026-09-01`) สำเร็จเป็น `PENDING_APPROVAL`, `READY_FOR_REVIEW`, ไม่มี blocker, 16 Planned Deltas และยังไม่ mutate Stock/Allocation/Journal; downstream Issue/Transfer/WIP/Return ถูกคำนวณต่อด้วยต้นทุน AVG ใหม่แล้ว
- [x] Journal-proof loading fix (2026-09-10): Direct Bridge/Transfer replay เลือก `journal_entry_id`, `status`, `cost_status` ครบก่อน classify และ scope fingerprint เป็น `legacy-proof-v4-journal-aware-timeline`; development Batch `#41` สร้าง Run `#194` (AVG item 1) เป็น `PENDING_APPROVAL`/`READY_FOR_REVIEW` ไม่มี blocker, ส่วน Production Run `#195` ยังคง block allocation `#2059` ที่ยังไม่ Posted ตาม lifecycle gate
- [x] Pre-Apply reconciliation checkpoint (2026-09-10): Run `#194` มี `applied_count=0`, `journal_count=0`, Stock Projection evidence ครบและ Debit/Credit เป็นศูนย์สมดุล; `run_status/delta_count/allocation_status/inventory_value` ที่ยัง BLOCKED เป็นผลตามลำดับก่อน Apply ไม่ใช่ ledger discrepancy และยังห้าม Complete จนกว่าจะเปิด staged Apply/GL gate
- [x] Apply dispatch UX (2026-09-10): เพิ่ม route/controller/UI ส่ง Run `APPROVED` เข้า `ApplyCostRevaluation` แบบ bounded queue พร้อม Feature Gate + Preflight guard; ยังไม่เปิด gate และยังไม่แก้ Stock/Cost/Journal จากการเพิ่ม endpoint นี้
- [x] Development staged Apply verification (2026-09-10): เปิดเฉพาะ Apply gate และประมวลผล Run `#194` ได้ `STOCK_PROJECTED`, Applied Delta `16/16`, Stock Projection evidence ผ่าน; GL gate ยังปิด, Journal ยังไม่ถูกสร้าง และ RECOST ที่รอ GL ยังเป็น `PENDING` ตาม lifecycle
- [x] GL posting contract guard (2026-09-10): แก้ `CostRevaluationJournalPostingService` ให้ใช้ signed `delta_value` แทน absolute RECOST allocation value; ตรวจพบ Run `#194` มี Journal บางรายการลงผิดด้านหลังเปิด gate ทดสอบ จึงปิด GL gate กลับและกัก Run ไว้ที่ `GL_POSTED`/Reconciliation blocked รอ compensating correction ก่อน Complete
- [x] Revaluation legacy-run UX: หน้า Detail แยก Run เก่าที่ไม่มี `cost-shadow-v2-*` Snapshot ออกจาก Run ที่คำนวณสำเร็จ และแจ้งให้สร้าง Run ใหม่แทนการพยายามอนุมัติ/Apply Run ที่เป็น `REQUIRES_REVIEW`
- [x] Production source-document linkage migration `2026_09_07_030000_add_source_issue_to_wms_inventory_adjustment_documents` รันบน local `new_erp` แล้ว เพื่อให้ Material Issue/Finished Receipt แสดงเอกสารปลายทางย้อนหลังได้
- [x] Issue Return drill-down: หน้าใบรับคืนแสดงรายละเอียดใบเบิกต้นทางแบบอ่านอย่างเดียว พร้อมลิงก์ไปหน้าใบเบิกทั่วไป/ใบเบิกวัตถุดิบผลิต และรายการต้นทางสำหรับตรวจสอบย้อนหลัง
- [x] WMS source-document schema guard: หน้า Issue ทั่วไปไม่โหลด Finished Receipt relation หาก migration `source_issue_id` ยังไม่พร้อม จึงไม่กระทบการใช้งาน legacy database

- [x] Soft-delete policy: Purchase/Inventory documents, stock movements, cost layers/allocations, Journal/OpenItem และ audit ใช้ immutable history + VOID/reversal เท่านั้น; SoftDeletes ใช้เฉพาะ master ที่ยังไม่มีประวัติผูกพัน และการลบต้องผ่าน domain guard/audit
- [x] Item, category, UOM และ unit conversion master พร้อม GL account/Select2 selectors และ factor validation
- [x] Immutable stock movement ledger foundation (intent/post/idempotency, decimal-safe contract, balance projection และ immutable reversal contract เสร็จ)
- [x] Stock balance/available/reserved foundation (persisted balance projection, atomic reserve/release และ Stock Card read path เสร็จ; negative-stock policy/recost เป็น release gate แยก)
- [x] Company-wide AVG costing foundation (policy global, warehouse cost pool, cost allocation ledger, historical/as-of valuation, typed Inventory/COGS mapping, source preflight, Purchase Receipt validation, Item/UOM linkage, Receipt Draft Intent UI, deterministic cost posting/Journal adapter และ reversal contract เสร็จ; production enablement รอ integration evidence)
- [x] Company-wide FIFO layers/allocation foundation (policy global, persisted layers, locked issue allocation, immutable RECOST/provisional allocation ledger, typed GL adapter และ deterministic dry-run/reversal contract เสร็จ; production enablement รอ integration evidence)
- [x] Inventory allocation → Journal line linkage foundation (เพิ่ม immutable `wms_cost_allocation_journal_lines` สำหรับ allocation/Journal-line/revision/identity และ preflight ตรวจ unlinked/mismatched proof แล้ว)
- [x] Inventory → GL local MVP integration gate: migration/seed, source smoke, Journal linkage และ reconciliation evidence มีแล้วทั้งแบบ persistent/non-rollback และ isolated rollback; Owner Release Sign-off ผ่านแล้วเมื่อ 2026-08-25; production feature rollout/operational sign-off ยังเป็น deployment gate แยก
- Verification record (2026-08-25): local MySQL costing/inventory gate ผ่าน `9 tests / 84 assertions` และ enabled smoke ผ่าน `1 test / 8 assertions`; preflight ทุกคลัง `ready=true`, `global_ready=true`, `reconciliation_ready=true`, ไม่มี unlinked/mismatched/missing proof และ reconciliation difference เป็นศูนย์. มี 1 operational test skip เพราะไม่ได้เปิด `ERP_RUN_MYSQL_OPERATIONAL=1`; ไม่ใช่ test failure และให้รันซ้ำเมื่อ posting/cost/reconciliation contract เปลี่ยนเท่านั้น
- [~] Inventory → GL source boundary: local MVP รายงาน Purchase/GR/Inventory Adjustment เท่านั้น; allocation จาก Issue, Issue Return และ Transfer จะแสดงเป็น Deferred ใน preflight และยัง block global/production release จนมี source posting contract และ reconciliation ครบ
- [~] Purchase Document → Inventory Post แบบ `NONE_VAT` มี route/adapter เดียว, operational runbook, local DB smoke/retry/rollback evidence แล้ว; enabled local smoke `1 test / 8 assertions` ผ่านและไม่เขียนซ้ำ; local purchase flag เปิดหลัง owner review ส่วน Manual UI/production operational sign-off และ environment-specific production evidence ยังรอ
- Verification record (2026-08-25): `tests/Feature/InventoryPurchaseMySqlIntegrationReadinessTest.php` และ `tests/Feature/CreditPurchaseInventoryMySqlIntegrationReadinessTest.php` ผ่านบน local MySQL `new_erp` รวม 3 tests / 25 assertions ครอบคลุม Purchase/GR → Stock Movement → Cost Allocation → Journal, credit-purchase reversal/rollback และ idempotency; มี 1 test skip ตาม feature flag. ให้รันซ้ำเมื่อ purchase/GR allocation, credit reversal, cost allocation หรือ Journal contract เปลี่ยนเท่านั้น
- [~] Negative-stock provisional cost ตาม setting (policy resolver, Pending/Recost request, queued dispatcher และ retry contract เสร็จ; signed AVG receipt หลัง stock ติดลบ, Recost→GL delta, immutable Journal-line linkage และ rollback/idempotency evidence ผ่านแล้ว; เหลือ manual UI และ final release sign-off)
- [x] Backdated movement เฉพาะงวดเปิด (ตรวจ Fiscal Period แบบ lock ก่อน Post และแนะนำวิธีแก้เมื่อปิดงวด)
- [x] Transfer foundation: document/line/event ledger, state contract, Controller/route/RBAC/UI, รับเข้าเต็มจำนวน/ปฏิเสธ และ AVG/FIFO cost lineage พร้อมแล้ว; local MySQL integration ผ่าน 6 tests / 24 assertions และ unit/invariant ผ่าน เหลือเฉพาะ manual UI/owner release gate
- Verification record (2026-08-25): `tests/Feature/WmsTransferCostLineageTest.php` ผ่านบน local MySQL `new_erp` จำนวน 6 tests / 24 assertions ครอบคลุม FIFO/AVG lineage, partial accept/reject/retry, warehouse scope, closed-period gate และ insufficient-stock rollback; ให้รันทดสอบซ้ำเมื่อเปลี่ยน Transfer state, cost-lineage, movement หรือ Journal contract เท่านั้น
- [x] Recost dependency propagation และ idempotent scheduled job (bounded safety-net dispatcher, unique ต่อ receipt, provisional parent lineage และ reversal delta foundation เสร็จ; downstream positive/negative Recost→GL, queue health, retry/idempotency, period-close gate และ reconciliation evidence ผ่าน local rollback แล้ว; Manual UI sign-off และ final release/production operational sign-off ยังเป็น deployment gate แยก)
- Verification record (2026-08-25): AVG/FIFO, Recost runtime/period-close, FIFO issue-return และ transfer cost lineage ผ่าน local MySQL รวม `9 tests / 59 assertions`; Unit Recost/gate รวม `25 tests / 76 assertions`; residue หลัง rollback เป็นศูนย์, preflight ทุกคลังพร้อม, queue health ทุกคลังไม่มีสถานะค้าง, route health 9 routes และ `view:cache` ผ่าน. ให้รันซ้ำเมื่อ costing/recost/period-close contract เปลี่ยนเท่านั้น
- [x] Bounded Recost queue cleanup Wave: ตรวจ orphan/stale `RecalculateInventoryCost` jobs ที่อ้าง movement `3/336/400/441` หลัง allocation เป็น `POSTED/FINAL`, ประมวลผลเฉพาะ 4 jobs แบบ `queue:work --once`, `jobs=0`, `failed_jobs=0`, ไม่มี recost request ค้าง; เพิ่ม dispatch guard ไม่ enqueue เมื่อไม่มี pending recost request
- [x] Stock Card/Stock Valuation read-only UI แสดง Movement, On-hand/Reserved/Available, historical valuation และ reconciliation read path ตาม Warehouse/Item/as-of; Stock Card แสดงจำนวน/ต้นทุนต่อหน่วย/ต้นทุนรวมจาก Cost Allocation จริงก่อน fallback Cost Layer, รวม allocation `PENDING + FINAL` สำหรับ Transfer/Issue Return ที่ไม่มี Journal proof, แยกต้นทุน Movement ออกจาก Running Average/Running Value ของคงเหลือ และใช้ช่วง `business_date` ที่ใช้ index ได้; Running Value ตรวจเทียบ Stock Balance แล้วตรงกัน `234024.02500000`; final release evidence ยังอยู่ใน Inventory→GL gate
- [x] Stock Card average-cost correction: Historical Valuation ใช้จำนวนจาก Posted Movement ledger แบบหนึ่ง Movement ต่อหนึ่ง quantity และใช้ Allocation สำหรับ value จึงไม่ double-count split allocation; scope `warehouse=1,item=1` แสดง `2912.00000000 / 234024.02500000 = 80.36539492` หรือ `80.37` ตาม Global Setting และตรงกับ Stock Balance
- [x] Stock Balance Projection Reconciliation แบบ read-only: เพิ่ม `StockBalanceProjectionReconciliationService` และ `GET /wms/stock/{item}/reconciliation` เพื่อตรวจ `warehouse + item + uom` ระหว่าง persisted `wms_stock_balances` กับ Posted Movement/Cost Allocation/Reservation โดยใช้ `business_date`, แสดง `MATCHED`/`DIFFERENCE`/`REBUILD_REQUIRED`, evidence และ delta โดยไม่แก้ยอดหรือเปิด transaction ใน read path; local development scope แรก `warehouse=1,item=1,uom=1` ตรงกัน `36 movements / 42 allocations`
- [ ] Optional lot/serial/expiry/warranty
- [~] Inventory reports: Stock Movement, Stock Balance/Available/Reserved และ Historical Valuation มี Yajra server-side, AJAX, Warehouse scope, permission และ human-readable contract พร้อม static QA แล้ว; manual UI sign-off จัดไว้เป็น final pre-release gate และรายงานต้นทุนสินค้าเทียบราคาขายยังรอ Sales/POS item-stock และ selling-price source contract
- Verification record (2026-08-25): WMS/Reconciliation regression ผ่าน local MySQL `16 tests / 109 assertions` และ Unit `29 tests / 85 assertions`; route smoke ทุกหน้าหลักตอบ 302 ไป login ตามปกติ ไม่พบ 500. ยังต้อง authenticated browser UI sign-off ก่อน release

## Operational Modules

### Purchasing

- [x] Supplier และ purchase terms ผ่าน shared Party/Role พร้อม Payment Term, CRUD, audit, permission และ Select2 AJAX
- [~] Purchase Requisition และ approval (Draft/Submit/Approve/Reject/Void, PR→PO linkage, RBAC/Workflow และ local tests พร้อม; Manual UI sign-off ยังรอ)
- [~] Purchase Order และ partial receipt (PO Draft/Approve/Void, PR linkage, Goods Receipt foundation และ nullable Purchase Document ↔ PO ↔ Receipt allocation schema พร้อม; Partial Receipt ยังไม่สร้าง Stock/GL โดยตรง)
- [~] Goods Receipt / Purchase Return (Goods Receipt persistence, UOM conversion/cost snapshot และ over-receipt/idempotency พร้อม; Credit Purchase Return/Reversal adapter และ route ผ่าน local MySQL/unit gate แล้ว; Manual UI/owner และ production operational sign-off ยังรอ)
- [ ] Landed cost input
- [~] AP/PJ accounting handoff (แยกใบตั้งหนี้/ใบลดหนี้, allocation หลาย GR ต่อบรรทัด และกรณี Expense/Service ไม่เรียก PO/GR แล้ว; ยังรอ MySQL/manual QA, runtime 3-way allocation/variance approval, source OpenItem/settlement realization และ inventory invoice)
- [x] Purchasing integration fixture readiness: มี Dedicated Approved Purchase fixture builder ที่สร้างผ่าน domain validation พร้อม Supplier/Item/UOM/PO/GR linkage ใน isolated transaction และไม่สร้างข้อมูลถาวรหรือ Stock/GL จาก mockup
- [x] Local MySQL integration fixture contract: opt-in rollback test ตรวจ Warehouse/Source chain, Journal/Movement/Allocation/Linkage counts และคืน baseline หลัง rollback; ใช้ dedicated process เท่านั้น ไม่ seed ถาวร (`docs/qa/inventory-gl-release-gate-local.md`)
- [~] Purchasing fixture status contract: optional PR/PO mockup เป็น Approved และ GR เป็น Draft พร้อม Item/UOM/conversion/warehouse/supplier scope; Purchase Invoice สินค้า Approved ยังต้องสร้างเฉพาะใน isolated integration process
- [x] Dedicated Approved Purchase fixture prerequisite: builder สร้าง Approved Purchase Invoice ผ่าน validation ใน isolated/rollback process แล้ว; PR→PO→GR Draft mockup ยังคงใช้เป็น UI foundation เท่านั้น ไม่ใช่หลักฐานว่า Inventory→GL พร้อม Post
- [x] Legacy allocation repair impact audit: allocation `2/4` ถูกตรวจ exact source/reversal/linkage แล้ว และซ่อมข้อมูลทดสอบตามคำสั่งผู้ดูแลพร้อม Audit โดยไม่แก้ Journal/Purchase/GR (`docs/qa/purchasing-legacy-repair-impact.md`, `docs/qa/inventory-gl-release-gate-local.md`)
- [x] Legacy Repair Wave A: allocation `2/4` ถูกกักกัน ตรวจ reversal source/revision และซ่อม parent linkage ของ reversal allocation `4→2` แล้ว; ไม่ถูกนำไปทำให้ 3-way ผ่าน
- [~] Legacy repair runbook: เอกสารผิดสถานะ/link ไม่ครบต้องถูกกักกัน `REVIEW_REQUIRED`; มีขั้นตอน dry-run, ตรวจ 3-way/reconciliation, Void/Reverse หรือ recreate และห้าม direct SQL (`docs/qa/purchasing-legacy-repair-impact.md`)
- [~] Purchasing post-integration evidence: legacy local GL เดิมยังเป็น reversal-only (Invoice `PI-INVENTORY-MOCK-001` ไม่มี PO/GR allocation, Journal `11→13`, allocations ยัง `PENDING`); ใช้เป็น positive evidence ไม่ได้
- [x] Purchasing source-flow review: Production adapter ผูก Purchase Invoice receipt allocation กับ PO/GR line + GR conversion snapshot ใน Movement metadata และบังคับ 3-way ก่อน posting; isolated positive evidence ผ่าน
- [~] Final isolated Purchasing evidence: Approved Invoice↔PO↔GR ใน Warehouse/Supplier เดียวกันผ่าน 3-way `CLEAR`, positive posting และ rollback แล้ว; persistent OPS-SMOKE chain ใน Warehouse `229` ผ่าน Journal/Movement/Allocation/Cost Layer linkage และ reconciliation เป็นศูนย์แล้ว; ยังรอ owner operational sign-off ก่อนเปิด feature flag
- [~] Positive isolated Inventory→GL evidence: Dedicated builder chain ใน Warehouse `221` ผ่าน 3-way และ posting transaction ได้ Journal `18`, Movement POSTED, allocation value `1000.00`, Journal-line link `37`, reconciliation differences `0.00`, `unlinked=0`, `unresolved_legacy_review=0`; rollback counts `7/2/2/2` สำเร็จ. Legacy allocation `2/4` ถูกซ่อมและ release gate ของคลังที่ตรวจผ่านแล้ว; หลักฐานนี้เป็น historical isolated evidence ส่วน local purchase flag เปิดแล้วหลัง owner review และ production operational sign-off ยังแยกต่างหาก
- [~] GR→Stock/Cost และ Purchase reversal evidence: มี bounded `CreditPurchaseInventoryReversalContract/Service` และ runtime adapter/route (`credit-inventory-reverse`) สำหรับ full-line Movement → Allocation → Credit Journal-line Linkage → Reconciliation พร้อม immutable/idempotency/rollback contract; isolated rollback `1 test / 11 assertions` และ persistent operational evidence `1 test / 10 assertions` ผ่านแล้ว. หลักฐาน persistent ล่าสุด (`CN-OPS-GATE2-20260824-`, Invoice `92`, Credit Note `93`, Credit Journal `536`, Movement `1065`, Allocation `874`) reconciliation differences เป็นศูนย์และ retry ไม่สร้างซ้ำ; local MySQL + unit gate ล่าสุด `8 tests / 25 assertions`, expected skip `1` ตาม feature flag; local feature flag เปิดหลัง owner UI/release review ส่วน production operational sign-off ยังรอ. ไม่ต้องรันซ้ำจนกว่า reversal, cost allocation, Journal หรือ feature-flag contract จะเปลี่ยน
- [x] WMS issue-return FIFO lineage: migrations `2026_08_24_620000_create_wms_issue_return_line_allocations` + `2026_08_24_703000_add_soft_deletes_to_issue_return_tables` และ service รองรับคืนข้ามหลาย OUT/FINAL cost layers, immutable per-layer allocation, idempotency, over-return และ rollback; dedicated MySQL `IssueReturnFifoMySqlIntegrationReadinessTest` ผ่าน `3 tests / 23 assertions` และ migration รันบน local `new_erp`
- [x] WMS stock policy scope: migration `2026_08_24_701000_add_item_scope_to_wms_stock_policies` เพิ่ม item-specific policy พร้อม unique `(warehouse_id,item_id)` และ duplicate guard; Admin ได้สิทธิ์ Stock Policy/Issue Type ครบหลัง RbacSeeder รอบล่าสุด
- [~] Purchasing operational history: ตรวจ audit log persistent (created/approved/posted/voided) และเพิ่ม audit สำหรับ inventory posted/reversed route; fixture prefix `INT-/PI-INT-/PO-INT-/GR-INT-` ใช้ใน transaction rollback เท่านั้น
- [x] OPS-SMOKE Purchasing: persistent chain `PR-OPS-SMOKE-230823-A-N9NDOGHF4O`→`PO-OPS-SMOKE-230823-A-N9NDOGHF4O`→`GR-OPS-SMOKE-230823-A-N9NDOGHF4O`→`PI-OPS-SMOKE-230823-A-N9NDOGHF4O` ใน Warehouse `229` ผ่าน 3-way `CLEAR`, Journal `28`, Movement `336`, Allocation `192`, Cost Layer `215`, Journal-line link `16`, reconciliation difference `0`, unlinked `0`; rerun เป็น idempotent และไม่เปิด feature flag
- [~] Ampere local evidence review: Approved PO/GR และ conversion snapshot พบแล้ว; isolated transaction สร้าง Approved inventory Purchase Invoice ชั่วคราวเชื่อม PO↔GR allocation amount `2222.00000000` ได้ และ `PurchaseThreeWayMatchGate` เป็น `ready=true`/`CLEAR` ก่อน rollback (rollback count เหลือ `0`). Snapshot allocation `allocated_amount=0.00` เป็นข้อมูลก่อน legacy repair และไม่ใช้เป็น current evidence; ปัจจุบันยังรอ release-level non-rollback evidence และ sign-off
- [x] Dedicated Approved fixture evidence: persistent local MySQL OPS-SMOKE fixture ตรวจ Warehouse/Supplier scope, UOM factor, quantity/value และ 3-way blockers แล้ว; Journal/Movement/Allocation/Cost Layer linkage และ release reconciliation ผ่านโดยไม่ rollback

### POS — Sales Order

- [~] Customer, price list, discount และ promotion (Customer และ Customer Group ผ่าน shared Party/Role พร้อม CRUD, audit, server-side DataTable, Select2 AJAX, routes/sidebar/RBAC และ Admin permission seed; Price List CRUD/UI/RBAC และการคำนวณราคา/ส่วนลดเปอร์เซ็นต์ของ Invoice ใหม่จาก server พร้อม immutable `price_snapshot`; ส่วนลดนอก Price List ตรวจเพดานจาก Global Setting ตอนอนุมัติ, บังคับเหตุผลเมื่อเกิน, และเก็บ approval snapshot แล้ว; Promotion มี CRUD/RBAC, ต่อรายการหรือท้ายบิล, เลือกได้เฉพาะ Sales Intake, ให้ Promotion เหนือ Price List, รองรับ rule จำนวน/กลุ่มลูกค้า/ช่วงเวลา/priority, ตั้งค่า stackable, จัดสรรส่วนลดท้ายบิลก่อน VAT และ freeze snapshot ถึง RFQ→Quotation→Order→HS/IV แล้ว; ยังรอ campaign ที่ซับซ้อน/เงื่อนไขตามช่องทางขาย; Credit Limit enforcement ผ่าน Approval/Post แล้ว)
- [~] Sales Core shared foundation: Customer/Customer Group/Price List/Credit Limit/Term foundation พร้อมใช้งานระดับ master แล้ว; Customer รองรับที่อยู่ออกบิล/จัดส่งหลายรายการ และ Sales Intake เลือกที่อยู่จากลูกค้าได้; RFQ/Quotation/Sales Order ทำ flow ต้นทางแล้ว ส่วน billing/deposit/debit note และ Sales analytics ยังรอ
- [~] POS/Sales testing policy: ใช้ Unit Test เป็น gate ระหว่างพัฒนาแต่ละ feature; เลื่อน local smoke test ไปทำครั้งเดียวท้ายสุดเมื่อ Sales Core, POS flow, migration/seed และ capability ที่เปิดใช้พร้อมครบ
- [~] POS/Sales Dashboard แสดงภาพรวมตามสาขาปัจจุบัน: ยอดขายสุทธิวันนี้/เดือนนี้, เทียบเป้าสาขา, งาน Sales Order/HS/IV ค้าง, เอกสาร Post ล่าสุด และ Chart.js แบบ local-pinned สำหรับแนวโน้ม 7 วัน, สัดส่วน HS/IV และยอดขายเทียบเป้า; หน้า render เบาและแยก API เป็น summary→trend→mix→work→recent โดยโหลดทีละ section พร้อม cache สรุประดับสาขา 30 วินาทีเพื่อลด DB load เมื่อผู้ใช้เข้าใช้งานพร้อมกัน; รายงานยอดขายประจำวันมี DataTable/Excel/filter จาก HS/IV และใบรับคืนที่ Post แล้วและคำนวณยอดสุทธิ; รายงานวิเคราะห์ขายสุทธิ/สาขายังรอ source contract ครบ
- [ ] Production-only Sales handoff: ใบสั่งผลิต, ใบเบิกผลิต, ใบรับผลิต และใบฝากผลิต/รับฝากผลิต (ไม่บังคับสำหรับ Trading company)
- [~] Sales flow: ใบรับข้อมูลเบื้องต้น → RFQ → Quotation/Sales Order → HS/IV และสายผลิตนอก Production (`...→ใบสั่งผลิต→ใบเบิกผลิต→ใบรับผลิต→HS/IV`) (ใบรับข้อมูลมี schema/CRUD/sequence/permissions/audit และ conversion ไป RFQ แบบ idempotent พร้อมลิงก์แล้ว; RFQ สร้างจากใบรับข้อมูลในสถานะ WAIT และผู้มีสิทธิ์พิจารณากรอกต้นทุนประเมินต่อรายการเพื่อดูยอดขาย/ต้นทุน/กำไรขั้นต้น/GP% ก่อน APPROVED หรือ REJECTED พร้อม audit แล้ว; RFQ ที่ APPROVED เท่านั้นสร้าง Quotation ได้แบบ one-to-one idempotent; Quotation DRAFT แก้ราคา/ส่วนลดได้โดยล็อกรายการ/จำนวนตาม RFQและคำนวณ server แล้ว; Sales Order สร้างจาก Quotation/RFQ แบบ one-to-one idempotent, snapshot รายการ/ราคา/ลูกค้า, Draft→Confirmed→Cancelled พร้อม reason/audit และยกเลิกไม่ได้เมื่อมีใบขายปลายทาง; HS/IV เลือกได้เฉพาะ Sales Order ที่ยืนยันแล้ว แต่การ Post Stock/COGS/GL ยังรอ gate WMS; ใบรับข้อมูล, Sales Order, ใบสั่งผลิต, ใบเบิกผลิต และใบรับผลิตไม่ต้อง Approval ใน MVP; RFQ ขอ Approval เฉพาะกรณีราคาต่ำกว่ามาตรฐาน; การลบต้องย้อนจากปลายทางกลับต้นทาง)
- [~] HS/IV และ sales return/credit note (HS/IV จาก Sales Order Post แบบ atomic: Stock Issue → final cost allocation/COGS → Revenue/Deferred Output VAT/AR Open Item พร้อม idempotency, audit และ route/permission แล้ว; WHT/VAT snapshot, HS รับเงินจริง, เงินรับล่วงหน้า และ IV รับชำระผ่าน Finance Settlement พร้อม MySQL rollback E2E แล้ว; Sales Return/credit note reversal ผ่าน E2E เช่นกัน; แก้ POS writer ให้ Stock Movement/Conversion Snapshot ใช้ `document_date` เสมอ และ reject `business_date` ที่ต่างจากเอกสาร; พบข้อมูล legacy `IV-2026-000002` Movement `#1765` ต่างวันและกักกันเป็น Legacy Review `#3` / Allocation `#1535` แบบไม่ mutate ข้อมูลแล้ว รอ Accounting ตัดสิน reversal/repost; เหลือ browser sign-off และ GL reconciliation รายงานจริง)
- [x] HS/IV customer-facing PDF (HS บิลเงินสด/ใบกำกับภาษี และ IV ใบส่งสินค้า/ใบกำกับภาษี) — PDF ภาษาไทยหลายหน้า มีโลโก้บริษัท header ตารางซ้ำ และรูปแบบพิมพ์สำหรับ Dot Matrix/เครื่องพิมพ์ทั่วไป; read-only ไม่เปลี่ยนสถานะหรือผลกระทบ Stock/GL (mPDF/shared renderer, route + permission ผ่านการตรวจรับ)
- [~] Local Sales/POS readiness fixture: `InventoryGlMockupSeeder` เป็น idempotent master fixture สำหรับ Item/Base UOM และ BOX→PCS conversion; Sales document lines รองรับ Item/UOM/stock-UOM และ immutable conversion snapshot แล้ว แต่ยังไม่ seed หรือ Post Stock ISSUE/COGS/GL จาก Sales จนกว่า WMS issue/cost lineage gate จะผ่าน
- [~] Receipt/payment status: Invoice ที่ Post แล้วแสดงยอดตั้งหนี้, รับชำระ, คงเหลือ และสถานะยังไม่ชำระ/บางส่วน/ครบจาก AR Open Item ตาม allocation/reversal contract พร้อมปุ่มรับชำระที่ prefill ลูกค้า/Invoice/ยอดคงเหลือ; ตารางใบแจ้งหนี้แสดงยอดคงเหลือ/สถานะและกรองสถานะได้แล้ว; Receipt หนึ่งใบตัดหลาย Invoice และสร้างเงินรับล่วงหน้าจากยอดส่วนเกินได้ โดย reversal จะถูกกันเมื่อเงินล่วงหน้าถูกนำไปตัดแล้ว, payment status ในรายงานรวมยังรอ
- [~] AR/SJ/CR accounting handoff (Sales Invoice/Credit Note แบบ NONE VAT/VAT_OUT และ Settlement VAT realization → SJ/AR Open Item พร้อมแล้ว; WHT snapshot/validation แล้ว แต่ source OpenItem/settlement realization และ advance/unapplied cash ยังรอ)
- [~] Sales reports: สรุปยอดขายประจำวัน, ตามลูกค้า, ตามสินค้า และกระทบยอดขาย–รับชำระ–ลูกหนี้ ใช้ HS/IV และใบรับคืนที่ Post แล้วเป็น source เดียวกัน (กรองช่วงวัน/สินค้า, แยกจำนวน/ยอด HS, IV, รับคืน, ยอดขายสุทธิ, เงินรับ HS/IV, เงินรับล่วงหน้า, คืนเงิน และยอด AR ณ วันนี้) พร้อม DataTable/Excel และ GL drill-down ตามสิทธิ์แล้ว; รายงานกำไรขั้นต้นแบบรายบรรทัด HS/IV ใช้รายได้ไม่รวม VAT, Promotion snapshot, FINAL Cost Allocation และต้นทุนคืนจาก Sales Return พร้อม filter/Excel/drill-down แล้ว; รายงานผล Promotion แยกต่อรายการ/ท้ายบิลจาก immutable snapshot, หักใบรับคืน และรองรับ allocation snapshot เมื่อใช้ร่วมกันแล้ว; ตั้งค่า/คำนวณ/กลับรายการ/อนุมัติคอมมิชชั่นขายจากข้อมูล Post แบบ immutable แล้ว และมีชุดจ่ายคอมมิชชั่นใน Finance ที่ Post GL แบบ idempotent, กันจ่ายซ้ำ, Void และ Reversal ได้; Campaign ROI เทียบงบ เป้ายอดขาย และเป้า GP กับผลจริงตามสาขา พร้อมค่าใช้จ่าย append-only, DataTable และ Excel แล้ว; ตั้งเป้าและรายงานผลงานเทียบเป้าสาขา/พนักงานตามงวด จาก HS/IV ที่ Post แล้ว หักรับคืน และ FINAL COGS พร้อม DataTable/Excel, RBAC และ Audit แล้ว
- [x] Commission workflow: POS อนุมัติรายการ → สร้าง/ส่งชุด CB → Finance ตรวจสอบ → สร้าง/ส่ง/อนุมัติ CPR → สร้าง PV แยกผู้รับ → Settlement/Post; รองรับดำเนินการทั้งชุด, ยกเลิกตามลำดับเอกสาร, audit trail, Supplier reuse, RBAC และสถานะ POS/Finance ที่สอดคล้องกันแล้ว

### Production

- [~] Production ถูกกำหนดเป็น optional module ตาม business profile; มี typed `TRADING/MANUFACTURING` + `production_enabled`, `capability:production` guard, Program selector filtering และ WorkflowCatalog capability filtering แล้ว แต่ UI ตั้งค่า/readiness graph ยังต้องทำ; บริษัทซื้อมาขายไปไม่ต้องตั้งค่า BOM/BOQ/Work Order/WIP และ core workflow ไม่ติด Production dependency

- [ ] BOM revision/version และสูตรหลายระดับ
- [ ] BOQ สำหรับงานก่อสร้าง/project
- [ ] Make-to-Stock และ Make-to-Order
- [ ] Work Order snapshot
- [ ] Material issue/return/substitution approval
- [ ] Multi-output และ by-product allocation
- [ ] Standard labor/overhead และ WIP/variance posting

### Finance

- [~] Finance Module shell แยกจาก Accounting, Dashboard และ Accounting posting contract foundation
- [x] Bank/Cash Account master ผูกบัญชีคุม GL พร้อม CRUD, Warehouse scope, SoftDelete, audit และ guard
- [x] Payment Term master พร้อม CRUD, SoftDelete, audit และ permission แยก
- [x] Other Income / Other Expense master พร้อม CRUD, GL/Tax validation, SoftDelete, audit และ permission
- [x] Document sequence และ document format ต่อประเภทเอกสาร/คลัง พร้อม CRUD, allocator, Warehouse scope, audit, SoftDelete guard และ permission
- [~] AR/AP open items และ aging (canonical ledger, shared Party ID, OpenItemService, สิทธิ์, เมนู, Select2, mock data และหน้ารายการ/Aging แบบ view-onlyเสร็จแล้ว; source wiring จาก Sales/Purchasing invoice ยังรอ)
- [~] Customer Receipt และการ allocate รับชำระหลาย invoice พร้อมเงินรับล่วงหน้า/มัดจำลูกค้า (Draft/Approve/POST ลง Journal + AR Open Item allocation แบบ idempotent และ VAT/WHT realization แล้ว; advance/unapplied cash และ reversal ยังรอ)
- [~] Pre-Payment Voucher และ Payment Voucher (สร้าง Draft, Submit, Approve/Void พร้อมบรรทัดจัดสรร AP Open Item แบบ snapshot, Select2 AJAX, Warehouse scope, Audit และ DataTable server-side แล้ว; Payment Voucher ที่ Approved สร้าง Settlement Draft แบบ one-to-one ได้แล้ว แต่ยังไม่ลง GL/ตัด Open Item จริง, PRE_PAYMENT/advance และ reversal)
- [~] Payment Supplier และการ allocate จ่ายหลาย invoice พร้อมเงินจ่ายล่วงหน้า/มัดจำ Supplier (Draft/Approve/POST ลง Journal + AP Open Item allocation แบบ idempotent และ VAT/WHT realization แล้ว; voucher approval, advance และ reversal ยังรอ)
- [ ] Petty Cash: วงเงิน, เติมเงิน, เบิกจ่าย, เคลียร์ และกระทบยอด
- [ ] เงินทดรองพนักงาน: เบิก, เคลียร์ค่าใช้จ่าย, คืนเงินหรือจ่ายเพิ่ม และ posting เข้า GL
- [~] Finance reports (เพิ่มรายงานธุรกรรมรับ/จ่ายแบบ Yajra server-side, Warehouse scope, human-readable และ HTML5 DataTables export แล้ว; รายงาน AR/AP, ชำระบิล/มัดจำ และสรุปโครงการยังรอ source contract)
- [ ] Cash/bank receipt/payment
- [~] Receipt/Payment document foundation เชื่อม Bank Account, Payment Term และเลขเอกสารอัตโนมัติ (Draft/Approve/Void และ POST GL + final Open Item allocation + VAT/WHT realization แบบ idempotent แล้ว; Posted Settlement reversal พร้อม audit/idempotency แล้ว; advance/unapplied cash ยังต่อ)
- [~] Advance/deposit subledger (ตาราง, immutable application/reversal contract, UI/DataTable, Select2, permission และ scope validation แล้ว; APPROVED Settlement รองรับการสร้าง Customer/Supplier Advance และ POSTED ใช้ materialize/retry ตาม source contract, Application/Reversal เชื่อม JournalPostingService แบบ atomic พร้อม unique journal linkage และ MySQL rollback evidence แล้ว; ยังไม่เปิดใช้งานจริงจนกว่า UI/owner sign-off และ policy ของ Advance/Deposit จะผ่าน)
- [ ] Bank reconciliation

### Logistics

- [ ] Shipment/trip/dispatch
- [ ] Delivery status และ proof of delivery
- [ ] Transport cost allocation

### Asset

- [~] Asset register Phase 1 พร้อม; Phase 2 capitalization/opening foundation พร้อม: Purchase Invoice line แบ่งหลาย Asset ได้ภายใต้ allocation ceiling, lifecycle/Journal/value event/history และ opening staging commit ที่ไม่ Post GL ซ้ำ. รอ manual QA และ unit matrix เชิงลึกก่อนปิด Gate Phase 2
- [x] Depreciation Phase 3: lifecycle Book/Tax post/reverse, policy change prospective, เลือกสินทรัพย์พร้อมเหตุผลยกเว้น, Book/Tax schedule, Book-vs-Tax และ Asset subledger-vs-GL reconciliation แยก Opening balance ผ่าน Unit tests และ owner manual UI sign-off แล้ว
- [x] Transfer/physical count: Transfer lifecycle และ physical count แบบ freeze scope/follow-up โดยไม่เปลี่ยนทะเบียนหรือสร้าง GL อัตโนมัติ ผ่านการตรวจรับแล้ว
- [~] Asset maintenance: ใบแจ้งซ่อม lifecycle, มอบหมาย, เริ่ม/รออะไหล่/ปิดงาน/ยกเลิก, downtime, ประกัน, ค่าใช้จ่ายอ้างอิง, evidence attachment และ UNDER_REPAIR ตามการยืนยันของ owner พร้อมแล้ว; preventive schedule และ daily alert ที่ไม่สร้างใบแจ้งซ่อมเองพร้อมแล้ว เหลือ dashboard toast และรายงาน
- [x] Disposal และ accounting posting: Impairment, Sale/Write-off, final depreciation prerequisite, downstream clearing, gain/loss, reversal blockers และ terminal status ผ่าน Unit tests + Manual QA แล้ว ดู [asset-phase-6-manual.md](docs/qa/asset-phase-6-manual.md)

## Migration และ Commercial Readiness

- [~] Versioned ERP Excel import templates (กำลังทำ Chart of Accounts template/import เป็นชุดแรก)
- [ ] Staging/validate/preview/approve/commit flow
- [ ] Express mapping guide
- [ ] WinSpeed mapping guide
- [ ] Opening master/stock/AR/AP/GL import และ reconciliation
- [~] Module/license enablement ต่อ installation (Production capability/profile foundation และ route guard เริ่มแล้ว; หน้าตั้งค่าและการกรองเมนู/Program selector ยังรอ)
- [ ] Vendor-managed branding/custom fields/document templates
- [ ] Performance benchmark >200 concurrent users และ ≥1,000 stock movements/day
- [ ] Pilot: metal sheet
- [ ] Pilot: solar-cell parts factory
- [ ] Pilot: construction contractor

## Owner Decisions ที่ยังต้องยืนยัน

- [ ] MySQL production version/topology และ GCP/on-premise sizing
- [ ] PAE/NPAE chart/report/disclosure ขั้นต่ำ
- [x] VAT/WHT/e-Tax/e-Withholding scope และ tax point (MVP ไม่รวม e-Tax/e-Withholding)
- [ ] Chart of Accounts template และ control accounts
- [ ] Accounting maker-checker matrix
- [x] Company-wide AVG/FIFO policy เดียว; operational balance/layer แยก Warehouse เพื่อ scope และ reconciliation (ห้ามตีความเป็น policy แยกคลัง)
- [x] Purchase Receipt accounting ใน trading-only MVP ใช้ Direct Inventory/AP ตอน Post Purchase Invoice; Goods Receipt ไม่สร้าง GRNI/Journal ก่อนใบซื้อ
- [ ] Provisional cost fallback เมื่อ stock ติดลบ
- [ ] BOM multi-output/by-product allocation method
- [ ] ความหมายและ accounting event ของ HS/IV และเอกสารเดิมจาก `minterp`
- [~] Promotion/discount stacking และ approval thresholds — Promotion ต่อรายการ/ท้ายบิลและ stackable policy พร้อม; ส่วนลดนอก Price List ใช้ approval threshold + immutable approval snapshot แล้ว; campaign/เงื่อนไขซับซ้อนยังรอ

## กติกาการอัปเดต Checklist

1. Agent เปลี่ยนเป็น `[~]` ก่อนเริ่มงานที่ได้รับมอบหมาย
2. เปลี่ยนเป็น `[x]` ได้เมื่อ implementation และ checks ที่เกี่ยวข้องผ่านแล้ว
3. ใช้ `[!]` พร้อมเขียน blocker สั้นๆ เมื่อทำต่อไม่ได้จริง
4. งานที่ยังขาด authorization, audit, accounting/stock reconciliation หรือ manual QA ห้ามระบุว่าเสร็จทั้ง use case
5. Master Agent เป็นผู้รวมสถานะหลัง parallel work เพื่อไม่ให้หลาย Agent แก้ checklist ชนกัน
- [~] Document Sequence รองรับ policy เลขเอกสาร: ค่าเริ่มต้น NEVER_REUSE, ประวัติเลข, และออกเลขใหม่เมื่อเปลี่ยนวันที่ Draft; REUSE_DELETED_DRAFT_ONLY รอ workflow ลบ Draft ที่ตรวจสอบประวัติการเงินครบ
- [x] WMS Issue Return cancellation: ใบรับคืนสถานะ Draft/Approved ยกเลิกได้พร้อมเหตุผล, เปลี่ยนเป็น VOID และบันทึก Audit Log; เอกสารที่ลง Stock แล้วไม่สามารถยกเลิกได้
- [x] WMS Transfer destination receipt visibility: ใบโอนออกและหน้า Detail แสดงเลขที่/สถานะ/ยอดรับเข้าปลายทางจาก Transfer Events โดยไม่สร้างเอกสารซ้ำ
- [x] WMS Transfer source document visibility: หน้า Incoming และหน้า Detail แสดงเลขที่/คลัง/สถานะ/วันเวลาของเอกสารโอนต้นทาง พร้อม drill-down
- [x] WMS Production Material Issue return linkage: หน้า Detail ใบเบิกวัตถุดิบผลิตแสดงใบรับคืนที่เชื่อมด้วย `issue_document_id` พร้อมสถานะ จำนวน และ drill-down
- [x] WMS Production Receipt cost UX: UI ใช้ทศนิยมตาม Global Setting, backend เก็บ precision 8 ตำแหน่ง และ normalize ส่วนต่างการปัดเศษเข้า line สุดท้ายโดยยอดรวมต้องตรงกับต้นทุนเบิกจริง
- [x] WMS Production Receipt detail summary: แสดงจำนวนรวม มูลค่ารวม ต้นทุนเฉลี่ยต่อหน่วย และต้นทุนต่อหน่วยรายรายการในหน้า Detail
- [x] WMS Finished Receipt routing: แยก DataTable endpoint `/wms/production/finished-receipts/data` จาก Inventory Adjustment endpoint และบังคับ context ให้แยกเอกสารชัดเจน
- [x] WMS Finished Receipt full separation: เพิ่ม `ProductionFinishedReceiptController`, route/action endpoints และ Blade ใน `Views/production/finished-receipts`; Finished Receipt ไม่เรียกใช้ controller/route/action/view ของ Inventory Adjustment อีกต่อไป และผ่าน route/view cache กับ contract regression tests แล้ว
- [x] WMS Finished Receipt detail status guard: หน้า Detail ไม่เรียก Posting Preflight กับเอกสาร `DRAFT`/`POSTED` ที่ contract ไม่อนุญาต; เรียก preflight เฉพาะ `APPROVED` จึงเปิดเอกสาร 56 ที่ `POSTED` ได้ตามปกติ
- [x] WMS Finished Receipt reversal lineage: แยก `ProductionFinishedReceiptReversalService` จาก Adjustment reversal รองรับ `WMS_PRODUCTION_RECEIPT` Movement/Cost Allocation/Journal และทดสอบ execution path แบบ rollback สำเร็จ
- [x] Installer seed impact review: Finished Receipt reversal เป็น domain/service logic ไม่เพิ่ม System Definition, Permission, Posting Event, Account Mapping หรือ Document Sequence ใหม่ จึงไม่ต้องเพิ่ม seeder/version; mapping, event และ sequence เดิมยังอยู่ใน Installer/System Update แล้ว
- [x] WMS Finished Receipt detail layout: จัดลำดับ `SOURCE DOCUMENT` ให้อยู่เหนือ card `รายการสินค้า` เพื่อให้ผู้ใช้ตรวจต้นทุนต้นทางก่อนตรวจรายการรับผลิต
- [x] WMS Stock Count UX/DataTable: ปรับให้ใช้ DataTable layout กลางของ WMS โดยตรง (Export/Search, spacing, responsive table, status badge และ action column), พร้อม confirmation และแก้ ParseError ในหน้า Create จากการใช้ `@json` กับ expression ซับซ้อนโดยเตรียม `initialLines` ก่อน render
- [~] WMS Issue Return reversal: เพิ่ม workflow กลับรายการใบรับคืน Posted พร้อม reverse stock movement/cost lineage, สถานะ REVERSED และ audit; migration `2026_09_07_050000_add_reversal_to_wms_issue_returns` รันบน local batch 191 แล้ว เหลือ MySQL integration verification
- [~] WMS Issue/Production Receipt feature hardening plan: แยกขอบเขตเอกสารเบิกสินค้า, เบิกวัตถุดิบผลิต และรับผลิตเสร็จตาม `Branch + Warehouse` เดียวต่อเอกสาร พร้อมตรวจ lineage, costing, reversal, routing และ installer seed; Boundary phase เสร็จแล้ว เหลือ feature separation และ UAT
- [x] WMS Issue/Production Receipt boundary hardening: เอกสาร Issue/Issue Return/Finished Receipt ตรวจ `branch_id` และ `warehouse_id` ให้ตรงกับบริบทก่อนเปิดดู/ดำเนินการ และตรวจซ้ำก่อน Post
- [x] WMS Production Material Issue separation: แยก Controller, Blade, DataTable และ action routes จาก General Issue พร้อม fix `issue_type=PRODUCTION` ฝั่ง server
- [x] WMS Production Issue Return separation: แยก Controller, Blade, DataTable และ action routes จาก Generic Issue Return พร้อมบังคับ Source Issue เป็น `PRODUCTION`
- [x] WMS Production costing Gate C preflight/posting: ตรวจ Source Issue/Movement/Allocation, ต้นทุนรวม Finished Receipt และกัน receipt-side Movement/Allocation/Journal ซ้ำก่อน Post พร้อม blocker รายสาเหตุ; transaction ตรวจ allocation↔GL, stock projection quantity/value และ journal balance ก่อน commit และ reuse AVG receipt allocation เดิม
- [x] WMS Cost Engine AVG split quantity correction: Canonical timeline นับ `Movement.base_quantity` เพียงครั้งเดียวต่อ Movement แต่รวมมูลค่า Cost Allocation split ครบ, เพิ่ม Legacy Accounting Proof gate และไม่ Apply Run ที่ไม่มี Journal proof
- [x] WMS Cost Revaluation manual-trigger refresh: Trigger identity รวม proof fingerprint แล้ว การกู้คืน Journal proof และกด Manual Trigger ซ้ำจะสร้าง Batch/Run ใหม่ ไม่ย้อนกลับไปใช้ Run เก่าที่ถูก quarantine
- [x] WMS Cost Revaluation signed-delta Journal correction: เพิ่ม immutable reversal/correction สำหรับ Journal เดิมที่ลงทิศทางผิด, ใช้ transaction สั้นต่อ Delta และ idempotent source key; Run #194 แก้ 10 รายการ, reconciliation ผ่าน และปิดเป็น COMPLETED โดยไม่แก้ Posted ledger เดิม; development เปิด GL gate แล้ว ส่วน production ยัง staged
- [x] WMS final issue zero residual: เมื่อจ่ายรายการสุดท้ายจน `on_hand=0` ให้ล้าง Inventory Value และ Average Unit Cost เป็นศูนย์ใน AVG/FIFO/Revaluation projection และ Stock Card running display ไม่แสดงเศษ `0.01`; Unit test ผ่าน 5 tests / 11 assertions
- [x] WMS Stock Card opening value: เมื่อกำหนดวันที่เริ่มต้น ให้ running value/average รวมมูลค่ายกมาจากวันก่อนหน้า ไม่เริ่มจากศูนย์; regression ที่เกี่ยวข้องผ่าน 6 tests / 19 assertions
- [x] WMS Cost Engine final issue terminal zero: Final Issue ที่จ่ายเท่าจำนวนคงเหลือบังคับ AVG pool เป็น quantity/value/average ศูนย์ และ Stock Card ไม่แสดง running value ติดลบ; regression ผ่าน 16 tests / 48 assertions
- [x] WMS Historical valuation terminal zero: เมื่อ Posted Movement quantity เหลือศูนย์ ให้ `final_value` เป็นศูนย์ใน valuation query ด้วย ป้องกัน `/wms/stock` แสดงมูลค่าค้างทั้งที่ On-hand เป็นศูนย์
- [x] WMS Stock list summary cards: แสดง On-hand, Reserved, Available, ต้นทุนเฉลี่ย และมูลค่าคงเหลือจากยอดรวมคลังใน `/wms/stock` แทนค่า `-`
- [x] WMS Stock summary formatter: แก้ BigDecimal-to-float exception ใน summary endpoint โดย normalize เป็น decimal string ก่อน format
- [x] WMS Stock Card DataTable adapter: เปลี่ยน endpoint การเคลื่อนไหวสินค้าให้ใช้ `DataTables::query()` กับ Query Builder; ไม่เรียกเมธอด `queryBuilder()` ที่ไม่มีใน Yajra version ปัจจุบัน
- [x] WMS Inventory Adjustment controller namespace: import `CostPropagationTriggerDispatcher` จาก Services เพื่อแก้หน้าเอกสาร 96 ที่ resolve class ผิด namespace
- [x] WMS Production Receipt allocation deduplication: ไม่ให้ `StockCostLayerService` สร้าง generic receipt allocation ซ้ำสำหรับ `WMS_PRODUCTION_RECEIPT` ทั้ง AVG/FIFO; ให้ Production Receipt service เป็นผู้สร้าง canonical allocation ที่มี Journal proof เพียงแถวเดียว
- [x] WMS Cost Impact Classifier accounting-proof regression: Purchase allocation ที่ยังรอ Journal proof ถูกจัดเป็น Legacy Review warning ตาม policy เดิม โดย Production Receipt ที่ไม่ใช่ legacy ยังติด `ALLOCATION_NOT_POSTED` เป็น blocker; Unit test ผ่าน 17 tests / 67 assertions
- [x] WMS POS legacy date-review evidence: เพิ่ม `warehouse_id` ในข้อมูล Movement ที่บันทึกลง Legacy Review เพื่อไม่ให้หลักฐานแสดง Warehouse เป็น `0` จากการ select field ไม่ครบ
- [x] WMS terminal pool reset: ปรับ `StockBalanceProjectionService`, historical valuation และ Stock Card running cost ให้ reset value เมื่อ Final Issue ทำให้ quantity เป็นศูนย์ แล้ว receipt ถัดไปเริ่ม cost pool ใหม่; scope warehouse 1/item 1/UOM 1 rebuild แล้วได้ On-hand 12,000, Inventory Value 240,000 และ Average 20.00
- [x] WMS Revaluation Queue scope visibility: แสดง Scope Batch ที่อยู่ระหว่าง `PLANNING/QUEUED/CALCULATING` ในหน้า Approval Queue แม้ worker ยังไม่สร้าง Revaluation Run เพื่อไม่ให้ batch ที่ส่งเข้าคิวหายจากมุมมองผู้ใช้; query จำกัดตาม Warehouse และไม่ดึงข้อมูลเกิน 25 batch ล่าสุด
- [x] WMS Legacy Review correction (2026-09-11): Accounting ยืนยัน Review #3 และ #6; Review #3 บังคับ Movement/Allocation ของ `IV-2026-000002` ให้ใช้ `document_date=2026-08-28` ผ่าน controlled audited repair และ Review #6 บันทึก duplicate correction #6 ให้ Allocation #2059 ใช้ canonical Allocation #2060 โดยไม่สร้าง Journal ซ้ำ; ทั้งสอง Review เป็น `RESOLVED` และต้องสร้าง Manual Document/Scope Run ใหม่เพื่อคำนวณต่อ
- [x] WMS Legacy correction timeline exclusion: `CostTimelineReader` ตัด allocation ที่มี immutable correction record ออกจาก AVG/FIFO timeline และ bounded anchor ด้วย `whereNotExists` เพื่อไม่ให้ duplicate allocation กลับมาถูกคำนวณซ้ำ
- [x] WMS Legacy correction trigger exclusion: `CostPropagationTriggerPlanner` ตัด corrected duplicate ออกจาก root allocation plan ด้วย เพื่อไม่ให้ Manual Trigger สร้าง blocker `ALLOCATION_NOT_POSTED` จาก allocation เดิมที่ถูกแก้แล้ว
- [x] WMS Legacy correction recalculation: Batch #44 (`IV-2026-000002`) ถูกกักกันและยกเลิก Run #202 ที่ติด downstream RECOST bridge เดิม (`DIRECT_BRIDGE_QUANTITY_EXCEEDED`) โดยยืนยันก่อนว่าไม่มี Delta/Stock/Journal ถูก Apply; ใช้ Clean Batch #48 / Run #204 แทน, คำนวณ 33 deltas, Apply + GL Posting + Reconciliation ผ่าน และ Run #204 เป็น `COMPLETED` โดยไม่แก้ Posted ledger เดิม
- [x] WMS RECOST propagation guard: `CostDirectBridgeResolver` ไม่ใช้ `RECOST` allocation เป็น child bridge ใน recursive propagation และ Trigger identity รวม `business_date` เพื่อให้การแก้วันที่ Legacy สร้าง Run รุ่นใหม่ได้จริง
- [x] WMS Legacy recalculation verification: หลังแก้ RECOST guard สร้าง POS Batch #48 / Run #204 ใหม่สำเร็จ, 2 partitions, 49 nodes scanned / 33 affected, ไม่มี blocker, queue ว่าง และสถานะ `PENDING_APPROVAL`; Production Batch #47 อยู่ `PENDING_APPROVAL` เช่นกัน รอ Accounting ตรวจทั้งสอง Run ก่อน Approve/Apply
- [x] WMS corrected allocation read-path consistency: Stock Card และ Historical Valuation ตัด allocation ที่มี correction ledger เช่นเดียวกับ Timeline/Planner/Reconciliation; duplicate #2059 ไม่ถูกนับซ้ำใน Receipt value หรือยอดคงเหลือ
- [x] WMS Stock Card reversal UX: รายละเอียด Movement แสดง `กลับรายการ` พร้อมเลข Movement ต้นทาง, แยก label ตามทิศทางจริงของ RECEIPT/ADJUSTMENT/COUNT และมีคำอธิบายบนหน้าจอเพื่อไม่ให้ผู้ใช้เข้าใจว่าเป็นรับเข้า/จ่ายออกปกติ
- [x] WMS Stock Card document number: คอลัมน์เลขที่เอกสาร resolve จากเอกสารต้นทางจริงผ่าน `source_type + source_id` (รวม Adjustment line → Adjustment document) และ fallback ไปยัง source reference เฉพาะกรณี legacy ที่ไม่มี mapping
- [x] WMS Costing menu UX: รวม Approval Revaluation, Manual Trigger, Emergency Rebuild และ Legacy Allocation Review ไว้ในกลุ่มเมนู `คำนวณและควบคุมต้นทุน` พร้อม active/expanded state ตาม route
- [x] WMS Cost Engine UAT contract hardening (2026-09-11): Preflight fixtures ใช้ calculation contract รุ่นปัจจุบัน, Transfer integration เลือก allocation ต้นทางจริงไม่ใช่ RECOST child, rollback trigger ยืนยันว่าไม่ enqueue job เมื่อ source transaction rollback, และ Reconciliation นับ `production.revaluation.finished_goods` เป็น Inventory impact ตาม control account `FINISHED_GOODS`; UAT ชุดรวมผ่าน `29 tests / 172 assertions` (skip 2 เพราะไม่มี fixture Issue Return/Material Issue ในฐานข้อมูล development)
- [~] WMS Cost Engine benchmark (2026-09-11): เพิ่มคำสั่ง read-only `wms:cost-benchmark` วัด partition latency, rows/sec, memory และ queue lag แบบ bounded; Development baseline `1:1:1:AVG` 86 nodes = 1,152.80 rows/sec, queue ค้าง 0, peak memory 32 MB; พบ fixture partition `1:32:64:AVG` มี `ALLOCATION_NOT_POSTED` จึงยังไม่ปิด benchmark/production sign-off จนกว่าจะมี production-sized clean dataset
- [~] WMS Cost Engine monitoring (2026-09-11): เพิ่ม read-only `wms:cost-health --json` สำหรับ external monitor ตรวจ pending/failed queue, stale lease, Run ที่ต้อง Review และ feature gates; Development พบ `RUN_REQUIRES_REVIEW` 20 Run แต่ queue pending/failed และ stale lease เป็น 0; ยังไม่ผูก scheduler/notification จนกว่าจะกำหนดช่องทางแจ้งเตือน Production
- [x] WMS Cost Engine Accounting sign-off report (2026-09-11): เพิ่ม read-only `wms:cost-signoff-report --run={id} --json` สรุป Source document, Preflight, Reconciliation, totals และคำตัดสินต่อ Run; ตรวจ Run #149 ของ `ADJHQ2609000005` แล้วเป็น `BLOCKED` จาก legacy contract/variance/posting period และไม่มี Delta/Journal จึงยืนยันให้ใช้ Clean Run แทน ไม่แก้ข้อมูลอัตโนมัติ
- [~] WMS Cost Engine staged gate readiness (2026-09-11): เพิ่ม read-only `wms:cost-gate-readiness --json` ตรวจ queue, failed jobs, stale lease และ unresolved review ก่อนเปิด Auto Trigger/Apply/GL; Development ยังไม่พร้อมทั้ง 3 gate เพราะมี `RUN_REQUIRES_REVIEW` 20 Run ขณะที่ queue/failed/stale เป็น 0; `.env` ปัจจุบัน Apply/GL เป็น `true` ต้องมีผู้ดูแลตัดสินใจปิดกลับหรืออนุมัติ scope ก่อนเปิดใช้งานต่อ
