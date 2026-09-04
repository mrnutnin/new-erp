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

## Checklist

- [ ] ยืนยัน document types และ field registry กับเจ้าของแต่ละ module
- [ ] สร้าง migration/model/policy ของ Platform template
- [ ] สร้าง version/publish lifecycle
- [ ] สร้าง normalized payload contract สำหรับ Purchasing
- [ ] สร้าง section-based builder
- [ ] สร้าง Preview และ PDF renderer เดียวกัน
- [ ] เชื่อม Purchasing PDF และ fallback template
- [ ] เพิ่ม cache, audit และ security tests
- [ ] เพิ่ม automated PDF smoke tests
- [ ] Owner ตรวจ print quality และ publish เป็น production template
