# Feature-based Posting Configuration Plan

เอกสารนี้เป็นแผนกลางสำหรับการตั้งค่าการลงบัญชีของทุก Feature ในระบบ ERP
จัดทำไว้เพื่อพิจารณาเท่านั้น ยังไม่เริ่ม refactor หรือเปลี่ยนพฤติกรรมการ Post
จนกว่า Owner จะอนุมัติให้เริ่มงาน

## วัตถุประสงค์และขอบเขต

- ให้ Accounting เป็นเจ้าของการตั้งค่า Account Mapping กลางของทุก Module
- ให้ Global Settings เก็บเฉพาะ policy/default ระดับบริษัท ไม่เก็บ account ID แบบกระจัดกระจาย
- ห้าม operational module hard-code account ID หรือเลือกบัญชีแรกจากฐานข้อมูล
- ทุก Feature ที่สร้าง Journal ต้องมี readiness gate, idempotency, snapshot และ reversal contract

## Ownership และตำแหน่งการตั้งค่า

| ส่วนงาน | ความรับผิดชอบ |
|---|---|
| Accounting > Account Mapping | กำหนด mapping ตาม event/feature และตรวจ account ที่ active, postable, type ถูกต้อง |
| Global Settings | policy ระดับบริษัท เช่น default journal book, period rule, fallback ที่อนุญาต |
| Master data | category/item/warehouse override เฉพาะ key ที่ contract อนุญาต |
| Operational module | ส่ง source identity และเรียก posting service ไม่เลือกบัญชีเอง |

## มาตรฐาน Mapping และ Readiness

- ลงทะเบียน `event_code`, recognition point, journal book, mapping keys, dimensions/subledger, period rule และ reversal event ใน event matrix ก่อนเปิด route Post
- ใช้ typed mapping keys ที่สื่อความหมาย เช่น `ASSET_DISPOSAL_CLEARING`, `ASSET_DISPOSAL_GAIN`, `ASSET_DISPOSAL_LOSS`
- ลำดับ fallback ต้องประกาศและตรวจสอบได้: feature/accounting mapping → master-data override ที่อนุญาต → global default ที่อนุญาต
- หาก mapping ไม่ครบ ให้หยุดพร้อม readiness error ที่ระบุ key และลิงก์หน้าตั้งค่า ห้ามเดาหรือ fallback เงียบ
- บัญชีต้อง active, postable และมีประเภท/subledger ที่เข้ากันได้
- ตอน Post ให้บันทึก account IDs, mapping version, source type/event/id, idempotency key และ posting hash เป็น snapshot ใน Journal/source metadata
- Journal ต้อง balanced และผ่าน `JournalPostingService`; Posted แล้วห้ามแก้ ใช้ reversal document เท่านั้น

## Feature/Event Matrix (ร่าง)

| Feature/event | Mapping keys ขั้นต่ำ | Recognition point | Reversal |
|---|---|---|---|
| Inventory receipt/purchase | `INVENTORY_DEFAULT`, `AP_DEFAULT` | เอกสารซื้อ/รับสินค้าที่อนุมัติและ Post | reversal receipt/purchase |
| Sales issue/COGS | `COGS_DEFAULT`, `INVENTORY_DEFAULT` | Sales ที่ Post และ issue สำเร็จ | reversal sales |
| Inventory adjustment | `INVENTORY_ADJUSTMENT_GAIN` หรือ `INVENTORY_ADJUSTMENT_LOSS` | Adjustment อนุมัติและ Post | reversal adjustment |
| Inventory recost | `INVENTORY_RECOST_GAIN` หรือ `INVENTORY_RECOST_LOSS` | recost run ผ่าน reconciliation gate | reversal/delta entry |
| Asset capitalization/addition | `ASSET_COST`, `ASSET_CAPITALIZATION_CLEARING` | อนุมัติและ Post | reversal capitalization |
| Book depreciation | `ASSET_DEPRECIATION_EXPENSE`, `ASSET_ACCUMULATED_DEPRECIATION` | depreciation run อนุมัติและ Post | reversal run |
| Asset impairment | `ASSET_IMPAIRMENT_LOSS`, `ASSET_ACCUMULATED_IMPAIRMENT` | impairment document Post | reversal impairment |
| Asset disposal/sale | `ASSET_DISPOSAL_CLEARING`, `ASSET_DISPOSAL_GAIN`, `ASSET_DISPOSAL_LOSS`, `ASSET_COST`, `ASSET_ACCUMULATED_DEPRECIATION`, `ASSET_ACCUMULATED_IMPAIRMENT` | final depreciation และ proceeds พร้อม | reversal disposal |
| Asset write-off | `ASSET_DISPOSAL_LOSS`, `ASSET_COST`, `ASSET_ACCUMULATED_DEPRECIATION`, `ASSET_ACCUMULATED_IMPAIRMENT` | evidence/override และอนุมัติ | reversal write-off |
| Asset branch transfer | `ASSET_TRANSFER_CLEARING` ตาม policy | รับโอนปลายทางสำเร็จ | reversal transfer |

## UX, สิทธิ์ และการควบคุม

- หน้าตั้งค่าอยู่ที่ Accounting > Account Mapping พร้อม permission แยก view/create/update และ audit log
- หน้าเอกสารแสดง readiness ที่อ่านเข้าใจได้ และปิดปุ่ม Post เมื่อ mapping ไม่พร้อม
- แสดง mapping ที่จะใช้และ version ก่อนอนุมัติ/Post เมื่อเหมาะสม
- Seeder/default ใช้ได้เฉพาะ mock/local; production ต้องให้ผู้มีสิทธิ์ตั้งค่าและตรวจสอบกับผังบัญชีบริษัท

## ลำดับการดำเนินงานเมื่อ Owner อนุมัติ

1. สำรวจ hard-code และสรุป event/mapping ของทุก Module
2. สร้าง schema/service สำหรับ typed mapping, version, readiness และ snapshot
3. Refactor inventory/purchase/sales/finance
4. Refactor Asset depreciation/impairment/disposal/transfer
5. เพิ่ม reconciliation, reversal, audit และ permission ให้ครบ
6. ทำ Unit tests + manual QA และเปิดใช้งานทีละ Feature

## สถานะ

เอกสารแผนแยกออกจากแผน Module แล้ว และยังไม่เริ่ม implementation จนกว่า Owner จะตัดสินใจเริ่ม
