# Purchasing Module Migration Plan

## Objective

ย้าย route, controller, view, model และ request ของงานจัดซื้อจาก `app/Modules/Wms` ไปเป็น ownership จริงของ `app/Modules/Purchasing` โดยรักษา route, permission, branch/warehouse scope, document sequence, accounting และ inventory integration เดิมให้ทำงานต่อเนื่อง

## Current state

- `app/Modules/Purchasing/Routes/web.php` มี canonical Purchasing routes แล้ว
- Purchasing มี Controller implementation จริงและเป็น route owner แล้ว; legacy WMS Purchasing controllers ถูก retire
- Purchasing มี canonical views แล้ว; legacy WMS Purchasing views ถูกลบหลัง cutover
- Purchasing มี Request implementation จริงแล้ว; legacy WMS Purchasing requests ถูกลบหลัง cutover
- Purchase-related Models หลักมี canonical ownership ใน `app/Modules/Purchasing/Models` แล้ว และ WMS model wrappers ถูกลบหลังย้าย references ครบ
- Inventory movement, cost layer, RECOST และ inventory posting services ยังคงเป็น WMS ownership; Purchasing orchestration services อยู่ Purchasing แล้ว
- WMS Purchasing routes เดิมถูก retire แล้ว เนื่องจากระบบยังไม่มีผู้ใช้งานจริงและไม่จำเป็นต้องเปิด compatibility window

## Target ownership

ย้ายไป `app/Modules/Purchasing`:

- Controllers: Supplier, Purchase Requisition, Purchase Order, Goods Receipt, Purchase Document และ Purchase Document PDF
- Purchasing PDF surface only: `PurchaseDocumentPdfController` สำหรับ PR/PO/GR/AP, Purchasing PDF view/template, Purchasing route names, permission boundary และ data snapshot contract; ไม่รวม PDF ของ module อื่น
- Models: Purchase Requisition/Line, Purchase Order/Line, Goods Receipt/Line, Purchase Document/Line/Receipt Allocation และ Purchase Variance Approval
- Requests: requests ของ workflow จัดซื้อทั้งหมด รวม validation contract, authorization และ compatibility wrapper
- Services: purchasing orchestration/business services ที่ไม่แตะ Stock/Cost/GL โดยตรงอยู่ Purchasing; service ที่โพสต์หรือกลับรายการ Inventory คง WMS boundary
- Support: purchasing state machine, calculator, three-way matching และ Landed Cost allocation policy/support contract ที่เป็น domain ของ Purchasing; WMS support คงไว้เฉพาะ inventory/costing integration
- Views: suppliers, purchase-requisitions, purchase-orders, purchase-receipts, purchase-documents และ purchase PDF
- Routes: canonical routes อยู่ที่ `Purchasing::Routes`

คงไว้ที่ WMS:

- Stock Movement และ Inventory models
- Inventory posting, reversal, costing, RECOST และ GL bridge services
- Inventory contracts/adapters ที่รับ source จาก Goods Receipt หรือ Purchase Document

## Phases

### Phase 1 — Inventory and boundary map

- รวบรวม reference ของ Controller, Model, Request, View, Route, factory, seeder, service และ test
- ตรวจ polymorphic `source_type/source_id`, route names, view aliases และ module permissions
- ระบุ WMS dependencies ที่เป็น integration boundary และห้ามย้าย
- เพิ่ม/ปรับ contract checks ให้ตรวจว่า Purchasing route ไม่ผูกกับ implementation ของ WMS หลัง cutover

#### Initial inventory

