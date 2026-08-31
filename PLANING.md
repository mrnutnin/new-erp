# New ERP Development Plan

> เอกสารนี้เป็น entrypoint ของแผนงานสำหรับมนุษย์และ Agent ทุกตัว  
> อ่านเฉพาะ reference ที่เกี่ยวกับงาน เพื่อลด context และห้ามโหลดทั้ง 4 ไฟล์โดยไม่มีเหตุผล

สถานะงานแบบย่อและรายการที่ทำเสร็จ/กำลังทำ/รอดำเนินการดูที่ [CHECKLIST.md](CHECKLIST.md)

## Product objective

สร้าง ERP แบบ modular monolith บน PHP 8.2, Laravel 12 และ MySQL โดยนำ business behavior ที่พิสูจน์แล้วจาก `/Users/mrnutninlaong/GitRepository/minterp` มาเป็นข้อมูลอ้างอิง แต่เขียน architecture ใหม่ให้รองรับหลายประเภทธุรกิจ

หนึ่ง installation/database มีหนึ่งบริษัทตามกฎหมาย ระบบเป็น web/online application, deploy หลักบน GCP และ config ให้ deploy on-premise ได้ ไม่ทำ SaaS, multi-company, intercompany, offline mode หรือ microservices ใน MVP

MVP ประกอบด้วย Platform, Settings, Purchasing, WMS, POS/Sales Order, Finance และ Accounting เป็นแกนใช้งานร่วมกัน ส่วน Production, Logistics และ Asset เป็น optional modules ที่เปิดใช้ตามประเภทธุรกิจ โดย Accounting และ WMS inventory costing เป็น shared kernel ที่ operational modules ห้ามเขียนข้ามเอง

## How to use this plan

อ่าน `SKILL.md` ก่อนเสมอ แล้วเลือกเอกสารตามงาน:

| งานที่กำลังทำ | เอกสารที่ต้องอ่าน |
|---|---|
| product boundary, architecture, module structure, shared data, Global Settings, GCS/file storage | [01-product-architecture.md](docs/planning/01-product-architecture.md) |
| stock movement, AVG/FIFO, transfer/recost, บัญชี 5 เล่ม, GL, period close, migration จาก Express/WinSpeed | [02-accounting-inventory.md](docs/planning/02-accounting-inventory.md) |
| scope/exit criteria ของแต่ละ module, end-to-end MVP flow, Blade/jQuery/AJAX/DataTables/SweetAlert/UI libraries | [03-modules-ui.md](docs/planning/03-modules-ui.md) |
| roadmap, dependency gate, Agent package, Definition of Done, risk และ decision ที่ยังรอยืนยัน | [04-delivery.md](docs/planning/04-delivery.md) |

### Minimum reading by task

- งาน CRUD ทั่วไป: อ่าน architecture + module/UI เฉพาะหัวข้อที่เกี่ยวข้อง
- งาน stock, production cost หรือ branch transfer: เพิ่ม accounting/inventory
- งานที่ post เงิน ภาษี หรือ GL: เพิ่ม accounting/inventory
- งาน migration/import: อ่าน migration section ใน accounting/inventory และ domain ปลายทาง
- งานวางแผน phase, integration, review หรือ handoff: อ่าน delivery
- งานที่แก้ shared contract ข้าม module: อ่าน reference ของทุก domain ที่ได้รับผลกระทบก่อนแก้

ใช้ `rg` ค้น heading/คำศัพท์ใน reference ก่อนเปิดทั้งไฟล์ เช่น:

```bash
rg -n "AVG|FIFO|recost" docs/planning/02-accounting-inventory.md
rg -n "### Accounting|### Production" docs/planning/03-modules-ui.md
rg -n "Definition of Done|Decisions still" docs/planning/04-delivery.md
```

## Fixed decisions quick reference

