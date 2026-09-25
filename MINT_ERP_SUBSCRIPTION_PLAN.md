# MintERP Subscription — แผนระบบและลำดับงาน

สถานะ: **ร่างเพื่อยืนยันขอบเขต** · เพิ่มข้อเสนอทดลองใช้ฟรี 1 เดือนแล้ว; ยังไม่มีการเปลี่ยนโค้ดหรือ migration สำหรับ Subscription
รายการลงมือทำ: [MINT_ERP_SUBSCRIPTION_CHECKLIST.md](MINT_ERP_SUBSCRIPTION_CHECKLIST.md)

## เป้าหมาย

เริ่มจาก Subscription entitlement ของ MintERP แยกต่อ instance/ฐานข้อมูล แล้วจึงสร้าง Portal/API สำหรับทีมงานที่ `ops.alexiasoft.co` โดยออกแบบข้อมูลกลางให้รองรับหลายผลิตภัณฑ์ เช่น MintERP, MintPOS และ MintHRM โดยไม่ย้าย business rules ของแต่ละผลิตภัณฑ์มารวมกัน

รอบแรกไม่ทำ payment gateway, customer self-service portal, หรือ multi-tenant database เดียว

## ข้อตกลงและสมมติฐาน

- เจ้าของยืนยันแนวทางเริ่มจากตาราง Subscription/entitlement ในแต่ละ instance แล้วค่อยทำ Portal และ API
- Portal เป็นโปรเจ็คใหม่สำหรับทีมงานที่ `ops.alexiasoft.co` ไม่ใช่การเพิ่มหน้าจัดการลงเว็บการตลาด; ระยะ Portal ต้องรองรับหลาย product ไม่ยึดชื่อ/โมเดลเฉพาะ MintERP
- หนึ่งบริษัทลูกค้าใช้ ERP instance และฐานข้อมูลของตัวเอง; แชร์เครื่อง HostAtom ได้ แต่ห้ามถือว่าการแชร์ VPS คือการแชร์ข้อมูล/ฐานข้อมูล
- แพ็กเกจ/ราคาในเว็บไซต์ยังเป็นข้อเสนอทางการค้า แผนนี้ไม่กำหนดราคา ต้นทุน หรืออัตราค่าพัฒนา
- ความจุ VPS ต้องวัดจากโหลดจริง ห้ามกำหนดจำนวนลูกค้าสูงสุดจากสเปกเครื่องอย่างเดียว

## สถานะปัจจุบันที่มีผลต่อแบบ

- `CompanySetting` และ `GlobalSettings::current()` ใช้ `CompanySetting` แถว `id=1` เป็นค่าตั้งค่าบริษัทระดับ instance; แบบปัจจุบันจึงสอดคล้องกับหนึ่งบริษัทต่อฐานข้อมูล
- ยังไม่พบตาราง Subscription, package entitlement หรือการบังคับ quota ผู้ใช้/สาขา/คลังในระบบ
- `ModuleCapability` ปัจจุบัน gate Production และ Asset; ค่า Asset ยังมาจาก Company Setting ส่วน CRM ยังไม่มี package gate ที่พบ ต้องเพิ่มการตรวจสิทธิ์แพ็กเกจทั้งฝั่ง UI และ server
- จุดจัดการข้อมูลที่เกี่ยวข้องอยู่ใน `Settings` ได้แก่ `UserController`, `BranchController`, `WarehouseController`; installer ตรวจ schema ที่ `DatabasePreparationService::assertRequiredSchemaReady()`
- Production ต้องคงกติกาปัจจุบันด้วย: ใช้ได้เมื่อแพ็กเกจมี Production add-on **และ** บริษัทตั้ง Manufacturing/เปิด Production แล้ว

## แยก Subscription ออกจาก License

