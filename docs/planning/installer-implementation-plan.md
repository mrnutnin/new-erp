# ERP Installer & Implementation Wizard — Implementation Plan

สถานะ: Discovery complete, implementation not started

## Constraints

- ใช้ existing Laravel module/provider/RBAC architecture
- Developer handover เป็น empty database; ห้าม manual `migrate`, `db:seed`, SQL หรือแก้ Seeder เพื่อ customer
- `/setup` ต้องทำงานก่อน migration โดยไม่พึ่ง `users`, `sessions`, `cache`, `jobs`, settings หรือ permission tables
- ทุก action ต้อง retry-safe, idempotent, transaction-safe และมี technical log แยกจากข้อความสำหรับ Seller
- ใช้ stable code ไม่อ้าง fixed database ID

## Current Architecture Evidence

- Laravel 12.67 / PHP 8.2; routes ถูกโหลดจาก Module Service Providers ใน `bootstrap/providers.php`
- Platform มี authentication, program/branch/warehouse context, capability และ audit logger
- Settings มี Company, Branch, Warehouse, User, Role และ Workflow controllers/routes
- RBAC อยู่ใน `users`, `roles`, `permissions` และ pivot tables; route guard ใช้ `auth`, `program`, `permission`
- `Program` และ module capability มีอยู่แล้ว แต่ module enablement ปัจจุบันถูกจัดการผ่าน seeded program records
- `CompanySetting` และ `SettingRegistry` มีอยู่แล้วสำหรับ company/global settings
- `DocumentSequence` มีอยู่แล้ว แต่ default sequences กระจายอยู่ใน `DatabaseSeeder` และ module seeders
- `DatabaseSeeder` ปัจจุบันทำทั้ง admin, RBAC, branch, warehouse, programs, document sequences, account types และ company defaults จึงต้องแยก orchestration ไม่ให้เป็น installer state
- ยังไม่มี installer route/UI/service/state/checklist/log/version/go-live lock
- `.env.example` ใช้ database session/cache/queue จึงต้องมี pre-install fallback

## Phase 0 — Contract and Documentation

- [x] อ่าน `docs/promt-install-dev.md` ครบ 514 บรรทัด
- [x] สำรวจ composer, env, bootstrap/providers, routes, migrations, seeders, models, services และ deployment files
- [x] สร้าง `INSTALLATION.md` สำหรับ Developer/DevOps
- [x] สร้างแผนนี้และบันทึกข้อจำกัด empty database handover
- [ ] ปรับ Composer setup scripts ไม่ให้ migrate อัตโนมัติ

## Phase 1 — Pre-install Runtime and Secure Entry

- [x] เพิ่ม `ERP_SETUP_ENABLED` และ token validation จาก environment/config
- [x] เพิ่ม pre-install entry point ที่ไม่ผ่าน auth/session middleware
- [x] เพิ่ม standalone `/setup` dashboard และ System Check เบื้องต้น
- [x] เพิ่ม checks: PHP/Laravel/database/extensions/storage/cache/URL/HTTPS/timezone/Redis/mail/disk
- [x] แยก critical failure กับ warning ใน UI (ยังไม่เปิด action ที่เปลี่ยนระบบ)
- [ ] ใช้ file session/cache หรือ stateless signed setup session ก่อน tables พร้อม
- [ ] เพิ่ม tests สำหรับ zero-table `/setup` และ token security

## Phase 2 — Installer State and Database Preparation

- [x] สร้าง installation state contract และ persistent tables ที่ migrate ได้เองโดย installer bootstrap
- [x] สร้าง `InstallationStep`, `InstallationChecklist`, `InstallationLog`, `SystemSeedVersion` ตาม schema ที่ compatible กับ existing conventions
- [x] เพิ่ม file-state fallback, progress/status/retry และ technical detail ที่ไม่แสดงต่อ Seller
- [x] เพิ่ม database Test Connection ใน System Check
- [x] เพิ่ม Prepare Database ซึ่งเรียก migration ผ่าน application action และตรวจ migration result/idempotency
- [ ] ไม่เปิด ERP routes ที่ต้อง auth จน users/RBAC พร้อม

## Phase 3 — System Defaults Orchestrator

