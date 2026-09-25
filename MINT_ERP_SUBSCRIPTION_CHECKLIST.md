# MintERP Subscription — Checklist

สถานะ: **วางแผนแล้ว · ยังไม่เริ่ม implement**
รายละเอียด/สถาปัตยกรรม: [MINT_ERP_SUBSCRIPTION_PLAN.md](MINT_ERP_SUBSCRIPTION_PLAN.md)

## ข้อตกลงที่ยืนยันแล้ว

- [x] เริ่มจากระบบ entitlement ใน `new-erp` แยกต่อ instance; Portal/API ทำภายหลัง
- [x] Portal สำหรับทีมงานเป็นโปรเจ็คใหม่ที่ `ops.alexiasoft.co`; ไม่ปนกับเว็บการตลาด
- [x] `new-erp` คือ local development/UAT; ผู้ใช้ทำ UAT เอง
- [x] พัฒนาฝั่งนี้รันเฉพาะ Unit/Contract tests ไม่รัน Feature/Integration/E2E/browser tests
- [x] ก่อนรัน migration ตรวจ connection/pending migrations และรันเฉพาะ migration ที่อนุมัติสำหรับงานนี้
- [x] ตรวจแล้ว: CompanySetting ใช้ `id=1`; ยังไม่พบ subscription/quota enforcement ปัจจุบัน
- [x] ทดลองใช้ฟรี 1 เดือนใช้ Business quota (3 สาขา/6 คลัง/15 users) พร้อม CRM/Asset; Production ไม่รวมอัตโนมัติ
- [x] Production Trial ขอเพิ่มได้เมื่อผ่านการคุยความพร้อม/Manufacturing; เริ่มนับ Trial เมื่อ instance พร้อมส่งมอบและไม่ auto-convert/charge
- [x] Subscription entitlement ต้องแยกจาก License; ห้ามเปลี่ยน behavior ของ License installs
- [x] Portal ในอนาคตต้องรองรับหลายผลิตภัณฑ์ เช่น MintERP, MintPOS และ MintHRM ไม่ผูกกับ MintERP เท่านั้น

## Phase 0 — ยืนยันกติกาก่อน implement

- [ ] ยืนยันขอบเขต ERP: 1 บริษัทลูกค้าต่อ ERP instance/database และแยกไฟล์/backup
- [ ] ออกแบบ Portal แยก Customer, Product, Instance และ Subscription/Contract; ลูกค้าหนึ่งรายมีหลาย products/instances ได้
- [ ] ตัดสินใจว่า Subscription ต่อ product ครอบคลุมได้กี่ instances; entitlement ยังอยู่และตรวจที่แต่ละ instance
- [ ] ยืนยัน package limits: Core 1 สาขา / 2 คลัง / 5 users; Business สูงสุด 3 สาขา / 6 คลัง / 15 users
- [ ] ยืนยันว่าบัญชี Admin ของลูกค้านับรวม quota ผู้ใช้หรือไม่
- [ ] ยืนยันการนับ quota: active เท่านั้นหรือไม่ และนิยาม soft-delete/การเปิดใช้ใหม่
- [ ] ยืนยัน CRM/Asset มีเฉพาะ Business และ Production เป็น add-on ได้ทั้ง Core/Business หรือไม่
- [ ] ยืนยันหนึ่งเดือนปฏิทินหรือ 30 วัน, timezone/เวลาเริ่ม, Trial ซ้ำต่อ Customer + Product ได้หรือไม่ และ Production Trial ใช้วันหมดอายุเดียวกับ Business Trial หรือไม่
- [ ] ยืนยันสถานะ/การเข้าถึงข้อมูลหลังหมด Trial: grace/read-only, retention, ค้างชำระ/ระงับ และยกเลิก
- [ ] ยืนยันให้ขอ Trial ผ่านทีมงานก่อน Portal และอนุมัติข้อความ/เงื่อนไข Trial ก่อนประกาศบน `minterp-site`
- [ ] กำหนด downgrade เมื่อใช้งานเกิน quota และการเข้าถึงข้อมูลของโมดูลที่ถูกถอด
- [ ] ตรวจจำนวน User/Branch/Warehouse/Module ในฐานข้อมูลเดิมก่อนตั้ง quota; ห้ามบังคับ Core โดยอัตโนมัติ

## Phase 1 — Entitlement ต่อ instance ใน `new-erp`

