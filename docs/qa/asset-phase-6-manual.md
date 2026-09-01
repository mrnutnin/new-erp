# Asset Phase 6 — Manual QA Evidence

วันที่ตรวจรับ: 1 กันยายน 2026  
เขตเวลา: Asia/Bangkok (UTC+7)

## Scenarios

| Scenario | Result |
|---|---|
| สร้างเอกสาร Impairment และตรวจ carrying value | ผ่าน |
| ป้องกัน impairment ทำให้มูลค่าติดลบหรือสูงกว่ามูลค่าตามบัญชี | ผ่าน |
| ตรวจบัญชีขาดทุน/ด้อยค่าสะสมของหมวดก่อน Post | ผ่าน |
| อนุมัติ, ยกเลิก และ Post Impairment | ผ่าน |
| สร้าง Disposal แบบ Sale พร้อม proceeds และเอกสารอ้างอิง | ผ่าน |
| สร้าง Write-off พร้อมหลักฐาน/เหตุผล override | ผ่าน |
| บังคับ final depreciation และงวดบัญชีเปิดก่อนอนุมัติ/Post | ผ่าน |
| คำนวณ derecognition, gain/loss และใช้ disposal clearing | ผ่าน |
| ป้องกันเอกสารจำหน่ายซ้ำและ downstream reference ที่ยังค้าง | ผ่าน |
| Post แล้วเปลี่ยนเป็นสถานะ terminal และแก้ด้วย reversal เท่านั้น | ผ่าน |
| ตรวจลำดับปุ่มและสีสถานะตาม UX guideline | ผ่าน |

## Acceptance

- Owner ยืนยันการอนุมัติและลงบัญชี Disposal สำเร็จ
- Owner ยืนยันการลงบัญชี Impairment สำเร็จหลังตั้งค่าบัญชีของหมวดครบ
- ไม่พบ blocker ที่เปิดให้ Post โดยไม่มี mapping/prerequisite ที่จำเป็น

## Automated evidence

```text
php artisan test tests/Unit/AssetImpairmentContractTest.php tests/Unit/AssetDisposalContractTest.php --testdox
6 tests passed, 57 assertions
```

Phase 6 gate ผ่าน และพร้อมเข้าสู่ Phase 7
