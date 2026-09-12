# WMS Cost Propagation / Revaluation Runbook

สถานะ: Development staged rollout ระยะ Shadow/Queue-only — ยังไม่ใช่ Production sign-off

เอกสารนี้ใช้ตรวจและควบคุมงาน Cost Propagation โดยยึด `document_date` เป็น Date Authority และไม่แก้ Posted ledger เดิมโดยตรง

## 1. ค่าเริ่มต้นและ staged gate

ค่า default สำหรับ Production และ environment ที่ยังไม่ผ่าน staged approval ให้คง gate เหล่านี้เป็น `false`:

```dotenv
ERP_REVALUATION_AUTO_TRIGGER_ENABLED=false
ERP_REVALUATION_APPLY_ENABLED=false
ERP_REVALUATION_GL_POSTING_ENABLED=false
```

สถานะ Development ปัจจุบัน (2026-09-12): เปิด `ERP_REVALUATION_AUTO_TRIGGER_ENABLED=true`, `ERP_REVALUATION_APPLY_ENABLED=true` และ `ERP_REVALUATION_GL_POSTING_ENABLED=true` แล้ว; controlled suite ผ่าน 15 tests / 148 assertions (skip 1 operational evidence), health/gate check ผ่าน, queue pending/failed = 0 และไม่มี stale/review Run. ยังเป็น Development staged rollout ไม่ใช่ Production sign-off

ข้อควบคุม no-op: Run ที่ไม่มี Planned Delta (`delta_count=0`) ต้องถูก block ก่อน Approve/Apply/GL โดย Preflight และ Journal Posting guard; หากพบ Run เก่าก่อน guard ให้ถือเป็น benchmark artifact/quarantine และห้ามนับเป็น Accounting completion

ลำดับเปิดใช้งานแบบ staged:

1. เปิด Auto Trigger อย่างเดียว เพื่อสังเกต Batch/Run และ queue โดยยังไม่ Apply/GL
2. ตรวจ Shadow, Variance, node/partition counters และ Stock Card ตาม scope เดียวกัน
3. เปิด Apply เฉพาะเมื่อ Run ผ่าน Preflight และมี Accounting approval
4. เปิด GL Posting หลัง Apply/Reconciliation ผ่าน และ mapping/period พร้อม
5. ปิด gate ทันทีเมื่อพบ residual, missing proof, stale revision, queue retry ซ้ำ หรือ reconciliation difference

ห้ามเปิด Apply หรือ GL Posting ด้วยการแก้ฐานข้อมูลโดยตรง ให้เปลี่ยนผ่าน environment/config deployment และตรวจ `config:cache` ของ worker ทุก instance

## 2. ตรวจสุขภาพก่อนเริ่ม worker

ตรวจเฉพาะ queue Cost Propagation ไม่ล้าง queue ทั้งระบบ:

```bash
php artisan queue:work --once --queue=cost-propagation
```

ก่อนและหลังรันให้ตรวจ:

- `wms_cost_revaluation_batches`: `QUEUED`, `CALCULATING`, `PENDING_APPROVAL`, `REQUIRES_REVIEW`
- `wms_cost_revaluation_runs`: `QUEUED`, `CALCULATING`, `WAITING_CONTINUATION`, `FAILED_RETRYABLE`, `LIMIT_REACHED`
- `jobs` เฉพาะ queue `cost-propagation`
- `failed_jobs` ที่เกี่ยวข้องกับ `CalculateCostRevaluation`, `ApplyCostRevaluation`, `PostCostRevaluationJournal`

Run ที่ `REQUIRES_REVIEW`, `LIMIT_REACHED` หรือมี stale lease ห้าม mark เป็น `COMPLETED` เอง ให้เปิดหน้า Run แล้วใช้ Resume/Cancel ตาม permission และเหตุผลที่ตรวจสอบได้

## 3. ลำดับตรวจ Accounting

สำหรับแต่ละ Batch/Run:

1. ตรวจ Source document และ `document_date`
2. ตรวจ Shadow rows, source/ending/terminal/open bridge/residual
3. ตรวจ partition ว่าครบทุกสินค้า/คลัง/สาขา และไม่มี root line ตกหล่น
4. ตรวจ Stock Card ด้วย warehouse/item/as-of date เดียวกับ Run
5. ตรวจ Cost Lineage และ Allocation revision
6. ตรวจ Preflight: calculation contract, posting period, mapping, movement, allocation และ journal proof
7. หลัง Apply ตรวจ Stock Projection และหลัง GL ตรวจ Journal link, Inventory impact และ Debit/Credit
8. ปิด Run ได้เฉพาะเมื่อ Reconciliation เป็น `ready=true` และไม่มี blocker

`RECOST` allocation เป็นผลลัพธ์ของ Revaluation ไม่ใช่ source bridge ของรอบถัดไป หากพบ `DIRECT_BRIDGE_QUANTITY_EXCEEDED` หรือ RECOST ถูกนำไป replay ให้กักกัน Run และสร้าง Clean Run ใหม่

## 4. Recovery policy

