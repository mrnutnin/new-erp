# Document Template Builder Plan

## Objective

ให้แต่ละบริษัทกำหนดรูปแบบเอกสารของตนเองได้ โดยรองรับข้อมูลบริษัท, ลูกค้า/Supplier, รายการสินค้า, ยอดรวม, ภาษี และลายเซ็น พร้อม Preview และ PDF ที่ใช้ renderer เดียวกัน

## Ownership and boundary

- `Platform` เป็นเจ้าของ Template definition, versioning, field registry, renderer และ preview service
- `Global Settings` เป็นจุดเข้าจัดการข้อมูลบริษัท/branding และเลือก default template ของเอกสาร
- `Purchasing`, `Sales`, `Accounting`, `Finance` เป็นเจ้าของข้อมูลธุรกรรมและประกาศ document type ที่รองรับ
- PDF renderer ต้องรับเฉพาะ normalized document payload จาก module ต้นทาง ไม่ query ข้อมูลธุรกรรมเอง

## MVP scope

### Supported template features

- Company logo, name, address, tax ID และ branch information
- Document title, document number, dates และ reference fields
- Customer/Supplier identity
- Line-item table พร้อม quantity, UOM, unit price, discount และ amount
- Subtotal, VAT, withholding tax, rounding และ grand total
- Notes, payment terms, signatures และ footer
- แสดง/ซ่อน field และกำหนด label ได้
- กำหนดลำดับ section, spacing, alignment และรูปแบบตัวเลข
- Preview ด้วย sample data และ PDF preview
- Default template แยกตามบริษัทและ document type

### Initial document types

- Purchasing: Purchase Requisition, Purchase Order, Goods Receipt, Purchase Invoice, Credit Note, Landed Cost
- Accounting/Finance: ยังเตรียม registry และ renderer contract ไว้ แต่ไม่บังคับเปิดใช้งานใน Phase 1

## User experience

จุดเข้าหลัก: `Settings → Global Settings → Document Templates`

Flow:

`เลือกประเภทเอกสาร → เลือก Template → แก้ไข section/field → Preview → Save Draft → Publish`

MVP ใช้ section-based editor และ field registry ที่ระบบอนุญาตก่อน ยังไม่ทำ free-form HTML หรือ drag-and-drop canvas เต็มรูปแบบ

## Template data model

### `platform_document_templates`

- `company_id`, `document_type`, `name`, `is_default`, `status`
- `created_by`, `updated_by`, timestamps และ soft delete ตามมาตรฐาน Platform

### `platform_document_template_versions`

- `template_id`, `version`, `status` (`DRAFT`, `PUBLISHED`, `RETIRED`)
- `schema_version`, `definition` JSON, `published_by`, `published_at`
- unique `(template_id, version)`

### Definition JSON constraints

- ใช้เฉพาะ component type ที่ whitelist เช่น `text`, `image`, `field`, `table`, `totals`, `signature`, `page_break`
- field ต้องอ้างอิง key จาก document field registry
- ห้ามฝัง JavaScript, arbitrary SQL, remote executable content หรือ template code
- เก็บ immutable snapshot ของ version ที่ใช้ render เอกสารแล้ว

## Renderer architecture

1. Module ต้นทางสร้าง normalized payload ตาม document contract
2. Platform ตรวจ template version และ field permissions
3. Renderer แปลง definition + payload เป็น HTML preview
4. PDF adapter แปลง HTML เดียวกันเป็น PDF
5. เก็บ `template_id` และ `template_version_id` ในเอกสารที่ออกแล้ว

Preview และ PDF ต้องใช้ renderer path เดียวกัน เพื่อป้องกันผลลัพธ์ไม่ตรงกัน

## Implementation phases

### Phase 1 — Foundation and registry

- migrations, models, policies และ company scope
- document type registry และ field registry
- template version lifecycle: Draft/Publish/Retire
- normalized payload contract สำหรับ Purchasing PDF
- automated unit/contract tests

### Phase 2 — Builder and preview

- section-based editor
- field visibility, labels, order และ basic style options
- sample data preview
- HTML preview endpoint แบบ read-only
- validation ของ definition JSON

### Phase 3 — PDF integration