- ทุก module ต้องเข้า Dashboard ก่อนเสมอหลังเลือก context และ Sidemenu ต้องวาง “กลับหน้าเลือกโปรแกรม” เป็นรายการแรกบนสุด ตามด้วย Dashboard และเมนูงานของ module
- ทุก module ต้องมีแผน Workflow Center/คู่มือการทำงานที่อธิบายลำดับเอกสาร, readiness, สิทธิ์ และ next action ตาม `docs/planning/05-module-workflows.md`
- Workflow Center ของทุก module ต้องแยกโหมด `เริ่มใช้งานครั้งแรก (setup)` กับ `งานประจำวัน (daily)` อย่างชัดเจน: setup สำหรับ master/configuration/opening/mapping/readiness และ daily สำหรับงานเอกสารที่ทำซ้ำ, งานค้าง, approval, settlement และ close; ห้ามรวมสองโหมดจนผู้ใช้ใหม่แยกลำดับไม่ได้
- Production เป็น optional capability: บริษัทซื้อมาขายไปต้องใช้งาน Purchasing → WMS → Sales/POS → Finance → Accounting ได้โดยไม่ต้องเปิด BOM, BOQ, Work Order, WIP หรือ Production GL; module ที่ปิดต้องไม่สร้าง readiness blocker, เมนูบังคับ หรือ dependency ใน workflow หลัก
- ERP ต้องออกแบบให้ผู้ใช้ที่ไม่เคยใช้ ERP เริ่มงานได้เอง: ใช้ภาษางานจริง, แสดงขั้นตอนถัดไปและสิ่งที่ต้องเตรียม, มีค่าเริ่มต้น/ตัวอย่างที่ปลอดภัย, อธิบาย blocker พร้อมวิธีแก้ และไม่บังคับให้ผู้ใช้จำรหัสเอกสารหรือผลกระทบทางบัญชีเอง; รายละเอียด UX อยู่ใน `docs/planning/03-modules-ui.md` และ `docs/planning/05-module-workflows.md`
- Human-error recovery เป็น shared contract: ทุก validation/state error ต้องบอกว่า “ผิดตรงไหน–แก้อย่างไร–ไปแก้ที่เมนูใด–ผลกระทบคืออะไร”; เอกสาร Draft/Approved แก้หรือ Void ได้ตามสิทธิ์, เอกสาร Posted ห้ามแก้ทับและต้องใช้เอกสารแก้ไข/contra/reversal ที่มีเหตุผล, audit และ idempotency; Workflow Center ต้องแสดงวิธีย้อนกลับและเงื่อนไขที่ย้อนกลับไม่ได้
- Bootstrap 5 + Blade view, shared template/CSS, jQuery-first behavior, AJAX CRUD, Yajra DataTables และ SweetAlert2; ใช้ Bootstrap component/utility class ก่อนเขียน CSS เพิ่ม, library pin ไว้ใน `public/vendor`, Boxicons 2.1.4 ใช้ CDN ที่ root layout เป็น icon family เดียว และไม่ต้องมี npm/Vite/frontend server
- ใช้ Laravel 12 convention เป็นโครงสร้างเทคนิค และใช้ `minterp` เป็น behavioral/business-style reference; `app/Modules` เป็นเพียง domain grouping ไม่สร้าง custom framework
- DataTable ทุกตัวมี DataTables Buttons `excelHtml5`, pagination, page length และ search; รายการฐานข้อมูลที่โตได้ใช้ server-side โดย HTML5 export ส่งออกแถวที่ browser โหลดอยู่ และค่อยเพิ่ม backend full-dataset export เมื่อเจ้าของระบบระบุเป็นรายกรณี
- หน้า list แบบ DataTable ใช้ flow ที่ทีมคุ้นเคยจาก `minterp`: `index()` คืน Blade เท่านั้น ไม่ compact/query dataset ของแถว; JavaScript ยิง AJAX ไป `data()` route แยกและ Yajra ทำ server-side (อนุญาตให้ index ส่งเฉพาะ option/filter dataset ขนาดเล็กที่หน้าจอต้องใช้จริง)
- JavaScript ที่ใช้เฉพาะหน้าให้อยู่ท้าย Blade เดียวกันใน `@push('scripts')` เพื่อให้ทีมเปิดแก้ได้จุดเดียว; แยกไป `public/js` เฉพาะ shared behavior, reuse จริง หรือ script ใหญ่จน Blade ดูแลยาก
- CRUD save ใช้ shared `erpAjaxForm()` และ delete ใช้ `erpAjaxDelete()` โดยแต่ละหน้ากำหนด selector/url/method/reload/redirect/alert-or-confirm; ค่าเริ่มต้นไม่ reload/redirect, หน้า Create ค่อยเปิด redirect, หน้า Update อยู่หน้าเดิม และ Delete reload เฉพาะ DataTable. Controller ตอบ `status` + `msg` (+ `redirect` เมื่อจำเป็น); delete ต้องมี permission, transaction, audit และ SoftDelete/ข้อห้ามตาม domain
- company-wide costing policy เลือก AVG หรือ FIFO; ไม่แยก costing pool ตามสาขา/คลัง
- อนุญาต stock ติดลบตาม Global Setting และลงย้อนหลังได้ตราบใดที่งวดยังเปิด
- period close/reopen ทั้งบริษัท และ Accounting ต้องรองรับบัญชี 5 เล่ม, GL, Trial Balance, P&L, Balance Sheet, PAE/NPAE, VAT และ WHT
- BOQ คือประมาณราคางาน/project; BOM คือสูตรผลิต รองรับ MTS/MTO และหลาย output/by-product
- ไฟล์ถาวรใช้ private GCS ผ่าน Platform storage contract
- automated tests เขียนเฉพาะ Unit Test; integration/database/UI/queue/GCS ใช้ repeatable manual QA
- Express/WinSpeed เป็นแหล่ง migration เดิม โดย import ผ่าน versioned ERP Excel templates ไม่ทำ continuous sync ใน MVP
- policy/default/threshold/format ที่แต่ละบริษัทอาจต่างกันและไม่ใช่ invariant ให้พิจารณาเป็น typed Global Setting
- แยก `Settings` module สำหรับ Global Settings และ User Management; `Platform` รับผิดชอบ login/context/shared infrastructure เท่านั้น ผู้ใช้ไม่ถูก hard delete จากหน้าจอ ให้เปิด–ปิดสถานะและใช้ SoftDeletes รองรับ lifecycle ที่ต้องเก็บประวัติ

## Document ownership

รายละเอียดหนึ่งเรื่องต้องมี source of truth เพียงไฟล์เดียว:

- เปลี่ยน product/architecture/global invariant ที่ `01-product-architecture.md`
- เปลี่ยน costing/accounting/migration invariant ที่ `02-accounting-inventory.md`
- เปลี่ยน module scope/flow/UI convention ที่ `03-modules-ui.md`
- เปลี่ยน phase/DoD/risk/open decision ที่ `04-delivery.md`
- แก้ entrypoint นี้เฉพาะ routing หรือ fixed-decision summary
- แก้ `SKILL.md` เมื่อกฎที่ Agent ต้องปฏิบัติทุกครั้งเปลี่ยน ไม่คัดลอกรายละเอียด domain กลับมาไว้ใน entrypoint
