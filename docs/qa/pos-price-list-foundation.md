# POS/Sale Price List Foundation QA Contract

สถานะ: foundation contract + unit test; ยังไม่มีหน้าจัดการราคาและยังไม่เปิด Sales/POS smoke test

## ขอบเขต

- `pos_price_lists` เป็นหัวรายการราคา: code, name, currency, branch, customer-group code, priority และช่วงวันที่มีผล
- `pos_price_list_items` เป็นรายการต่อสินค้า/หน่วย: minimum quantity, unit price, discount percent และช่วงวันที่มีผล
- ใช้ `wms_items` และ `wms_uoms` เป็น master เดียว ห้ามสร้างสินค้า/หน่วยซ้ำใน POS
- `customer_group_code` เป็น contract boundary ไปยัง Customer Group ที่ shared POS foundation จะเป็นเจ้าของภายหลัง

## Feature contract

- Input: price-list code/name/currency, branch scope, customer-group scope (ถ้ามี), priority, effective dates, item, UOM, minimum quantity, price และ discount
- Filter: price list, customer group, item, currency, effective date, active status; หน้าจริงต้องใช้ server-side/Yajra เมื่อข้อมูลโต
- Persisted data: header/line master ในสองตาราง, soft delete สำหรับ master ที่ยังไม่มีเอกสารอ้างอิง และ index ตาม item/UOM/effective scope
- Resolution: เอกสารขายต้องส่ง branch ปัจจุบันเข้า resolver และ resolver ต้องคืนราคาเฉพาะ Price List ของสาขานั้น; exact customer group ก่อน fallback global list; UOM ที่ระบุชนะรายการ generic; จากนั้น priority สูงกว่า, minimum quantity สูงกว่า และ id ใหม่กว่า; ต้องไม่โหลด catalog ทั้งชุด
- Snapshot: เมื่อสร้างเอกสารขาย ให้บันทึก `PriceListSnapshot::fromSelection()` ลง line snapshot field พร้อม source ids, code, currency, group, price, discount และ effective date; ออกเอกสารแล้วห้ามอ่านราคาใหม่จาก masterเพื่อคำนวณย้อนหลัง
- Priority: Promotion ที่เข้าเงื่อนไขต้อง override Price List เสมอ; Price List เป็น fallback เท่านั้น และทั้ง Promotion/Price List ที่เลือกต้องถูก snapshot ลงเอกสาร
- Recovery: การแก้ไข/ลบ master ทำได้แบบ audit + soft delete โดย snapshot ของเอกสารเดิมต้องไม่เปลี่ยน; เอกสารที่ออกแล้วใช้ reversal/CN/DN ตาม workflow ขาย

## Validation contract

- Price List ต้องมี `branch_id` ที่ผู้ใช้เข้าถึงได้; ห้ามใช้ราคา cross-branch แม้ customer group/item/UOM ตรงกัน
- `effective_to` ต้องไม่ก่อน `effective_from` ทั้งหัวและบรรทัด
- ใน Price List เดียวกัน คู่ `item + UOM + minimum quantity` ซ้ำกันได้เฉพาะเมื่อช่วงวันที่ไม่ overlap; หาก overlap ให้ reject เพราะการเลือกตาม id เป็นผลลัพธ์ที่ไม่ deterministic สำหรับผู้ใช้
- UOM ที่ระบุต้องเป็น base UOM ของสินค้า หรือมี conversion ที่ใช้ได้ในวันที่มีผล; รายการ UOM generic ใช้เป็น fallback เท่านั้น
- quantity tier ใช้ค่าขั้นต่ำที่มากที่สุดซึ่งไม่เกินจำนวนขาย และต้องใช้ความละเอียดทศนิยมตาม global quantity setting

## QA ที่ผ่านใน foundation

- Unit test ตรวจ source identity, group, price, effective date และ captured timestamp
- Migration ใช้ foreign key ไปยัง Item/UOM และมี query-path indexes
- ยังไม่ทดสอบ migration บน local หรือ browser smoke ตามขอบเขตของ sub-agent; Master Agent เป็นผู้รวม migration/seed และตรวจ route/RBAC ใน Wave ถัดไป

## Route / permission contract สำหรับ Master Agent

Controller และ views รอ route names ต่อไปนี้ โดยรอบนี้จงใจไม่แก้ shared routes หรือ RbacSeeder:

- `GET /pos/price-lists` → `pos.price-lists.index` (`pos.price-lists.view`)
- `GET /pos/price-lists/data` → `pos.price-lists.data` (`pos.price-lists.view`)
- `GET /pos/price-lists/item-options` → `pos.price-lists.item-options` (`pos.price-lists.view`)
- `GET /pos/price-lists/uom-options` → `pos.price-lists.uom-options` (`pos.price-lists.view`)
- `GET /pos/price-lists/group-options` → `pos.price-lists.group-options` (`pos.price-lists.view`)
- `GET /pos/price-lists/create` → `pos.price-lists.create` (`pos.price-lists.create`)
- `POST /pos/price-lists` → `pos.price-lists.store` (`pos.price-lists.create`)
- `GET /pos/price-lists/{priceList}/edit` → `pos.price-lists.edit` (`pos.price-lists.update`)
- `PUT /pos/price-lists/{priceList}` → `pos.price-lists.update` (`pos.price-lists.update`)
- `DELETE /pos/price-lists/{priceList}` → `pos.price-lists.destroy` (`pos.price-lists.delete`)

Admin role should receive all five permissions. Delete is SoftDelete; once Sales line price snapshots are wired, Master must add an issued-reference guard before allowing master/line removal.