- ใช้ renderer เดียวกับ preview
- เชื่อม Purchasing PDF controller ทุก document type ที่กำหนด
- fallback ไป Default System Template เมื่อไม่มี company template
- เก็บ version ที่ใช้กับเอกสารเดิม
- automated PDF smoke/HTML contract tests

### Phase 4 — Hardening

- permission, audit log และ publish approval ตามความจำเป็น
- cache compiled template definition
- render timeout, payload size limit และ observability
- manual owner sign-off เฉพาะ typography/print quality

## Performance requirements

- Dashboard/editor initial page ห้าม load document payload ขนาดใหญ่โดยอัตโนมัติ
- Preview โหลดเมื่อผู้ใช้กด Preview หรือแก้ไขครบ debounce interval
- cache published definition ตาม `company_id + document_type + version`
- PDF render ใช้ compiled definition และ query ข้อมูลธุรกรรมเพียงครั้งเดียวจาก module ต้นทาง
- จำกัดขนาด logo, จำนวน rows และจำนวน component ต่อ template
- แยก preview/PDF render endpoint จาก CRUD template เพื่อควบคุม timeout และ queue ได้ในอนาคต

## Security and data integrity

- ทุก record ต้อง scope ตาม company และสิทธิ์ผู้ใช้
- Published version ห้ามแก้ไขโดยตรง ให้สร้าง version ใหม่
- เอกสารที่ออกแล้วต้อง render ด้วย version เดิม
- sanitize text/HTML และ reject component/field ที่ไม่อยู่ใน registry
- ตรวจ MIME, ขนาด และ storage path ของ logo/signature
- audit การสร้าง, publish, retire และเปลี่ยน default template

## Acceptance criteria

- ผู้ใช้สร้างและ publish template แยกตามบริษัทและ document type ได้
- Preview แสดงข้อมูลบริษัทและ sample transaction ได้
- PDF กับ Preview ใช้ layout และ field mapping เดียวกัน
- template ที่ publish แล้วไม่กระทบเอกสารเก่า
- field ที่ไม่อนุญาตหรือ definition ที่มี script ถูกปฏิเสธ
- ไม่มี template ของบริษัทหนึ่งถูกอ่านหรือใช้กับอีกบริษัท
- ไม่มี template ระบบ ให้ fallback ได้โดยไม่ทำให้การออก PDF ล้มเหลว
- cache ถูก invalidate เมื่อ publish หรือเปลี่ยน default
- Unit, feature, security scope และ PDF smoke tests ผ่าน

## Out of scope for MVP

- Free-form HTML/JavaScript editor
- Drag-and-drop canvas เต็มรูปแบบ
- Conditional formula ที่ผู้ใช้เขียนเอง
- Multi-language translation management เต็มรูปแบบ
- Dynamic report designer และ spreadsheet-like layout

## Progress log

> Phase 1 foundation (2026-09-04): สร้าง Template/Version schema, Platform models และ publish contract แล้ว; migration local MySQL ผ่าน และ unit tests ผ่าน 3 tests / 12 assertions. ยังไม่เริ่ม builder, renderer หรือ PDF integration.

> Phase 1 template service (2026-09-04): เพิ่ม `DocumentTemplateService` สำหรับ create template/version, publish, retire และ resolve default โดย scope ตามบริษัท, retire published version เดิม และใช้ transaction ทุก mutation; tests ที่เกี่ยวข้องผ่าน 4 tests / 17 assertions.

> Phase 2 template lifecycle (2026-09-04): เพิ่มแก้ไขเฉพาะ Draft, สร้าง Draft Version ใหม่จาก Version ล่าสุด และ Archive แบบ soft lifecycle; Published แก้ไขทับไม่ได้เพื่อรักษาเอกสารย้อนหลัง; เพิ่มปุ่ม Preview/Edit/New Version/Archive บนหน้า Settings และอัปเดต checklist แล้ว.

> Phase 2 branding (2026-09-04): เปิดใช้ `company.logo` ใน field registry และ shared renderer; Preview ใช้ logo จาก Global Settings และ Purchase Order PDF ส่ง company logo เข้า renderer แล้ว.