- [ ] นิยาม plan catalog/version สำหรับ Core, Business และ Production add-on; Trial ใช้ Business quota ไม่สร้างชุด quota ซ้ำ
- [ ] เพิ่ม `commercial_mode` ในแถว entitlement: `UNASSIGNED|SUBSCRIPTION|LICENSE|INTERNAL`; mode เปลี่ยนได้เฉพาะทีมงาน
- [ ] สร้าง migration ตาราง entitlement แบบหนึ่งชุดต่อ instance พร้อม `up()`/`down()`; เก็บ Trial และช่วงเริ่ม/หมดอายุ
- [ ] Backfill instance ที่มี `company_settings.id=1` เป็น `LICENSE`; fresh install ที่ยังไม่มี company setting เป็น `UNASSIGNED`; ทดสอบ backfill/down และห้ามเปลี่ยน License behavior
- [ ] หลัง migration เปลี่ยน `new_erp` local/UAT จาก `LICENSE` backfill เป็น `INTERNAL` ก่อน UAT Subscription
- [ ] เก็บ plan/version, status/ช่วงมีผล และจำนวน add-on; ไม่เก็บราคาเป็นตัวกำหนด quota
- [ ] บังคับ entitlement เป็น singleton ต่อ instance ด้วย database constraint และ lock แถวเดียวตอนเปลี่ยน quota
- [ ] เพิ่ม model/casts และ service สำหรับอ่าน entitlement/คำนวณ quota
- [ ] เพิ่ม schema/table/column ใน `DatabasePreparationService::requiredSchema()` และ contract test ตรวจ schema ที่ Installer ต้องใช้
- [ ] สำรองทุก instance ก่อน migration; หลังมี Subscription จริงใช้ forward-fix แทน rollback ที่ลบ entitlement history
- [ ] กำหนด Installer: License mode ใช้ flow เดิม; fresh install เป็น `UNASSIGNED` ไม่แจก Trial/paid plan อัตโนมัติ; business routes ปิดจนทีมงาน provision แต่ Installer/health checks ยังทำงานได้
- [ ] กำหนด local/test/UAT เป็น `INTERNAL`; production ต้องปฏิเสธ `INTERNAL`; Subscription ที่ไม่มี entitlement และ `UNASSIGNED` ต้อง fail closed; migration ฐานเดิมต้องคง License behavior ไม่เปลี่ยนเป็น Core/Trial หรือตัดข้อมูลเดิม
- [ ] เพิ่ม Artisan command ที่จำกัดให้ทีมงาน provision/เปลี่ยน plan, add-on และออก/แปลง Trial พร้อม validate, ป้องกัน trial ซ้ำจากทะเบียน และ audit
- [ ] จำกัด implementation เป็น service แยก + guard hooks ที่จำเป็น; mode License/Internal ต้อง no-op และไม่ refactor permission/stock/accounting flows
- [ ] ไม่ให้ Customer Admin แก้ commercial mode, package หรือ quota ผ่าน Settings/request payload; `.env` เก็บเฉพาะ infrastructure/secrets
- [ ] `SubscriptionEntitlementService` เป็น no-op สำหรับ License/Internal; quota และ Trial logic ใช้เฉพาะ Subscription mode

## Phase 2 — บังคับ quota ผู้ใช้/สาขา/คลัง

- [ ] ตรวจ quota ฝั่ง server ในการสร้างและเปิดใช้ User เฉพาะ `SUBSCRIPTION`
- [ ] ตรวจ quota ฝั่ง server ในการสร้างและเปิดใช้ Branch เฉพาะ `SUBSCRIPTION`
- [ ] ตรวจ quota ฝั่ง server ในการสร้างและเปิดใช้ Warehouse เฉพาะ `SUBSCRIPTION`
- [ ] ทำ count + lock + create/activate ใน transaction เดียว ป้องกันใช้ slot เดียวกันพร้อมกัน
- [ ] แสดงจำนวนใช้แล้ว/โควตาและเหตุผลที่เพิ่มไม่ได้ใน Settings
- [ ] เมื่อเกิน quota หรือ downgrade: ห้ามลบประวัติ/เอกสาร; บล็อกเฉพาะการเพิ่ม/เปิดใช้จนกว่าจะอยู่ใน quota
- [ ] ตรวจ direct route/API ด้วย ไม่พึ่งการซ่อนปุ่ม

## Phase 3 — บังคับสิทธิ์โมดูล

- [ ] เพิ่ม plan gate เป็นชั้นเสริมจาก `ModuleCapability` เฉพาะ Subscription; License ใช้ผล gate เดิมทุกประการ
- [ ] Gate CRM สำหรับแพ็กเกจที่ไม่ได้รวม CRM
- [ ] Gate Asset โดยคง Company Setting/capability ปัจจุบันร่วมด้วย
- [ ] Gate Production เมื่อไม่มี add-on; ต้องคงเงื่อนไข Manufacturing และ `production_enabled`
- [ ] ให้ Program selector, Sidebar, routes และ API ตรงกัน; ไม่สร้าง permission RBAC ใหม่เพื่อแทน package gate
- [ ] ตรวจว่าการปิด/ถอดโมดูลไม่ลบข้อมูลหรือเปลี่ยน stock/accounting flow