- ยกเลิกได้เฉพาะ Run ที่ยังไม่มี Delta `APPLIED`
- Resume ได้เมื่อ lease หมดอายุหรืออยู่ในสถานะที่ recovery อนุญาต และต้องใช้เหตุผล
- ห้ามแก้ `CostAllocation`, Stock Balance หรือ Journal Posted ด้วย SQL ตรง
- เอกสาร Legacy ที่ `Movement.business_date` ไม่เท่ากับ `document_date` ต้องเข้า Legacy Review และใช้ controlled correction พร้อม Audit Log
- Reversal หลัง Revaluation ต้องสร้าง compensating Run ใหม่ โดยไม่แก้ Completed Run เดิม

## 5. หลักฐานก่อน Production gate

ต้องแนบผลเหล่านี้ใน release evidence:

- MySQL integration/UAT และ performance contract ล่าสุด
- benchmark production-sized graph: rows/sec, memory, queue lag, lock wait และเวลาจบต่อ partition
- รายการ Run/Batch ที่ค้างและ failed jobs เป็นศูนย์ หรือมี owner/เหตุผลชัดเจน
- Accounting sign-off ของ Stock Card → Shadow/Lineage → Reconciliation → GL
- staged feature flag change และแผน rollback

คำสั่ง benchmark เป็น read-only และจำกัดจำนวน partition/page:

```bash
php artisan wms:cost-benchmark --partitions=3 --page=250
```

ผล Development baseline วันที่ 2026-09-11: partition `1:1:1:AVG` จำนวน 86 nodes ใช้เวลา 73.733 ms (1,152.80 rows/sec), partition `1:32:64:AVG` จำนวน 4 nodes ใช้เวลา 7.524 ms (664.57 rows/sec), queue `cost-propagation` ค้าง 0 jobs และ peak memory 32 MB. Partition สินค้า 32 พบ `ALLOCATION_NOT_POSTED` จึงเป็นข้อมูลที่ต้องแก้/กักกันก่อนใช้เป็น production benchmark ไม่ถือเป็น sign-off

ผล Development benchmark ล่าสุดวันที่ 2026-09-12 (`--partitions=10 --page=250`): 10 partitions, รวม 106 nodes scanned, elapsed 175.922 ms, peak memory 32 MB, memory delta 2 MB, queue lag 0 jobs และไม่พบ blocker ในชุดที่เลือก; partition ใหญ่สุด `1:1:1:AVG` ใช้ 115.522 ms / 735.79 rows/sec. ผลนี้เป็น bounded development smoke benchmark เท่านั้น ยังไม่ใช่ production-sized/high-fan-out sign-off

ผล benchmark fixture แยกวันที่ 2026-09-12: ฐาน `new_erp_benchmark` ถูก migrate/seed และสร้าง 2 branches (`HQ`, `BR-BENCH-02`) กระจาย 10 warehouses (สาขาละ 5 คลัง) และ 10 isolated partitions ด้วย `wms:inventory-ops-smoke` (`OPS-SMOKE-B01` ถึง `OPS-SMOKE-B10`) โดยทุก chain เป็น Purchase Invoice → Journal → Movement → Cost Allocation แบบ POSTED และ reconciliation ตรงกัน; ใช้ `wms:benchmark-fanout --nodes=100 --confirm` ซึ่งจำกัดฐานข้อมูลและ commit แยกต่อ partition เพื่อเพิ่มรวม 1,000 nodes. `wms:cost-benchmark --partitions=20 --page=250 --from=2026-01-01` อ่านได้ 10 partitions / 1,000 nodes, elapsed 821.170 ms, peak memory 34 MB, memory delta 4 MB, queue lag 0 jobs และไม่พบ blocker. เนื่องจาก policy ปิดงบทีละปี ชุดนี้ถือเป็น one-fiscal-year high-fan-out baseline ครบหลายสาขา/หลายคลัง

Production-like read-only pass วันที่ 2026-09-12 (`--partitions=50 --page=250 --from=2024-01-01`): ระบบพบและทดสอบ 13 partitions, 111 nodes scanned, elapsed 196.185 ms, peak memory 32 MB, memory delta 2 MB, queue lag 0 jobs และไม่พบ blocker; partition ใหญ่สุด `1:1:1:AVG` ใช้ 110.9 ms / 766.46 rows/sec. เนื่องจาก development มีข้อมูลจริงเพียง 13 partitions และไม่มี high fan-out หลายปีเพียงพอ ผลนี้ยังไม่ใช่ production-sized/high-fan-out sign-off

## Legacy Review decision

## Permission verification

วันที่ 2026-09-12 รัน `CostRevaluationPermissionMySqlIntegrationTest` บน `new_erp_benchmark` ผ่าน 9 tests / 19 assertions: unauthorised user ได้ 403 ครบ 7 protected actions, admin มี lifecycle permissions ครบ และ role ที่ได้รับเฉพาะ Trigger ไม่สามารถใช้ Approve/Post/Recover/Cancel/Emergency Rebuild ได้

