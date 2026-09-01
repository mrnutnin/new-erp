# Asset Phase 7 — Regression / UAT Readiness

## Automated regression

รันชุดทดสอบ Asset, Accounting handoff, Workflow และ branch scope:

```text
php artisan test --filter='Asset|WorkflowCatalog|DocumentBranchScope|AccountingReport'
```

ผลล่าสุด: **86 tests / 1,098 assertions ผ่าน**

ตรวจเพิ่มแล้ว:

- PHP lint ผ่านในไฟล์ที่แก้ไข
- Blade view cache ผ่าน
- `git diff --check` ผ่าน
- Asset disposal value event migration ทำงานสำเร็จบน local database

## Manual UAT checklist

- [ ] เลือกสาขาแล้วเห็นเฉพาะ Asset ของสาขานั้น
- [ ] เปิด Asset และ Accounting ในคนละแท็บตามข้อจำกัด context ปัจจุบัน
- [ ] Import Opening Balance → Validate → Commit และตรวจ Batch/Asset status
- [ ] ตรวจค่าเสื่อม Book/Tax และ Export
- [ ] ตรวจ Reconciliation และเปิด GL จากบรรทัดที่มีผลต่าง
- [ ] ตรวจ Disposal/Impairment และยอด GL หลัง Post/Reverse
- [ ] ตรวจแจ้งซ่อม/แผนบำรุงรักษาและรายงาน
- [ ] ตรวจ Dashboard controls และ Workflow Center
- [ ] ตรวจ DataTable filter, reset, pagination และ Excel export

Benchmark baseline: ดู [asset-phase-7-benchmark.md](asset-phase-7-benchmark.md)

สถานะ: automated regression และ manual UAT ผ่านการตรวจรับแล้ว; รายการที่ยังไม่ใช่ gate ปิด Phase 7 ให้ติดตามใน implementation plan

## User guide quick reference

- รายการสินทรัพย์: ใช้ตัวกรองและปุ่ม “นำเข้า Excel”; ประวัติ Import Batch อยู่ในหน้าดำเนินการนำเข้า
- Opening Balance: อัปโหลด → ตรวจสอบ Batch → เปิดรายละเอียด → “นำเข้าและสร้างทะเบียน” (ไม่สร้าง Journal)
- Reconciliation: เลือกงวด/บัญชี/ประเภท; ถ้ามีผลต่างให้กด “ตรวจสอบผลต่าง” เพื่อเปิด GL ใน Asset scope
- General Ledger: บัญชีเป็นตัวกรองแบบเลือกหรือไม่เลือกก็ได้; ไม่เลือกจะแสดงทุกบัญชีในขอบเขตปัจจุบัน
- Workflow Center: งานประจำวันเป็นแท็บเริ่มต้น และแยก workflow สินทรัพย์กับแจ้งซ่อม