| Area | Current finding | Migration note |
|---|---|---|
| Routes | Purchasing canonical routes มีครบสำหรับ Supplier, PR, PO, Receipt, AP และ Landed Cost; WMS Purchasing routes ถูกถอดแล้ว | ตรวจ route cache และ canonical binding ต่อเนื่อง |
| Controllers | Purchasing adapters ของ PO, AP และ Supplier ยัง extends WMS; Requisition/Receipt มี seam ลักษณะเดียวกัน | เริ่มจาก Model/Request ก่อนตัด inheritance |
| Views | Purchasing มีไฟล์ซ้ำบางส่วน แต่บางไฟล์ยัง include `Wms::...`; Purchasing provider ยังโหลด WMS view fallback | เปลี่ยนเป็น Purchasing-owned view และลบ fallback หลัง verification |
| Models | Purchase-related Models หลักอยู่ Purchasing; WMS ไม่มี purchase model wrapper แล้ว | รักษา Purchasing ownership และตรวจ relation เมื่อเพิ่ม feature ใหม่ |
| Requests | มี Purchasing request seams แต่หลายไฟล์ยัง extends WMS requests | ย้าย implementation และคง validation contract เดิม |
| Inventory boundary | Goods Receipt inventory posting, Stock Movement, costing, RECOST และ GL adapters อยู่ WMS | ไม่ย้าย ownership; Purchasing เรียกผ่าน service boundary |
| Matching boundary | `PurchaseThreeWayMatchContract/Policy/Service/Gate` อ่าน persisted PO/GR/Invoice allocation แต่เป็น policy/domain ของ Purchasing และถูกเรียกจาก WMS posting services | ย้าย ownership ไป Purchasing Support แล้ว; WMS เรียกผ่าน canonical support namespace |
| External references | Purchasing services ถูกย้ายแล้ว; WMS ยังมีเฉพาะ inventory/cost services และ support ที่รับ Purchasing models | รักษา integration boundary และตรวจ consumer เมื่อเพิ่ม workflow ใหม่ |

### Phase 2 — Move Models, Requests, Services and Support

- ย้าย namespace Model ไป Purchasing และแก้ relationships/imports
- ย้าย Request implementation ออกจาก inheritance ของ WMS
- แยก Service/Support ที่เป็น Purchasing domain ออกจาก service/support ของ Inventory boundary
- แก้ references ใน services, controllers, seeders และ tests
- ตรวจ migration compatibility และ polymorphic identity โดยไม่เปลี่ยนข้อมูลเดิม

### Phase 3 — Cut over Controllers

- เปลี่ยน Purchasing adapters เป็น implementation จริง
- คง `purchasing` route prefix และ Purchasing permission boundary
- ให้ Controller เรียก WMS services เฉพาะ integration ที่เกี่ยวกับ Stock/Cost/Inventory
- ลบ inheritance จาก WMS เมื่อ test ครบ

### Phase 4 — Cut over Views

- ใช้ `Purchasing::layout` และ Purchasing view alias ทั้งหมด
- ลบ `@include('Wms::...')` และ view fallback
- ตรวจ DataTable, Select2, AJAX, PDF และ link ทุกจุดให้ใช้ `purchasing.*`

### Phase 5 — Route retirement and cleanup

- ให้ Purchasing routes เป็น route หลัก
- retire WMS purchasing routes, controllers, requests, views และ purchasing domain support ทันทีหลังตรวจ consumer
- ตรวจ route collision โดยเฉพาะ `data` กับ wildcard routes
- คง WMS purchase models เฉพาะที่ WMS inventory/cost และ module อื่นยัง type-hint; ลบได้เมื่อย้าย integration references ครบ

#### Cleanup audit (2026-09-03)

