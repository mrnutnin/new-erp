# New ERP

ระบบ ERP แบบ modular monolith สำหรับหนึ่งบริษัทต่อ installation พัฒนาด้วย PHP 8.2, Laravel 12, MySQL, Blade, Bootstrap 5, jQuery/AJAX และ Yajra DataTables

## เอกสารสำคัญ

- [CHECKLIST.md](CHECKLIST.md) — สถานะงานที่เสร็จแล้ว กำลังทำ และรอดำเนินการ
- [PLANING.md](PLANING.md) — แผนและ fixed decisions ของผลิตภัณฑ์
- [SKILL.md](SKILL.md) — กติกาที่ Agent และทีมพัฒนาต้องปฏิบัติ
- [Foundation manual QA](docs/qa/foundation-manual.md) — ขั้นตอนตรวจ Foundation แบบทำซ้ำได้

## Local setup

ต้องมี PHP 8.2, Composer 2 และ MySQL โดยสร้างฐานข้อมูลชื่อ `new_erp` ก่อน

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8010
```

ระบบไม่ใช้ npm, Vite หรือ frontend server เพิ่มเติม Frontend libraries อยู่ใน `public/vendor` และ include จาก root layout

## Local development login

- Username: `admin`
- Password: `123132123`

บัญชี seed นี้ใช้สำหรับ local development เท่านั้น ต้องเปลี่ยนรหัสผ่านก่อนนำไปใช้ใน environment จริง

## Verification

```bash
vendor/bin/pint --test
php artisan test --testsuite=Unit
php artisan route:list --except-vendor
php artisan view:cache
composer validate --strict
```

Automated tests ของโครงการใช้เฉพาะ Unit Tests ตามนโยบายเจ้าของระบบ ส่วน route, database, permission, UI, queue, GCS และ end-to-end flow ตรวจด้วย manual QA checklist