- เพิ่ม `SubscriptionEntitlementService` ขนาดเล็ก แยกจาก permission/RBAC และ business rules; wire เฉพาะจุดสร้าง/เปิดใช้ User/Branch/Warehouse และ package module gate ไม่ refactor flow อื่น
- เก็บ `commercial_mode` ในแถว entitlement ต่อ instance: `UNASSIGNED`, `SUBSCRIPTION`, `LICENSE`, `INTERNAL`; ไม่ใช้ `.env` เป็นตัวตัดสิน commercial mode
- Migration จัด instance ที่ตั้งบริษัทไว้แล้ว (`company_settings.id=1`) เป็น `LICENSE` เพื่อคงพฤติกรรมเดิม; fresh install ที่ยังไม่มี company setting เป็น `UNASSIGNED` จนทีมงานกำหนด mode ผ่าน CLI/Portal
- `UNASSIGNED` ปิด business access จน provision; `SUBSCRIPTION` ต้องมี entitlement ที่ active จึงใช้ package/quota gates; `LICENSE`/`INTERNAL` ข้าม Subscription gates และคง `ModuleCapability`, permission และ flow ปัจจุบัน
- `INTERNAL` ใช้ได้เฉพาะ local/test/UAT ที่แยกจากลูกค้า; production ต้องปฏิเสธ mode นี้ แม้ถูกตั้งผ่าน Artisan/API
- รอบนี้ไม่สร้าง/เปลี่ยนระบบตรวจ License ทางเทคนิค; `LICENSE` หมายถึงไม่ใช้ Subscription quota เท่านั้น
- mode และ plan เปลี่ยนได้เฉพาะ CLI/Portal ฝั่งผู้ให้บริการ; ห้ามลูกค้าแก้ผ่าน Settings หรือ request payload

## ขอบเขตแพ็กเกจทางเทคนิค (อิงข้อเสนอปัจจุบัน)

| แพ็กเกจ | บริษัท | สาขา | คลังรวม | บัญชีผู้ใช้ | โมดูล |
|---|---:|---:|---:|---:|---|
| Core | 1 | 1 | 2 | 5 | POS, WMS, จัดซื้อ, Finance, Accounting, Dashboard |
| Business | 1 | สูงสุด 3 | 6 | 15 | Core + CRM + Asset |
| Production add-on | เพิ่มใน Core/Business ตามความเหมาะสม | — | — | — | BOM, วางแผน และติดตามงานผลิต; ยังคงต้องเปิด Manufacturing ในบริษัท |

จำนวนผู้ใช้/สาขา/คลังที่ซื้อเพิ่มให้บวกกับ quota พื้นฐานตามจำนวน add-on จริง ไม่เก็บเป็นค่าที่ลูกค้าแก้เองได้

### ทดลองใช้ฟรี 1 เดือน (ข้อเสนอใหม่)

- ไม่สร้าง quota plan `TRIAL` ซ้ำกับ Core/Business; เก็บ Trial เป็นสถานะ/ช่วงเวลาของ Business plan เพื่อใช้ quota และ module matrix เดิม
- Trial ใช้ Business เต็มตาม quota ปกติ: สูงสุด 3 สาขา, 6 คลัง, 15 users พร้อม CRM/Asset; ไม่มี quota แบบไม่จำกัด
- Production ไม่รวมอัตโนมัติ; บริษัทที่ทำ Manufacturing และผ่านการคุยความพร้อมขอทดลอง Production add-on ได้ โดยต้องเปิด Manufacturing/ตั้งค่า BOM ตามกติกาเดิม
- เริ่มนับเมื่อ instance พร้อมและมอบสิทธิ์เข้าใช้ให้ลูกค้า ไม่ใช่ตอนสร้างฐานข้อมูล; ใช้หนึ่งเดือนปฏิทินตาม `Asia/Bangkok` เป็นข้อเสนอ รอยืนยันก่อน implement
- ไม่ต่อเป็นแพ็กเกจเสียเงินหรือเรียกเก็บเงินอัตโนมัติเมื่อหมด Trial; การเปลี่ยนเป็น paid ต้องให้ทีมงานยืนยันและเก็บประวัติ
- ก่อนมี Portal ให้ลูกค้าขอทดลองผ่านทีมขายและให้ทีมงาน provision ด้วยมือ; Production add-on Trial ต้องผ่านการคัดกรองความพร้อม; ป้องกัน Trial ซ้ำต่อบริษัทและผลิตภัณฑ์ด้วยทะเบียนภายใน และเก็บประวัติแยก product ใน Portal ภายหลัง
- ก่อนประกาศบน `minterp-site` ต้องยืนยัน quota, โมดูล, วิธีขอทดลอง, วันเริ่ม/หมดอายุ และข้อความหลัง trial
- เมื่อ trial หมดอายุให้คงฐานข้อมูล/ไฟล์และเอกสารทั้งหมดไว้; การปิด write access, read/export, grace period และระยะ retention เป็น owner decision ก่อนเปิดใช้งานจริง

