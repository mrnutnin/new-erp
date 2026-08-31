# Inventory → GL Local Release-Gate Checklist

ใช้บน local MySQL `127.0.0.1:3306/new_erp` เท่านั้นในช่วง MVP และทำหลัง migration/seed ของรอบนั้นเสร็จแล้ว ห้ามเปิด feature flag ระหว่างตรวจ foundation

## Read-only release evidence recheck (23 สิงหาคม 2026)

- Release gate ของ Warehouse ที่มีอยู่ทั้งหมดผ่าน `ready=true` และไม่มี blocker: `HQ-WH`, `OPS-SMOKE-230823-A-N9NDOGHF4O`, `OPS-SMOKE-240823-D5-RKQ7AEJZVQ`
- `erp.inventory.purchase_posting_enabled=true` (local owner-approved release review; production remains disabled)
- `wms_cost_allocation_reviews` ที่มีสถานะ `OPEN = 0`; ไม่แก้ข้อมูล legacy ในรอบนี้
- Contract suite สำหรับ retry เดิม, payload hash เปลี่ยนต้อง reject, feature gate ก่อน collaborator, atomic rollback, reversal identity และ legacy isolation: `33 tests / 114 assertions ผ่าน`
- Readiness integration suite แบบ MySQL rollback-only ใน `phpunit.mysql.xml`: `8 tests / 68 assertions ผ่าน` (ไม่สร้างข้อมูลถาวร)
- ไม่พบ duplicate active Advance/Deposit idempotency key หรือ duplicate `FINANCE` source Journal identity จาก cross-module readiness check
- Stock Valuation UI audit พบ header ของ Current Valuation ขาดคอลัมน์ `จัดการ` ทั้งที่ DataTable มี detail action; เพิ่ม header ให้จำนวนคอลัมน์ตรงกันแล้ว และคง server-side DataTable/Select2 AJAX/permission gate เดิม
- Final audit read-only: `php artisan migrate:status --no-interaction` บน local `new_erp` แสดง migration ทั้งหมดเป็น `Ran` รวม migration ที่รองรับ `STALE`; `RbacSeeder` เคยผ่านแล้วและ permission ถูกผูกกับ admin
- ยังไม่ได้รัน `migrate:fresh` หรือ `DatabaseSeeder` ใหม่ในรอบ Final Audit เพื่อป้องกันการเปลี่ยนข้อมูล local; จึงต้องแนบผล fresh/repeat migration และ seed รอบ release แยกก่อนปิด gate
- Gate 3 safe seed idempotency recheck: เรียก `RbacSeeder`, `InventoryGlMockupSeeder` และ `PurchasingGoodsReceiptMockupSeeder` สองรอบภายใน outer transaction แล้ว rollback บน `new_erp`; counts ระหว่างรอบที่ 1/2 คงที่ (ไม่เพิ่มซ้ำ), baseline หลัง rollback กลับเท่าเดิมทุกตารางที่ตรวจ และไม่สร้าง Journal/Movement/Cost Layer/Allocation เพิ่ม
- Gate 3 migration evidence ยืนยันซ้ำด้วย `php artisan migrate:status --no-interaction`: migration ทั้งหมดเป็น `Ran`; ไม่ใช้ `migrate:fresh`, ไม่ทำ persistent seed และไม่เปิด feature flag

## Current release blockers (24 สิงหาคม 2026)

- Local feature state: `purchase_posting_enabled=true` และ `adjustment_posting_enabled=true` ตาม owner-approved local review; production flags ยังคงปิด
- ไม่พบ orphan Recost queue request (`orphan_recost_requests=0`) และไม่มี open/pending Recost request ในรอบตรวจนี้
- รอบตรวจแรกพบ `missing_cogs_mapping=1` ใน Warehouse `INT-B6XCI1JGTE` และ `INT-PL4QL4JHMZ`; แก้โดยผูก COGS mapping แบบ typed กับ `COGS_DEFAULT` (บัญชี active/postable) แล้วตรวจ preflight ซ้ำจนทั้งสองคลัง `ready=true`
- ทุก warehouse ที่ตรวจรอบล่าสุดไม่มี missing COGS, orphan/unlinked allocation, line mismatch หรือ pending Recost; `global_orphan_inventory_gl=0` และ `OPEN legacy review=0`
- Inventory Adjustment→GL rollback/reversal/service-boundary/document multi-line gate ผ่าน `4 tests / 34 assertions` ด้วย `phpunit.mysql.xml` ครอบคลุม feature guard, GAIN/LOSS, service-owned Movement/Allocation, idempotent retry, immutable reversal, document หลายบรรทัด และ rollback; Detail/UI/route/RBAC พร้อมแล้ว, migrations `2026_08_24_500000`, `2026_08_24_010000` และ document reversal รันบน local `new_erp`; adjustment flag เปิดเฉพาะ local แล้วเพื่อ manual UI/owner release review
- Recost→GL delta contract, queue scheduler/health และ reconciliation gate ผ่าน focused tests และ dedicated MySQL rollback runtime `tests/Feature/RecostRuntimeMySqlIntegrationReadinessTest.php` (`1 test / 15 assertions`): negative-stock receipt resolve, positive/negative delta, allocation→Journal-line proof, idempotent retry และ reconciliation ครบ; ไม่มี persistent mutation. เหลือ manual UI/owner release review และ production operational sign-off ก่อนปิด release gate ระดับระบบ
- FIFO issue-return gate ผ่าน dedicated MySQL rollback runtime `IssueReturnFifoMySqlIntegrationReadinessTest` (`3 tests / 23 assertions` รวม contract): รับเข้า 2 cost layers (6@10 และ 4@20), จ่าย FIFO 10, รับคืนแยก 6/4, ตรวจ movement/allocation lineage, retry ไม่สร้าง movement ซ้ำ และ over-return rollback; local migration `2026_08_24_703000` เติม SoftDeletes ให้ตาราง Issue/Return ครบ

