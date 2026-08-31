# Asset Module Implementation Plan

> สถานะ: Implementation blueprint / ยังไม่เริ่ม implementation  
> วันที่จัดทำ: 31 สิงหาคม 2026  
> เป้าหมาย: เป็น source of truth สำหรับมนุษย์และ AI ที่พัฒนา Asset Module ต่อจาก Core ERP

## 1. วิธีใช้เอกสารนี้

Agent ที่รับงาน Asset ต้องทำตามลำดับดังนี้:

1. อ่านเอกสารนี้ทั้งฉบับก่อนแก้ code
2. อ่าน `docs/planning/01-product-architecture.md`, `docs/planning/02-accounting-inventory.md`, `docs/planning/09-branch-context-migration-plan.md` และ `docs/planning/10-global-document-sequence-user-profile-plan.md`
3. ทำทีละ Phase และอัปเดต checkbox ในเอกสารนี้หลังมีหลักฐานทดสอบแล้วเท่านั้น
4. ห้ามข้าม Foundation/Accounting gate เพื่อเร่งทำ UI
5. ทุก Phase ต้องมี migration, permission, audit, validation, test และ UAT ตามที่ระบุ
6. ถ้าพบว่ากติกาใหม่ขัดกับเอกสารนี้ ให้แก้เอกสารและขออนุมัติก่อนเปลี่ยน business rule

Definition ของคำสำคัญ:

- **Asset**: สินทรัพย์ถาวรรายชิ้นหรือ component ที่ติดตามในทะเบียน
- **Book depreciation**: ค่าเสื่อมตามบัญชี ใช้ Post GL
- **Tax depreciation**: ค่าเสื่อมเพื่อรายงานภาษี ไม่ Post GL
- **Capitalization**: การรับรู้ต้นทุนเป็นสินทรัพย์เมื่อพร้อมใช้งาน
- **Write-off**: ตัดสินทรัพย์ออกโดยไม่มีสิ่งตอบแทน เช่น สูญหาย ชำรุด หรือเลิกใช้
- **Disposal**: จำหน่าย บริจาค หรือโอนออกจากกิจการ
- **Custodian**: ผู้รับผิดชอบดูแลสินทรัพย์ ไม่ใช่เจ้าของทางบัญชี

## 2. Product outcome

Asset Module ต้องตอบคำถามธุรกิจต่อไปนี้ได้จากระบบเดียว:

- บริษัทมีสินทรัพย์อะไร อยู่สาขาและสถานที่ใด ใครดูแล
- สินทรัพย์ได้มาเมื่อใด จากเอกสารใด ต้นทุนเท่าไร และพร้อมใช้เมื่อใด
- ราคาทุน ค่าเสื่อมสะสม ด้อยค่า และมูลค่าตามบัญชี ณ วันใดวันหนึ่งเป็นเท่าไร
- ค่าเสื่อมตามบัญชีและภาษีต่างกันเท่าไร
- เดือนนี้มีสินทรัพย์ใดต้องคิดค่าเสื่อม ตรวจนับ หมดประกัน หรือเข้าซ่อม
- มีการย้ายสถานที่ เปลี่ยนผู้ดูแล ส่งซ่อม สูญหาย หรือจำหน่ายเมื่อใด ใครอนุมัติ
- ยอดทะเบียนสินทรัพย์กระทบกับ GL ตรงกันหรือไม่
- รายการใดติด blocker และต้องให้ใครทำขั้นตอนต่อไป

## 3. Scope decision

### 3.1 MVP ที่ต้องมี

- Dashboard และ Workflow Center ของ Asset
- หมวดสินทรัพย์และบัญชีประจำหมวด
- สถานที่เก็บ/ใช้งานแบบลำดับชั้นภายในสาขา
- ทะเบียนสินทรัพย์ รายละเอียด รูปภาพ เอกสารรับประกัน และ QR/Barcode value
- สินทรัพย์หลักและ component แยกคิดค่าเสื่อมได้
- รับรู้สินทรัพย์จากเอกสารซื้อ หรือ opening balance ที่ยืนยันแล้ว
- Book และ Tax depreciation แยกจากกัน
- Depreciation run รายสาขา/งวด พร้อม preview, approve, post และ reverse
- โอนสาขา ย้ายสถานที่ และเปลี่ยนผู้รับผิดชอบ
- ตรวจนับสินทรัพย์และบันทึกผลต่าง
- แจ้งซ่อม ติดตามสถานะ ค่าใช้จ่าย downtime ประกัน และผู้รับซ่อม
- Impairment, disposal, sale, donation และ write-off
- Audit trail และประวัติ domain ของสินทรัพย์
- รายงานทะเบียน ค่าเสื่อม เคลื่อนไหว ซ่อม การจำหน่าย และ GL reconciliation
- Import opening assets จาก Excel แบบ validate/stage/commit
- Export Excel/PDF สำหรับรายงานที่ต้องตรวจสอบ

### 3.2 ไม่ทำใน MVP

- Lease/Right-of-use asset ตาม TFRS 16
- Revaluation model และ OCI/revaluation surplus
- Construction in progress/project capitalization แบบเต็มระบบ
- Automatic tax incentives หรือสิทธิหักค่าเสื่อมพิเศษตามมาตรการชั่วคราว
- Units-of-production depreciation ที่เชื่อม meter/IoT
- RFID gateway, GPS tracking, mobile offline และ native mobile app
- Rental/fleet revenue, asset budget และ asset procurement approval ใหม่ซ้ำกับ Purchasing
- Predictive maintenance และอะไหล่ซ่อมแบบ Work Order เต็มรูปแบบ

สิ่งเหล่านี้เพิ่มภายหลังได้เมื่อมี requirement จริง โดยต้องไม่เปลี่ยน ledger และ document history ที่ Posted แล้ว

## 4. Accounting and tax anchors

กติกาต่อไปนี้เป็นหลักออกแบบ ไม่ใช่การ hardcode อัตราภาษี:

