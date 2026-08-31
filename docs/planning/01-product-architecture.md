# New ERP Development Plan

> เอกสารนี้เป็นแผนหลักสำหรับมนุษย์และ Agent ทุกตัวที่ทำงานใน `new-erp`  
> ก่อนเริ่มงานทุกครั้งต้องอ่าน `SKILL.md` และอ่านหัวข้อที่เกี่ยวข้องในเอกสารนี้

## 1. Product vision

สร้าง ERP แบบ modular monolith ที่นำความรู้และ business flow ที่พิสูจน์แล้วจาก
`/Users/mrnutninlaong/GitRepository/minterp` มาพัฒนาใหม่บน Laravel โดยมีเป้าหมายสองข้อ:

1. ส่งมอบ MVP ที่ใช้งานจริงได้ครบวงจรตั้งแต่จัดซื้อ สต็อก ขาย ผลิต ขนส่ง การเงิน บัญชี และสินทรัพย์
2. ทำให้ผลิตภัณฑ์ตั้งค่าไปใช้กับธุรกิจอื่นได้ โดยไม่ผูก domain กับโรงงานหลังคาเหล็กเมทัลชีท

ระบบเป็น Laravel project เดียว ใช้ฐานข้อมูลเดียว และแบ่งขอบเขตด้วย module ที่ชัดเจน ไม่แยก microservice ใน MVP

## 2. Fixed constraints and assumptions

### ข้อกำหนดที่ยืนยันแล้ว

- PHP 8.2
- Laravel 12 (`^12.0`) เพราะ Laravel 13 ต้องใช้ PHP 8.3 ขึ้นไป
- Backend, web UI, queue และ scheduled jobs อยู่ใน project เดียว
- ทุก module ใช้ relational database เดียวกัน
- ใช้ MySQL; version production ต้องยืนยันและ benchmark ก่อน deploy
- หนึ่ง installation และหนึ่ง database รองรับบริษัทตามกฎหมายเพียงหนึ่งบริษัท ลูกค้าแต่ละรายแยก installation/database; ไม่ทำ SaaS, multi-tenant หรือ intercompany flow
- deploy หลักบน GCP แต่ configuration ต้อง deploy on-premise ได้โดยไม่เปลี่ยน business code
- ระบบเป็น web application ที่เน้นใช้งานออนไลน์เท่านั้น; ไม่รองรับ offline mode หรือ offline synchronization
- รูปแบบการใช้งานและโครงสร้าง module ใกล้เคียง `minterp` แต่เขียนใหม่ด้วย convention ของ Laravel ปัจจุบัน
- Frontend ใช้ Blade เป็น view หลัก, jQuery เป็น DOM/event convention, AJAX เป็นค่าเริ่มต้นของ CRUD form และ Yajra Laravel DataTables สำหรับ server-side tables เมื่อเหมาะสม
- ไฟล์ถาวรเก็บใน Google Cloud Storage ผ่าน Laravel Filesystem และ storage service กลาง; bucket เป็น private โดยค่าเริ่มต้น
- UI ใช้ Bootstrap 5 และ widget มาตรฐานจาก public library ที่ดูแลต่อเนื่อง โดย pin ไฟล์ไว้ส่วนกลางใน `public/vendor` และ include จาก root layout; ห้ามคัดลอก vendor asset แยกตาม module
- UI ใช้โทนขาว ดำ และเทากลาง คลีน อ่านง่าย และ responsive
- Core MVP modules: Purchasing, WMS, POS, Finance และ Accounting; Production, Logistics และ Asset เป็น optional modules ที่เปิดใช้จาก Product/License settings ตามประเภทธุรกิจ
- Business profile อย่างน้อยต้องรองรับ `TRADING` (ซื้อมาขายไป) และ `MANUFACTURING`; profile `TRADING` ห้ามบังคับตั้งค่า BOM/BOQ/Work Order/WIP และไม่ควรแสดงเมนู Production ที่ปิดใช้งาน
- Optional module ที่ปิดต้องถูกตัดออกจาก readiness graph และ Workflow Center ของ core modules; เอกสารหรือ API ที่อ้าง Production ต้องตรวจ capability ก่อนและตอบข้อความว่าต้องเปิด module ใด ไม่ใช่ error จาก route/ตารางที่ไม่มี
- Accounting เป็น kernel ของ ERP: ทุก operational module ต้องระบุ accounting event และ post เข้าบัญชีแยกประเภทเดียวกันผ่านระบบบัญชี 5 เล่มของไทย
- Finance เป็นเจ้าของ AR/AP operational subledger, receipt/payment, Supplier Payment, Pre-Payment/Payment Voucher, customer/supplier deposit, Petty Cash และเงินทดรอง; Purchasing/Sales เป็นเจ้าของเอกสารต้นทางและ Accounting เป็นเจ้าของ GL/control-account reconciliation