## สถาปัตยกรรมระยะเริ่มต้น

1. **ขอบเขตลูกค้า:** แยก instance, database, `.env`, private file storage และ backup ของแต่ละบริษัท; แชร์เฉพาะ compute เมื่อผ่าน load test
2. **Plan catalog:** นิยาม Core/Business และความสามารถใน config ที่ version ควบคุมโดยทีมพัฒนา ไม่ทำสำเนากติกาในหลาย Controller
3. **ข้อมูล Subscription:** เพิ่มตาราง entitlement แบบหนึ่งรายการต่อ instance เก็บ `commercial_mode`, plan/version, สถานะ (`TRIAL`/`ACTIVE`/สถานะอื่นที่ตกลง), ช่วงมีผล, จำนวน add-on/Production add-on และข้อมูลการเปลี่ยนแปลงที่ตรวจสอบย้อนหลังได้; บังคับ single-row invariant ใน schema และล็อกแถวนั้นตอนเปลี่ยน quota; Trial ใช้ Business quota ไม่สร้าง quota ซ้ำ
4. **การตั้งค่าในระยะไม่มี Portal:** ทีมงานใช้ Artisan command ที่จำกัดสิทธิ์เพื่อกำหนด/เปลี่ยน mode และ entitlement ต่อ instance; `.env` เก็บเฉพาะ infrastructure/secrets
5. **การบังคับ quota:** ตรวจฝั่ง server ก่อนสร้างหรือเปิดใช้ User/Branch/Warehouse เฉพาะ mode `subscription`; guard เป็น no-op สำหรับ `license`/`internal` และตรวจ quota ในธุรกรรมเดียวกับการบันทึก
6. **จำนวนที่นับ:** ข้อเสนอคือคิดเฉพาะรายการที่ active และไม่ถูก soft-delete; บัญชีผู้ดูแลลูกค้านับรวมในจำนวนผู้ใช้ ต้องยืนยันก่อน implement
7. **การเกิน quota/ลดแพ็กเกจ:** ห้ามลบข้อมูลหรือเอกสารอัตโนมัติ; แจ้งยอดเกินและห้ามเพิ่ม/เปิดใช้รายการใหม่จนกว่าจะลดจำนวนหรือซื้อ add-on; วิธีให้เข้าถึงโมดูลเดิมหลัง downgrade และ grace period ต้องตกลงก่อนเปิดใช้จริง
8. **Module gate:** วาง package entitlement เป็นชั้นเสริมจาก `ModuleCapability` เฉพาะ mode `subscription` และตรวจใน program selector/sidebar/middleware/route; การซ่อนเมนูอย่างเดียวไม่ถือเป็นการบังคับสิทธิ์; การปิด Asset/Production/CRM ต้องไม่ลบข้อมูลเดิม
9. **สถานะขาดหาย:** `UNASSIGNED` ต้องไม่ fallback เป็น `LICENSE` หรือ unlimited; local/test/UAT ต้องระบุ `INTERNAL` แยกจาก plan ลูกค้าอย่างชัดเจน