> Phase 2 PDF preview (2026-09-04): ปรับ Preview ของ Version ให้ใช้ `DocumentPdfRenderer` และตอบกลับ `application/pdf` แบบ inline; browser จึงเปิด PDF จริงจาก URL เดิม และใช้ HTML renderer เดียวกับ Preview.

> Phase 2 layout properties (2026-09-04): เพิ่มค่าต่อ Section สำหรับ alignment, spacing, image size, label และ visible; เก็บใน definition เดิม และ renderer ใช้ค่าร่วมกันทั้ง HTML Preview/PDF.

> Phase 2 UX polish (2026-09-04): ปรับ Section card เป็น responsive two-row layout แยก controls หลักกับ Layout Properties และรองรับหน้าจอแคบ โดยไม่เพิ่ม dependency.

> Phase 2 page separation (2026-09-04): แยกหน้า Template List, Create และ Edit Draft เป็นคนละ URL; List เน้นการจัดการรายการ ส่วน Form เน้นการออกแบบและ Preview ลดความหนาแน่นของหน้าจอ.

> Phase 2 form layout (2026-09-04): หน้า Create/Edit จัด Card แก้ไขและ Card Preview เป็น `col-xl-6` คนละครึ่งจอ และยัง stack อัตโนมัติบนจอเล็ก.

> Phase 2 renderer fields (2026-09-04): ปรับ shared renderer ให้ใช้ Section เป็นตัวควบคุมหลัก ป้องกัน company logo/name แสดงซ้ำกับ header และรองรับ company/party/document/totals fields ที่อยู่ใน registry มากขึ้น.

> Phase 2 preview UX (2026-09-04): หน้า Edit Draft โหลด HTML Preview อัตโนมัติ และมีปุ่มเปิด PDF Preview จริงในแท็บใหม่ผ่าน Version preview route.

> Phase 2 section ordering (2026-09-04): จัดกลุ่ม company header sections ให้ render ก่อน Supplier ตามลำดับที่ออกแบบ และป้องกัน Logo/ชื่อบริษัทซ้ำกับ header อัตโนมัติ.

> Phase 2 ajax save (2026-09-04): หน้า Edit Draft บันทึกผ่าน Ajax โดยไม่ reload หน้า และ refresh เฉพาะ HTML Preview เมื่อบันทึกสำเร็จ.

> Phase 2 header placement (2026-09-04): ย้าย company sections ให้อยู่ใน Header เดียวกับที่อยู่บริษัท และจัดตามลำดับ Logo → ชื่อบริษัท → ที่อยู่/เลขผู้เสียภาษี → ข้อมูลเอกสาร เพื่อไม่ให้ Logo อยู่หลัง Supplier.

> Phase 2 duplicate suppression (2026-09-04): ป้องกัน document title/number/date แสดงซ้ำระหว่าง Header metadata กับ Section ที่ผู้ใช้เลือก โดยให้ Section เป็นแหล่งแสดงผลหลัก.

> Phase 2 PDF parity (2026-09-04): เพิ่ม print CSS แบบ inline ใน shared renderer เพื่อให้ mPDF รองรับ layout primitives เดียวกับ HTML Preview เช่น header columns, table, signatures, borders และ typography.

> Phase 2 signatures and A4 (2026-09-04): เพิ่ม `signatures.prepared_by`/`signatures.approved_by` สำหรับเลือกผู้จัดทำและผู้อนุมัติ และกำหนด PDF เป็น A4 พร้อม repeat table header และป้องกันแถวตารางถูกตัดข้ามหน้า.

> Phase 2 parity tests (2026-09-04): เพิ่ม regression test ตรวจ section order, duplicate document metadata suppression และสร้าง PDF จาก HTML เดียวกัน; ชุด Document tests ผ่าน 33 tests / 148 assertions.

> Phase 2 CSS isolation (2026-09-04): scope print CSS ใต้ `.document-render` เพื่อไม่ให้ rule ของ PDF เช่น `.row` ไปทับ Bootstrap grid ของหน้า Builder และทำให้ Create/Edit แสดง Form กับ Preview เป็น `col-xl-6` ได้ถูกต้อง.