## Evidence classification

ต้องแยกหลักฐานสองประเภทออกจากกันอย่างชัดเจน:

- **Evidence A — non-rollback/persistent:** ใช้ chain ที่ตั้งใจคงอยู่ใน local `new_erp` เช่น OPS-SMOKE เพื่อยืนยัน idempotency, reconciliation และการอ่านข้อมูลหลังจบ transaction; ห้ามใช้เป็น Production operational sign-off และยังไม่เปิด feature flag ถาวร
- **Evidence B — rollback-only/isolated:** ใช้ dedicated fixture หรือ PHPUnit MySQL integration ที่เปิด outer transaction แล้ว rollback เสมอ เพื่อยืนยัน source validation, posting/reversal และการคืน baseline counts; หลักฐานนี้ไม่ใช่ persistent operational evidence

การผ่าน Evidence B เพียงอย่างเดียวไม่ทำให้ Inventory→GL พร้อมใช้งานระดับระบบ และการมี Evidence A ใน warehouse หนึ่งไม่ลบ blocker ของ warehouse อื่น

## Wave 3–4 Recost contract review (local-only)

ตรวจ source contract และ focused tests แล้ว โดยยังไม่เปิด feature flag และไม่ทำ persistent DB mutation:

- Scheduler dispatch ใช้ bounded batch `100` (job clamp ไม่เกิน `500`), `everyFiveMinutes`, `withoutOverlapping` และ `onOneServer`; job ต่อ receipt ใช้ unique identity เดิมเพื่อป้องกันการ enqueue ซ้ำ
- Stale scanner ทำงานทุกชั่วโมงตาม `recost_sla_minutes` และเปลี่ยนเฉพาะ `PENDING/PROCESSING` ที่เกิน SLA เป็น `STALE` ด้วย conditional update; ไม่ย้อนสถานะ `RESOLVED`
- Operator retry ใช้ row lock และอนุญาตเฉพาะ `FAILED/STALE`; `PENDING/PROCESSING/RESOLVED` ถูกปฏิเสธเพื่อไม่สร้างงานซ้ำหรือแทรกกลางงานที่กำลังทำ
- Period Close Gate ตรวจ `PENDING/PROCESSING/FAILED/STALE` และต้องหยุดปิดงวดจนกว่า Recost จะ resolve; หากงวดเดิมปิดให้ทำ adjustment ในงวดเปิด ห้ามแก้ Journal เดิม
- Queue health เป็น read path แบบจำกัดจำนวน แสดงสถานะ/attempt/error และ retry action เฉพาะ permission `wms.recost.retry`; รายงานหนักทั่วไปยังไม่ถูกย้ายเข้า Queue ใน MVP

Focused contract result: **22 tests / 66 assertions ผ่าน** ครอบคลุม scheduler/bounded dispatch, decimal-safe recost, retry/idempotency, GL contract และ preflight UI route/permission. รอบ DB-backed local เพิ่มเติมผ่าน **1 test / 7 assertions** โดยสร้าง request ชั่วคราว ตรวจ Period Close blocker, `PROCESSING → STALE → PENDING` retry และ rollback baseline ครบ; ยังไม่ถือว่า Recost หรือ Inventory→GL พร้อมเปิดใช้งานระดับระบบจนกว่า manual UI/owner sign-off และ release evidence อื่นจะครบ

Migration note: migration `2026_08_22_312000_add_stale_status_to_wms_recost_requests` รองรับ enum สถานะ `STALE` ที่ Queue contract ใช้งานจริง และ `migrate:status` บน local `new_erp` แสดงเป็น `Ran`; ไม่มี fixture row ค้างจาก integration test

