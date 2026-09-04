# Feature/Document Posting Configuration Implementation Plan

> สถานะ: **พร้อมส่งต่อให้ AI เริ่ม Phase 1**
>
> Owner ยืนยันเป้าหมาย: 1 กันยายน 2026
>
> Testing policy: **Unit tests + manual QA**
> เป้าหมาย: ให้แต่ละบริษัทตั้งค่าได้ว่าแต่ละ Feature/เอกสาร/Event จะ Post GL เข้าบัญชีใด โดย Accounting เป็นเจ้าของการตั้งค่า และ Operational Module ห้าม hard-code บัญชี

## 1. วิธีใช้เอกสารนี้

AI/Agent ที่รับงานต้อง:

1. อ่านเอกสารนี้ทั้งฉบับก่อนแก้ code
2. อ่าน `AGENTS.md` ทุกระดับที่ครอบ repository (ถ้ามี), skill/rule ที่ task ระบุ และเอกสาร:
   - `docs/planning/01-product-architecture.md`
   - `docs/planning/02-accounting-inventory.md`
   - `docs/planning/03-modules-ui.md`
   - `docs/planning/05-module-workflows.md`
   - `docs/planning/11-asset-module-implementation-plan.md`
3. ตรวจ `git status` ก่อนแก้ ห้าม reset/revert งานเดิมและห้ามแก้ไฟล์นอก Phase โดยไม่จำเป็น
4. ทำทีละ Phase และอัปเดต checkbox เมื่อมีหลักฐาน test/manual QA แล้วเท่านั้น
5. ห้ามเปลี่ยน recognition point, debit/credit, tax point, reversal หรือ branch/company policy เอง
6. ถ้าพบคำถามด้าน business flow ตามหัวข้อ 17 ให้หยุด สรุปตัวเลือก/ผลกระทบ แล้วถาม Owner
7. ห้ามเปิดใช้ configuration ใหม่กับ Feature จน readiness, regression และ rollback path ของ Feature นั้นผ่าน

รูปแบบ handoff ทุกครั้ง:

- งานที่ทำและไฟล์ที่เปลี่ยน
- checkbox ที่ปิด พร้อมหลักฐาน
- test command และผลลัพธ์
- manual QA ที่ Owner ต้องตรวจ
- migration/feature flag/rollback note
- blocker หรือ business decision ที่ยังค้าง

## 2. Product outcome

เมื่อจบแผนนี้:

- Accounting มีหน้าตั้งค่าบัญชีแยกตาม **Module → Feature/ประเภทเอกสาร → Posting event → บทบาทบัญชี**
- บริษัทเลือกผังบัญชีของตนเองได้โดยไม่แก้ code
- Operational Module ส่ง source/document/context และขอ resolve บัญชีจาก Accounting contract
- ก่อน Post ระบบบอกได้ว่าขาด mapping ใด และลิงก์ไปหน้าตั้งค่าที่ถูกต้อง
- Journal เก็บ account IDs, ที่มาของบัญชี และ mapping version ที่ใช้จริง
- การเปลี่ยน mapping ใช้กับรายการใหม่เท่านั้น ไม่เปลี่ยน Posted history
- retry ไม่สร้าง Journal ซ้ำ และ reversal ใช้ Journal เดิม ไม่ใช้ mapping ชุดใหม่
- ผู้ตรวจสอบ trace จากเอกสาร → event/mapping → Journal → reversal ได้

ตัวอย่างที่ระบบต้องรองรับ:

```text
Purchasing
└── ใบตั้งหนี้สินค้า / supplier_invoice.inventory
    ├── INVENTORY              → 13100 สินค้าคงเหลือ
    ├── ACCOUNTS_PAYABLE       → 21000 เจ้าหนี้การค้า
    └── DEFERRED_INPUT_VAT     → 11510 ภาษีซื้อพัก
```

บริษัทอื่นสามารถเลือกบัญชีเลขที่ต่างออกไปได้โดยไม่แก้ Purchasing/WMS code

## 3. Scope และสิ่งที่ไม่ทำ

### 3.1 In scope

- Event/document-specific Account Mapping ที่ Accounting เป็นเจ้าของ
- mapping version และ Journal posting snapshot
- event contract/readiness ตาม Feature
- source/master override เฉพาะที่ contract อนุญาต
- UI, permission, audit, filter และ recovery link
- refactor Feature ที่สร้าง Journal อยู่แล้วแบบทีละ event
- reconciliation, reversal regression และ rollout gate