### สมมติฐานเพื่อให้เริ่มงานได้

- คำว่า **Purchasing** หมายถึงงานบริหารจัดซื้อ ส่วน **WMS** หมายถึงงานคลังสินค้า สต็อก และ costing; code ภายในเดิม `wms` และ `inventory` ยังคงไว้เพื่อไม่ทำลาย route/reference เดิม
- มี company profile/global settings หนึ่งชุดต่อ installation; ไม่ใส่ `organization_id`/tenant scope ในทุก transactional table เผื่ออนาคต
- ใช้ Bootstrap 5 + Blade + jQuery และ CSS design tokens เป็นฐาน ไม่ทำ SPA และไม่ต้องมี npm/Vite/frontend server; `php artisan serve` ต้องเปิดระบบได้ครบ ห้ามเพิ่ม React, Vue, Livewire หรือ Alpine โดยไม่มี requirement ที่อนุมัติ
- ภาษาเริ่มต้นเป็นไทย รองรับ translation key และ locale เพื่อเพิ่มภาษาในอนาคต
- ใช้สกุลเงินฐานหนึ่งสกุลต่อบริษัทใน MVP แต่โครงสร้างเอกสารรองรับ currency และ exchange rate
- MVP รองรับต้นทุนสินค้าคงเหลือสองวิธี: moving weighted average (`AVG`) และ first-in, first-out (`FIFO`) โดยเลือกหนึ่งวิธีเป็น global accounting policy และคำนวณรวมทั้งบริษัท ห้ามแยกตามสาขา/คลัง/เอกสาร

### หลักการรองรับขนาดธุรกิจ (Small → Medium → Enterprise)

- ธุรกิจขนาดเล็กต้องเริ่มได้ด้วย configuration ขั้นต่ำ เปิดเฉพาะ Purchasing, WMS, POS, Finance และ Accounting ที่จำเป็น โดยไม่บังคับ Production, Logistics, Asset, lot/serial หรือ approval หลายชั้น
- ความสามารถระดับกลางและใหญ่เปิดแบบ capability/policy เมื่อจำเป็น โดยไม่เปลี่ยน accounting kernel, stock ledger, party master หรือ document lifecycle เดิม
- UI และ Workflow Center ใช้ progressive disclosure: แสดงงานวันนี้และ blocker ก่อน ซ่อนการตั้งค่าขั้นสูงไว้ในเมนู/สิทธิ์ที่เปิดใช้งาน
- ข้อมูลจำนวนมากต้องใช้ server-side/pagination และงานหนักใช้ queue/scheduler; ห้ามโหลดข้อมูลทั้งระบบเพียงเพื่อรองรับบริษัทใหญ่
- Capability ที่ปิดต้องไม่ทำให้ profile `TRADING` ติด Production และไม่สร้างภาระให้ผู้ใช้บริษัทเล็ก
- การขยายระบบต้องรักษา safe defaults, backward compatibility, idempotency และ audit/reconciliation; เพิ่ม infrastructure เมื่อมี volume evidence เท่านั้น

### หลักการรองรับทีมขนาดเล็ก

