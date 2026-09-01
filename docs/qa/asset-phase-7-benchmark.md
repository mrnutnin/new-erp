# Asset Phase 7 — Local Benchmark Evidence

รันบนฐานข้อมูล local วันที่ 1 กันยายน 2026 ด้วย branch 1 และงวดล่าสุดที่มีข้อมูล:

| งาน | ข้อมูลที่อ่าน | เวลา |
|---|---:|---:|
| Asset reconciliation query | 3 rows | 4.96 ms |
| Asset register count | 3 assets | 1.77 ms |
| Period-close gate | 0 blockers | 29.57 ms |

## Representative event-volume smoke benchmark

รันซ้ำวันที่ 1 กันยายน 2026 โดยสร้าง `asset_value_events` จำลอง 5,000 รายการใน
transaction เดียว แล้ว rollback หลังวัดผล (ไม่มีข้อมูลทดสอบคงค้างในฐานข้อมูล):

| งาน | ข้อมูลที่อ่าน | เวลา |
|---|---:|---:|
| Asset reconciliation totals | 5,000 events | 34.88 ms |
| Period-close gate | 0 blockers | 17.06 ms |

ผลนี้เป็น event-volume smoke benchmark ไม่ใช่ load test เต็มรูปแบบหลายสาขา/หลายสินทรัพย์
จึงยังควรเก็บ production-like benchmark เพิ่มก่อนกำหนด SLO ถาวร

โครงสร้างรองรับข้อมูลขนาดใหญ่แล้วด้วย server-side DataTable, deferred rendering, chunked export และ index ตาม branch/date/status ตาม [AssetPerformanceReadinessTest](../../tests/Unit/AssetPerformanceReadinessTest.php)

ข้อจำกัด: ตัวเลขทั้งหมดเป็น local benchmark และยังไม่มี threshold/SLO ที่เจ้าของระบบยืนยัน