## ออกแบบ Portal ให้เพิ่มผลิตภัณฑ์ได้

- แยกแนวคิด **Customer** (ผู้ซื้อ/นิติบุคคล), **Product** (`MINTERP`, `MINTPOS`, `MINTHRM`), **Instance** (deployment ของ product หนึ่งตัว) และ **Subscription/Contract**; Customer หนึ่งรายมีหลาย product และหลาย instance ได้
- Product คือผลิตภัณฑ์ที่ deploy/ขายแยกกัน; ห้ามสับสน `POS` module ภายใน MintERP กับ product code `MINTPOS`
- ระบุ `product_code` และ API/entitlement schema version ในทะเบียน instance และทุกการ sync; ตรวจว่า product ของ payload ตรงกับ product ที่ลงทะเบียนไว้
- ข้อมูลกลางใช้ field ทั่วไป เช่น customer, product, instance, plan/version, status, period, revision และ audit; อย่าใส่ quota เฉพาะ ERP เช่น branches/warehouses ไว้เป็น column กลางสำหรับทุก product
- ให้ package/entitlement schema เฉพาะ product อยู่ใน product adapter/versioned payload; `new-erp` เริ่มด้วย adapter `MINTERP` และยังคงเป็นผู้ตรวจ quota ใน instance เอง
- ก่อนกำหนด Portal schema ต้องตัดสินใจว่า subscription/contract ต่อ product ครอบคลุมหลาย instance ได้ไหม; local entitlement ยังต้องอยู่ในแต่ละ instance
- สิทธิ์ทดลองใช้ฟรีและการป้องกัน trial ซ้ำต้องแยกตาม Customer + Product ไม่ใช้สถานะ trial ของ MintERP ไปบล็อก MintPOS/MintHRM
- ยังไม่ต้องสร้าง plugin framework ล่วงหน้า: ทำ common registry/API contract ให้ product-neutral และเพิ่ม adapter เมื่อเริ่มเชื่อม MintPOS/MintHRM จริง

## Migration และ Installer ที่ต้องคุม

- เพิ่ม migration แยกสำหรับ entitlement พร้อม `up()`/`down()`; เพิ่มตารางและคอลัมน์ที่ต้องมีใน `DatabasePreparationService::requiredSchema()` และ contract test
- Migration backfill instance เดิมที่มี `company_settings.id=1` เป็น `LICENSE` แบบ no-subscription; fresh DB ที่ยังไม่มี company setting เป็น `UNASSIGNED`; ทดสอบทั้งสองทางและ down migration
- `new_erp` ที่เป็น local/UAT ปัจจุบันจะถูก backfill เป็น `LICENSE` หากมี company setting; เปลี่ยนเป็น `INTERNAL` อย่างชัดเจนก่อน UAT Subscription
- สำรองฐานข้อมูลทุก instance ก่อน migration; หลัง provision Subscription จริงแล้วห้าม rollback แบบ drop entitlement history ให้ใช้ forward-fix; `down()` ใช้ก่อน provision หรือหลัง restore backup ที่ยืนยันแล้วเท่านั้น
- Fresh install ห้ามแจก paid plan หรือ Trial อัตโนมัติ; หลัง Installer ตั้งบริษัท/ผู้ใช้เสร็จยังคง `UNASSIGNED` จนทีมงานเลือก `LICENSE` หรือ provision Subscription ผ่าน CLI/Portal
- `UNASSIGNED` ใน fresh install ปิด business routes แต่ยังให้ทำขั้น Installer/health checks ได้; `LICENSE` ใช้ flow เดิม
- local/test/UAT กำหนด `INTERNAL` อย่างชัดเจน; production ต้องปฏิเสธ `INTERNAL`; ห้าม migration เปลี่ยนเป็น Core/Trial
- Existing License databases ต้องไม่ถูกตั้ง quota/Trial หรือซ่อนโมดูล; สำรวจจำนวน active records ก่อนเปิด Subscription quota และห้ามลบ/แก้ข้อมูลเดิมเพื่อให้ต่ำกว่า quota
- เพิ่ม schema check/Installer UAT test ให้ระบุชัดว่าขาด entitlement schema หรือสถานะ provisioned อย่างไร โดยไม่ทำให้การติดตั้งฐานข้อมูลใหม่ deadlock ก่อนทีมงานตั้ง entitlement
- Installer ห้ามให้ผู้ติดตั้งเลือกรับ Trial/Business/Production เอง; Trial เริ่มเมื่อทีมงานอนุมัติและ provision แล้วเท่านั้น