## Release Evidence B — transaction-safe operational smoke

- [ ] Purchase: enable flag only inside a transaction; verify rollback restores journal/movement/allocation/link counts and restores the flag.
- [ ] Purchase retry: same source/hash reuses the existing result; changed payload/hash is rejected.
- [ ] Recost: positive/negative mapping, closed period, missing mapping and reconciliation failure all fail closed.
- [x] Adjustment: GAIN/LOSS mapping, approval/period/reconciliation gates, same-key retry, changed-hash rejection, Detail/Audit, service boundary, immutable line reversal และ atomic document multi-line reversal pass (`4 tests / 34 assertions`).
- [x] Adjustment UI: Header direction (เพิ่ม/ลด) เป็น source of truth ทุกบรรทัด และ migration ตรวจ mixed legacy directions.
- [x] Adjustment DataTable: 1 เอกสารต่อ 1 แถว พร้อมจำนวนบรรทัด/สรุปสินค้า; reversal error ต้องระบุสินค้าที่เป็นปัญหาและไม่อนุญาตให้ยอดคงเหลือติดลบ.
- [ ] Unresolved legacy review is scoped by fixture warehouse; global reconciliation remains blocked while any open review exists.

## 1. Schema และ seed

- [ ] `php artisan migrate:status` แสดง migration ครบและไม่มี migration ค้าง
- [ ] `php artisan db:seed --class=DatabaseSeeder --no-interaction` ผ่าน
- [ ] mockup ที่เกี่ยวข้องรันซ้ำได้โดยไม่เกิด duplicate: `InventoryGlMockupSeeder`, `PurchasingGoodsReceiptMockupSeeder`
- [ ] ตรวจ Item/Base UOM/Conversion, Party/Role, Account Mapping และ Warehouse scope ครบ
- [ ] ตรวจว่า seed foundation ไม่สร้าง POS Stock ISSUE, COGS Journal หรือ Adjustment Journal โดยไม่ได้ตั้งใจ

## 2. Preflight และ reconciliation

- [ ] หน้า WMS Stock Valuation แสดง `โหมด Preview เท่านั้น` เมื่อ `ERP_INVENTORY_PURCHASE_POSTING_ENABLED=false` และ `ERP_INVENTORY_ADJUSTMENT_POSTING_ENABLED=false`
- [ ] ผู้ใช้ที่มี `wms.cost-allocation-reviews.view` เห็นเมนู `ตรวจสอบ Legacy Allocation` ในกลุ่มตรวจสอบสต็อก และการ์ด Preflight แสดงปุ่มเปิดรายการเมื่อมี Legacy review ค้าง
- [ ] ผู้ใช้ที่ไม่มี `wms.cost-allocation-reviews.view` ไม่เห็นเมนู/ปุ่ม Legacy Review และการเรียก route รายการ, data หรือรายละเอียดถูกปฏิเสธด้วย `403`
- [ ] ตาราง Legacy Review ใช้ server-side DataTable, shared defaults, search/pagination และ HTML5 Excel export; ค่า Allocation/สินค้า/Movement/สถานะต้องอ่านเข้าใจได้ ไม่แสดง raw JSON
- [ ] Preflight แสดง blocker ที่แก้ได้: pending Recost, unlinked/mismatched allocation, missing mapping และ source identity
- [ ] allocation-vs-GL difference = 0, balance-vs-allocation difference = 0 และ unlinked/pending = 0 ก่อนพิจารณาเปิด posting
- [ ] `unresolved_legacy_review=0` ก่อนพิจารณาเปิด posting; allocation เก่าที่ Journal ผูกอยู่แต่ยัง `PENDING` ต้องผ่าน quarantine/review หรือมี approved reversal/correction evidence ครบ
- [ ] Inventory control account และ item subledger ตรงกับ allocation journal-line linkage
- [ ] ตรวจ as-of/historical report ว่าไม่ใช้ current Stock Balance เป็น historical source

## 3. Purchasing dependency

