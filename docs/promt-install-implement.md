# Prompt: ERP Implementer Installation & Initial Setup Wizard

ฉันต้องการให้คุณออกแบบและพัฒนา **ERP Application Initialization / Implementation Wizard** สำหรับระบบ ERP ที่พัฒนาด้วย Laravel

## Starting Condition

ก่อนทีม Implementer เริ่มงาน ทีม Developer ได้ดำเนินการแล้ว:

- Deploy Source Code
- Setup Server / Container / Infrastructure
- Configure `.env`
- Configure Database Connection
- Configure Storage / Cache / Queue / Redis / Scheduler ตามที่ระบบต้องใช้
- Application เปิดใช้งานได้
- `/setup` เปิดใช้งานได้
- Database ถูกสร้างแล้ว
- Database ยังไม่มี ERP Tables / ยังไม่ได้ Initialize
- Setup Token / Installation Key ถูกสร้างแล้ว

ดังนั้น Wizard นี้ **ไม่ต้องทำ Server Setup, `.env` Setup หรือ Database Credential Setup**

เป้าหมายคือ Seller / Implementer สามารถ Initialize ERP และเตรียมระบบให้ลูกค้า Go Live ได้ทั้งหมดผ่าน Web UI โดยไม่ต้อง:

- SSH
- Run Artisan
- Run SQL
- เปิด Database Client
- แก้ Seeder
- แก้ Source Code
- แก้ `.env`

หลักการสำคัญ:

**If the system can safely determine a default, seed it automatically.**

Seller / Implementer ต้อง Configure เฉพาะข้อมูลที่เป็น Customer-specific จริง ๆ

---

## 1. Analyze Existing ERP First

ก่อน Implement Feature ให้สำรวจ Existing Project ก่อน โดยตรวจสอบ:

- migrations
- seeders
- models
- routes
- middleware
- authentication
- roles
- permissions
- modules
- company
- branch
- warehouse
- system settings
- document types
- document numbering
- accounting
- inventory
- purchasing
- sales
- POS
- approval workflow
- existing master data

ห้ามสร้าง Seeder, Table, Permission หรือ Configuration ซ้ำโดยไม่จำเป็น

Reuse Existing Logic ก่อนสร้างใหม่

---

## 2. Setup Entry Point

สร้าง Web Setup Wizard เช่น:

`/setup`

เมื่อระบบยังไม่ Initialize ให้พา User เข้า Setup Flow

Normal Flow:

1. Prepare Database
2. Install ERP Defaults
3. Company Setup
4. Select Modules
5. Create Administrator
6. Review Configuration
7. Validate
8. Go Live

UI ต้องเหมาะกับ Seller / Implementer ที่ไม่ใช่ Developer

---

## 3. Pre-Migration Bootstrap

Database อาจไม่มี Table เลย

ดังนั้น `/setup` ก่อน Step Prepare Database ต้องไม่พึ่ง:

- users table
- sessions table
- cache table
- jobs table
- permissions table
- settings table
- installation table

ตรวจสอบ State ด้วยวิธีที่ไม่ต้อง Query Table ที่ยังไม่มี เช่น Schema / Migration Repository / Bootstrap State ตามที่เหมาะสม

Setup Security ก่อน Migration ให้ใช้ Installation Key / Setup Token ที่ Developer เตรียมไว้

---

## 4. Step 1 — Prepare Database

แสดงปุ่ม:

**Prepare Database**

ภายในให้ Run Laravel Migrations อัตโนมัติ

Seller ไม่ต้องเห็นหรือรัน:

```bash
php artisan migrate
```

Normal UI แสดง Progress เชิง Business เช่น:

```text
Preparing ERP Database...

✓ Core System
✓ Security
✓ Organization
✓ Accounting
✓ Inventory
✓ Purchasing
✓ Sales

Database Ready
```

ต้อง:

- ตรวจสอบ Migration Status
- Run เฉพาะ Pending Migration
- Retry ได้
- ปลอดภัยเมื่อ Run ซ้ำ
- Log Technical Error
- ไม่แสดง Raw Stack Trace / SQL Error ใน Normal UI

---

## 5. Step 2 — Install ERP Defaults

หลัง Migration สำเร็จ ให้ติดตั้ง System Default Data

เป้าหมาย:

**ทุกข้อมูลที่เป็น Standard ของ ERP และสามารถ Default ได้อย่างปลอดภัย ต้อง Seed ให้อัตโนมัติ**

ไม่ให้ Seller กด Seeder ทีละตัว

