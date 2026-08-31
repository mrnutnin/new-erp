# Finance Advance/Deposit MySQL Readiness

อัปเดตล่าสุด: 23 สิงหาคม 2026

## ขอบเขต

- ตรวจเฉพาะ source event, Journal linkage, idempotency และ rollback ของ Customer Advance และ Supplier Advance
- ใช้ local MySQL `new_erp` เท่านั้น
- ไม่ seed ข้อมูลถาวร และไม่เปิด Inventory posting flags

## ผลตรวจ

รันคำสั่ง:

```text
ERP_RUN_MYSQL_INTEGRATION=1 DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=new_erp DB_USERNAME=root DB_PASSWORD='' php vendor/bin/phpunit tests/Feature/AdvanceDepositMySqlIntegrationReadinessTest.php
```

ผลลัพธ์: 1 test / 30 assertions ผ่าน

ครอบคลุม:

- Customer Receipt → Customer Advance
- Supplier Payment → Supplier Advance
- source Journal event และ warehouse scope
- Journal balance และสองบรรทัดบัญชี
- retry ไม่สร้าง Advance หรือ Journal ซ้ำ
- Advance application → Journal
- application retry แบบ idempotent
- application reversal → reversal Journal
- outer transaction rollback ทำให้จำนวนข้อมูลถาวรกลับเท่าเดิม

## Production source-action contract

- Settlement `APPROVED` แสดง action สร้าง Advance/Deposit ได้เมื่อยังไม่มี allocation/intents; action จะสร้าง Journal และเปลี่ยน source เป็น `POSTED` ภายใน transaction เดียว
- Settlement `POSTED` แสดง action materialize/retry ได้เมื่อ source Journal ตรงกับ contract และยังไม่มี Advance ที่ active; retry ต้องคืนรายการเดิมแบบ idempotent
- `customer_payment`/`supplier_payment` เป็น source event ของ Settlement เท่านั้น ไม่เปิดทางให้ผู้ใช้เปลี่ยนเอกสารรับ/จ่ายทั่วไปเป็น Advance โดยไม่มี instrument และ party direction ที่ตรงกัน
- หากมี allocation/intents หรือ source identity ไม่ตรง ต้อง fail closed พร้อมข้อความและแนวทางแก้ ไม่แสดง action ที่ทำให้ผู้ใช้เข้าใจว่าย้อนแก้ Posted Journal ได้

## Persistent DB read-only check

หลัง rollback บน `new_erp`:

- `finance_advance_deposits = 0`
- `finance_advance_deposit_applications = 0`
- duplicate active advance idempotency keys = 0
- duplicate `FINANCE` source Journal identity = 0
- `erp.inventory.purchase_posting_enabled = false`
- `erp.inventory.adjustment_posting_enabled = false`

ข้อมูล Mock Settlement เดิมมี source event `customer_payment` และ `supplier_payment` ถูกต้องตามเอกสาร และไม่พบ duplicate Journal

## ข้อจำกัดการปล่อยใช้งาน

ผลตรวจนี้เป็น integration/readiness evidence เท่านั้น ยังไม่ถือเป็น owner UI sign-off หรือ production operational sign-off. Advance/Deposit ยังต้องรอ policy, UI sign-off และการตัดสินใจเปิดใช้งานก่อนนำไปใช้จริง