### 3.2 Out of scope

- generic rule/formula/workflow engine
- ให้ผู้ใช้สร้างหรือลบ `event_code` และ account role เอง
- แก้ Chart of Accounts หรือสร้างบัญชี production ให้อัตโนมัติ
- เปลี่ยน recognition/tax policy ของ Feature เดิม
- เปิด event ที่ยังเป็น dry-run/deferred เพียงเพราะตั้ง mapping แล้ว
- ทำ Asset branch transfer ให้เกิด GL; policy ปัจจุบันเป็นการย้ายทะเบียนภายในบริษัทเดียว
- multi-company ใน database เดียว จนกว่าจะมี requirement จริง; รอบนี้เป็น company-wide profile ของบริษัทที่ระบบกำลังให้บริการ

## 4. ข้อตกลงการออกแบบ

1. **Accounting owns configuration** — หน้าตั้งค่าอยู่ใน Accounting
2. **Mapping ต้องเจาะจง event/document** — identity ขั้นต่ำคือ `event_code + account_role`
3. **Code owns contract** — event, roles, account type, book, recognition และ reversal rule อยู่ใน code review ไม่ให้ผู้ใช้สร้างเอง
4. **Company chooses accounts** — account ID อยู่ใน configuration/master/source data ไม่อยู่ใน constant หรือ query code คงที่ของ Module
5. **Reuse ของเดิม** — ขยาย `AccountMappingService`, `PostingEvent`, `JournalPostingService` และตารางเดิม
6. **No silent fallback** — mapping ไม่ครบต้องหยุดพร้อม event/role/recovery URL; ห้ามเลือกบัญชีแรกหรือเดาจาก code
7. **Immutable history** — mapping ใหม่ไม่ rewrite Journal เดิม
8. **Reversal from original** — reversal สลับ original Journal lines/snapshot ไม่ resolve mapping ปัจจุบัน
9. **No new dependency** — ใช้ Laravel/Eloquent และ UI library กลางที่มีอยู่

### 4.1 Ownership boundary: Accounting กับ Global Settings

- **Accounting > ตั้งค่าการลงบัญชี** เป็นเจ้าของ event/document mapping, account roles,
  account IDs, Journal Book contract, readiness, version, permission และ audit
- **Global Settings** เก็บเฉพาะ policy ระดับบริษัทที่ไม่ใช่การเลือกบัญชี เช่น base currency,
  timezone, rounding, posting-date/period policy และ feature flags
- ห้ามเก็บ Account ID, Account code หรือ debit/credit mapping ของ Feature ไว้ใน Global Settings
- Operational Module แสดง readiness/recovery link ไป Accounting ได้ แต่ห้ามมีหน้าตั้งค่า Account Mapping ซ้ำใน Module ของตน
- หากอนาคตรองรับหลายบริษัทในฐานข้อมูลเดียว ให้เพิ่ม company scope ใน Accounting Posting Configuration
  โดยไม่ย้าย Account Mapping ไป Global Settings

## 5. Baseline จาก code ปัจจุบัน

### 5.1 ของเดิมที่ต้อง reuse

- `accounting_account_mappings`: `key` unique, `account_id`, active flag, actor/timestamps
- `AccountMappingService`: typed keys และ validation active/postable/account type
- `AccountMappingController`/Request/View: list/create/edit, Select2, permission และ `AuditLogger`
- `PostingEvent`: event-to-journal-book catalog
- `JournalPostingService`: open-period gate, balanced entry, control subledger validation, idempotency key, posting hash และ reversal
- Item, Bank Account, Open Item, Asset Category และเอกสารบางชนิดมี source/master account อยู่แล้ว
- Workflow Center มี route ไป Account Mapping และ readiness ขั้นพื้นฐาน

### 5.2 ช่องว่าง

- mapping ปัจจุบันผูกเพียง `key` กลาง ยังไม่แยก Feature/document/event
- mapping ไม่มี version
- Journal ไม่มี JSON snapshot ของ account resolution
- readiness ปัจจุบันนับ active mapping รวม ไม่ตรวจตาม event
- event requirements กระจายในหลาย service/support
- error ไม่มี event/role/settings URL แบบเดียวกันทุก Module
- event naming บางจุดใช้ทั้ง `inventory_adjustment` และ `inventory.adjustment`
- Asset ใช้ Category accounts โดยตรง แต่ Journal ยังไม่บอก provenance/category snapshot