- [ ] สร้าง/ตรวจ `Dedicated Approved Purchase Fixture Builder` ใน isolated integration process ก่อนเริ่ม MySQL integration; builder ต้องสร้างข้อมูลผ่าน domain validation และลบ/rollback ได้ ไม่ใช่ seed ถาวร
- [ ] แยกหลักฐานให้ชัดเจน: `PurchasingGoodsReceiptMockupSeeder` = PR→PO→GR Draft/UI foundation เท่านั้น; Dedicated fixture = Approved Purchase Invoice สำหรับ Inventory→GL integration เท่านั้น
- [ ] PR → PO → Goods Receipt → Credit Purchase/Invoice มี source link และ warehouse scope เดียวกัน
- [ ] Goods Receipt conversion snapshot มี purchase UOM, stock UOM, factor และ business date
- [ ] Purchase Invoice inventory path ยังผ่าน source/preflight/atomic linkage gate เดียว ไม่สร้าง Journal ซ้ำจาก Receipt
- [ ] กรณี source document หรือ allocation ไม่พร้อม ต้องเห็น reason และแนวทางแก้ ไม่แสดงปุ่ม Post จริง
- [ ] ตรวจ migration order หลัง fresh schema: purchase documents/linkage → PR/PO → Goods Receipt → UOM effective dates; index/foreign key ของ receipt allocation ต้องอยู่ครบ
- [ ] ยืนยัน 3-way allocation runtime (Purchase Invoice line ↔ GR line) และ variance approval ก่อนนับเป็น Inventory→GL evidence; schema อย่างเดียวไม่ถือว่าผ่าน
- [ ] Credit Purchase/Credit Note ต้องอ้าง posted original invoice, supplier/warehouse เดียวกัน และ AP ceiling; ยังไม่นับว่าเป็น stock reversal จนกว่าจะมี GR allocation/reversal contract
- [ ] Integration fixture ต้องมี Purchase Invoice สินค้า `APPROVED` (NONE_VAT), Supplier active, Item stock พร้อม Inventory/COGS mapping, UOM/conversion snapshot และ PO/GR linkage ครบ; ห้ามใช้ Draft-only mockup เป็นหลักฐาน Post
- [ ] ใช้ `ERP_RUN_MYSQL_INTEGRATION=1` เฉพาะ dedicated integration process; test ต้องเปิด transaction และ rollback เพื่อตรวจ Journal/Movement/Allocation/Linkage counts กลับค่าเดิม

## 4. Retry / rollback / operational evidence

- [ ] retry ด้วย idempotency key และ posting hash เดิมไม่สร้าง Journal/Movement/Allocation ซ้ำ
- [ ] payload เปลี่ยนภายใต้ key เดิมถูกปฏิเสธ
- [ ] transaction failure ไม่เหลือ partial Journal, Movement, Cost Allocation หรือ linkage
- [ ] Posted history แก้ไข/ลบไม่ได้; ใช้ reversal/contra พร้อม reason เท่านั้น
- [ ] Recost dispatcher ผ่าน bounded batch, `everyFiveMinutes`, `withoutOverlapping`, `onOneServer`; failed/stale retry ตรวจได้
- [ ] เก็บ SQL/query result, test output และผู้ตรวจไว้เป็น release evidence
- [ ] Legacy allocation repair ต้องเป็น dry-run/read-only ก่อน; ห้ามเปลี่ยน Purchase/GR/Invoice status หรือยอด และต้องตรวจ exact Journal-line/source/revision linkage ก่อนเสนอ transition
- [ ] เอกสารเก่าที่ PI/PO/GR link ไม่ครบ, status/ยอดผิด หรือ allocation เป็น `PENDING` ต้องถูกกักกันเป็น `REVIEW_REQUIRED`; ผู้ใช้ต้องเห็นขั้นตอนแก้/ย้อนกลับใน `docs/qa/purchasing-legacy-repair-impact.md` และห้ามนำไปผ่าน 3-way หรือ Inventory→GL โดยอัตโนมัติ
- [ ] Quarantine migration prerequisite: สร้างสถานะ/รายการ quarantine ที่ trace ได้สำหรับ legacy allocation review โดยไม่แก้ Journal/Purchase/GR เดิม และตรวจ count `unresolved_legacy_review` เหลือศูนย์ก่อน release

## 5. Final sign-off boundary

- [x] Owner Release Sign-off ของ Preview/Workflow/DataTable และ local Inventory→GL ผ่านเมื่อ 2026-08-25; ขอบเขตจำกัดที่ `new_erp` local เท่านั้น
- [ ] Production operational sign-off หลัง MVP modules ในขอบเขตพร้อมครบเท่านั้น
- [x] Mapping, reconciliation, source contract, rollback/retry และ local evidence ผ่านแล้ว; feature flags เปิดเฉพาะ local

> Owner Release Sign-off ไม่ใช่ Production operational sign-off และไม่อนุญาตให้เปิด feature flag ใน Production

## Dedicated fixture builder stages (ไม่ใช่ release-ready โดยตัวมันเอง)