## ลำดับดำเนินงาน

### Phase 0 — ยืนยันกติกาและสำรวจฐานข้อมูล (เริ่มที่ `new-erp`)

- ยืนยันจำนวนที่คิดเงิน: ผู้ดูแลนับรวมไหม; นับเฉพาะ active หรือไม่; ความหมายของบริษัท/สาขา/คลัง
- ยืนยัน module matrix โดยเฉพาะ CRM, Asset และ Production add-on
- ตกลงหนึ่งเดือนปฏิทิน/เวลาเริ่ม Trial, grace period และการอ่าน/เขียนข้อมูลหลังหมดอายุ; กำหนดช่องทางขอ Production Trial ก่อน Portal
- ตกลงสถานะต่ออายุ/หมดอายุ/ค้างชำระ และวิธี downgrade เมื่อใช้เกิน quota
- สำรวจจำนวน User/Branch/Warehouse และ modules ในทุก instance ก่อนเปิด enforcement; ห้ามตั้ง Core อัตโนมัติให้ฐานข้อมูลเดิม
- คง branch/warehouse permissions, audit, stock/accounting flows และ UAT data เดิม

### Phase 1 — Entitlement ต่อ instance

- เพิ่ม migration ย้อนกลับได้, model/cast และ plan resolver; มี version เพื่อไม่เปลี่ยนสิทธิ์ลูกค้าเดิมโดยเงียบเมื่อแก้ catalog
- เพิ่ม entitlement table ใน `DatabasePreparationService::requiredSchema()` และ contract/unit test ตรวจ schema/installer
- กำหนดวิธี provision local/test/UAT และฐานข้อมูลเดิมแบบไม่ทำให้ผู้ใช้/คลัง/สาขาที่มีอยู่ถูกบล็อกหรือลบ
- เพิ่ม Artisan command สำหรับทีมงานตั้ง plan, add-on, Business Trial 1 เดือน/วันเริ่ม-หมดอายุ, Production Trial ที่อนุมัติ และแปลง Trial เป็น paid พร้อม validation, ป้องกัน trial ซ้ำตามทะเบียน และ audit log; ยังไม่มีหน้าแก้ให้ Customer Admin

### Phase 2 — Quota enforcement

- เพิ่ม guard ใน `SubscriptionEntitlementService` ที่นับ active records/lock entitlement; ทำงานเฉพาะ mode `subscription` และ no-op สำหรับ `license`/`internal`
- ใช้กับสร้าง/แก้/เปิดใช้ User, Branch และ Warehouse; แจ้ง quota/จำนวนคงเหลือเป็นภาษาไทยข้าง action
- หน้า Settings แสดง `ใช้แล้ว/โควตา` และแสดง add-on; ห้ามแก้ quota จากหน้า Settings ของลูกค้า
- รักษาข้อมูลเดิมเมื่อเกิน quota/downgrade และป้องกันการเพิ่มผ่าน API/route โดยตรง

### Phase 3 — สิทธิ์โมดูล