- ทุก module ต้องใช้งานได้เมื่อมีพนักงานประจำแผนกเพียง 1–2 คน โดยไม่ต้องสร้างผู้อนุมัติหรือผู้ตรวจสอบปลอมเพื่อให้เอกสารเดินต่อ
- Approval, segregation of duties และการมอบหมายงานต้องปรับระดับได้: บริษัทเล็กใช้ self-check/อนุมัติคนเดียวตาม policy บริษัท ส่วนบริษัทใหญ่จึงค่อยเพิ่มหลายขั้น
- Workflow Center ต้องแสดงงานค้างของผู้ใช้ที่มีสิทธิ์เดียวกันรวมกัน ลดการส่งต่องานและการกรอกข้อมูลซ้ำ พร้อมมี bulk action เฉพาะงานที่ปลอดภัย
- ระบบต้องมีค่าเริ่มต้นและ template ที่ช่วยให้ผู้ใช้หนึ่งคนทำงานครบวงจรได้ แต่ยังคง audit trail และข้อห้ามแก้เอกสาร Posted ตามหลักบัญชี

หากเจ้าของระบบเปลี่ยนสมมติฐานข้อใด ให้แก้เอกสารนี้ก่อนแก้ implementation ที่ได้รับผลกระทบ

## 3. What to learn from `minterp`

### สิ่งที่ต้องรักษา

- business flow และชื่อเอกสารที่ผู้ใช้จริงคุ้นเคย
- การแยก `app/Modules/<Module>/{Controllers,Models,Routes,Views}`
- การเลือก warehouse/branch และสิทธิ์ของผู้ใช้แต่ละ warehouse
- approval flow, document lifecycle, stock costing และการเชื่อมเอกสารข้าม module
- แบบฟอร์ม รายงาน และ edge cases ที่เกิดจากการใช้งานจริง
- การใช้ database transaction กับงาน stock, purchasing, payment และ accounting

### สิ่งที่ห้ามคัดลอกโดยตรง

- Laravel 5.8, PHP 7.x และ package ที่หมดอายุ
- controller หรือ Blade file ขนาดใหญ่ที่รวมหลาย use case
- model ซ้ำกันหลาย namespace แต่ชี้ table เดียวกัน
- business logic ใน base controller, view, route closure หรือ JavaScript
- schema ที่สร้างด้วย SQL/manual operation โดยไม่มี migration
- raw SQL ที่ต่อค่าจาก input, magic status และการคำนวณเงินด้วย float
- route สำหรับ clear cache, queue worker หรือ administrative command ผ่าน HTTP
- secret, credential, token, production data และไฟล์จาก `.env`
- code ที่ comment ทิ้งไว้จำนวนมาก, ไฟล์ `copy`, `old`, `v2` โดยไม่มี migration path

ใช้ `minterp` เป็น behavioral reference ไม่ใช่ source template: ก่อนนำ flow ใดมาใช้ ให้ trace ตั้งแต่เอกสารต้นทางจนถึง stock/finance/accounting แล้วบันทึก acceptance checklist พร้อม Unit Test ของ business rule/calculation ที่แยกทดสอบได้

### Laravel baseline with `minterp` familiarity

ให้ Laravel 12 convention เป็นฐานทางเทคนิค และใช้ `minterp` เป็นฐานความคุ้นเคยของทีม/ธุรกิจ หากสองอย่างขัดกันให้ใช้ Laravel convention แล้วทำชื่อหรือ behavior mapping ให้ทีมเข้าใจ ห้ามยก legacy workaround มาเพียงเพราะเคยใช้ใน `minterp`