| กลุ่ม | Service/contract หลัก | สภาพปัจจุบัน |
|---|---|---|
| Accounting | `JournalPostingService`, `PostingEvent`, `AccountMappingService` | ใช้งานจริง แต่ไม่มี event-specific mapping/version/snapshot |
| Purchase/WMS | Purchase posting, inventory contracts, recost services | ผสม mapping, line/item account และ deferred flow |
| POS/Sales | invoice/physical sale/return/COGS services | ผสม mapping, bank/item และ original Journal |
| Finance | settlement, advance, VAT/WHT services | ผสม mapping, bank และ Open Item |
| Asset | capitalization/depreciation/impairment/disposal | ใช้ category/source account; transfer ไม่สร้าง GL |

## 6. Configuration identity และ resolution

### 6.1 Mapping identity

หนึ่ง configuration row แทน:

```text
event_code + account_role → account_id
```

ตัวอย่าง:

```text
supplier_invoice.inventory + ACCOUNTS_PAYABLE → account 21000
supplier_invoice.expense   + ACCOUNTS_PAYABLE → account 21100
```

จึงรองรับบริษัทที่ต้องการใช้บัญชีเจ้าหนี้ต่างกันตามเอกสาร/Feature ได้ แม้ role จะชื่อเหมือนกัน

### 6.2 Resolution precedence

1. `ORIGINAL` — original Journal snapshot สำหรับ reversal/correction
2. `DOCUMENT` — บัญชีที่เอกสาร/ต้นทางระบุและ contract อนุญาต เช่น Bank Account/Open Item
3. `MASTER` — Item/Asset Category override ที่ contract อนุญาต
4. `MAPPING` — event-specific Accounting mapping

ไม่มี fallback ไปบัญชีแรก, account code คงที่ หรือ global key แบบเงียบ

ทุก resolution คืน:

```text
event_code, account_role, account_id, source,
source_type, source_id, mapping_id, mapping_version
```

กติกา:

- `DOCUMENT`/`MASTER` ต้องผ่าน account compatibility rule เดียวกับ role
- account ต้อง active/postable ณ ตอน Post
- control account ต้องมี subledger type/id ตาม contract
- ถ้า contract กำหนดให้ Mapping เป็น mandatory ห้าม master override
- ถ้า contract อนุญาต override แต่ไม่มีค่า จึงใช้ Mapping

## 7. Data model และ migration strategy

### 7.1 ขยาย `accounting_account_mappings`

เพิ่ม:

- `event_code` string(80) nullable ชั่วคราวระหว่าง rollout
- เปลี่ยนความหมาย `key` เป็น `account_role` โดยยังใช้ชื่อ column เดิมเพื่อ backward compatibility
- `version` unsigned integer default `1`
- unique ใหม่: `event_code + key`
- index: `event_code + is_active`

Migration ต้อง:

1. เพิ่ม columns/index ใหม่แบบไม่แก้ migration เดิม
2. ถอด unique เดิมของ `key` หลังตรวจชื่อ index จริง
3. เก็บ row เดิมเป็น `event_code=null` เพื่อ compatibility เท่านั้น
4. ไม่เดาหรือ auto-enable event mapping ใน production
5. มีหน้า/คำสั่งช่วย copy legacy mapping เป็น event-specific draft ให้ผู้ทำบัญชียืนยันภายหลังได้ แต่ยังไม่ทำจน Phase 2 ต้องใช้จริง

Compatibility:

- `resolve(string $key)` เดิมอ่าน legacy row ต่อระหว่าง rollout
- caller ที่ย้ายแล้วต้องใช้ `resolveForEvent(event, role, context)` เท่านั้น
- ห้าม caller ใหม่ใช้ legacy resolve
- Phase 6 ต้องไม่มี LIVE caller พึ่ง `event_code=null`; จึงค่อยวางแผนบังคับ not-null ภายหลัง

### 7.2 ขยาย `journal_entries`

- เพิ่ม `posting_metadata` JSON nullable
- model cast เป็น array
- metadata รวมใน normalized posting hash
- migration เดิมห้ามแก้

### 7.3 Snapshot shape