- ต้นทุนสินทรัพย์รวมราคาซื้อและต้นทุนโดยตรงที่ทำให้สินทรัพย์อยู่ในสภาพพร้อมใช้งาน
- เริ่มคิดค่าเสื่อมเมื่อสินทรัพย์พร้อมใช้งาน (`placed_in_service_date`) ไม่ยึดวันที่ใบซื้อโดยอัตโนมัติ
- component ที่มีต้นทุนมีนัยสำคัญและอายุใช้งานต่างกันต้องแยกเป็น asset/component เพื่อคิดค่าเสื่อมแยก
- ทบทวนอายุใช้งาน มูลค่าคงเหลือ และวิธีคิดค่าเสื่อมได้ แต่การเปลี่ยนต้องมี effective date และใช้กับอนาคต ห้ามแก้ผล Posted ย้อนหลัง
- สินทรัพย์ที่คิดค่าเสื่อมครบแล้วยังคงอยู่ในทะเบียนจนกว่าจะจำหน่ายหรือตัดออก
- Book และ Tax book ต้องแยกกัน เพราะฐาน อัตรา อายุ และข้อจำกัดอาจไม่เท่ากัน
- อัตราภาษี เพดานต้นทุน และสิทธิพิเศษเป็น configuration ที่มี effective date ห้ามฝังค่าคงที่ใน calculator

แหล่งอ้างอิงที่ Agent ต้องตรวจซ้ำเมื่อ implement:

- [มาตรฐานการบัญชี ฉบับที่ 16 เรื่อง ที่ดิน อาคารและอุปกรณ์](https://eservice.tfac.or.th/files/accounting_std_document/TAS_16_revised_2566.pdf)
- [คำสั่งกรมสรรพากร ป.3/2527 เรื่องค่าสึกหรอและค่าเสื่อมราคา](https://www.rd.go.th/3618.html)
- [พระราชกฤษฎีกาฉบับที่ 505 เรื่องข้อจำกัดต้นทุนรถยนต์บางประเภท](https://www.rd.go.th/32406.html)

ระบบต้องแสดงคำเตือนว่า Tax profile เป็นการตั้งค่าของบริษัทและควรผ่านการตรวจสอบจากผู้ทำบัญชี/ที่ปรึกษาภาษี

## 5. Non-negotiable business rules

### 5.1 Branch และ Warehouse

- `branch_id` ต้องมีเสมอใน Asset และเอกสาร Asset ทุกชนิด
- สาขาเป็น ownership, permission scope, document counter scope และ reporting dimension หลัก
- `warehouse_id` เป็น nullable และใช้เฉพาะกรณีสินทรัพย์อยู่ในพื้นที่คลังจริง
- Warehouse ที่เลือกต้องเป็นของ `branch_id` เดียวกัน
- ห้ามใช้ `warehouse_id` เป็นตัวแทนสาขา หรือใช้กรอง Asset ทั้ง module
- การเปลี่ยน Warehouse/Location ไม่เปลี่ยนสาขา เว้นแต่ผ่านเอกสารโอนข้ามสาขา
- Header/topbar แสดงสาขา ไม่แสดงคลัง; จุดที่เกี่ยวกับสถานที่จริงจึงค่อยให้เลือก Location/Warehouse

### 5.2 Document date และเลขเอกสาร

- เลขเอกสารใช้ Global Document Sequence ที่ Settings เป็นเจ้าของ
- Counter แยกตาม `document_type + branch_id`
- `{YY}`/`{YYMM}` ต้องยึดวันที่เอกสารจาก View เท่านั้น ไม่ใช้วันที่ server แทน
- เลขที่จ่ายแล้วไม่ reuse แม้ Draft ถูกลบหรือเอกสารถูกยกเลิก
- ประเภทเอกสาร Asset เป็น internal master ผู้ใช้แก้ template ได้ แต่เพิ่ม/ลบ/เปลี่ยนประเภทไม่ได้
- รูปแบบเริ่มต้นของเอกสารที่สาขาทำเองคือ `{PREFIX}{BRANCH}{YYMM}{NUMBER:6}`

ประเภทและ Prefix ขั้นต่ำ:

| document_type | ชื่อไทย | Prefix |
|---|---|---|
| `ASSET_REGISTER` | รหัสสินทรัพย์ | `FA` |
| `ASSET_CAPITALIZATION` | ใบรับรู้สินทรัพย์ | `AC` |
| `ASSET_TRANSFER` | ใบโอน/ย้ายสินทรัพย์ | `AT` |
| `ASSET_COUNT` | ใบตรวจนับสินทรัพย์ | `FC` |
| `ASSET_MAINTENANCE` | ใบแจ้งซ่อมสินทรัพย์ | `MR` |
| `ASSET_DEPRECIATION` | ชุดคำนวณค่าเสื่อม | `DP` |
| `ASSET_IMPAIRMENT` | ใบบันทึกด้อยค่าสินทรัพย์ | `IM` |
| `ASSET_DISPOSAL` | ใบจำหน่าย/ตัดออก | `AD` |

### 5.3 Money, dates และ immutability

- เงินใช้ `decimal(18,2)` และ calculator ห้ามใช้ float
- อัตราใช้ decimal ที่กำหนด scale ชัดเจน
- เก็บวันที่ธุรกิจเป็น `date`; audit timestamp เก็บตาม application timezone `Asia/Bangkok`
- Draft แก้ไขได้ตามสิทธิ์; Submitted/Approved แก้เฉพาะ action; Posted ห้ามแก้ทับ
- Posted transaction แก้ด้วย reversal/new transaction เท่านั้น
- ห้าม hard delete Asset หรือเอกสารที่เคย Approved/Posted
- Category/Location ใช้ active/inactive และ soft delete เฉพาะรายการที่ยังไม่ถูกอ้างอิง
- ทุก action สำคัญต้องเก็บ actor, เวลา, before/after หรือ reason ใน AuditLog และ domain history

### 5.4 Asset lifecycle

สถานะ Asset:

```text
DRAFT -> REGISTERED -> ACTIVE
ACTIVE <-> SUSPENDED
ACTIVE <-> UNDER_REPAIR
ACTIVE -> HELD_FOR_DISPOSAL -> DISPOSED
ACTIVE/SUSPENDED/UNDER_REPAIR -> WRITTEN_OFF
```

กติกา:

- `REGISTERED` คือมีทะเบียนแต่ยังไม่พร้อมคิดค่าเสื่อม
- `ACTIVE` ต้องมี capitalization ที่ Approved/Posted หรือเป็น opening balance ที่ยืนยันแล้ว
- `UNDER_REPAIR` หยุดค่าเสื่อมเฉพาะเมื่อ policy และเอกสารระบุว่า unavailable for use; การแจ้งซ่อมทั่วไปไม่หยุดอัตโนมัติ
- `HELD_FOR_DISPOSAL` ห้ามโอนและห้ามสร้างงานซ่อมใหม่ ยกเว้นยกเลิกแผนจำหน่าย
- `DISPOSED`/`WRITTEN_OFF` เป็น terminal state; reversal ต้องเปิดสถานะเดิมผ่าน service เท่านั้น

## 6. Module architecture

สร้าง module ตาม convention เดิม:

```text
app/Modules/Asset/
├── Controllers/
├── Models/
├── Requests/
├── Services/
├── Support/
├── Providers/AssetServiceProvider.php
├── Routes/web.php
└── Views/
    ├── dashboard.blade.php
    ├── workflow/
    ├── assets/
    ├── categories/
    ├── locations/
    ├── capitalizations/
    ├── depreciation-runs/
    ├── transfers/
    ├── counts/
    ├── maintenance/
    ├── impairments/
    ├── disposals/
    └── reports/
```

ห้ามสร้าง repository interface, command bus, DTO layer หรือ generic workflow engine ใหม่ ใช้ Eloquent, Form Request, Controller และ service เฉพาะ transaction/calculation ที่มีหลาย record

Service ที่มีเหตุผลให้สร้าง:

- `AssetCapitalizationService`
- `DepreciationCalculator` เป็น pure calculation ไม่มี query
- `DepreciationRunService`
- `AssetTransferService`
- `AssetImpairmentService`
- `AssetDisposalService`
- `AssetReconciliationService`
- `AssetImportService`

CRUD เช่น Category, Location และ Maintenance note ไม่ต้องสร้าง service หาก transaction ไม่ซับซ้อน

## 7. Platform prerequisites

Asset ต้องเป็น branch-scoped program ที่ไม่บังคับ Warehouse จึงต้องทำ Foundation นี้ก่อน:

- เพิ่ม `programs.requires_branch` แยกจาก `requires_warehouse`
- ตั้ง Asset เป็น `requires_branch=true`, `requires_warehouse=false`, `entry_route=asset.index`
- เพิ่ม `EnsureBranchSelected` middleware และ alias `branch`
- Asset routes ใช้ `['auth', 'program:asset', 'branch']`
- เปลี่ยน `ContextSelection` ให้ตรวจ `requires_branch` และ `requires_warehouse` แยกกัน
- เพิ่ม `ModuleCapability::ASSET` และ `asset_enabled` ใน Global Settings; เปิดค่าเริ่มต้นเมื่อ deploy Asset สำเร็จ
- ลงทะเบียน `AssetServiceProvider` ใน `bootstrap/providers.php`
- แทน placeholder Asset ใน `WorkflowCatalog` ด้วย routes/readiness จริงเมื่อแต่ละ Phase พร้อม

Accounting prerequisite:

- `journal_entries.branch_id` ต้องมีเสมอ
- ทำ `journal_entries.warehouse_id` nullable สำหรับ event ที่ไม่เกี่ยวกับ stock
- เพิ่ม `JournalPostingService::postForBranch(..., Branch $branch, ?Warehouse $warehouse)` โดย method เดิมที่รับ Warehouse delegate เข้ามาเพื่อ backward compatibility
- Asset ห้ามสร้าง Warehouse ปลอมเพียงเพื่อ Post GL

## 8. Data model

ทุกตาราง transactional ต้องมี index ที่รองรับ `branch_id`, `status`, document/business date และ foreign keys ที่ใช้ค้นหา

### 8.1 Master tables

#### `asset_categories`

ฟิลด์สำคัญ:

- `id`, `code` unique, `name`, `description`
- `is_depreciable`, `capitalization_threshold`
- Book defaults: `book_method`, `book_useful_life_months`, `book_residual_value_percent`
- Tax defaults: `tax_method`, `tax_useful_life_months`, `tax_rate_percent`, `tax_cost_cap`
- Accounts: `asset_account_id`, `accumulated_depreciation_account_id`, `depreciation_expense_account_id`, `accumulated_impairment_account_id`, `impairment_loss_account_id`, `disposal_gain_account_id`, `disposal_loss_account_id`
- `is_active`, `created_by`, `updated_by`, timestamps, soft delete

Rules:

- Accounts ต้อง active/postable และมี account type/control contract ถูกต้อง
- เปลี่ยน default ไม่แก้ profile ของ Asset ที่สร้างแล้ว
- Category ที่ถูกใช้งานห้ามลบ ให้ปิดใช้งาน
- ที่ดินตั้ง `is_depreciable=false`

#### `asset_locations`

- `id`, `branch_id`, `parent_id` nullable
- `code`, `name`, `location_type` (`BRANCH`, `WAREHOUSE`, `BUILDING`, `FLOOR`, `ROOM`, `SITE`, `OTHER`)
- `warehouse_id` nullable และต้องอยู่สาขาเดียวกัน
- `address`, `is_active`, audit columns, soft delete
- unique `branch_id + code`
- Parent ต้องอยู่สาขาเดียวกัน และป้องกัน circular hierarchy

### 8.2 Asset register

#### `assets`

- Identity: `id`, `asset_number` unique, `tag_number` unique nullable, `barcode_value` unique nullable
- Ownership: `branch_id`, `warehouse_id` nullable, `location_id`, `custodian_user_id` nullable
- Classification: `asset_category_id`, `parent_asset_id` nullable
- Description: `name`, `description`, `brand`, `model`, `serial_number`, `manufacturer`
- Acquisition: `acquisition_date`, `placed_in_service_date` nullable, `supplier_id` nullable
- Warranty/insurance: `warranty_end_date`, `insurance_policy_number`, `insurance_end_date`
- Value snapshot: `original_cost`, `currency_code`, `exchange_rate`
- Current values: `book_cost`, `book_accumulated_depreciation`, `book_accumulated_impairment`, `book_value`
- Status: lifecycle enum, `is_depreciation_suspended`, `status_reason`
- Source: `source_type`, `source_id`, `source_line_id` nullable
- Audit columns, timestamps; soft deleteเฉพาะ DRAFT

Constraints:

- unique source line สำหรับการรับรู้สินทรัพย์ เพื่อป้องกัน capitalize ซ้ำ
- parent/component ต้องอยู่สาขาเดียวกัน
- cost/value ห้ามติดลบ; accumulated amounts ห้ามเกิน depreciable/carrying amount
- current value columns เป็น cached projection เพื่อ query/report; ledger/run lines เป็น source of truth และต้องมี reconciliation test

#### `asset_depreciation_books`

หนึ่ง Asset มีอย่างน้อย `BOOK` และอาจมี `TAX`:

- `asset_id`, `book_type` (`BOOK`, `TAX`)
- `method` เริ่มด้วย `STRAIGHT_LINE`
- `depreciable_cost`, `residual_value`, `useful_life_months`
- `start_date`, `end_date` nullable
- `tax_rate_percent`, `tax_cost_cap` nullable
- `accumulated_depreciation`, `last_depreciation_date` nullable
- `is_active`, audit columns
- unique `asset_id + book_type`

ห้าม update profile ย้อนหลังหลังมี run Posted ให้สร้าง policy change/effective-date record

#### `asset_value_events`

Append-only subledger สำหรับต้นทุนและมูลค่า:

- `asset_id`, `branch_id`, `event_date`
- `event_type` (`OPENING`, `CAPITALIZATION`, `ADDITION`, `IMPROVEMENT`, `IMPAIRMENT`, `IMPAIRMENT_REVERSAL`, `DISPOSAL`, `WRITE_OFF`, `REVERSAL`)
- `cost_delta`, `depreciation_delta`, `impairment_delta`
- `source_type`, `source_id`, `source_line_id`, `journal_entry_id` nullable
- `idempotency_key` unique, `created_by`, timestamp

ห้าม update/delete event; reversal เป็น event ใหม่ที่อ้าง event เดิม

### 8.3 Workflow documents

#### `asset_capitalizations` และ `asset_capitalization_lines`

Header:

- `document_number`, `branch_id`, `document_date`
- `source_type` (`PURCHASE_DOCUMENT`, `PAYMENT_VOUCHER`, `OPENING`, `MANUAL_RECLASS`)
- `source_id`, `status` (`DRAFT`, `SUBMITTED`, `APPROVED`, `POSTED`, `REVERSED`, `VOID`)
- `description`, approval/post/reversal columns, `journal_entry_id`

Line:

- `asset_id`, source line reference, `capitalized_cost`, `clearing_account_id`
- directly attributable cost description
- unique source reference เมื่อ source เป็นเอกสารซื้อ

#### `asset_depreciation_runs` และ `asset_depreciation_lines`

Header:

- `document_number`, `branch_id`, `fiscal_period_id`, `book_type`
- `run_through_date`, `status` (`CALCULATING`, `DRAFT`, `SUBMITTED`, `APPROVED`, `POSTED`, `REVERSED`, `FAILED`)
- totals, calculation hash, progress/error, approval/post/reversal fields, `journal_entry_id`
- unique active run `branch_id + fiscal_period_id + book_type`

Line:

- asset and category snapshot
- opening cost/accumulated depreciation/impairment
- period depreciation, catch-up adjustment, closing values
- calculation inputs snapshot and explanation JSON
- `journal_entry_line_id` nullable

#### `asset_transfers` และ `asset_transfer_lines`

- Header: document number/date, source branch, destination branch, reason, status and approval/post fields
- Lines: asset, old/new branch, old/new warehouse/location/custodian snapshots
- Transfer within branch Posts no GL
- Transfer across branches Posts reclassification only when branch reporting needs balances moved

#### `asset_counts` และ `asset_count_lines`

- Header: document number, branch, scope location/category, freeze date, status
- Lines: expected asset, scanned tag/barcode, found location/custodian, result (`FOUND`, `MISSING`, `WRONG_LOCATION`, `DAMAGED`, `EXTRA`), note
- Count approval does not write-off automatically; missing/damaged creates follow-up task or draft write-off

#### `asset_maintenance_requests`

- `document_number`, `asset_id`, `branch_id`, reported date/user
- `maintenance_type` (`CORRECTIVE`, `PREVENTIVE`, `INSPECTION`)
- `priority` (`LOW`, `NORMAL`, `HIGH`, `CRITICAL`)
- issue, diagnosis, resolution, vendor `party_id` nullable
- warranty flag, planned/start/completed dates, downtime minutes
- estimated/actual cost, source purchase/payment document reference
- `takes_asset_out_of_service`
- status (`OPEN`, `ASSIGNED`, `IN_PROGRESS`, `WAITING_PARTS`, `COMPLETED`, `CANCELLED`)
- assignment/completion/cancellation audit fields

Repair cost เป็นข้อมูลติดตามเท่านั้น รายการเจ้าหนี้/จ่ายเงินจริงต้องมาจาก Purchasing/Finance และ link กลับมา ห้าม Asset Post ค่าใช้จ่ายซ้ำ

#### `asset_maintenance_schedules`

- `asset_id`, title, interval months/days, next_due_date, last_completed_date
- responsible user, default priority, active flag
- Scheduler สร้าง alert; MVP ไม่สร้าง request ซ้ำเองจนกว่าผู้ใช้ยืนยัน

#### `asset_impairments` และ `asset_impairment_lines`

- document number/date, branch, reason/evidence, status
- asset, carrying amount before, recoverable amount, impairment amount
- approved/posted/reversed data and journal reference
- หลัง Post ต้องปรับฐานค่าเสื่อมในอนาคต ไม่แก้ run เดิม

#### `asset_disposals` และ `asset_disposal_lines`

- Header: document number/date, branch, disposal type (`SALE`, `DONATION`, `SCRAP`, `LOST`, `DAMAGED`, `OTHER`), reason, status
- Link sales document/settlement สำหรับ proceeds; Asset ไม่สร้าง AR/receipt เอง
- Line: asset, original cost, accumulated depreciation, accumulated impairment, carrying amount, proceeds allocated, gain/loss
- Approval ต้องยืนยันว่าค่าเสื่อมถึง disposal date ครบและ downstream document พร้อม

#### `asset_attachments`

- polymorphic subject (`ASSET`, `MAINTENANCE`, `IMPAIRMENT`, `DISPOSAL`)
- file type (`PHOTO`, `INVOICE`, `WARRANTY`, `INSURANCE`, `REPAIR_REPORT`, `DISPOSAL_EVIDENCE`, `OTHER`)
- private disk/path, original name, MIME, bytes, checksum, uploaded_by
- download ผ่าน authorized controller/signed URL เท่านั้น

#### `asset_histories`

- `asset_id`, `event_type`, event date/time, source document, actor, reason
- old/new branch, location, custodian, status และ value snapshots ที่เกี่ยวข้อง
- ใช้แสดง timeline ที่ผู้ใช้เข้าใจง่าย ส่วน `audit_logs` ยังเป็น technical audit source

## 9. Core workflows

### 9.1 Setup

```text
Account readiness
  -> Asset Category + accounts
  -> Asset Location
  -> Document sequences
  -> Opening/import policy
  -> Ready for registration
```

Readiness ต้อง block transaction เมื่อ:

- ไม่มี fiscal period เปิด
- Category ขาดบัญชีที่จำเป็น
- Document sequence ปิดหรือไม่มี
- Branch ไม่มีสิทธิ์หรือ inactive
- Accounting posting context สำหรับ branch ยังไม่พร้อม

### 9.2 Register and capitalize

```text
สร้าง/Import Asset Draft
  -> เลือก source และ Category
  -> Snapshot book/tax profile
  -> Submit
  -> Approve
  -> Capitalize/Post
  -> Asset ACTIVE
```

Rules:

- Purchase-linked asset รับรู้ได้เฉพาะ source line ที่ Approved/Posted ตาม contract ของ Purchasing
- Source line เดียวห้าม capitalize ซ้ำ
- Invoice date, acquisition date และ placed-in-service date แยกกัน
- ต้นทุนต่ำกว่า threshold ให้เตือนและต้องมี reason/permission เพื่อ capitalize
- `OPENING` ใช้สำหรับยอดเดิมก่อน go-live และไม่ Post GL ซ้ำ; ต้องเก็บ opening cost/accumulated depreciation และ reconciliation batch
- `MANUAL_RECLASS` Post Dr Asset / Cr clearing/expense account ที่อนุมัติ

### 9.3 Depreciation

```text
เลือก Branch + Fiscal Period
  -> Calculate preview
  -> Review exceptions
  -> Submit
  -> Approve
  -> Post GL (BOOK only)
  -> Reconcile register vs GL
```

Calculation contract:

- Straight-line MVP: `(depreciable cost - residual value) / useful life`
- ใช้ cumulative target ถึง run-through date ลบ accumulated depreciation ที่ Posted แล้ว เพื่อรองรับงวดที่ข้ามและลด rounding drift
- ห้ามลด book value ต่ำกว่า residual value
- งวดสุดท้ายเป็น balancing amount เท่านั้น
- Leap year, partial period และ placed-in-service date ต้องมี unit tests
- Asset ที่ disposed/written-off ก่อน run-through date คิดได้ถึง effective disposal dateเท่านั้น
- Tax run คำนวณและรายงาน แต่ `journal_entry_id` ต้องเป็น null
- Run เดิมซ้ำต้อง return ผลเดิมเมื่อ hash เท่ากัน; hash ต่างต้อง block และให้ reverse/recalculate ตามสถานะ
- Locked period ห้าม calculate/post/reverse เข้าไป

### 9.4 Transfer, move and assign

```text
Draft transfer
  -> Validate destination
  -> Submit/Approve
  -> Post movement
  -> Update current projection + history
```

- Location/custodian move ในสาขาเดียวกันไม่ Post GL
- ข้ามสาขาต้องมี source/destination approval permission
- ถ้ารายงาน GL แยกสาขา ให้สร้าง Journal สองฝั่งผ่าน interbranch clearing และต้อง net เป็นศูนย์
- Asset ที่ UNDER_REPAIR หรือ HELD_FOR_DISPOSAL block transfer เว้นแต่มี override permission + reason
- Parent/component transfer ใช้ bulk include children โดยผู้ใช้เห็นรายการก่อนยืนยัน

### 9.5 Count

```text
Create count scope
  -> Freeze expected list
  -> Scan/mark results
  -> Review variance
  -> Approve count
  -> Create follow-up actions
```

- Count snapshot immutable หลังเริ่มตรวจ
- Extra tag ต้องไม่สร้าง Asset อัตโนมัติ
- Missing/Damaged ต้องไม่ write-off อัตโนมัติ
- Wrong location สามารถสร้าง Draft transfer หลังผู้ใช้ยืนยัน

### 9.6 Maintenance

```text
Report issue
  -> Assign
  -> Diagnose / start
  -> Wait parts/vendor (optional)
  -> Complete + cost/source refs
  -> Return to service
```

- ตรวจ warranty ก่อนบันทึกผู้รับซ่อม/ค่าใช้จ่าย
- Critical request แสดง Dashboard alert
- เมื่อ `takes_asset_out_of_service=true` ให้บันทึก suspension window; depreciation policy เป็นผู้ตัดสินผล ไม่ใช้ status อย่างเดียว
- ค่าใช้จ่ายซ่อมปกติเป็น expense ใน Purchasing/Finance
- Improvement ที่เพิ่มประโยชน์/อายุใช้งานต้องสร้าง capitalization/addition document แยกและอนุมัติ

### 9.7 Impairment

```text
Draft assessment
  -> Enter evidence + recoverable amount
  -> Approve
  -> Post impairment
  -> Recalculate future depreciation basis
```

- ห้าม impairment ทำให้ carrying amount ติดลบ
- Reversal เป็นเอกสารใหม่และต้องไม่ทำให้มูลค่าสูงกว่ามูลค่าที่ควรเป็นหากไม่เคยด้อยค่า
- การเปลี่ยน future depreciation ใช้ prospective calculation

### 9.8 Disposal/write-off

```text
Draft disposal
  -> Validate final depreciation/downstream proceeds
  -> Submit/Approve
  -> Post derecognition
  -> Asset DISPOSED/WRITTEN_OFF
```

- Sale ต้อง link Sales/Finance document; proceeds ใช้ disposal clearing เพื่อไม่รับรู้เงินซ้ำ
- Write-off ไม่มี proceeds
- Missing asset ต้องผ่าน count/investigation reference หรือมี override reason
- หลังมี downstream sale/settlement ที่ยังไม่ reverse ห้าม reverse disposal

## 10. Accounting events and journals

เพิ่ม event ใน `PostingEvent`:

- `asset.capitalization`
- `asset.addition`
- `asset.depreciation` (มี foundation แล้ว)
- `asset.impairment`
- `asset.disposal`
- `asset.write_off`
- `asset.branch_transfer`

Journal examples:

| Event | Debit | Credit |
|---|---|---|
| Capitalization | Fixed asset cost | Asset clearing/expense reclass |
| Addition/Improvement | Fixed asset cost | Asset clearing |
| Book depreciation | Depreciation expense | Accumulated depreciation |
| Impairment | Impairment loss | Accumulated impairment |
| Write-off | Accumulated depreciation + accumulated impairment + disposal loss | Fixed asset cost |
| Sale disposal | Accumulated depreciation + accumulated impairment + disposal clearing + loss (ถ้ามี) | Fixed asset cost + gain (ถ้ามี) |
| Cross-branch transfer | Reclass cost/accumulated balances ผ่าน interbranch clearing | คู่บัญชีอีกสาขา |

Rules:

- `source_type`, `source_event`, `source_id`, `idempotency_key`, posting hash ต้องครบ
- Journal ทุกชุดต้อง balanced และ Post ผ่าน `JournalPostingService`
- Category snapshot account IDs ลงใน document line เพื่อป้องกัน category เปลี่ยนแล้วกระทบเอกสารย้อนหลัง
- GL depreciation อาจ aggregate ตาม Category แต่ Asset run lines ต้อง drill down รวมได้ตรงกับ Journal ทุกบาท
- Reverse ผ่าน Accounting service และสร้าง opposite Journal; ห้ามเปลี่ยน Journal Posted
- Asset register vs GL reconciliation ต้องตรวจ cost, accumulated depreciation และ accumulated impairment แยกกัน

## 11. UI and menu

Sidebar:

- Dashboard
- คู่มือการทำงาน
- ทะเบียนสินทรัพย์
- รับรู้สินทรัพย์
- ค่าเสื่อมราคา
- โอน/ย้ายสินทรัพย์
- ตรวจนับสินทรัพย์
- แจ้งซ่อมและบำรุงรักษา
- ด้อยค่า
- จำหน่าย/ตัดออก
- รายงาน
- ตั้งค่าสินทรัพย์
  - หมวดสินทรัพย์
  - สถานที่สินทรัพย์

Asset detail ต้องมี tabs:

- ภาพรวม
- มูลค่าตามบัญชี/ภาษี
- ค่าเสื่อม
- ตำแหน่งและผู้ดูแล
- ซ่อมบำรุง
- เอกสารแนบ
- ประวัติ

Dashboard แสดงเฉพาะข้อมูลที่ตัดสินใจได้:

- Cost, accumulated depreciation, impairment และ NBV
- จำนวน Active/Under repair/Held for disposal
- ค่าเสื่อมรอคำนวณ/รออนุมัติ/รอ Post
- งานซ่อม Critical และเกิน SLA
- ประกัน/แผนซ่อมใกล้ครบกำหนด
- Count variance ที่ยังไม่ปิด
- Assets ที่ยังไม่มี custodian/location
- Register vs GL variance

Performance ของ Dashboard:

- หน้า HTML ไม่ query aggregate ก้อนใหญ่
- แยก endpoint อย่างน้อย: summary, depreciation tasks, maintenance alerts, warranty alerts, reconciliation
- โหลดแต่ละ section แบบ lazy/parallel พร้อม skeleton/error/retry เฉพาะ section
- Cache summary ระยะสั้นตาม branch และ invalidate เมื่อมี Posted event

UX rules:

- ทุก status มีคำไทยและสีเดียวกันทุกหน้า
- Action ที่ใช้ไม่ได้ต้องซ่อนหรือ disabled พร้อมเหตุผล
- Modal ใช้ดู breakdown ได้ แต่ action สำคัญต้องมีหน้าเอกสาร/URL ที่อ้างอิงได้
- ตารางใช้ server-side DataTables, responsive horizontal scroll และ export ที่ไม่ดึงทุก row เข้า browser
- QR/Barcode ต้องมี printable label แต่ MVP ไม่ต้อง integrate printer driver

## 12. Permissions

Permission keys ขั้นต่ำ:

```text
asset.dashboard.view
asset.workflow.view
asset.register.view
asset.register.create
asset.register.update
asset.register.import
asset.categories.view
asset.categories.manage
asset.locations.view
asset.locations.manage
asset.capitalizations.view
asset.capitalizations.create
asset.capitalizations.submit
asset.capitalizations.approve
asset.capitalizations.post
asset.capitalizations.reverse
asset.depreciation.view
asset.depreciation.calculate
asset.depreciation.submit
asset.depreciation.approve
asset.depreciation.post
asset.depreciation.reverse
asset.transfers.view
asset.transfers.create
asset.transfers.approve
asset.transfers.post
asset.counts.view
asset.counts.create
asset.counts.approve
asset.maintenance.view
asset.maintenance.create
asset.maintenance.assign
asset.maintenance.complete
asset.impairments.view
asset.impairments.create
asset.impairments.approve
asset.impairments.post
asset.disposals.view
asset.disposals.create
asset.disposals.approve
asset.disposals.post
asset.disposals.reverse
asset.reports.view
asset.attachments.manage
```

- Query ทุกตัวต้อง scope ตามสาขาที่ผู้ใช้มีสิทธิ์
- ผู้มี permission approve สามารถอนุมัติเอกสารตนเองได้ใน MVP เพื่อรองรับทีมเล็ก แต่ audit ต้องชัดเจน
- Cross-branch transfer ต้องมีสิทธิ์เข้าถึงทั้งสองสาขา
- File download ต้อง authorize subject ทุกครั้ง

## 13. Settings

เพิ่ม Setting Registry:

- `asset_enabled` boolean
- `asset_capitalization_threshold` decimal
- `asset_depreciation_proration` enum `DAILY`, `FULL_MONTH`
- `asset_depreciation_rounding` integer 0–4, default 2
- `asset_warranty_alert_days` integer
- `asset_maintenance_alert_days` integer
- `asset_allow_depreciation_during_suspension` boolean

Settings เป็น default/safe policy; Asset และ document ต้อง snapshot ค่าที่มีผลต่อการคำนวณ ณ วันที่อนุมัติ

Tax rate/life/cap อยู่ Category/Tax profile ไม่อยู่ global ค่าเดียว

## 14. Integration contracts

### Purchasing/WMS

- ค้นหา source purchase document/line ที่ eligible
- เก็บ source snapshot และ unique link ป้องกัน capitalize ซ้ำ
- งานซ่อม link PO/Purchase invoice ได้ แต่ไม่เปลี่ยนสถานะ AP จาก Asset
- การซื้อสินทรัพย์ไม่สร้าง stock movement เว้นแต่ item นั้นเป็น stock ก่อน capitalize ตาม flow ที่อนุมัติในอนาคต

### Finance

- Supplier/Payment Voucher/Settlement ยังคงเป็นของ Finance
- Asset sale proceeds link Sales invoice/receipt หรือ Finance settlement
- Asset แสดง payment status แบบ read-only ไม่สร้างการจ่ายเอง

### Accounting

- Asset ส่ง posting intent ผ่าน service กลาง
- Period status และ account readiness เป็น blocker
- Reversal และ reconciliation ใช้ Journal source identity เดิม

### Settings/Platform

- Branch, User/Custodian, permissions, document sequence, audit และ private files ใช้ foundation กลาง
- ไม่สร้าง employee master ซ้ำ; custodian อ้าง `users`

## 15. Performance and concurrency

- Index ทุกตารางหลักอย่างน้อย `(branch_id, status)`, `(branch_id, document_date)`, source reference และ due-date columns
- DataTables และ option endpoints ต้อง paginate/search ฝั่ง server
- Depreciation calculate ใช้ `chunkById`, snapshot inputs และ progress ที่เก็บใน run
- Run มากกว่า threshold ที่กำหนดใช้ queued job; UI poll status แทน request ค้าง
- Lock unique run ต่อ branch/period/book และ lock Asset rows ตอน Post
- ใช้ idempotency key ป้องกัน double click/retry
- Dashboard แบ่ง APIs ต่อ section และ cache สั้น
- Report export จำนวนมากใช้ queued export/private download
- Scheduler รายวันสร้าง alert จาก indexed due dates ห้าม scan ทุก request
- ห้าม N+1 ใน register/detail/report; tests ต้องเปิด query count assertion สำหรับ endpoint สำคัญ

## 16. Reports

MVP reports:

1. Asset register ณ วันที่
2. Cost/NBV by branch, category, location และ custodian
3. Book depreciation schedule
4. Tax depreciation schedule
5. Book vs tax depreciation difference
6. Additions, transfers, impairments และ disposals movement report
7. Fully depreciated assets still in use
8. Asset count variance
9. Maintenance history, cost และ downtime
10. Warranty/insurance/maintenance due
11. Asset subledger vs GL reconciliation
12. Audit/document history report

ทุกรายงานต้องมี date/branch filter, server pagination, Thai labels, Excel export และยอดรวมที่คำนวณฝั่ง server

## 17. Implementation phases and checklist

### Phase 0 — Contract and platform foundation

- [ ] อ่านและยืนยัน plan กับเจ้าของระบบ
- [ ] เพิ่ม `requires_branch` และ branch middleware/context flow
- [ ] ตั้ง Asset program เป็น branch-only และ entry route ที่ถูกต้อง
- [ ] เพิ่ม Asset capability/global setting
- [ ] ทำ Journal posting แบบ branch + optional warehouse
- [ ] สร้าง Asset provider/routes/layout/sidebar/dashboard shell
- [ ] Seed program/permissions โดย re-run ได้
- [ ] เพิ่ม internal Asset document sequence types/templates
- [ ] เพิ่ม workflow placeholder ที่ชี้ route จริง
- [ ] Feature tests: program selection, branch scope, no warehouse requirement

Gate: เข้า `/asset` ได้ด้วยสาขาที่มีสิทธิ์ โดยไม่ต้องสร้างหรือเลือกคลังปลอม

### Phase 1 — Master data and asset register

- [ ] Migrations: categories, locations, assets, depreciation books, attachments, histories
- [ ] Category CRUD + account validation
- [ ] Location tree CRUD + same-branch/cycle validation
- [ ] Register CRUD, component relation, custodian/location and warranty
- [ ] Asset code via global sequence/document date
- [ ] Detail tabs, attachments and printable QR/Barcode label
- [ ] Server-side table/options/export
- [ ] Audit trail + domain history
- [ ] Unit/feature tests and authorization tests

Gate: สร้าง Draft Asset ได้ครบและข้อมูลทุก row ถูก scope ตาม branch

### Phase 2 — Acquisition, opening and capitalization

- [ ] Migrations: capitalization header/lines and value events
- [ ] Purchase source lookup/eligibility contract
- [ ] Opening balance stage/import/reconciliation flow
- [ ] Draft/submit/approve/post/reverse lifecycle
- [ ] Snapshot category/account/book/tax profiles
- [ ] Capitalization Journal + idempotency
- [ ] Prevent duplicate source capitalization
- [ ] Update projection/status/history atomically
- [ ] Tests: duplicate, threshold, period lock, GL balanced, retry, reverse

Gate: Asset ACTIVE มี cost/subledger/Journal ที่ตรวจย้อนกลับถึง source ได้และไม่ Post ซ้ำ

### Phase 3 — Book and tax depreciation

- [ ] Depreciation calculator unit tests ก่อน UI
- [ ] Migrations: runs/lines and policy change records
- [ ] Preview/progress/exceptions UI
- [ ] Submit/approve/post/reverse lifecycle
- [ ] Book GL posting and tax non-posting
- [ ] Catch-up, partial period, leap year, residual and final rounding
- [ ] Policy change prospective handling
- [ ] Run concurrency/idempotency/hash
- [ ] Depreciation schedule and book-vs-tax report
- [ ] Register vs run reconciliation

Gate: ค่าเสื่อมทุกบาทรวมตรง run, asset projection และ GL; re-run ไม่สร้างซ้ำ

### Phase 4 — Transfer, assignment and physical count

- [ ] Transfer header/lines and movement service
- [ ] Same-branch location/custodian move
- [ ] Cross-branch validation and optional reclass Journals
- [ ] Parent/component bulk handling
- [ ] Count header/lines, frozen scope and scan entry
- [ ] Variance review and follow-up draft actions
- [ ] Movement/count reports and histories
- [ ] Tests: branch permissions, wrong location, terminal statuses, idempotency

Gate: current location/custodian/status ตรงกับ immutable history และ count ไม่แก้ทะเบียนโดยไม่มี action

### Phase 5 — Maintenance and alerts

- [ ] Maintenance request lifecycle
- [ ] Assignment, priority, warranty, downtime and source expense links
- [ ] Preventive schedule and daily alert command/job
- [ ] Under-repair/out-of-service rules
- [ ] Attachment/evidence flow
- [ ] Dashboard cards/toasts for critical/overdue/warranty
- [ ] Cost/downtime/maintenance history reports
- [ ] Tests: transitions, permissions, no duplicate accounting

Gate: งานซ่อมติดตามครบวงจรและ link ค่าใช้จ่ายจริงโดยไม่ Post AP/expense ซ้ำ

### Phase 6 — Impairment, disposal and write-off

- [ ] Impairment document + future depreciation basis
- [ ] Disposal/write-off header/lines and approval
- [ ] Final depreciation prerequisite
- [ ] Sale proceeds/downstream clearing contract
- [ ] Derecognition/gain-loss Journals
- [ ] Reverse blockers and reversal Journals
- [ ] Terminal status/history
- [ ] Tests: carrying value, no negative, sale/write-off, downstream blockers

Gate: Cost/accumulated values/proceeds/gain-loss รวมตรง GL และ terminal Asset แก้ไม่ได้

### Phase 7 — Dashboard, reports, import and close gate

- [ ] Dashboard section APIs + caching/error states
- [ ] All MVP reports and exports
- [ ] GL reconciliation dashboard/drill-down
- [ ] Production-safe Excel import stage/validate/commit
- [ ] Period-close gate: block เมื่อมี Asset run ค้างหรือ variance ตาม policy
- [ ] Workflow Center/readiness documentation
- [ ] Performance tests with representative volume
- [ ] Full regression and manual UAT
- [ ] Update user guide and checklist status

Gate: ผู้จัดการเห็นภาพรวมครบ ผู้ทำบัญชีกระทบยอดได้ และ period close ไม่ปล่อย blocker สำคัญผ่าน

## 18. Required tests

### Unit tests

- Straight-line monthly/daily calculation
- Leap year and partial month/year
- Residual floor and fully depreciated asset
- Catch-up after skipped period
- Policy change prospective amount
- Impairment and reversal ceiling
- Disposal gain/loss
- Component asset independence
- Document date controls YYMM

### Feature tests

- Branch isolation and cross-branch authorization
- Warehouse optional but same-branch when supplied
- State transition guards for every document
- Posted records immutable
- Fiscal period open/locked behavior
- Double-submit/idempotency/concurrent run
- Account mapping and balanced Journal
- Reverse creates opposite Journal and preserves original
- File upload/download authorization
- DataTables pagination/filter/export
- Audit and Asset history presence

### MySQL integration tests

- Unique active depreciation run under concurrency
- Source line capitalization uniqueness
- Counter per branch and document date reset
- Large run chunking and lock behavior
- Register/value event/GL reconciliation

## 19. Manual UAT scenarios

1. สร้าง Category คอมพิวเตอร์พร้อมบัญชีและค่าเริ่มต้น
2. สร้าง Asset จาก Purchase line และยืนยันว่า link ซ้ำไม่ได้
3. Capitalize ด้วยวันที่พร้อมใช้ต่างจากวันที่ซื้อ
4. Run Book/Tax เดือนแรกและตรวจ partial amount
5. Post Book แล้วตรวจ Journal/GL; Tax ต้องไม่มี Journal
6. เปลี่ยนอายุใช้งาน effective เดือนถัดไปและตรวจว่าเดือนเก่าไม่เปลี่ยน
7. ย้ายห้อง/ผู้ดูแลในสาขาเดียวกันโดยไม่มี Journal
8. โอนข้ามสาขาและตรวจสิทธิ์/ยอดสาขา
9. ตรวจนับพบผิด Location แล้วสร้าง Draft transfer
10. แจ้งซ่อม Critical, link invoice ซ่อม และปิดงาน
11. บันทึก impairment แล้วตรวจ future depreciation
12. ขาย Asset ผ่าน Sales/Finance และ Post disposal โดยไม่รับรู้ proceeds ซ้ำ
13. Write-off สูญหายพร้อมหลักฐานและ approval
14. Reverse disposal หลัง downstream ถูก reverse แล้ว
15. กระทบยอด Asset register กับ GL เป็นศูนย์
16. ตรวจ audit/history ว่าแสดงผู้ทำ วันเวลาไทย เหตุผล และ reference ครบ

## 20. Definition of Done

Asset Module ถือว่าเสร็จ MVP เมื่อ:

- Checklist Phase 0–7 ผ่านทั้งหมด
- ไม่มี route/menu ที่ชี้ placeholder
- ไม่มีการสร้าง Warehouse ปลอมสำหรับ Asset/Journal
- เอกสารทุกชนิดมี branch, document number/date, audit และ state guard
- Book/Tax depreciation แยกชัดเจน
- Posted/Reverse/Retry ทำงานแบบ idempotent
- Register, subledger และ GL reconcile ได้
- Maintenance ไม่ลงบัญชีซ้ำกับ Purchasing/Finance
- Dashboard/report รองรับ server-side load และผู้ใช้หลายคน
- Permissions และ branch isolation มี automated tests
- Migration ใช้ได้ทั้งฐานใหม่และฐานที่มีข้อมูลเดิม โดยไม่เปลี่ยนเลขเอกสารเดิม
- `php artisan test`, Pint, route cache และ view cache ผ่าน
- เจ้าของระบบผ่าน Manual UAT และอนุมัติ sign-off

## 21. Implementation notes for future AI

- เริ่มจาก Phase 0 เท่านั้น อย่าสร้างตารางทั้งหมดใน migration เดียว
- ก่อนสร้าง service ใหม่ ให้หา pattern ใน Accounting, WMS, Finance และ POS ก่อน
- อย่าแก้ยอด cached projection โดยไม่มี immutable event/run line รองรับ
- อย่าให้ model observer Post GL หรือเปลี่ยนสถานะธุรกิจ
- อย่าคำนวณค่าเสื่อมใน Blade/JavaScript; browser ใช้แสดง preview เท่านั้น
- อย่าดึง Asset ทั้งบริษัทมา render; ทุก table/report ต้อง branch scoped และ paginated
- อย่า hardcode rate ภาษีหรือเพดานรถยนต์จากตัวอย่างในเอกสารอ้างอิง
- อย่าแก้ Posted transaction เพื่อให้ test ผ่าน; ใช้ reversal ตาม contract
- ทุกครั้งที่จบ Phase ให้บันทึกไฟล์ที่แก้, tests, migration result, blocker และ UAT evidence ใต้ checklist ของ Phase นั้น

## 22. Handoff prompt สำหรับเริ่มงานรอบถัดไป

ใช้ข้อความนี้เพื่อสั่ง AI ตัวถัดไป โดยเปลี่ยนเลข Phase เมื่อ Phase ก่อนหน้าผ่าน Gate แล้วเท่านั้น:

```text
อ่าน docs/planning/11-asset-module-implementation-plan.md ทั้งฉบับ รวมถึงเอกสาร prerequisite
ที่ระบุในหัวข้อ 1 แล้วเริ่มทำ Asset Module เฉพาะ Phase 0 ตาม checklist

ก่อนแก้ code ให้ตรวจ implementation pattern ปัจจุบันของ Platform, Settings, Accounting,
WMS, Purchasing และ Finance ห้ามสมมติ schema หรือ route จากชื่อเพียงอย่างเดียว

ทำ migration และ seeder ให้ re-run ได้, รักษาข้อมูลและเลขเอกสารเดิม, เขียน automated tests,
รัน test ที่เกี่ยวข้อง และอัปเดต checkbox พร้อมหลักฐานเฉพาะรายการที่ผ่านจริง

ห้ามเริ่ม Phase ถัดไปจนกว่า Gate ของ Phase ปัจจุบันจะผ่านและเจ้าของระบบยืนยัน
สรุปท้ายงานเป็น: ผลลัพธ์, ไฟล์ที่แก้, migration, tests, UAT, blocker และงานถัดไป
```

สถานะเริ่มต้นของ handoff นี้คือ **Phase 0 — Contract and platform foundation**