1. ตรวจ schema/migration, feature flag ปิด และ baseline `unresolved_legacy_review=0` หรือหยุดเป็น quarantine
2. เลือก Approved PO และ Approved GR ที่ Supplier/Warehouse/PO line/Item/UOM เดียวกัน พร้อม conversion snapshot
3. สร้าง Approved inventory Purchase Invoice ชั่วคราวผ่าน domain model และผูก `purchase_order_line_id` + receipt allocation amount/value
4. รัน `PurchaseThreeWayMatchGate` ให้ `ready=true`, variance `CLEAR`, blockers ว่าง
5. ตรวจ source-flow metadata (allocation IDs, GR line IDs, conversion snapshots) ก่อนจึงพิจารณา integration posting test
6. เปิด transaction, เก็บ baseline counts/snapshots, ทดสอบตาม opt-in แล้ว rollback และยืนยัน Purchase/GR/legacy rows ไม่เปลี่ยน

ผลจาก stages 1–6 เป็น evidence ของ isolated integration; แม้ positive posting จะผ่านใน scope ใหม่ ก็ห้าม mark Inventory→GL ready ระดับระบบจนกว่า legacy quarantine, reconciliation, retry/reversal และ operational sign-off ครบ

## Current Purchasing evidence status (local audit 2026-08-23, after Ampere run)