- ลบ/ไม่คง `app/Modules/Purchasing/Controllers/PurchaseRequisitionControllerAdapter.php` ซึ่งไม่มี route หรือ consumer ใช้งานแล้ว
- retire `app/Modules/Wms` Purchasing routes/controllers/requests/views และ purchasing domain support แล้ว; ไม่มี legacy route consumer เหลือ
- คง WMS purchase models และ integration support ที่ WMS inventory/cost, Asset หรือ Pos ยังอ้างอิง; จะลบหลังย้าย reference ครบ
- เพิ่ม boundary test ยืนยัน stale adapter ต้องไม่กลับมา และ legacy wrappers ต้องมี consumer ก่อนจึงจะลบได้
- ตรวจ route action แล้ว: canonical route bind กับ Purchasing Controller เพียงชุดเดียว และ DataTable/option route ไม่ชน wildcard
- ตรวจ Purchasing views/frontend references แล้ว ไม่พบ hardcoded `wms.*` route หรือ permission; Purchasing ใช้ layout และ permission namespace ของตนเอง
- MySQL integration QA ผ่าน 2 tests / 32 assertions สำหรับ Purchase Document inventory posting และ Goods Receipt → Landed Cost lifecycle; ทั้งคู่ rollback-safe และรันด้วย `ERP_INVENTORY_PURCHASE_POSTING_ENABLED=false` เป็น runtime override โดยไม่แก้ `.env`
- เพิ่ม multi-GR integration coverage ใน `LandedCostMySqlIntegrationReadinessTest`: 2 posted receipts จาก PO เดียวกันถูก allocate แยกต่อ receipt ผ่าน 2 tests / 22 assertions; VAT IN exclusive/inclusive, NONE VAT และ allocation calculator ผ่าน 11 tests / 23 assertions
- เพิ่ม `PurchaseDocumentVatProfileMySqlIntegrationReadinessTest` สำหรับ read-only validation ของ Supplier ที่มี `tax_id` และ active VAT IN Tax Code; ผ่าน 1 test / 8 assertions ใน dedicated MySQL integration process
- Final MySQL verification (2026-09-04): Landed Cost multi-GR/lifecycle, Supplier VAT profile และ Credit Purchase reversal ผ่าน 5 tests / 41 assertions โดยมี 1 intentional skip สำหรับ persistent operational evidence; Inventory Purchase enabled smoke ผ่าน 1 test / 8 assertions
- ปรับ focused regression tests ให้ตรวจ canonical Purchasing routes และยืนยัน legacy WMS Purchasing surface ถูก retire
- Cross-module consumers ที่อยู่นอก WMS เปลี่ยนเป็น canonical Purchasing route/permission แล้ว ได้แก่ Accounting journal drill-down และ Platform Workflow runtime/catalog; ผ่าน regression suite 65 tests / 1,338 assertions

## Test gates

- PR → PO → Goods Receipt → Purchase Document
- Partial receipt, three-way match และ variance approval
- Purchase Document → Journal / Inventory Posting / reversal
- Branch and warehouse scope
- Permission and sidebar visibility
- DataTable, Select2, AJAX และ PDF
- Document sequence และ idempotency
- Landed Cost linkage กับ Goods Receipt และ WMS Cost Allocation

## Definition of done

- Purchasing Controller ไม่ extends จาก WMS
- Purchase-related Models/Requests ไม่อยู่ใน WMS
- Purchasing Views ไม่ include จาก WMS
- Purchasing routes ไม่ bind กับ WMS purchasing Controllers
- ไม่มี reference ของ `App\\Modules\\Wms\\Models\\Purchase...` ใน Purchasing flow
- ทุก workflow และ test ที่เกี่ยวข้องผ่าน
- อัปเดต `docs/planning/06-core-feature-menu-checklist.md` หลังแต่ละ phase

## Current progress

- [~] Phase 1 — inventory/dependency map completed for initial route/controller/view/model/request/service/test references
- [x] Phase 2 — Models, Requests, Services and Support (Purchase-related Models, requests, orchestration services, three-way matching และ Landed Cost allocation support อยู่ Purchasing แล้ว; references ใน WMS/Asset/POS/Platform/seeders/tests ย้ายเป็น `Purchasing\\Models`, `Purchasing\\Services` หรือ `Purchasing\\Support`; posting/receipt movement/cost adapters คงเป็น WMS integration support; WMS purchase model wrappers ถูกลบ)
- [x] Phase 3 — Controllers (Purchasing เป็น controller/route owner; legacy WMS Purchasing controllers และ routes ถูก retire; Stock/Cost/GL ยังคงเชื่อมผ่าน WMS service boundary)
- [x] Phase 4 — Views/PDF (Purchasing เป็นเจ้าของ views/layout/PDF; legacy WMS Purchasing views และ PDF wrapper ถูก retire; canonical permission/route ผ่านแล้ว)
- [x] Phase 5 — Route retirement and cleanup (ลบ legacy WMS Purchasing routes/controllers/requests/views/domain support และ model wrappers, ปรับ permission/tests/docs และตรวจ route/view cache แล้ว; WMS เหลือเฉพาะ inventory/cost integration models/services)
