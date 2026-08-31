# Transfer → Inventory reconciliation smoke

ใช้ตรวจฐาน local `new_erp` แบบ read-only หลัง migration โดยไม่ใช้ `migrate:fresh` หรือ seed เดิม

## Current local evidence

ตรวจเมื่อ 22 สิงหาคม 2026:

- database: `new_erp`
- `wms_transfers`: 0 rows
- `wms_transfer_events`: 0 rows
- `wms_stock_movements`: 2 rows
- `wms_cost_allocations`: 2 rows

ดังนั้นผลครั้งนี้ยืนยันได้เฉพาะ schema/migration readiness ยังไม่มี transfer fixture ให้สรุป net-zero integration ผ่าน

## Isolated DB-backed integration evidence

ตรวจเมื่อ 22 สิงหาคม 2026 ด้วย `php vendor/bin/phpunit -c phpunit.transfer.xml --testdox`:

- `WmsTransferCostLineageTest`: **6 tests / 24 assertions ผ่าน**
- ใช้ `DatabaseTransactions`; Warehouse B/C, receipt, transfer และ costing fixtures ถูก rollback หลังแต่ละ test
- ครอบคลุม FIFO และ AVG แบบ A → B → C พร้อม parent layer lineage และไม่สร้าง Journal/GL gain-loss
- ครอบคลุม partial accept, reject, retry เดิมแบบ idempotent, wrong warehouse, closed period และ insufficient stock rollback
- ผลนี้เป็นหลักฐาน integration ใน isolated transaction เท่านั้น ไม่ใช่ข้อมูลธุรกรรมถาวรใน local database

สถานะ: Transfer/AVG-FIFO integration contract ผ่านใน test isolation แล้ว แต่ยังต้องทำ manual UI/production operational sign-off ก่อนเปิดใช้งานจริง

## Read-only aggregate query

```sql
SELECT
    m.source_id AS transfer_id,
    SUM(CASE WHEN a.direction = 'OUT' THEN a.value ELSE 0 END) AS source_out_value,
    SUM(CASE WHEN a.direction = 'IN' THEN a.value ELSE 0 END) AS destination_in_value,
    SUM(a.value) AS net_value,
    SUM(CASE WHEN a.journal_entry_id IS NOT NULL THEN 1 ELSE 0 END) AS journal_link_count,
    SUM(CASE WHEN a.parent_allocation_id IS NULL THEN 1 ELSE 0 END) AS missing_parent_count,
    SUM(CASE WHEN a.stock_cost_layer_id IS NULL THEN 1 ELSE 0 END) AS missing_layer_count
FROM wms_cost_allocations a
JOIN wms_stock_movements m ON m.id = a.stock_movement_id
WHERE m.source_type = 'WMS_TRANSFER'
GROUP BY m.source_id;
```

Expected gate per transfer:

- `net_value = 0.00000000`
- `journal_link_count = 0` (Transfer ต้องไม่สร้าง GL gain/loss ใน MVP)
- `missing_parent_count = 0` และ `missing_layer_count = 0` สำหรับ AVG/FIFO destination allocations
- source/destination warehouse ต้องยังแยกกันใน row-level reconciliation แต่รวมกันเป็นศูนย์ระดับบริษัท

## Required fixture evidence

สร้างผ่าน Transfer UI/service ใน isolated transaction หรือ disposable test database เท่านั้น แล้ว rollback/drop fixture หลังตรวจ:

1. Receipt ที่ Warehouse A
2. Transfer A → B และ dispatch
3. Accept บางส่วนที่ B แล้ว retry command เดิม
4. Accept ส่วนที่เหลือ หรือ reject บางส่วน
5. Transfer B → C แล้วตรวจ parent allocation/layer lineage
6. รัน aggregate query ข้างต้น และตรวจ movement/event/allocation count ไม่เพิ่มเมื่อ retry เดิม

ห้ามใช้ local production-like rows เพื่อสร้าง fixtureโดยตรงจนกว่า Master จะอนุมัติวิธี seed/rollback