```json
{
  "contract_version": 1,
  "event_code": "asset.depreciation",
  "accounts": [
    {
      "account_role": "DEPRECIATION_EXPENSE",
      "account_id": 123,
      "source": "MASTER",
      "source_type": "ASSET_CATEGORY",
      "source_id": "5",
      "mapping_id": null,
      "mapping_version": null
    }
  ]
}
```

Snapshot ต้องตรงกับ Journal lines, canonicalize ก่อน hash, ไม่มีข้อมูลส่วนบุคคล และไม่เปลี่ยนหลัง Post

## 8. Event/account role matrix

สถานะ `LIVE`, `DEFERRED`, `NO_GL` ใช้เป็น release gate ไม่ใช่เพียงข้อความ UI

| Event | Module/เอกสาร | Account roles/source ขั้นต่ำ | Book | สถานะ |
|---|---|---|---|---|
| `supplier_invoice.inventory` | Purchasing / ใบตั้งหนี้สินค้า | `INVENTORY`, `ACCOUNTS_PAYABLE`, tax role เมื่อมี | PURCHASE | LIVE/feature-gated |
| `supplier_invoice.expense` | Purchasing / ใบตั้งหนี้ค่าใช้จ่าย | `PURCHASE_EXPENSE`, `ACCOUNTS_PAYABLE`, tax role | PURCHASE | LIVE |
| `purchase_credit_note` | Purchasing / ใบลดหนี้ซื้อ | original invoice Journal/source | PURCHASE | LIVE |
| `sales_invoice` | Sales/POS / ใบกำกับขาย/ขายสด | `ACCOUNTS_RECEIVABLE`, tax/WHT/advance role เมื่อมี; รายได้ POS ใช้ `Item.sales_account_id` เป็น `MASTER` source | SALES | LIVE |
| `sales_cogs` | POS/WMS / ตัดต้นทุนขาย | Item master: `cogs_account_id`, `inventory_account_id` (mandatory, no mapping fallback) | SALES | LIVE/feature-gated |
| `sales_credit_note` | Sales/POS / คืนขาย | original sale/return snapshot | SALES | LIVE |
| `customer_payment` | Finance / รับชำระ | Bank + AR/Open Item + VAT/WHT role | RECEIPT | LIVE |
| `customer_advance` | POS/Finance / รับมัดจำ | Bank + `CUSTOMER_ADVANCE` + WHT เมื่อมี | RECEIPT | LIVE |
| `supplier_payment` | Finance / จ่ายชำระ | Bank + AP/Open Item + VAT/WHT role | PAYMENT | LIVE |
| `expense_payment` | Finance / จ่ายค่าใช้จ่าย | ใช้ `supplier_invoice.expense` → AP → `supplier_payment` ใน release ปัจจุบัน; direct expense payment ต้องมี document/tax/WHT contract ก่อน | PAYMENT | DEFERRED |
| `sales_commission_payout` | POS/Finance / จ่ายคอมมิชชั่น | Bank + `COMMISSION_EXPENSE` | PAYMENT | LIVE |
| `inventory_adjustment` | WMS / ปรับปรุงสินค้า | `INVENTORY`, `ADJUSTMENT_GAIN` หรือ `ADJUSTMENT_LOSS` | GENERAL | LIVE/feature-gated |
| `inventory.recost` | WMS / ปรับต้นทุน | `INVENTORY`, `RECOST_GAIN` หรือ `RECOST_LOSS` | GENERAL | DEFERRED/flagged |
| `inventory.receipt` | WMS / รับสินค้า | `INVENTORY`, source clearing/AP | PURCHASE | DEFERRED |
| `production.material_issue` | Production / เบิกผลิต | `WIP`, `INVENTORY` | GENERAL | DEFERRED |
| `production.finished_receipt` | Production / รับผลผลิต | `FINISHED_GOODS`, `WIP`, variance เมื่อมี | GENERAL | DEFERRED |
| `asset.capitalization` | Asset / รับรู้สินทรัพย์ | `ASSET_COST`, `CAPITALIZATION_CLEARING` | GENERAL | LIVE |
| `asset.addition` | Asset / เพิ่มมูลค่า | `ASSET_COST`, source/clearing | GENERAL | LIVE |
| `asset.depreciation` | Asset / ค่าเสื่อม Book | `DEPRECIATION_EXPENSE`, `ACCUMULATED_DEPRECIATION` | GENERAL | LIVE |
| `asset.impairment` | Asset / ด้อยค่า | `IMPAIRMENT_LOSS`, `ACCUMULATED_IMPAIRMENT` | GENERAL | LIVE |
| `asset.disposal` | Asset / จำหน่าย | cost, accumulated, `DISPOSAL_CLEARING`, gain/loss | GENERAL | LIVE |
| `asset.write_off` | Asset / ตัดออก | cost, accumulated, `DISPOSAL_LOSS` | GENERAL | LIVE |
| `asset.branch_transfer` | Asset / โอนสาขา | ไม่มี Journal | - | NO_GL |
| `accounting.period_adjustment` | Accounting / ปรับปรุงงวด | explicit accounts โดยผู้มีสิทธิ์ | GENERAL | LIVE |