- รักษา module grouping แบบ `app/Modules/<Module>` ที่ทีมคุ้นเคย แต่ class ภายในต้องเป็น Laravel class ปกติ ใช้ PSR-4 namespace/autoload และไม่สร้าง custom framework/module runtime
- ใช้ resource-controller method เมื่อ use case ตรงกับ CRUD: `index`, `create`, `store`, `show`, `edit`, `update`, `destroy`; action ธุรกิจเช่น approve/post/reverse ใช้ named method/route ที่สื่อความหมายและตรวจ transition ฝั่ง server
- Model ใช้ชื่อ singular StudlyCase, table/column ใช้ plural/snake_case, foreign key ใช้ `<model>_id`, route name/URL และ permission key ใช้ convention เดียวทั้งระบบ; ข้อยกเว้นเพื่อรองรับข้อมูลเดิมต้องมี mapping และเหตุผล
- ใช้ Form Request สำหรับ input validation/authorization ที่เหมาะสม, Policy/Gate และ middleware สำหรับ access, Eloquent relationship/query scope สำหรับ data access, service container/config/service provider ตาม Laravel; ห้ามสร้าง validation, auth, ORM, router หรือ dependency container เอง
- เก็บ infrastructure/static application configuration ใน `config/*.php`, อ่าน environment ผ่าน config เท่านั้น และเก็บ business-varying policy ใน typed Global Settings; schema ทุกอย่างอยู่ใน Laravel migrations, seed data ที่ repeat ได้อยู่ใน seeder และ background work ใช้ Laravel Job/Queue/Scheduler/Command ไม่ทำ administrative HTTP route
- shared views ใช้ `resources/views`; runtime CSS/JavaScript ใช้ `public/css`, `public/js` และ pinned libraries ใช้ `public/vendor` โดย include จาก root layout เดียว; module views/routes เป็นการจัดกลุ่มที่บางและลงทะเบียนผ่าน service provider/bootstrap convention เดียว ห้ามให้แต่ละ module bootstrap framework หรือ include asset ซ้ำ
- ordinary CRUD ใช้ Eloquent/Controller/Form Request/Policy ให้ตรงไปตรงมา; service สร้างเมื่อมี transaction หลาย record, calculation หรือ cross-module invariant จริง ไม่สร้าง BaseController, BaseRepository, generic service หรือ helper ก้อนใหญ่เลียนแบบ legacy
- business/master model ที่ถูกอ้างอิงจากประวัติใช้ Eloquent `SoftDeletes` เป็นค่าเริ่มต้น และ UI ใช้ active/inactive ก่อน delete; ห้ามใช้ SoftDeletes แบบเหมารวมกับ pivot, journal, stock movement, cost allocation, audit log หรือ posted ledger ที่ต้อง immutable/reversal
- business-critical flow ต้องมองเห็นได้จาก application service/transaction ที่ชัดเจน ห้ามซ่อน stock/accounting/posting effect ใน model observer, accessor, Blade, JavaScript หรือ event chain ที่ trace ยาก
- ใช้ Laravel Pint/PSR-12, type declarations และชื่อ class/method/variable ที่สื่อ domain; รักษาคำศัพท์ เอกสาร code/status และ user flow ที่ทีมคุ้นเคยจาก `minterp` ผ่าน data dictionary แทนการคิดคำใหม่ในแต่ละ module
- ก่อนสร้าง pattern ใหม่ให้หา reference implementation ใน project นี้ก่อน; เมื่อ pattern มาตรฐานหนึ่งชุดถูกอนุมัติ ให้ Agent/module ถัดไปทำตามเพื่อให้ทีมบำรุงรักษาได้โดยไม่ต้องเรียนหลายสไตล์

## 4. Target architecture

```text
app/
├── Models/                    # shared framework models ที่จำเป็นจริง เช่น User
├── Support/                   # primitive/shared helper ที่มีผู้ใช้หลาย module เท่านั้น
└── Modules/
    ├── Platform/              # authentication, program/warehouse context, shared audit/files
    ├── Settings/              # company/global settings, users, access and administration
    ├── Wms/                   # purchasing: PR, PO, goods receipt, supplier return
    ├── Inventory/             # item, warehouse, stock movement, count, transfer, costing
    ├── Pos/                   # customer, quotation, sales order, invoice, receipt/return
    ├── Production/            # BOQ, BOM, work order, issue, multi-output, production cost
    ├── Finance/               # cash/bank, AR, AP, receipt, payment, reconciliation
    ├── Accounting/            # COA, journal, posting, period close, financial statements
    ├── Logistics/             # shipment, trip, dispatch, proof of delivery, transport cost
    └── Asset/                 # asset register, capitalization, depreciation, disposal
```