## Phase 4 — ตรวจสอบและเตรียมใช้งาน

- [ ] Unit/Contract tests สำหรับ mode backfill/fail-closed, plan resolution, boundary/activation, module gates, migration/installer schema และ License regression (ผู้ใช้/สาขา/คลังเกิน Core ยังทำงานตามเดิม)
- [ ] ตรวจ PHP lint, Blade compilation และ `git diff --check`
- [ ] ตรวจ pending migrations ของ connection `new_erp`; ไม่รัน unrelated migrations
- [ ] เตรียม UAT cases: Business Trial ได้ CRM/Asset และ quota ถูกต้อง; Production ปิดโดย default/เปิดได้เมื่ออนุมัติ, Trial เริ่ม/เตือน/หมดอายุ/แปลง paid, trial ซ้ำ, quota เต็ม, concurrent create, deactivate/reactivate, upgrade/downgrade และ module off/on
- [ ] ผู้ใช้ทดสอบ flow จริงบนฐานข้อมูล UAT แยก; ห้ามเปลี่ยนข้อมูลธุรกิจย้อนหลังเพื่อให้ test ผ่าน
- [ ] ทดสอบ backup/restore แยกต่อ instance และแยก private storage
- [ ] วัดโหลด HostAtom VPS ก่อนกำหนดจำนวน instance; ตั้ง monitoring/backup/restore procedure
- [ ] เขียน runbook provision ลูกค้า: instance, DB, domain, `.env`, storage, plan, admin และ backup

## Phase 5 — Portal/API ภายหลัง

- [ ] สร้างโปรเจ็คใหม่สำหรับทีมงานที่ `ops.alexiasoft.co` ด้วย Laravel รุ่นที่ยังได้รับ security support
- [ ] แยก deployment, database, secrets และ user directory จากเว็บการตลาดและ ERP instances
- [ ] เพิ่ม staff login, MFA, RBAC, audit log และปิด public registration
- [ ] Portal มี product registry แยกจาก module/program ภายใน product; ข้อมูลกลางไม่ hard-code quota ของ MintERP
- [ ] Portal จัดการ customer/product/instance, product-scoped plan/add-ons, Trial eligibility/history ต่อ product, วันต่ออายุ, status และ sync result
- [ ] ระบุ `product_code`, instance ID และ API/entitlement schema version ใน sync; ปฏิเสธ product code ที่ไม่ตรง instance
- [ ] เริ่มเชื่อม adapter ของ `MINTERP` ก่อน; เพิ่ม adapter ของ MintPOS/MintHRM เมื่อระบบเหล่านั้นเริ่มพร้อม โดยไม่เปลี่ยน customer/instance core model
- [ ] เพิ่ม versioned API ใน `new-erp` ที่รับเฉพาะ entitlement update
- [ ] ใช้ HTTPS, credential แยกต่อ instance, rotation, replay/idempotency protection และ audit; เข้ารหัส secrets at-rest และห้ามบันทึก credential ลง log
- [ ] ห้าม Portal เชื่อม DB ลูกค้าโดยตรง; ERP ใช้ entitlement local และไม่ตรวจ Portal ทุก request
- [ ] ทดสอบ Portal ล่ม, API retry, key rotation, Trial issue/expiry/conversion, product/instance mismatch, downgrade และ rollback ก่อนเปิดใช้จริง

## เกณฑ์ปิดงานรอบแรก

- [ ] แต่ละ instance มี entitlement ชัดเจน และ package limits ถูกตรวจฝั่ง server
- [ ] ผู้ดูแลลูกค้าเปลี่ยน quota เองไม่ได้; upgrade/downgrade ทำโดยทีมงานผ่านช่องทางที่ audit ได้
- [ ] การเกิน quota ไม่ทำให้ข้อมูลเดิมหาย และสิทธิ์โมดูลไม่ข้าม plan
- [ ] Installer ตรวจ schema ที่ต้องใช้ และ local/UAT เดิมยังเริ่มทำงานได้
- [ ] Unit/Contract tests ที่เกี่ยวข้องผ่าน; ผู้ใช้ลงชื่อ UAT ก่อนใช้งานกับลูกค้าจริง
- [ ] มีคู่มือ provision, backup/restore และ deploy/update ต่อ instance