แสดงประมาณ:

```text
Installing ERP Defaults...

✓ System Settings
✓ Modules
✓ Permissions
✓ Roles
✓ Role Permissions
✓ Document Types
✓ Document Status
✓ Document Numbering
✓ Stock Movement Types
✓ Units
✓ Currency
✓ Tax
✓ Payment Terms
✓ Payment Methods
✓ Accounting Defaults
✓ Inventory Defaults

ERP Defaults Ready
```

---

## 6. Default Data Classification

### A. System Defaults — Auto Seed

ข้อมูล Core ที่ระบบกำหนดเอง:

- Permissions
- System Roles / Role Templates
- Role Permission Mapping
- Module Definitions
- System Menus
- Document Types
- Document Status
- Transaction Types
- Stock Movement Types
- Approval Status
- Notification Types
- Unit Types
- Standard Units
- Currency
- Tax Types
- Payment Methods
- Product Types
- Costing Method Definitions

### B. Recommended Defaults — Auto Create แต่แก้ได้

- Document Numbering
- Payment Terms
- Default System Settings
- Head Office
- Main Warehouse
- Main Warehouse Location
- Standard Chart of Accounts Template
- Accounting Mapping
- Inventory Policies
- Fiscal Periods หลังระบุ Fiscal Year

### C. Customer-specific — Implementer Configure / Import

- Company Information
- Additional Branches
- Additional Warehouses
- Users
- Customers
- Vendors
- Products
- Employees
- Bank Accounts
- Opening Stock
- Opening AR
- Opening AP
- Opening Balance
- Customer-specific Approval Workflow

---

## 7. Document Numbering — Auto Seed Entire ERP

เลขที่เอกสารพื้นฐานทั้งระบบต้องมี Default ให้อัตโนมัติ

ตัวอย่าง:

```text
Quotation          QT-{YYYY}{MM}-{#####}
Sales Order        SO-{YYYY}{MM}-{#####}
Delivery Order     DO-{YYYY}{MM}-{#####}
Invoice            INV-{YYYY}{MM}-{#####}
Tax Invoice        TAX-{YYYY}{MM}-{#####}
Receipt            RC-{YYYY}{MM}-{#####}
Credit Note        CN-{YYYY}{MM}-{#####}
Debit Note         DN-{YYYY}{MM}-{#####}

Purchase Request   PR-{YYYY}{MM}-{#####}
Purchase Order     PO-{YYYY}{MM}-{#####}
Goods Receipt      GR-{YYYY}{MM}-{#####}
Purchase Return    PRT-{YYYY}{MM}-{#####}

Goods Issue        GI-{YYYY}{MM}-{#####}
Stock Transfer     TR-{YYYY}{MM}-{#####}
Stock Adjustment   ADJ-{YYYY}{MM}-{#####}
Stock Count        SC-{YYYY}{MM}-{#####}

Journal Voucher    JV-{YYYY}{MM}-{#####}
Payment Voucher    PV-{YYYY}{MM}-{#####}
Receipt Voucher    RV-{YYYY}{MM}-{#####}
AR Invoice         AR-{YYYY}{MM}-{#####}
AP Invoice         AP-{YYYY}{MM}-{#####}
```

ต้องตรวจสอบ Existing Document Types จริงก่อน Finalize รายการ

Document Number Configuration ต้องรองรับอย่างน้อย:

- Prefix
- Format
- Running length
- Current running
- Reset monthly / yearly / never
- Preview
- Active status
- Branch-aware numbering หาก Existing ERP รองรับ

Implementer ไม่ต้อง Configure ใน Standard Installation แต่สามารถแก้ผ่าน Advanced Configuration ภายหลัง

---

## 8. Permissions

Permission เป็น System Definition

ต้อง Seed อัตโนมัติจาก Existing ERP Features

ตัวอย่าง Pattern:

```text
sales.view
sales.create
sales.update
sales.cancel
sales.approve

purchase.view
purchase.create
purchase.update
purchase.cancel
purchase.approve

inventory.view
inventory.receive
inventory.issue
inventory.transfer
inventory.adjust
inventory.approve

accounting.view
accounting.journal.create
accounting.journal.approve
accounting.report.view
accounting.period.close
```

ห้ามให้ Implementer สร้าง Core Permission เอง

---

## 9. Default Roles

สร้าง Role Template ที่เหมาะกับ Existing ERP เช่น:

- Super Admin
- Administrator
- Manager
- Approver
- Accounting Manager
- Accountant
- Sales Manager
- Sales
- Purchasing Manager
- Purchasing
- Warehouse Manager
- Warehouse Staff
- Cashier
- Viewer

ต้องตรวจสอบ Role เดิมของ Project ก่อน

Role สามารถ Customize Permission ภายหลังได้

---

## 10. Stable System Codes

System Default ทุกตัวต้องมี Stable Code

ตัวอย่าง:

```text
DRAFT
PENDING
WAITING_APPROVAL
APPROVED
REJECTED
COMPLETED
CANCELLED

QUOTATION
SALE_ORDER
PURCHASE_ORDER
GOODS_RECEIPT
STOCK_TRANSFER

OPENING
PURCHASE_RECEIVE
SALE_ISSUE
TRANSFER_IN
TRANSFER_OUT
ADJUST_IN
ADJUST_OUT
RETURN_IN
RETURN_OUT
```

ห้าม Business Logic อ้าง Fixed Database ID เช่น:

```php
$status->id === 3
```

ให้ใช้ Stable Code

---

## 11. Idempotent Seeders

Seeder / Default Installer ทุกตัวต้อง Run ซ้ำได้โดยไม่ Duplicate

ใช้ Pattern ที่เหมาะสม เช่น:

- `updateOrCreate`
- `firstOrCreate`
- `upsert`

อ้างอิง Stable Code / Unique Business Key

ต้องสามารถ Run ซ้ำหลายครั้งโดยข้อมูลยังถูกต้อง

---

## 12. Seeder Dependency

จัด Dependency อัตโนมัติ เช่น:

```text
Permissions
    ↓
Roles
    ↓
Role Permissions

Document Types
    ↓
Document Numbering

Chart of Accounts
    ↓
Accounting Mapping
```

ถ้าบางส่วน Fail:

- ระบุ Step ที่ Fail
- Retry เฉพาะส่วนได้
- ไม่ Duplicate
- ไม่ทิ้งข้อมูลเสียครึ่งกลาง
- ใช้ Transaction ในจุดที่เหมาะสม

---

## 13. Seeder Versioning / System Default Versioning

สร้าง Version สำหรับ Default Data เช่น:

```text
core_permissions       1.0
document_types         1.0
document_numbering     1.0
inventory_defaults     1.0
accounting_defaults    1.0
```

เป้าหมายคือรองรับ Customer เดิมหลัง Upgrade ERP

หาก Version ใหม่มี Default เพิ่ม ให้ Configuration Center แสดง:

```text
System Defaults Update Available

+ 5 Permissions
+ 1 Document Type
+ 2 Stock Movement Types

[ Apply System Update ]
```

ไม่ควรต้องให้ Developer SSH เข้าไป Run Seeder

---

## 14. Company Setup

Implementer กรอกเฉพาะข้อมูล Customer เช่น:

- Company Code
- Company Name Thai
- Company Name English
- Tax ID
- Address
- Province
- District
- Subdistrict
- Postal Code
- Phone
- Email
- Website
- Logo
- Currency
- Timezone
- VAT Registration

ใช้ Validation ที่เหมาะสม

---

## 15. Auto-create Head Office

หลัง Company ถูกสร้าง ให้สร้าง Head Office อัตโนมัติ

Default เช่น:

```text
Branch Code: 00000
Branch Name: Head Office
Branch Type: HEAD_OFFICE
```

หาก Existing ERP ใช้ Convention อื่น ให้ใช้ Existing Convention

Implementer ค่อยเพิ่มสาขาอื่นภายหลัง

---

## 16. Auto-create Main Warehouse

หาก Module ที่เกี่ยวข้องกับ Stock ถูกเปิด ให้สร้าง:

```text
Warehouse Code: WH001
Warehouse Name: Main Warehouse
Location: MAIN
```

หรือ Existing Convention ของ ERP

Implementer ไม่ต้องสร้างคลังแรกเองใน Standard Setup

---

## 17. Module Selection

ให้ Implementer เลือก Module ที่ Customer ซื้อ / ใช้งาน

ตัวอย่าง:

```text
[x] Accounting
[x] Sales
[x] Purchasing
[x] Inventory
[ ] POS
[ ] Manufacturing
[ ] Logistics
[ ] HRM
[ ] CRM
```

ต้องใช้ Module Definition จริงจาก Existing Project

Module Activation ต้อง Database-driven / Configuration-driven

ห้ามแก้ Source Code ต่อ Customer

เมื่อ Module Disabled:

- Menu ที่เกี่ยวข้องไม่แสดง
- Feature ถูก Disable
- Role Configuration ซ่อน Permission ที่ไม่เกี่ยวข้องตามความเหมาะสม

---

## 18. Module-aware Defaults

เมื่อ Enable Module ให้ตรวจสอบและติดตั้ง Default ที่เกี่ยวข้อง

Sales:

- Sales Permissions
- Sales Document Types
- Sales Numbering
- Sales Status

Purchasing:

- Purchasing Permissions
- PR / PO / GR / Return Types
- Purchasing Numbering

Inventory:

- Inventory Permissions
- Stock Movement Types
- Adjustment Reasons
- Stock Document Numbering

Accounting:

- Accounting Permissions
- Journal Types
- Tax
- Accounting Document Types
- Accounting Numbering
- COA Template

หากข้อมูลมีอยู่แล้ว ห้าม Duplicate

---

## 19. Standard Units

Seed Unit พื้นฐานที่เหมาะสม เช่น:

```text
PCS     ชิ้น
BOX     กล่อง
PACK    แพ็ค
KG      กิโลกรัม
G       กรัม
TON     ตัน
M       เมตร
CM      เซนติเมตร
MM      มิลลิเมตร
M2      ตารางเมตร
M3      ลูกบาศก์เมตร
L       ลิตร
SET     ชุด
ROLL    ม้วน
SHEET   แผ่น
BAG     ถุง
PALLET  พาเลท
```

Customer สามารถเพิ่มภายหลัง

ตรวจสอบ Existing Unit Master ก่อน Seed

---

## 20. Currency / Thailand Defaults

หาก Company อยู่ประเทศไทย ให้ Default:

```text
Currency = THB
Timezone = Asia/Bangkok
Language = th
```

Currency Master อาจ Seed:

- THB
- USD
- EUR
- JPY
- CNY

ตาม Existing ERP Requirements

---

## 21. Tax Defaults

สำหรับประเทศไทย เตรียม Master Data ที่ระบบรองรับ เช่น:

```text
VAT 7%
VAT 0%
VAT Exempt
Non VAT
```

Withholding Tax หาก ERP รองรับ:

```text
WHT 1%
WHT 2%
WHT 3%
WHT 5%
```

Tax Account Mapping ต้องสัมพันธ์กับ Accounting Configuration จริง

อย่า Hard-code Accounting Mapping ที่ไม่ตรง Existing COA

---

## 22. Payment Terms

Default Master เช่น:

```text
Cash
COD
7 Days
15 Days
30 Days
45 Days
60 Days
90 Days
```

กำหนด Default ตาม Existing Business Rule

---

## 23. Payment Methods

Seed Master ที่ Existing ERP รองรับ เช่น:

- Cash
- Bank Transfer
- Cheque
- Credit Card
- QR Payment
- Credit

---

## 24. Accounting Setup

ถ้าเปิด Accounting ให้ Standard Setup ง่ายที่สุด

Default Option:

**Use Standard Accounting Setup**

พิจารณาสร้าง:

- Standard Chart of Accounts
- Tax Masters
- Journal Types
- Default Account Mapping
- Fiscal Year
- Accounting Periods

Implementer ระบุเฉพาะข้อมูลที่ Default ไม่ได้ เช่น Fiscal Year Start

จากนั้น Generate Period อัตโนมัติ

ตัวอย่าง:

```text
01/2026
02/2026
...
12/2026
```

ห้ามให้ Implementer สร้างทีละเดือน

หาก Customer ใช้ Custom COA ให้มี Advanced Option สำหรับ Import / Configure

---

## 25. Inventory Defaults

ถ้าเปิด Inventory ให้เตรียม Default เช่น:

- Product Types
- Stock Movement Types
- Adjustment Reasons
- Main Warehouse
- Main Location
- Stock Control
- Negative Stock Policy
- Costing Method

ใช้ Existing ERP Business Rule เป็นหลัก

อย่าเปลี่ยน Costing Method หรือ Accounting Behavior โดยเดาเอง

---

## 26. Approval Defaults

ระบบต้องสามารถ Go Live ได้โดยไม่บังคับให้ทุก Customer สร้าง Approval Workflow

หาก Existing Business Rule อนุญาต ให้ Default เป็น:

```text
Approval Required = false
```

หรือ Standard No-Approval Workflow

Customer-specific Approval ค่อย Configure ภายหลัง

---

## 27. Create First Administrator

สร้าง Administrator คนแรกผ่าน Setup