โครงสร้างภายใน module ให้สร้างเท่าที่ใช้งานจริง:

```text
Module/
├── Controllers/
├── Models/
├── Requests/
├── Services/          # เฉพาะ transaction/use case ที่เกิน CRUD หรือข้ามหลาย record
├── Policies/
├── Routes/web.php
└── Views/
```

ไม่สร้าง repository interface, event, DTO, command bus หรือ layer เพิ่มเพียงเพื่อให้โครงสร้างดูครบ ใช้ Eloquent, Form Request, Policy และ service แบบเจาะจงเป็นค่าเริ่มต้น

โครงสร้าง module เป็นเพียงการจัดกลุ่ม domain บน Laravel ไม่ใช่ framework ใหม่: shared migrations อยู่ใน `database/migrations`, shared Blade/components อยู่ใต้ `resources`, runtime assets อยู่ใต้ `public`, configuration อยู่ใน `config`, tests อยู่ใน `tests/Unit` และ entry/bootstrap ใช้ไฟล์มาตรฐาน Laravel 12

### Module interaction rules

- module เจ้าของข้อมูลเป็นผู้เขียนข้อมูลของตัวเอง
- module อื่นอ่านผ่าน relationship/query ที่เปิดเผยชัดเจน และสั่งเปลี่ยนสถานะผ่าน service ของ module เจ้าของ
- operation ที่ stock หรือบัญชีต้องสำเร็จพร้อมเอกสารต้นทางให้ใช้ synchronous service ใน `DB::transaction()`
- event/queue ใช้กับงานผลข้างเคียงที่ retry ได้ เช่น notification, export และ integration เท่านั้น
- ห้ามใช้ event แบบ asynchronous เป็นตัวรักษาความถูกต้องของ stock, payment หรือ journal
- งาน recost ย้อนหลังอนุญาตให้ทำผ่าน queue ได้เมื่อ movement เก็บต้นทุนเริ่มต้นและ dependency ไว้แล้ว, แสดง `cost_status` ชัดเจน และห้ามปิดงวด/ออกรายงาน final ขณะที่ยังมีผลกระทบค้างคำนวณ
- cross-module posting ต้อง idempotent และมี unique reference จาก `source_type + source_id + posting_type`

### Code readability and comments

- code ต้องอ่าน flow หลักได้จากชื่อ class/method/variable ก่อน; ถ้ายังต้องอธิบายทุกบรรทัดให้แยก method หรือตั้งชื่อใหม่ก่อนเพิ่ม comment
- เพิ่ม comment ใน logic ที่ซับซ้อนเพื่ออธิบาย **ทำไม** และข้อห้ามที่โค้ดบอกเองไม่ได้ เช่น accounting recognition/reversal, debit-credit mapping, stock costing, lock order, idempotency, rounding, timezone, branch/warehouse scope และ workaround ของ external system
- comment ต้องระบุ business invariant, edge case หรือผลเสียหากเปลี่ยนลำดับ เพื่อให้ junior developer แก้ต่อได้โดยไม่ทำลาย behavior
- ห้าม comment สิ่งที่เห็นชัดจาก syntax เช่น `// save order`; ห้ามเก็บ code ที่ comment ทิ้งไว้ ใช้ version control แทน
- PHPDoc ใช้กับ public/shared service contract เมื่อ parameter, return value, exception, units หรือ business meaning อธิบายด้วย type ไม่พอ ไม่บังคับ PHPDoc ซ้ำทุก method
- `TODO/FIXME` ต้องมีเหตุผล เงื่อนไขที่จะลบหรือแก้ และ issue/owner เมื่อมี; workaround ต้องบอก limitation และทางออกระยะยาวแบบสั้น
- เมื่อแก้ behavior ต้องแก้หรือลบ comment และ test ที่เกี่ยวข้องใน change เดียวกัน; comment ที่ล้าสมัยถือเป็น defect
- ใช้ภาษาไทยที่ชัดเจนหรือ simple English ให้ทีมอ่านเข้าใจ โดยคงคำศัพท์บัญชีและธุรกิจตาม data dictionary เดียวกัน