Agent ต้องตรวจ code/service/route จริงก่อน implement แต่ห้ามเปลี่ยน business behavior จาก matrix เอง

## 9. Service contract ขั้นต่ำ

ขยายของเดิม:

- `PostingEvent`: label, module/document group, roles, account compatibility, book, status, recognition/reversal description
- `AccountMappingService`:
  - legacy `resolve(key)` ระหว่าง rollout
  - `resolveForEvent(event, role, context)` คืน account + provenance/version
  - `readiness(event, context)` คืน blockers โดยไม่สร้าง Journal
- `JournalPostingService`: validate/normalize/persist `posting_metadata`

ห้ามสร้าง repository interface, factory, DTO hierarchy, rule engine หรือ event table ใหม่

Readiness shape:

```text
ready, event_code, required_roles, resolved_accounts, blockers
```

Blocker:

```text
code, field, event_code, account_role,
message, recovery_label, recovery_url
```

Server ต้อง reject Post แม้ยิง request ตรง; UI disable เป็นเพียง UX เสริม

## 10. UX, permission และ audit

หน้า Accounting > ตั้งค่าการลงบัญชี:

- group ตาม Module → Feature/ประเภทเอกสาร → Event
- แสดง account role เป็นภาษาไทย, บัญชีที่เลือก, source policy, version และ status
- account selector ใช้ Select2 AJAX + pagination/debounce และกรอง type/control ตาม role
- list ใช้ server-side DataTable
- Filter อยู่ card แยกจาก table และมีปุ่ม `ล้างตัวกรอง`
- Badge ใช้ shared pastel semantic classes
- ปุ่มเรียง action หลัก → ยกเลิก/ปิดใช้งาน → กลับ/รายการทั้งหมด
- ใช้ permission เดิม `accounting.account-mappings.view/create/update`; ยังไม่เพิ่ม delete
- create/update/activate/deactivate มี audit actor/time/before/after/version
- mapping ที่เคยใช้ห้าม hard delete ให้ inactive และสร้าง/แก้ version ตาม contract
- Seeder/default ใช้ได้เฉพาะ mock/local; production ให้ผู้มีสิทธิ์เลือกบัญชีบริษัทเอง

## 11. Implementation phases และ checklist

### Phase 0 — Discovery และ blueprint

- [x] สำรวจ mapping schema/service/UI เดิม
- [x] สำรวจ PostingEvent/JournalPosting/idempotency/reversal
- [x] inventory posting callers ใน Accounting, WMS, POS, Finance และ Asset
- [x] ยืนยันเป้าหมายเป็น event/document-specific company configuration
- [x] ยืนยัน Asset branch transfer เป็น `NO_GL`
- [x] จัดทำ execution checklist และ AI handoff ฉบับนี้

Gate: plan ระบุ target, migration compatibility, phases, tests และ stop conditions ครบ

### Phase 1 — Accounting foundation

- [x] เพิ่ม migration `event_code`, `version`, composite unique/index ใน mapping table
- [x] preserve legacy rows และ legacy resolver ระหว่าง rollout
- [x] เพิ่ม migration `journal_entries.posting_metadata`
- [x] อัปเดต model fillable/casts
- [x] mapping update ใช้ transaction + row lock + increment version เมื่อ account/status เปลี่ยน
- [x] ขยาย `PostingEvent` ด้วย event/document/account-role contract
- [x] เพิ่ม event-specific resolver + provenance
- [x] เพิ่ม structured readiness
- [x] `JournalPostingService` persist metadata และรวม metadata ใน posting hash
- [x] caller เดิมยังผ่าน regression โดย behavior ไม่เปลี่ยน (Unit 670 tests / 3,926 assertions และ Feature MySQL 34 tests / 290 assertions)
- [x] Unit tests: migration contract, version, event identity, compatibility, provenance, readiness, metadata hash
- [x] รัน migration local MySQL, `optimize:clear`, route/view cache และ `git diff --check`

