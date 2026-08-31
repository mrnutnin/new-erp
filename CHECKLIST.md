# New ERP Checklist

อัปเดตล่าสุด: 22 สิงหาคม 2026

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
- [x] Document Sequence รองรับ Sales/Purchase Invoice และ Credit Note พร้อม lock และป้องกัน reset รอบย้อนหลัง
- [~] General Ledger และ Trial Balance
- [~] รายงานเปรียบเทียบรายได้ (เพิ่มแล้ว; manual QA และรายงานชุดอื่นยังค้าง)
- [~] Profit & Loss และ Balance Sheet
- [~] VAT, withholding tax และรายงานภาษีที่ยืนยันแล้ว (Purchase/Sales Deferred VAT และ Settlement VAT realization แบบ partial/final rounding แล้ว; WHT snapshot, OpenItem snapshot และ Settlement realization journal แบบ partial/final rounding แล้ว แต่รายงานภาษี WHT ยังรอ)
- [~] Accounting/Tax reports: รายงานหลัก, เปรียบเทียบรายได้, ภาษีซื้อ, ภาษีขาย, ภาษีสินค้า, WHT ค่าใช้จ่าย, WHT ถูกหัก, รายได้, รายได้–รายจ่าย, ค่าใช้จ่าย, กำไรขาดทุน, สรุปการเงิน (รายงาน WHT ค่าใช้จ่าย/ถูกหักเริ่มใช้งานจาก WHT realization ledger แล้ว; รายงานภาษีที่มีอยู่แสดง Tax Point/Settlement Date แบบ human-readable แล้ว; รายงานชุดอื่นยังค้าง)
- [x] Control-account reconciliation foundation สำหรับ AR/AP/Inventory (AR/AP มีรายงานแล้ว; Inventory มี historical reconciliation read path, allocation/stock projection/GL comparison, ITEM subledger และ balance-drift gate แล้ว)
- [x] Control-account reconciliation local release evidence: ยืนยันข้อมูลจริงเป็นศูนย์หลัง migration/seed และก่อนเปิด Inventory→GL แล้ว; production ต้องเก็บ evidence ของ environment จริงซ้ำ

## WMS — Inventory และ Costing Kernel

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
- [x] Stock Card/Stock Valuation read-only UI แสดง Movement, On-hand/Reserved/Available, historical valuation และ reconciliation read path ตาม Warehouse/Item/as-of; final release evidence ยังอยู่ใน Inventory→GL gate
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
- [~] HS/IV และ sales return/credit note (HS/IV จาก Sales Order Post แบบ atomic: Stock Issue → final cost allocation/COGS → Revenue/Deferred Output VAT/AR Open Item พร้อม idempotency, audit และ route/permission แล้ว; WHT/VAT snapshot, HS รับเงินจริง, เงินรับล่วงหน้า และ IV รับชำระผ่าน Finance Settlement พร้อม MySQL rollback E2E แล้ว; Sales Return/credit note reversal ผ่าน E2E เช่นกัน; เหลือ browser sign-off และ GL reconciliation รายงานจริง)
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

- [ ] Asset register และ capitalization
- [ ] Depreciation
- [ ] Transfer/repair/maintenance
- [ ] Disposal และ accounting posting

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