## 5. Shared data foundation

### Settings module and global settings

`Settings` เป็น module แยกจาก `Platform`: Platform ดูแล authentication และ selected program/branch context ส่วน Settings ดูแล company-wide configuration, users และ access assignment. Settings ไม่ต้องเลือกสาขาหรือคลัง เพราะค่ามีผลระดับบริษัท การเข้าถึงต้องผ่านทั้งโปรแกรม `settings` และ permission middleware; operational module เพิ่ม Policy/branch/warehouse scope ตาม use case. Branch context ที่ผู้ใช้เลือกและยังมีสิทธิ์ใช้งานต้องคงอยู่ใน session เมื่อเปลี่ยน Program; เลือกใหม่เฉพาะเมื่อยังไม่มี context, สาขาถูกปิด หรือสิทธิ์ถูกถอน และ app top bar ต้องแสดงสาขา/คลังปัจจุบันพร้อมทางเปลี่ยน context

User Management ขั้นพื้นฐานต้องค้นหา/แบ่งหน้า เพิ่ม–แก้ไข เปิด–ปิดผู้ใช้ และกำหนด program/warehouse access; ไม่เปิด hard delete จาก UI และต้องกันผู้ดูแลปิดตนเองหรือนำสิทธิ์ Settings ของตนเองออกจนล็อกระบบ

Role/Permission ใช้ Eloquent และ pivot มาตรฐานโดยไม่เพิ่ม RBAC package; admin role ปิดหรือเปลี่ยน code ไม่ได้. การเปลี่ยน Company/User/Role/Branch/Warehouse เขียน append-only audit log ใน transaction เดียวกันและห้ามเก็บ password/remember token; Audit DataTable/export ต้อง scrub sensitive values ซ้ำก่อน response

Global settings เป็น MVP ไม่ใช่งานภายหลัง และต้องมีหน้าจอบริหารอย่างน้อยดังนี้:

| กลุ่ม | ค่าที่ต้องตั้งได้ |
|---|---|
| Company | ชื่อบริษัท เลขผู้เสียภาษี โลโก้ ที่อยู่ ช่องทางติดต่อ; มีหนึ่ง record ต่อ installation |
| Localization | locale, timezone, date format, precision, base currency |
| Structure | company profile, branch, warehouse, location/bin |
| Documents | prefix, running number, reset policy, template, print footer |
| Tax | VAT, withholding tax, tax-inclusive/exclusive, tax point, document/report profile, rounding policy |
| Accounting | PAE/NPAE profile, fiscal year, company-wide periods, chart template, control accounts, tax documents, lock date |
| Inventory | company-wide costing policy (`AVG`/`FIFO`), effective date, allow-negative-stock, fallback provisional cost, default warehouse, UOM rounding |
| Approval | amount threshold, approver role/user, escalation, maker-checker และเอกสารที่ต้องอนุมัติ |
| Sales/Purchase | credit term, price/tax defaults, discount/promotion policy, return policy, default documents/status |
| Production | MTS/MTO enablement, default issue/output warehouse, standard labor/overhead, scrap/yield, output allocation, substitution approval |
| Logistics | transport mode, delivery status, distance/fuel units |
| Assets | capitalization threshold, depreciation method, useful-life defaults |
| Security | password/session policy, role, permission, branch/warehouse access |
| Product/License | subscription/license state, enabled modules, expiry/grace policy |
| Operations | posting/recost SLA thresholds, queue schedule/chunk controls, document/file/audit retention with legal minimum guard |
| Reports/Print | company header/footer, logo, signature, copies, paper size, language และ export defaults |

ค่าที่เป็น secret อยู่ใน environment/config เท่านั้น ไม่เก็บปะปนกับ editable business settings