- พร้อม: migration status บน local MySQL แสดงทุก migration เป็น `Ran`; PR approval → PO source linkage/idempotency, PO → Goods Receipt draft guards, UOM conversion snapshot, over-receipt protection, Credit Purchase AP/original-invoice guards, permission/route contracts และ optional mockup ที่ไม่สร้าง Stock/GL
- Fixture scope ที่ยืนยันแล้ว: optional mockup มี PR/PO เป็น `APPROVED` และ Goods Receipt เป็น `DRAFT` พร้อม warehouse/supplier/PO-line/item/UOM/conversion snapshot; สถานะนี้ถูกต้องสำหรับ Draft UI review แต่ยังไม่ใช่ Approved inventory posting evidence
- หลักฐาน isolated fixture ล่าสุด: สร้าง Approved inventory Purchase Invoice ชั่วคราวใน transaction แล้วเชื่อม PO `PO-2026-000005` ↔ GR `GR-20260822145011` ใน Warehouse `1` / Supplier `2`; Invoice line quantity `1.00000000`, allocation amount `2222.00000000`, purchase/stock UOM เดียวกัน และ conversion factor `1.00000000`. `PurchaseThreeWayMatchGate` ให้ `ready=true`, `variance_state=CLEAR`, blockers ว่าง และ rollback แล้วตรวจ `PI-INTEGRATION-TEMP` เหลือ `0` แถว
- Review ซ้ำใน isolated transaction รอบล่าสุดยืนยัน Invoice/PO/GR เป็น `APPROVED`, scope เดียวกัน (`warehouse_id=1`, `supplier_id=2`), Item/UOM `1/1`, allocation quantity/value `1.00000000 / 2222.00000000` ตรง GR total cost และผล matching ยังคง `ready=true`, `CLEAR`, blockers ว่าง; transaction rollback สำเร็จและไม่มี legacy row ถูกแตะ
- GL evidence ฝั่ง local ที่มีอยู่เป็นกรณี reversal เท่านั้น: `PI-INVENTORY-MOCK-001` (`POSTED`) ไม่มี PO/GR allocation; source Journal `11` (`REVERSED`) ถูกหักล้างด้วย Journal `13` (`POSTED`, reversal) และ cost allocations เดิมยัง `PENDING`. ณ as-of `2026-08-23` reconciliation service คำนวณ allocation/GL/balance เป็น `0.00000000` และ gate เป็น `ready=true` เพราะ reversal net เป็นศูนย์ แต่หลักฐานนี้ไม่ใช่ positive Approved Purchase integration evidence และห้ามใช้เปิด feature
- Source-flow review: แก้แล้วสำหรับ dedicated fixture path — `PurchaseLineMovementAdapter` ส่ง allocation IDs, GR line IDs, amount และ conversion snapshots เข้า Movement metadata และ `InventoryPurchaseProductionAdapter` บังคับ 3-way ก่อนสร้าง intent; positive isolated posting evidence ด้านล่างยืนยัน source→movement→allocation→GL linkage ใน transaction เดียว
- Final isolated warehouse evidence: transaction สร้าง Approved Invoice ชั่วคราวบน PO `PO-2026-000005` และ GR `GR-20260822145011` (ทั้งคู่ `APPROVED`) ใน scope Warehouse `1` / Supplier `2`, Item/UOM `1/1`, conversion `1.00000000`, allocation `1.00000000` / `2222.00000000`; 3-way เป็น `ready=true`, `CLEAR`, blockers ว่าง. หลัง rollback จำนวน Purchase Document/receipt allocation กลับเท่าเดิม และ snapshot legacy allocations `2/4` (`PENDING`, values `1000.00/-1000.00`) เหมือนเดิม จึงยืนยันว่าไม่มี legacy contamination
- Positive isolated posting evidence (2026-08-23): Dedicated builder สร้าง chain ใหม่ใน Warehouse `221` / Supplier `3`, Approved Invoice id `16` ผ่าน 3-way แล้วเปิด feature flag เฉพาะใน transaction; ได้ Journal `18` (`POSTED`, `supplier_invoice.inventory`), Movement (`POSTED`, source `PURCHASING/16`, base quantity `100.00000000`), Cost Allocation (`PENDING/FINAL`, value `1000.00`, Journal link `18`) และ immutable allocation→Journal-line link `37`. Reconciliation ของ warehouse ใหม่เป็น allocation/stock/GL `1000.00`, differences `0.00`, `unlinked=0`, `unresolved_legacy_review=0`; rollback คืน counts journals/movements/allocations/links เป็น `7/2/2/2` และคืน feature flag เป็นปิด
- GR→Stock/Cost + Purchase reversal evidence (isolated, 2026-08-23): fixture ใหม่สร้าง Movement IN `POSTED` จาก GR snapshot (base qty `100.00000000`, cost `1000.00`) และ reversal ได้ Journal `26→27`, Movement OUT `POSTED`, reversal allocation value `-1000.00` ผูก `parent_allocation_id` กับ source allocation และ Journal-line linkage ครบ; document `reversal_status=REVERSED`, revision `1`, reconciliation หลัง reversal เป็นศูนย์ทุกค่า. ก่อน/หลัง transaction counts กลับเดิมและ legacy rows ไม่เปลี่ยน
- Operational history review: persistent local audit log มี `wms.purchase_document.created/approved/posted/voided` พร้อม before/after values; เพิ่ม audit action สำหรับ production route `inventory_posted` และ `inventory_reversed`. Dedicated rollback fixture ใช้ prefix `INT-/PI-INT-/PO-INT-/GR-INT-`; Gate 2 operational evidence ใช้ prefix `CN-OPS-GATE2-20260824-` และเป็น persistent ตาม release gate
- OPS-SMOKE review ล่าสุด: ไม่พบข้อมูล persistent ที่มี prefix `PI-INT-/PO-INT-/GR-INT-` (ถูกต้องตาม policy ไม่สร้างค้างถาวร). Isolated run IDs: PR `14`, PO `13` (`PO-INT-YUZKLPR6ZM`), GR `10` (`GR-INT-YUZKLPR6ZM`), Invoice `22` (`PI-INT-WM93HEHRQ0VD`), line `29`, allocation `14`, GR line `10`; ทุกเอกสาร `APPROVED`, scope Warehouse `227` / Supplier `10`, UOM purchase/stock `17/18`, factor `10`, allocation amount `1000.00`, 3-way `ready=true/CLEAR`, rollback counts เดิม `warehouses/parties/PR/PO/GR/docs/allocations = 1/2/6/4/2/4/2`
- Persistent Gate 2 operational evidence (2026-08-24): Invoice `45` (`PI-INT-FU5SNJLMRX45`) → Credit Note `46` (`CN-OPS-GATE2-20260824-N9OTKFEGWMXD`) ใน Warehouse `305` / Supplier `27`, Credit Journal `140`, original Journal `139`; reversal Movement `453` (`OUT`, source movement `452`), reversal Allocation `269` (`POSTED/FINAL`, parent allocation `268`, value `-1000.00000000`), และ allocation→Credit Journal-line link `1` รายการ. Reconciliation เป็น `ตรงกัน`, `allocation_vs_gl_difference=0.00000000`, `balance_vs_allocation_difference=0.00000000`; retry คืน IDs เดิมและไม่สร้างรายการซ้ำ
- Persistent Gate 2 recheck (2026-08-24): รันบน local MySQL `new_erp` ด้วย prefix `CN-OPS-GATE2-20260824-` ใหม่ ได้ Invoice `92`, Credit Note `93`, Credit Journal `536`, reversal Movement `1065`, reversal Allocation `874`; allocation→credit journal-line link `1` รายการ, `allocation_vs_gl_difference=0.00000000`, `balance_vs_allocation_difference=0.00000000`, retry คืน identity เดิมและไม่สร้างรายการซ้ำ. ใช้เป็นหลักฐาน local ล่าสุดแทนการอ้าง ID เก่าเมื่อวิเคราะห์รอบนี้
- Enabled local smoke (2026-08-24): `tests/Feature/InventoryPurchaseEnabledSmokeReadinessTest.php` ตรวจ Invoice `45` → Journal `139` → Movement `452` → Allocation `268`/Journal link, เรียก POST retry ได้ Journal เดิมและ counts ไม่เพิ่ม, wrong posting date ถูก reject และ counts ไม่เปลี่ยน. ผล `1 test / 8 assertions ผ่าน`; การรันใช้ environment flag ตาม local development context และไม่แก้ `.env` ในงานนี้—ตรวจภายหลังพบ local `.env` เป็น `true`; production sign-off ยังไม่เกิด
- Queue cleanup/readiness (2026-08-24): ตรวจ jobs `RecalculateInventoryCost` ที่อ้าง movement/receipt `3, 336, 400, 441`; ทุก source allocation เป็น `POSTED/FINAL` และไม่มี pending recost layer/request จึงเป็น no-op orphan/stale jobs ไม่ใช่งานที่ต้อง recost. ประมวลผลแบบ bounded `queue:work --once` จำนวน 4 รายการ (ไม่ล้าง queue กว้าง), ทุก job `DONE`; หลังตรวจ `jobs=0`, `failed_jobs=0`, ไม่มี recost request ค้าง. เพิ่ม guard ใน `StockMovementService` ให้ dispatch เฉพาะเมื่อ `requestIdsForReceipt()` มี pending request จริง
- ยังขาด: MySQL fresh/repeat migration evidence รอบ release และ production operational sign-off; runtime variance approval แบบ persistent, Goods Receipt → Stock/Cost และ Credit Purchase → GR stock reversal มีหลักฐานครบแล้ว. Local feature flags เปิดตาม Owner Release Sign-off แล้ว แต่ production flags ยังปิด
- ข้อมูล persistent ปัจจุบันแยกชัดเจน: Gate 2 ใช้ Credit Note ใหม่ `CN-OPS-GATE2-20260824-N9OTKFEGWMXD` ที่อ้าง Invoice/GR จริง ไม่ใช่ `PI-INVENTORY-MOCK-001` legacy reversal
- Allocation/reversal เดิมที่เคยมี `allocated_amount=0.00` ถูกซ่อมตามหัวข้อ legacy repair แล้ว; ต้องใช้ผล reconciliation หลัง repair ไม่ใช้ snapshot ก่อนซ่อมเป็น release evidence
- Persistent Credit Purchase → GR reversal audit (read-only, local `new_erp`): เดิมพบ `CREDIT_NOTE=0`; ปัจจุบันมี persistent Gate 2 evidence ใหม่ `CREDIT_NOTE id=46` (`CN-OPS-GATE2-20260824-N9OTKFEGWMXD`) อ้าง Invoice `45` และ GR allocation จริง พร้อม Movement OUT `453`, Allocation `269`, Credit Journal `140`, lineage และ reconciliation ครบ. `PI-INVENTORY-MOCK-001` ยังคงเป็น legacy Purchase Invoice reversal ที่ไม่มี GR allocation และไม่ถูกนำมาใช้แทน evidence นี้
- Gate 2 runtime foundation/evidence: schema linkage `inventory_reversal_movement_id`/`inventory_reversal_allocation_id`, adapter และ permission-protected route `purchase-documents/{id}/credit-inventory-reverse` เปิดเฉพาะเมื่อ source contract ครบ; persistent evidence ผ่านแบบ targeted local run และ local feature flag เปิดหลัง owner review (production ยังคงปิด)
- Gate 2 isolated runtime evidence (rollback-only, local MySQL): `tests/Feature/CreditPurchaseInventoryMySqlIntegrationReadinessTest.php` ยังคงผ่าน `1 test / 11 assertions`; persistent operational run แยกต่างหากใช้ prefix `CN-OPS-GATE2-20260824-` และผ่าน `1 test / 10 assertions` โดย commit เฉพาะข้อมูล evidence ที่ตรวจ reconciliation แล้ว
- Bounded foundation เพิ่มแล้ว: `CreditPurchaseInventoryReversalContract` บังคับ full-line GR scope เดียวกัน, Posted/FINAL source, immutable source, idempotency key/hash, credit Journal-line linkage และ reconciliation gate; `CreditPurchaseInventoryReversalService` จัดลำดับ Movement → Allocation → Linkage → Reconciliation ภายใต้ transaction callback และปิด feature flag เป็นค่าเริ่มต้น
- Release rule: positive isolated/persistent posting evidence และ legacy repair evidence ผ่านแล้ว โดย local warehouses ที่ตรวจล่าสุดมี `unresolved_legacy_review=0`; local Inventory→GL เปิดหลัง owner UI/release review แล้ว ส่วน production ต้องรอ operational sign-off แยกต่างหาก