Fields ตาม Existing User Model เช่น:

- Name
- Username
- Email
- Password
- Confirm Password

Assign Super Admin / Administrator Role อัตโนมัติ

ต้องใช้ Existing Password Policy

---

## 28. Review Configuration

ก่อน Go Live ให้มีหน้า Summary เช่น:

```text
Database
✓ Ready

System Defaults
✓ Installed

Permissions
✓ 220 Installed

Roles
✓ 14 Installed

Document Types
✓ 27 Ready

Document Numbering
✓ 27 Ready

Company
✓ Configured

Head Office
✓ Created

Main Warehouse
✓ Created

Modules
✓ 4 Enabled

Administrator
✓ Created
```

จำนวนต้องคำนวณจากข้อมูลจริง ไม่ Hard-code

---

## 29. Checklist Engine

สร้าง Checklist สำหรับ Initial Setup

แต่ละรายการมีประเภท:

- Required
- Recommended
- Optional

ตัวอย่าง:

```text
Database Initialized        Required      ✓
System Defaults             Required      ✓
Company                     Required      ✓
Administrator               Required      ✓
Document Numbering          Required      ✓
Main Warehouse              Required      ✓
Opening Stock               Recommended   -
Approval Workflow           Optional      -
```

Go Live ต้อง Block เฉพาะ Critical / Required ที่จำเป็นจริง

---

## 30. Validation Engine

ก่อน Go Live ตรวจสอบอัตโนมัติ เช่น:

### Database

- All required migrations complete

### System

- Required defaults installed
- Module configuration valid

### Security

- Administrator exists
- Roles ready
- Permissions ready

### Documents

- Required document types exist
- Required document numbering exists

### Organization

- Company exists
- Head Office exists

### Inventory

- Main Warehouse exists เมื่อ Stock Module เปิด

### Accounting

- Required COA / Mapping / Fiscal Configuration พร้อมเมื่อ Accounting เปิด

หาก Fail:

```text
Cannot Go Live

1 required configuration is missing.

Default AR Account is not configured.

[ Fix Configuration ]
```

ปุ่ม Fix ต้องพาไป Configuration ที่เกี่ยวข้อง

---

## 31. Go Live

เมื่อ Validation ผ่าน ให้มีปุ่ม:

**Go Live**

เมื่อ Confirm:

- Set Installation Status = completed
- Set System Status = LIVE
- Save `go_live_at`
- Save `go_live_by`
- Lock Initial Installation Actions ที่ไม่ควร Run ซ้ำ
- Disable Initial Setup Token / Initial Setup Access ตาม Architecture
- Redirect ไป Login / Dashboard

---

## 32. Configuration Center After Go Live

หลัง Go Live ห้ามบังคับให้ Run Installer ใหม่หากต้องเปลี่ยน Configuration

ให้มี Configuration Center สำหรับ:

- Company
- Branch
- Warehouse
- Modules
- Users
- Roles
- Permission Mapping
- Document Numbering
- Tax
- Accounting Mapping
- Inventory Settings
- Approval Workflow
- System Default Updates

เป้าหมายคือ Customer Configuration ไม่ต้องแก้ Code

---

## 33. Installation State

ออกแบบ State ที่ชัดเจน เช่น:

```text
NOT_INITIALIZED
DATABASE_READY
DEFAULTS_INSTALLED
COMPANY_CONFIGURED
READY_TO_GO_LIVE
LIVE
```

ก่อน Migration ต้องตรวจ State โดยไม่พึ่ง Installation Table

หลัง Migration สามารถย้ายไปใช้ Database-backed State ได้

---

## 34. Setup Progress

UI แสดง Progress และ Status:

- Not Started
- In Progress
- Completed
- Warning
- Failed

ต้อง Resume ได้หลัง Refresh / ปิด Browser เมื่อ Database พร้อมแล้ว

---

## 35. Audit Log

หลัง Migration พร้อมแล้ว ให้บันทึก Critical Setup Actions เช่น:

- Initialize Database
- Install Defaults
- Create Company
- Enable Module
- Create Administrator
- Change Document Number
- Go Live

เก็บตาม Existing Audit Architecture หรือสร้างเท่าที่จำเป็น:

- datetime
- user / installer identity
- action
- old value
- new value
- status
- IP
- metadata

---

## 36. Error Handling

Normal UI ห้ามแสดง:

- Raw SQLSTATE
- Stack Trace
- Class Names ที่ไม่จำเป็น
- Migration Filename ที่ไม่จำเป็น