> Phase 1 field/payload boundary (2026-09-04): เพิ่ม `DocumentFieldRegistry` และ `NormalizedDocumentPayloadContract` เพื่อ whitelist field และกำหนด payload กลางสำหรับ renderer; ครอบคลุม company, party, document, lines, totals และ signatures โดยไม่ให้ renderer queryฐานข้อมูลเอง; tests foundation รวม 7 tests / 22 assertions ผ่าน.

> Phase 2 builder UI foundation (2026-09-04): เพิ่ม Settings routes/controller/view สำหรับสร้าง Template Draft, แสดง version, ตั้ง Default และ Publish โดยใช้ `settings.company.view/update` permission; UI contract tests ผ่าน 5 tests / 16 assertions. ยังไม่ใช่ drag-and-drop และยังไม่เชื่อม PDF renderer.

> Phase 2 preview foundation (2026-09-04): เพิ่ม Preview panel แบบ responsive และแยก endpoint ที่ใช้ normalized sample payload, แสดงผล sections/lines/totals เบื้องต้นโดยไม่บันทึกข้อมูล; UI/field contract tests ผ่าน 5 tests / 19 assertions.

> Phase 3 shared renderer integration (2026-09-04): เพิ่ม `DocumentTemplateRenderService` และ shared render view ให้ Preview กับ PDF ใช้ definition/payload path เดียวกัน; Purchase Order PDF ใช้ Published Default Template ได้ และ fallback เป็น renderer เดิมเมื่อไม่มี template; contract tests ผ่าน 4 tests / 17 assertions.

> Phase 2 visual ordering (2026-09-04): เพิ่ม native drag-and-drop สำหรับจัดลำดับ section โดยไม่เพิ่ม dependency และ sync ลำดับกลับเข้า definition อัตโนมัติ; UI contract tests เพิ่ม coverage แล้ว.

> Phase 2 PDF stability (2026-09-04): แยก browser-only `<style>` ออกจาก HTML ก่อนส่งเข้า mPDF เพราะ CSS scoped layout ทำให้เกิดหน้าว่าง 678 หน้า; ใช้ inline layout สำหรับ header, table, totals และ signatures เพื่อรักษาตำแหน่งและเส้นตาราง. ตรวจ PDF จาก Version 2 จริงแล้วเหลือ 1 หน้า.

> Phase 2 preview parity (2026-09-04): เพิ่ม mPDF-safe CSS primitives สำหรับ typography, spacing, fixed table columns และ right-aligned document metadata; แยก signature sections ออกจาก body เพื่อวาง Footer ด้านล่างเสมอ และจัด title/number/date ใน Header ตามลำดับ.

> Phase 2 PDF font (2026-09-04): เพิ่ม `Noto Sans Thai` และตั้งเป็น default font ของ A4 mPDF profile เพื่อให้เอกสารภาษาไทยอ่านง่ายและแสดงผลสม่ำเสมอ โดย HTML Preview ไม่ได้รับผลกระทบ.

## Checklist

- [~] ยืนยัน document types และ field registry กับเจ้าของแต่ละ module (มี registry foundation สำหรับ Purchasing แล้ว; รอ business confirmation)
- [x] สร้าง migration/model/policy ของ Platform template (migration/models/contract foundation แล้ว)
- [x] สร้าง version/publish lifecycle (service + publish/retire contract foundation แล้ว; UI ยังไม่เริ่ม)
- [x] สร้าง normalized payload contract สำหรับ Purchasing (foundation contract แล้ว)
- [~] สร้าง section-based builder (มี form, field whitelist, Preview panel และ native drag-and-drop ordering; ยังไม่รองรับการแก้ template version เดิม)
- [~] สร้าง Preview และ PDF renderer เดียวกัน (ใช้ definition/payload และ markup เดียวกัน; PDF strip เฉพาะ browser-only style ก่อนส่ง mPDF; ยังเหลือปรับ print quality ให้ครบทุก document type)
- [~] เชื่อม Purchasing PDF และ fallback template (Purchase Order แล้ว; เหลือ PR/GR/Invoice/Credit Note/Landed Cost)
- [ ] เพิ่ม cache, audit และ security tests
- [ ] เพิ่ม automated PDF smoke tests
- [ ] Owner ตรวจ print quality และ publish เป็น production template