### Global-setting decision and implementation rules

- ก่อน hard-code policy/default/threshold/format ให้ถามว่าแต่ละบริษัทหรือประเภทธุรกิจอาจใช้ต่างกันหรือไม่; ถ้าต่างและไม่ใช่ system invariant ให้ทำเป็น company Global Setting
- ค่าที่กระทบ accounting, costing, stock, authorization, document sequence, query/filter หรือ period close ใช้ typed table/column/enum พร้อม validation และ foreign key; key-value setting ใช้เฉพาะ optional presentation/extension ที่ไม่รักษา invariant
- มี company scope เดียวเป็นค่าเริ่มต้น; branch/warehouse override มีได้เฉพาะ setting ที่ requirement ระบุชัด ห้ามสร้าง hierarchy override ทั่วระบบเผื่ออนาคต
- setting ที่มีผลต่อเอกสารที่ post แล้วต้องมี effective date/version และ snapshot/reference บนเอกสารหรือ calculation result; การเปลี่ยนค่าไม่ rewrite ประวัติย้อนหลังโดยอัตโนมัติ
- setting สำคัญต้องมี permission, confirmation, audit before/after, reason และ dependency validation; ถ้าค่าที่จำเป็นยังไม่ครบให้ readiness check ระบุรายการและ block transaction ด้วยข้อความที่แก้ได้
- module อ่านค่าผ่าน typed Settings resolver เดียว ห้าม query settings table, อ่าน `.env` หรือกำหนด fallback กระจายตาม controller/model/JavaScript
- cache settings ที่อ่านบ่อยแบบมี version/key เดียวต่อ installation และ invalidate หลัง commit เมื่อแก้ค่า; ห้าม cache จน worker/job ใช้ค่าเก่าโดยไม่มี version
- ทุก setting ต้องมีชื่อ/คำอธิบายภาษาไทย, type, allowed values, default ที่มีเหตุผล, owner module และระบุว่าเปลี่ยนย้อนหลังได้หรือไม่
- สิ่งที่ไม่ทำเป็น Global Setting: secret/infrastructure config, database/GCS credential, security invariant, debit=credit, immutable posted records, company-wide costing-pool rule และข้อกำหนดที่เจ้าของระบบล็อกเป็น product behavior

### Core master data

- singleton company profile, branches, warehouses, warehouse locations
- users, roles, permissions, user branch/warehouse access
- parties + party roles (customer, supplier หรือทั้งสองอย่าง)
- addresses, contacts, payment terms, tax profiles
- items, item categories, item types, variants, barcodes, units และ unit conversions
- currencies, exchange rates, tax codes, bank/cash accounts
- fiscal years/periods, document sequences, approval definitions
- immutable audit log สำหรับเหตุการณ์สำคัญ

Party เป็น company-scoped identity กลางเพียงชุดเดียว: ข้อมูลชื่อ, Tax ID/Branch, ที่อยู่และผู้ติดต่ออยู่ที่ `parties` ส่วนบทบาท `CUSTOMER`/`SUPPLIER`, เงื่อนไขการชำระเงินและวงเงินอยู่ที่ `party_roles`; Party เดียวมีได้ทั้งสองบทบาทโดยไม่สร้างข้อมูลซ้ำ. POS เป็นเจ้าของหน้าจอ Customer, Purchasing เป็นเจ้าของหน้าจอ Supplier และ Finance อ้างอิง Party ID กลางพร้อมตรวจ role ของเอกสาร ห้ามสร้างตาราง Customer/Supplier identity แยกกันหรือผูก Party กับ Warehouse.

ไม่เพิ่ม tenant/`organization_id` ใน transactional tables; เพิ่ม `branch_id` และ `warehouse_id` เฉพาะเมื่อขอบเขตธุรกิจต้องใช้จริง พร้อม foreign key และ composite index ตาม query path

### กติกากลาง: สาขาและคลังในเอกสารปฏิบัติการ