ตัวอย่าง:

```text
Unable to prepare ERP database.

The database setup could not be completed.

[ Retry ]
```

Technical Error ต้อง Log สำหรับ Developer

Advanced Technical Detail อาจเปิดเฉพาะ Authorized Technical User

---

## 37. Dangerous Operations

หลังมี Transaction Data แล้ว ห้ามมีปุ่ม Reset / Truncate / Drop Database แบบง่าย

Operation อันตรายต้อง:

- จำกัดสิทธิ์
- Confirmation
- Typed Confirmation หากเหมาะสม
- Audit Log
- Block หลัง Go Live หากไม่ปลอดภัย

---

## 38. UX Principle

Normal UI หลีกเลี่ยง Technical Terms

แทน:

```text
Migration → Prepare Database
Seeder → Install Default Data
Artisan → ไม่ต้องแสดง
Schema → ไม่ต้องแสดง
```

Implementer ควรเห็นเพียง:

```text
ERP Initial Setup

1. Prepare Database
2. Install Default Data
3. Company
4. Modules
5. Administrator
6. Review
7. Validate
8. Go Live
```

---

## 39. Suggested Architecture

Analyze Existing Architecture ก่อน แล้วพิจารณา:

```text
app/
  Setup/
    Actions/
    Services/
    Seeders/
    Validators/
    Steps/
    DTO/
    Enums/
```

ตัวอย่าง:

- SetupService
- DatabaseInitializationService
- SystemDefaultService
- SeederManager
- SeederVersionService
- CompanySetupService
- ModuleSetupService
- SetupValidationService
- GoLiveService

Controller ต้องบาง

อย่ารวม Business Logic ทั้งหมดไว้ใน Controller

---

## 40. Tests

ต้องมี Automated Tests อย่างน้อย:

- Fresh zero-table database
- Setup page before migration
- Invalid setup token
- Database initialization
- Migration failure
- Migration retry
- Install defaults
- Run defaults multiple times
- Seeder dependency
- Seeder version update
- Role / Permission defaults
- Document numbering defaults
- Company creation
- Auto-create Head Office
- Auto-create Main Warehouse
- Module activation
- Administrator creation
- Validation failure
- Successful Go Live
- Setup locked after Go Live

Critical Test:

**เริ่มจาก Database ที่ไม่มี Table เลย และต้องสามารถเปิด `/setup` → Prepare Database → Install Defaults → Configure → Go Live ได้โดยไม่ใช้ Manual Command**

---

## 41. Implementation Order

อย่าเขียนทุกอย่างพร้อมกันโดยไม่สำรวจระบบ

ดำเนินการตามลำดับ:

1. Analyze Existing Project
2. Report Existing Architecture / Reusable Components
3. Identify missing pieces
4. Design Setup State / Bootstrap
5. Implement Pre-Migration `/setup`
6. Implement Database Initialization
7. Integrate Existing Seeders / Defaults
8. Make Defaults idempotent
9. Implement Company / Module / Admin Setup
10. Implement Checklist / Validation
11. Implement Go Live
12. Implement Configuration Center integration
13. Add Tests
14. Document behavior

ห้าม Rewrite Existing ERP Architecture โดยไม่จำเป็น

---

## 42. Definition of Done

Flow ต้องเป็น:

```text
Developer Handover
        ↓
Application Running
        ↓
Empty Database Connected
        ↓
/setup
        ↓
Prepare Database
        ↓
Install ERP Defaults
        ↓
Company Setup
        ↓
Auto-create Head Office
        ↓
Select Modules
        ↓
Auto-create Required Defaults
        ↓
Create Administrator
        ↓
Review
        ↓
Validate
        ↓
GO LIVE
```

ตลอด Flow Implementer ต้องไม่:

- SSH
- Run Artisan
- Run SQL
- เปิด Database Client
- แก้ Source Code
- แก้ `.env`
- แก้ Seeder

ข้อมูลพื้นฐานที่ ERP สามารถกำหนด Standard ได้ เช่น Permission, Role Templates, Document Types, Document Status, Document Numbering, Stock Movement, Units, Currency, Tax, Payment Terms, Payment Methods และ Module Defaults ต้องถูกติดตั้งให้อัตโนมัติ

เป้าหมายสุดท้าย:

**Developer prepares the environment.  
Implementer initializes the ERP.  
The ERP seeds everything that can be standardized.  
Customer-specific configuration is done through UI.  
No customer installation should require source-code modification.**