Gate: foundation พร้อม แต่ยังไม่เปลี่ยน Feature account selection และ rollback migration ได้

### Phase 2 — Accounting configuration UX

- [x] list group/filter ตาม Module/document/event/status
- [x] create/edit เลือก event + role จาก catalog ที่ code อนุญาต
- [x] Select2 AJAX กรองบัญชีถูก type/control และ paginate
- [x] แสดง version, active/inactive และ legacy warning
- [x] ปุ่ม copy legacy mapping เป็น event-specific draft ถ้าจำเป็น โดยผู้ใช้ต้องยืนยันบัญชี
- [x] audit before/after/version/reason
- [x] Filter card แยก table card + ปุ่มล้างตัวกรอง
- [x] SweetAlert2 และ badge/button order ตามมาตรฐาน
- [x] permission route/UI ครบ
- [x] Unit tests request/controller/query contract
- [x] Manual QA list/create/update/inactivate/filter/reset/permission/audit

Gate: ผู้ทำบัญชีตั้งค่าแต่ละ Feature/เอกสารได้โดยไม่แก้ code และรู้ว่า legacy row ใดยังต้อง migrate

### Phase 3 — Purchase และ Inventory/WMS

- [x] normalize canonical event name `inventory_adjustment`
- [x] `supplier_invoice.inventory`
- [x] `supplier_invoice.expense`
- [x] `purchase_credit_note`
- [x] `inventory_adjustment`
- [x] `inventory.recost` คงสถานะ deferred จนกว่า release gate เดิมผ่าน
- [x] `inventory.receipt` คงสถานะ deferred จนกว่า source/allocation contract ผ่าน
- [x] แต่ละ LIVE event ใช้ `resolveForEvent`/allowed source override เท่านั้น
- [x] snapshot provenance/version ครบ
- [x] same identity/same payload reuse; payload ต่าง reject
- [x] reversal ใช้ original Journal
- [x] Inventory allocation/cost/GL reconcile
- [x] Unit tests + manual QA ต่อ event

Gate: ห้ามเปิด deferred event; stock/cost/GL ต้อง atomic และ reconcile

### Phase 4 — POS/Sales และ Finance

- [x] `sales_invoice`
- [x] `sales_cogs`
- [x] `sales_credit_note`
- [x] `customer_payment`
- [x] `customer_advance`
- [x] `supplier_payment`
- [x] `expense_payment` คงสถานะ `DEFERRED`: release ปัจจุบันใช้ `supplier_invoice.expense` → AP → `supplier_payment`; ห้ามเปิด direct expense payment ก่อนมี document/tax/WHT contract และ Owner อนุมัติ
- [x] `sales_commission_payout`
- [x] Bank/Open Item/document accounts มี provenance `DOCUMENT`/`ORIGINAL`
- [x] VAT/WHT/revenue/AR/AP/expense roles มี readiness ตาม event
- [x] return/refund/cancellation ใช้ original Journal เมื่อ contract กำหนด
- [x] AR/AP/Open Item, tax และ GL reconcile
- [x] Unit tests + manual QA ต่อ event

Gate: payment/return retry ไม่ซ้ำ และ subledger/tax ตรง GL

### Phase 5 — Asset

- [x] เพิ่ม Asset event/account roles และ compatibility rules
- [x] `asset.capitalization` / `asset.addition`
- [x] `asset.depreciation`
- [x] `asset.impairment`
- [x] `asset.disposal`
- [x] `asset.write_off`
- [x] Asset Category เป็น `MASTER` override เฉพาะ role ที่ contract อนุญาต
- [x] event mapping เป็น default เมื่อ category/source ไม่มีค่า
- [x] snapshot เก็บ category/source/mapping provenance
- [x] branch transfer ยัง `NO_GL` และมี regression test ป้องกัน Journal
- [x] reversal ใช้ original Journal
- [x] Asset subledger vs GL reconcile
- [x] Unit tests + manual QA ต่อ event

Gate: cost/accumulated depreciation/impairment/gain-loss ตรง GL และ transfer ไม่สร้าง GL

### Phase 6 — Readiness, reconciliation และ rollout