- ผู้ใช้เลือก **สาขา** เป็น context หลักก่อนเข้าใช้งาน ไม่เลือกคลังเป็น context หลัก; ระบบเลือกคลังเริ่มต้นที่ active ของสาขาให้เมื่อจำเป็น
- เอกสารขายและเอกสารจัดซื้อทุกประเภทต้องบันทึก `branch_id` เสมอ ตั้งแต่ Draft และห้ามเปลี่ยนเมื่อเอกสารถูก Post แล้ว
- เอกสารหรือบรรทัดที่มีผลต่อสินค้า, การรับเข้า, การจ่ายออก, การส่งมอบ, การโอน หรือการคำนวณต้นทุน ต้องบันทึก `warehouse_id` เสมอ; เอกสารที่ไม่มีผลต่อสต็อกไม่ต้องบังคับ `warehouse_id`
- `warehouse_id` ต้องเป็นคลัง active ที่อยู่ใต้ `branch_id` เดียวกับเอกสารเท่านั้น ทั้งตัวเลือกใน UI และ validation ฝั่ง server; ห้ามเลือกคลังข้ามสาขา
- หากสาขามีหลายคลัง ผู้ใช้เปลี่ยนคลังได้เฉพาะจุดสินค้า/การส่งมอบที่เกี่ยวข้อง และต้องเห็นเฉพาะคลังของสาขาในเอกสาร
- POS, Purchasing และ WMS ใช้กติกานี้ร่วมกัน; WMS ยังคงใช้ `warehouse_id` เป็นมิติ stock balance/costing และต้องอ่าน `branch_id` จากเอกสารต้นทางเพื่อ scope, รายงาน และ audit

### Shared file storage and attachments

- ใช้ Google Cloud Storage (GCS) เป็น object storage ของ environment ที่ deploy; local development/manual QA เปลี่ยน disk ผ่าน config หรือ fake disk ได้
- ใช้ Laravel Filesystem/Flysystem v3 กับ adapter ที่รองรับ Laravel 12 และยังมีการดูแล เช่น `spatie/laravel-google-cloud-storage`; ห้ามยก `superbalist/laravel-google-cloud-storage` รุ่นเก่าจาก `minterp` มาใช้
- module เรียก `FileStorageService`/attachment contract ของ Platform เท่านั้น ห้ามเรียก Google SDK, `Storage::disk('gcs')` หรือประกอบ GCS URL กระจายตาม Controller/Job
- bucket เป็น private, เปิด Uniform bucket-level access และใช้ IAM; download/preview ให้ application ตรวจสิทธิ์ก่อนออก V4 signed URL อายุสั้น ส่วน public object ต้องเป็นกรณีที่อนุมัติและแยก policy ชัดเจน
- production ใช้ Application Default Credentials/Workload Identity เมื่อ infrastructure รองรับ; key file ใช้เฉพาะ environment ที่จำเป็นและห้าม commit credential หรือ JSON key
- object key สร้างโดยระบบตาม convention เช่น `<environment>/<company-code>/<module>/<yyyy>/<mm>/<uuid>` ไม่ใช้ชื่อไฟล์จากผู้ใช้เป็น path โดยตรง
- เก็บ attachment metadata ในฐานข้อมูลอย่างน้อย owner/source, branch scope เมื่อเกี่ยวข้อง, disk, object key, original name, MIME, size, checksum, uploader และ timestamps; ทุก download/delete ต้องตรวจ Policy
- validate MIME, extension และขนาดฝั่ง server; upload/delete ต้องมี audit, error handling และ orphan cleanup เพราะ database transaction กับ object storage ไม่ atomic
- MVP upload ผ่าน application server ก่อน เพิ่ม direct signed upload เฉพาะเมื่อมีหลักฐานว่าขนาดไฟล์หรือ load จำเป็นต้องใช้
- lifecycle, retention/versioning, CORS, cleanup งานที่ล้มเหลว และ backup/restore expectation ต้องกำหนดแยกตามประเภทเอกสาร ห้ามถือว่า bucket คือ backup ของฐานข้อมูล