## Runtime release-gate verification (local MySQL, 2026-08-23)

## Dedicated MySQL rollback run (2026-08-23)

ใช้ config แยก `phpunit.mysql.xml` เพื่อไม่เปลี่ยนชุด PHPUnit ปกติที่ใช้ SQLite:

```text
php vendor/bin/phpunit -c phpunit.mysql.xml
```

ผลตรวจบน `127.0.0.1:3306/new_erp`: **8 tests / 68 assertions ผ่าน**

ครอบคลุม Advance/Deposit, Purchase/GR → Stock Movement → Cost Allocation → Journal และ Transfer lineage โดยทุก test เปิด transaction และ rollback; ไม่มี `migrate:fresh`, ไม่มี seed ถาวร และไม่เปิด feature flag ค้างไว้

การรันเฉพาะ Purchase/GR และ Transfer ซ้ำ: **7 tests / 38 assertions ผ่าน**

ตรวจหลัง rollback: `journal_entries=11`, `wms_stock_movements=3`, `wms_cost_allocations=3`, `wms_cost_allocation_journal_lines=3` และ `erp.inventory.purchase_posting_enabled=false` เท่ากับ baseline

- แก้และตรวจ container binding ของ `InventoryPostingPreflightReader` ให้ `InventoryPostingPreflightService` ถูก resolve ได้จริงจาก `InventoryWarehouseReleaseGate` (ก่อนหน้านี้ Unit contract ผ่าน แต่ runtime container ยัง bind interface ไม่ได้)
- `php artisan db:seed --class=RbacSeeder --no-interaction` ผ่าน; permission `wms.cost-allocation-reviews.view` ถูกสร้างและผูกกับ role `admin` ในฐานข้อมูล `new_erp`
- Warehouse `229` (OPS-SMOKE) ผ่าน gate: `ready=true`, reconciliation พร้อม, blockers ว่าง; global feature flag ยังปิด
### Historical pre-repair snapshot (superseded)