- เพิ่ม package gate แบบแยกชั้นจาก `ModuleCapability`; ทำงานเฉพาะ Subscription และไม่เปลี่ยนผลเดิมของ License; Core/Business และ Production add-on ต้องให้ผลเดียวกันใน program selector, sidebar, route และ API
- เพิ่ม CRM package gate ฝั่ง server; Business เปิด CRM/Asset ตาม plan แต่ยังเคารพ capability/config บริษัทที่มีอยู่
- Production ต้องผ่านทั้ง entitlement, Manufacturing profile และ `production_enabled`; ไม่มีการเพิ่ม permission RBAC ใหม่เพื่อแทน subscription

### Phase 4 — ทดสอบและเตรียม operation

- เขียนเฉพาะ Unit/Contract tests ที่เกี่ยวข้อง: mode migration/backfill, plan resolution, Business Trial limits, Production Trial eligibility, expiry/conversion, quota boundary/activation, module gates, migration/installer schema และ License-mode regression
- ตรวจ PHP lint, Blade compilation และ `git diff --check`; ผู้ใช้เป็นผู้ทำ UAT เอง
- ก่อน migration ทุกครั้งตรวจ connection และ pending migrations บน `new_erp`; รันเฉพาะ migration ของงานนี้ตามขั้นตอนที่ผู้ใช้อนุมัติ ไม่รัน migration อื่นที่ค้าง
- ทดสอบ provision/restore แยกบริษัท, backup นอก VPS, object storage แยก, deploy/upgrade และ migration ทีละ instance; ยืนยัน License instance ที่มีผู้ใช้/สาขา/คลังเกิน Core ยังทำงานเหมือนเดิม
- วัด CPU/RAM/DB/queue/backup ภายใต้โหลดจริงก่อนกำหนดจำนวน instance ต่อ VPS; เตรียมแผนย้าย/เพิ่ม VPS เมื่อถึงขีดจำกัด

### Phase 5 — Portal/API ภายหลัง

- สร้างแอปใหม่สำหรับทีมงานที่ `ops.alexiasoft.co` ใช้ Laravel รุ่นที่ยังได้รับ security support, DB และ secrets แยก, ปิด public registration และบังคับ MFA/RBAC/audit
- Portal เก็บ customer/product/instance registry, product-scoped plan/add-on, Trial eligibility/history ต่อ product, สถานะ contract, วันต่ออายุ, endpoint, API version และ sync status; ไม่เก็บข้อมูลธุรกรรมหรือรหัสผ่านของ product instances
- แต่ละ product instance เปิด API แบบแคบและ versioned สำหรับรับ entitlement เท่านั้น; ยืนยัน product/instance registration, ใช้ credential แยก instance, HTTPS, replay/idempotency protection, audit และ key rotation
- Portal เก็บ private/API credentials ใน secret manager หรือเข้ารหัส at-rest; ห้ามเก็บ plain text ในฐานข้อมูล/log; entitlement ที่ส่งต้องมี signature/revision/expiry ให้ instance ตรวจ local
- Portal ห้ามเชื่อมฐานข้อมูล product instances โดยตรง; แต่ละ product ตรวจ entitlement จากข้อมูล local ที่ลงลายเซ็น/ยืนยันแล้ว ไม่เรียก Portal ทุก request และไม่ lock ลูกค้าเมื่อ Portal ล่ม
- ทดสอบการออก/หมดอายุ/แปลง Trial, ต่ออายุ/เปลี่ยน quota, API outage, credential rotation และ rollback ก่อนเชื่อม production instances

## ยังไม่รวม

- การเปลี่ยน ERP เป็น multi-tenant database เดียว
- การรวม business data หรือ package logic ของ MintPOS/MintHRM เข้ามาใน `new-erp`
- Payment gateway, ออกใบแจ้งหนี้/ตัดบัตรอัตโนมัติ และ customer self-service portal
- การกำหนดราคาทางการค้า, VAT/เงื่อนไขสัญญา และรายละเอียด Support
- การเปลี่ยน flow stock/accounting หรือเพิ่ม permission ของโมดูลธุรกิจ