> สถานะ: **ปิด implementation scope โดย Owner — 2 กันยายน 2026**
>
> รายการที่ยังไม่ติ๊กด้านล่างเป็น release follow-up ที่ยังไม่มีหลักฐานทดสอบครบถ้วน
> จึงไม่ถือว่า Owner sign-off, performance หรือเอกสารส่งมอบเสร็จโดยอัตโนมัติ

- [x] Workflow Center/readiness แสดง event ที่พร้อม/ไม่พร้อม + recovery link
- [x] หน้าเอกสารปิดปุ่ม Post และแสดง blocker แต่ server ยังตรวจซ้ำ
- [x] period close gate ตรวจ source ที่ควร Post แต่ค้าง/ล้มเหลว
- [x] report/drill-down แสดง event, account provenance/version และ reversal link
- [x] audit ว่าไม่มี LIVE operational path ใช้ hard-coded/fallback-first account
- [x] LIVE caller ไม่พึ่ง legacy `event_code=null`
- [x] permission/audit/full targeted regression ผ่าน (Unit และ Feature regression ผ่าน; 2 integration cases ถูก skip ตาม opt-in)
- [ ] manual UAT ทุก Module ผ่าน Owner sign-off
- [ ] performance benchmark + rollback/release evidence
- [ ] อัปเดต user guide/QA/checklist และปิดแผน

Gate: trace source → configuration snapshot → Journal → reversal ได้ และ period close ไม่ปล่อย blocker

## 12. Checklist ต่อหนึ่ง Event ก่อนเปิดใช้

- [ ] event/document/recognition point ยืนยันแล้ว
- [ ] account roles และ source/master override ระบุครบ
- [ ] account type/control/subledger contract ครบ
- [ ] branch/warehouse scope ถูกต้อง
- [ ] readiness เป็น read-only และมี recovery URL
- [ ] Post อยู่ใน transaction + source status guard
- [ ] idempotency key stable
- [ ] posting hash รวม normalized lines + metadata
- [ ] Journal balanced และ account active/postable
- [ ] source/mapping snapshot persist และตรง Journal lines
- [ ] source เก็บ Journal linkage
- [ ] reversal ใช้ original Journal ไม่ resolve mapping ใหม่
- [ ] reconciliation เป็นศูนย์
- [ ] Unit tests ผ่าน
- [ ] Manual QA + Owner sign-off ผ่าน
- [ ] release flag/status ระบุชัด

## 13. Required automated tests

ใช้ Unit tests เป็นหลัก และ MySQL smoke เฉพาะ migration/locking/query behavior ที่ SQLite พิสูจน์ไม่ได้:

- composite identity `event_code + role`
- event เดียวกัน role ซ้ำไม่ได้ แต่ role เดียวกันคนละ event ได้
- mapping version increment และ stale/concurrent update ไม่ทำ version หาย
- resolver precedence/provenance
- missing/inactive/wrong-type mapping เป็น structured blocker
- PostingEvent contract ครบทุก registered LIVE event
- metadata canonicalization/hash deterministic
- same identity + same payload reuse; payload ต่าง reject
- mapping/master change ไม่เปลี่ยน Posted snapshot
- reversal account/amount ตรงข้าม original
- control account ขาด subledger reject
- branch/warehouse mismatch, closed period, unbalanced/non-postable reject
- Asset branch transfer ไม่สร้าง Journal
- deferred event ยัง block จน gate ผ่าน

ห้ามใช้ source-string assertion แทน behavior test หากทดสอบ behavior ได้

## 14. Manual QA evidence

สร้าง `docs/qa/feature-posting-configuration-phase-{n}.md` ต่อ Phase และบันทึก:

- วันที่, branch/warehouse, user/permission, dataset
- event/document และ mapping/version ก่อนทดสอบ
- expected debit/credit/subledger
- readiness ก่อน/หลังตั้งค่า
- Post ครั้งแรก, retry, payload conflict
- แก้ mapping แล้ว Journal เดิมไม่เปลี่ยน
- เอกสารใหม่ใช้ mapping version ใหม่
- reversal และ source/Journal linkage
- GL/subledger reconciliation
- error/SweetAlert/recovery link
- UX ตาม `docs/planning/03-modules-ui.md`
- Owner sign-off

## 15. Performance และ concurrency