รายการต่อไปนี้เป็นผลตรวจ **ก่อน** user-authorized legacy repair และไม่ใช่สถานะปัจจุบัน: Warehouse `1` มี blocker จาก allocation `2/4`, review `1/2` เป็น `OPEN/REVIEW_REQUIRED`, และ reversal linkage ยังไม่ครบ จึงถูกกักกันไม่ให้นับเป็น positive Inventory→GL evidence

หลัง repair ตามหัวข้อถัดไป Warehouse `1` และ `229` ผ่าน warehouse-scoped gate, `unresolved_legacy_review=0` และ reconciliation differences เป็นศูนย์; ยังคงไม่ใช่ Global Posting หรือ Production operational sign-off

## User-authorized legacy smoke-data repair (local MySQL, 2026-08-23)

- ผู้ดูแลยืนยันว่า allocation `2/4` เป็นข้อมูลทดสอบที่สร้างผิดและอนุญาตให้แก้ไข
- ซ่อมเฉพาะ linkage ที่ขาด: `allocation 4.parent_allocation_id = 2` พร้อม metadata ระบุเหตุผลการซ่อม; ไม่ลบหรือแก้ Journal/Movement เดิม
- ตรวจ exact reversal chain ก่อน commit: Journal `11 REVERSED` → Journal `13 POSTED`, Movement `3 IN` → Movement `5 OUT`, allocation-to-Journal-line linkage ครบ และยอด `+1000/-1000`
- Review `1/2` เปลี่ยนเป็น `RESOLVED` พร้อม Audit action `wms.cost_allocation.review_resolved`
- หลังซ่อม Warehouse `1` และ `229` ผ่าน Release Gate; `unresolved_legacy_review=0`, allocation/stock/GL differences `0.00000000`, `unlinked_allocations=0`
- Global feature flag ยังคง `false`; ยังไม่ถือเป็น Production operational sign-off
- หลัง repair รัน `wms:inventory-ops-smoke --prefix=OPS-SMOKE-230823-A --actor=1 --confirm` ซ้ำแล้วคืน IDs เดิม (`purchase_document=23`, `journal=28`, `movement=336`, `allocation=192`), reconciliation Warehouse `229` ยังคงตรงกัน และไม่พบ review `OPEN`
- ตรวจ persistent smoke source แล้วไม่เกิดเอกสารซ้ำ (`purchase_documents=1`, `purchase_orders=1`, `purchase_requisitions=1`, `goods_receipts=1`); feature flag ยังคงปิด
## Persistent OPS-SMOKE evidence — 23 August 2026

The existing idempotent OPS-SMOKE chain was rechecked on local MySQL `new_erp` without creating duplicate rows and without enabling either Inventory posting feature flag.

- Warehouse `229`: PR → PO → GR → posted Purchase Invoice
- Journal `28`, Stock Movement `336`, Cost Allocation `192`, Cost Layer `215`
- Journal-line linkage `16` present
- 3-way: ordered 10 / received 10 / invoiced 10; quantity and price variance 0; `CLEAR`
- Allocation, stock balance and Inventory GL all `1,000.00`; differences 0; unlinked allocation 0
- Warehouse release gate ready; unresolved legacy review 0
- Purchase posting flag เปิดเฉพาะ local หลัง owner UI/release review; adjustment posting และ production flags ยังคง disabled pending operational sign-off