Accounting UAT readiness วันที่ 2026-09-12: UAT chain จริง `OPS-SMOKE-UAT` ผ่าน reconciliation (`allocation_vs_gl=0`, `balance_vs_allocation=0`), Journal link 1 รายการ, pending/unlinked allocation = 0, Movement/Allocation date mismatch = 0 และ debit/credit imbalance = 0. Readiness suite รวม `IssueReturnFifoMySqlIntegrationReadinessTest`, `CreditPurchaseInventoryMySqlIntegrationReadinessTest` และ `SalesReturnMySqlIntegrationTest` ผ่าน 11 tests / 97 assertions โดย skip 1 เฉพาะ operational evidence; ครอบคลุม Issue/Return FIFO, Purchase Credit Note full/partial return, NON_RETURN, Sales Return แบบ HS/IV และ retry/rollback/idempotency. Revaluation Journal matrix เพิ่มเติมผ่าน 4 tests / 51 assertions ครบ 10 classified impact cases พร้อม account routing, debit/credit, journal proof, projection และ checkpoint resume; ยังเหลือ Accounting ตรวจหลักฐานจริงและ sign-off staged rollout

ระหว่าง UAT พบและแก้ seeding gap: `WmsDocumentSequenceSeeder` เดิมสร้างเฉพาะ sequence ระดับคลัง แต่ Issue/Return และ Purchase Credit Note service ใช้ global sequence; ปัจจุบัน seeder สร้าง global `INVENTORY_ISSUE`, `INVENTORY_RETURN`, `PURCHASE_RETURN` และ `PURCHASE_CREDIT_NOTE` แล้ว และรันชุดทดสอบซ้ำผ่าน. Sales Return fixture ใช้ Opening Balance ผ่าน service จริงในคลังทดสอบ และ test transaction rollback ข้อมูลขาย/คืนทุกครั้ง

- ผู้มีสิทธิ์ `wms.cost-allocation-reviews.view` ตรวจหลักฐานและดู Movement/Allocation/Journal ได้ แต่ปิด Review หรือแก้ข้อมูลไม่ได้
- ผู้มีสิทธิ์ `wms.cost-allocation-reviews.approve` เท่านั้นจึงใช้ Legacy Correction หรือ `APPROVED_NO_ACTION` ได้
- `APPROVED_NO_ACTION` หมายถึง Accounting ยืนยันว่าไม่ต้องแก้ Stock/Cost/Movement เท่านั้น ไม่ได้ยืนยันว่ามี Journal proof หาก Evidence ยังระบุ `journal_proof_missing=true` ต้องบันทึกเป็นข้อสังเกตและดำเนินการ Journal recovery ตาม policy แยกต่างหาก
- เอกสาร `ISSUE_RETURN` สถานะ `REVERSED` ห้ามใช้ recovery ของเอกสาร `POSTED` โดยตรง; ให้ตรวจ Journal ของรายการเดิมและรายการกลับรายการก่อน หากไม่มี Journal จริงให้ส่งต่อ Accounting เพื่อกำหนด recovery contract
- เมื่อ Movement date ไม่ตรงกับ Document date ห้ามใช้ `APPROVED_NO_ACTION`; ต้องใช้ controlled correction ที่มี Audit Log หรือกักกันเป็น `REQUIRES_REVIEW`

Health/alert check สำหรับ scheduler หรือ external monitor:

```bash
php artisan wms:cost-health --json
```

คำสั่งนี้ไม่แก้ข้อมูลและจะคืน non-zero เมื่อพบ stale lease, failed queue job หรือ Run ที่ `REQUIRES_REVIEW`/`LIMIT_REACHED`/`FAILED_RETRYABLE`; Development baseline วันที่ 2026-09-11 พบ `RUN_REQUIRES_REVIEW` จำนวน 20 Run, queue pending/failed เป็น 0 และ stale lease เป็น 0

รายงานสำหรับ Accounting ตรวจราย Run:

```bash
php artisan wms:cost-signoff-report --run=149 --json
```

รายงานจะแสดง Source document, Preflight blockers, Reconciliation blockers และ totals โดยไม่ Apply/สร้าง Journal/แก้ Stock; ตัวอย่าง Run #149 (`ADJHQ2609000005`) ถูกระบุเป็น `BLOCKED` จาก legacy calculation contract/variance/posting period และยังไม่มี Delta/Applied/Journal จึงไม่ควรนำไปตรวจเป็นผลสำเร็จ ต้องใช้ Clean Run ที่สร้างใหม่แทน

ตรวจความพร้อมก่อนเปิด gate:

```bash
php artisan wms:cost-gate-readiness --json
```

ถ้ามี Run ที่ต้อง Review, failed job หรือ stale lease คำสั่งจะคืน non-zero และบอก gate ที่ยังเปิดไม่ได้ โดยไม่เปลี่ยนค่า environment. ผล Development วันที่ 2026-09-11: ทั้ง Auto Trigger/Apply/GL ยัง `ready=false` เพราะมี `RUN_REQUIRES_REVIEW` 20 Run แม้ queue และ stale lease จะปกติ; ค่า `.env` ปัจจุบันเปิด Apply/GL อยู่ จึงต้องให้ผู้ดูแลตัดสินใจปิดกลับหรือกักกัน Run ก่อนใช้เป็น release evidence