- list/filter เป็น server-side DataTable
- Chart of Accounts Select2 AJAX + pagination/debounce
- resolver query เฉพาะ event/roles ที่ต้องใช้
- index รองรับ `event_code + key + is_active`
- Post lock เฉพาะ source/mappings/book/period ที่ใช้ และรักษา lock order เดิม
- ห้าม cache mapping ข้าม request จน version ใหม่ไม่ถูกใช้
- benchmark อย่างน้อย 100 mappings, 30 event contracts และ 5,000 readiness resolutions หรือ production-like volume ที่ดีกว่า
- บันทึก query count, median/worst local; Owner ตัดสิน acceptance หากยังไม่มี SLO

## 16. Hard-code audit checklist

ก่อนปิด Phase 6 ต้องค้นและตรวจอย่างน้อย:

- [ ] numeric literal `account_id` ที่ฝังใน posting payload
- [ ] `Account::where('code', ...)` ใน operational posting service
- [ ] `Account::first()`/`value('id')` เพื่อเลือกบัญชี Post
- [ ] config/env account ID
- [ ] Module controller/Blade เลือกบัญชีแทน service contract
- [ ] default account ที่ไม่มี event/role provenance
- [ ] reversal ที่ resolve mapping ใหม่
- [ ] seeders production ที่ auto-enable mapping โดยไม่ให้ผู้ทำบัญชียืนยัน

ข้อยกเว้นที่ถูกต้อง: test fixture/mockup, explicit account จาก document/master ตาม contract และ manual Journal โดยผู้มีสิทธิ์

## 17. จุดที่ Agent ต้องหยุดถาม Owner

- จะเปลี่ยน recognition point หรือ tax point
- จะเปิด event สถานะ `DEFERRED`
- จะเปลี่ยน Asset branch transfer จาก `NO_GL`
- ต้องรองรับหลายบริษัทใน database เดียวหรือ mapping แยก branch
- ต้องเปลี่ยน resolution precedence ในหัวข้อ 6
- ต้องเพิ่ม event/role ใหม่ที่ไม่มีใน matrix
- reversal อ้าง original Journal ไม่ได้
- source/master account ขัดกับ event mapping และ contract เดิมไม่บอกว่าใครมีอำนาจ
- flow เดิมไม่ชัดว่าเอกสารสถานะใดจึง Post ได้

คำถามต้องแนบ code/data ที่พบ, behavior ปัจจุบัน, ตัวเลือก 2–3 แบบ, ผลกระทบ GL/subledger และข้อเสนอแนะ

## 18. Definition of Done

- [ ] ทุก LIVE event มี event-specific mapping/source contract
- [ ] ไม่มี hard-coded/fallback-first account ใน operational posting path
- [ ] configuration UI, permission, audit และ readiness ผ่านมาตรฐาน
- [ ] Journal เก็บ immutable provenance/version snapshot
- [ ] mapping/master change ไม่เปลี่ยน Posted history
- [ ] reversal ใช้ original Journal
- [ ] subledger/source reconcile GL ทุก Module
- [ ] period close ตรวจ posting blocker ได้
- [ ] Unit tests + manual QA + Owner sign-off ครบ
- [ ] performance/release/rollback evidence ครบ
- [ ] docs/QA/checklist ตรงกับ code จริง

## 19. Handoff prompt สำหรับ AI ตัวถัดไป

```text
ทำงานใน repository new-erp ตาม
docs/planning/12-feature-posting-configuration-plan.md

เริ่มจาก Phase แรกที่ยังไม่ปิด checkbox:
1. อ่านเอกสารทั้งฉบับ, AGENTS.md/skills/rules และเอกสารอ้างอิง
2. ตรวจ code กับ git status และ preserve งานเดิม
3. ทำเฉพาะ scope ของ Phase; reuse service/pattern เดิมและไม่เพิ่ม dependency
4. ใช้ Unit tests + manual QA ตามแผน
5. ถ้าพบ business decision ตามหัวข้อ 17 ให้หยุดถาม Owner
6. อัปเดต checkbox เฉพาะเมื่อมีหลักฐาน และส่ง handoff ตามหัวข้อ 1

ห้ามเปิด deferred event, เปลี่ยน recognition/reversal/NO_GL policy
หรือเลือกบัญชีด้วย hard-code/fallback-first
```

## 20. สถานะปัจจุบัน

**Phase 4 ผ่าน — พร้อมเริ่ม Phase 5: Asset**