- [x] เริ่มแยก default orchestration ออกจาก `DatabaseSeeder` โดยไม่เรียก customer/mock data
- [ ] เพิ่ม `InstallerStep` interface และ step registry
- [x] เพิ่ม `SystemDefaultOrchestrator`, file state และ `SystemSeedVersion` tracking
- [x] ทำ seed core defaults ด้วย stable code/upsert ผ่าน existing `RbacSeeder`/`JournalBookSeeder`
- [x] ทำ Program และ Role Templates แบบ idempotent; module-specific defaults จะต่อใน step registry
- [ ] ตัด default password/admin/company/customer data ที่ hard-coded ออกจาก normal deployment path
- [ ] แสดง advanced technical detail แต่ใช้ภาษาธุรกิจใน default UI
- [ ] ทดสอบ seed ซ้ำ 1/10/100 ครั้งไม่ duplicate กับฐานข้อมูลจริง

## Phase 4 — Customer Configuration

- [x] Company information ผ่าน Setup UI พร้อมค่าเริ่มต้นสำหรับประเทศไทย
- [x] Default Head Office branch `00000` และ Main Warehouse `WH001` แบบ idempotent
- [x] Module selection จาก database-driven program/capability
- [x] Administrator creation พร้อม password policy ขั้นต้นและการผูก role/context
- [x] Recommended accounting defaults: Thai standard COA v1.1, ครบทุก PostingEvent role, core account mappings และ stable codes เมื่อเปิด Accounting
- [ ] inventory policy/defaults เมื่อเปิด WMS
- [x] document numbering: seed template ครบตาม Module ที่เปิดใช้งานแบบ idempotent และ reuse `DocumentSequence` สำหรับการแก้ไขผ่าน UI

## Phase 5 — Review, Import and Validation

- [x] Review default setup summary ในหน้า Setup
- [ ] Import wizard: upload → validate → preview → confirm → import
- [x] Initial Stock path เชื่อมกับ WMS Opening Balance import เดิม: upload/validate/confirm และสร้างเอกสารร่างก่อน Post
- [x] Customer/Supplier CSV path: upload/validate/confirm และ upsert Party + Role แบบ transaction
- [x] Product/Item CSV path: validate Category/UOM/GL references และ upsert Item แบบ transaction
- [x] Employee CSV path: create/update User พร้อม Viewer role และไม่เก็บ password plaintext ใน staging
- [x] Opening AR/AP CSV path: validate Party/Account/Warehouse และสร้าง Journal + Open Item ผ่าน Accounting contract ใน transaction เดียว
- [ ] Customer-specific data: customers, vendors, products, employees, opening stock/AR/AP/balance
- [x] error report ดาวน์โหลดได้ ระบุ row/field และไม่แสดง raw exception
- [x] validation engine แสดงผลผ่าน/ต้องแก้ไขแบบ business-friendly
- [x] validation ตรวจ Document Numbering, Accounting Defaults และ Opening Stock เพิ่มเติม
- [x] Fix Configuration links ไปยังหน้าตั้งค่าจริงของแต่ละ Module
- [x] Required/Recommended/Optional checklist ถูกบันทึกใน installation session

## Phase 6 — Go Live and Configuration Center

- [x] Go Live summary and confirmation
- [x] record user/date/status
- [x] lock installer mutation after go-live
- [x] `/setup` เปลี่ยนเป็น Configuration Center สำหรับผู้มีสิทธิ์
- [x] dangerous reset operation (version markers only) Super Admin only, typed confirmation, audit
- [x] system default update/version notification and apply action

Migration order contract:

- [x] Module/schema migrations run first
- [x] Installer state migrations run from `database/migrations/installer`
- [x] Customer-specific defaults run only after migration completion

## Definition of Done

Seller/Implementer สามารถทำ `Check System → Prepare Database → Initialize Defaults → Company → Modules → Administrator → Review → Import → Validate → Go Live` ผ่าน browser โดย Developer ไม่ต้อง seed ERP data, run SQL, run Artisan หรือแก้ source code/customer `.env` ระหว่าง handover

## Test Matrix

- [ ] fresh empty database
- [ ] resume after browser close
- [ ] retry failed step
- [ ] token invalid/expired/disabled
- [ ] defaults run repeatedly without duplicates
- [ ] module-aware defaults
- [ ] company/branch/warehouse/admin setup
- [ ] import validation and rollback
- [ ] incomplete checklist blocks Go Live
- [ ] validation failure with actionable fix
- [ ] successful Go Live locks setup
