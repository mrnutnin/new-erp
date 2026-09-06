# New ERP — Developer Installation & Handover

เอกสารนี้เป็นคู่มือสำหรับ Developer/DevOps เท่านั้น เป้าหมายคือส่งมอบ Laravel application ที่เชื่อมต่อกับ **ฐานข้อมูลว่าง** และเปิด `/setup` ให้ทีม Implementer ดำเนินการติดตั้ง ERP ผ่าน Web UI ต่อไป

ห้ามสร้าง Company, Branch, Warehouse, User, Role, Permission, Document Number หรือ ERP master data ด้วยการเข้า database, รัน SQL, `php artisan migrate` หรือ `php artisan db:seed` ในขั้นตอน handover ปกติ เมื่อ Installer Web UI พร้อมใช้งานแล้ว การเตรียม database และ ERP defaults จะทำผ่าน `/setup` ตาม implementation plan ใน `docs/planning/installer-implementation-plan.md`

## 1. Requirements ที่ตรวจพบจาก Project

- PHP `^8.2` (local evidence: PHP 8.2.28)
- Laravel `^12.0` (local evidence: Laravel 12.67.0)
- MySQL เป็นค่าเริ่มต้นของ `.env.example`
- Composer packages: Laravel, Laravel Tinker, maatwebsite/excel, mpdf, yajra/laravel-datatables-oracle
- Application document root ต้องชี้ไปที่ `public/`
- Application timezone ใน `config/app.php` ปัจจุบันเป็น `UTC`; ให้ผู้ดูแลระบบกำหนดค่าที่เหมาะสมก่อน production (ค่า ERP สำหรับประเทศไทยควรเป็น `Asia/Bangkok`)

## 2. Source Code และ Dependencies

```bash
git clone <repository-url>
cd new-erp
composer install --no-dev --optimize-autoloader
```

ห้ามใช้ Composer script ที่มีชื่อ `setup` หรือ `post-create-project-cmd` เป็น handover procedure จนกว่า script จะถูกปรับให้ไม่ migrate database อัตโนมัติ การ migrate และการติดตั้ง ERP defaults เป็นความรับผิดชอบของ Web Installer หลัง handover

## 3. Environment

สร้าง `.env` จาก `.env.example` และใส่ค่าของ environment จริงโดยไม่ commit secret:

```bash
cp .env.example .env
php artisan key:generate
```

ตัวแปรที่ Project ใช้จริง:

| กลุ่ม | ตัวแปร |
|---|---|
| Application | `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_LOCALE`, `APP_FALLBACK_LOCALE` |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, optional `DB_SOCKET`, `DB_URL` |
| Session | `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` |
| Cache | `CACHE_STORE`, optional `CACHE_PREFIX`, `DB_CACHE_CONNECTION`, `DB_CACHE_TABLE` |
| Queue | `QUEUE_CONNECTION`, optional `DB_QUEUE_*`, `REDIS_QUEUE_*` |
| Redis | `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, optional `REDIS_URL`, `REDIS_*_DB` |
| Mail | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` |
| Files | `FILESYSTEM_DISK`, `PRIVATE_FILESYSTEM_DISK`, AWS/S3 variables when used |

Installer security variablesที่จะเพิ่มตาม plan:

```dotenv
ERP_SETUP_ENABLED=true
ERP_SETUP_TOKEN=<random-secret-generated-outside-git>
```

ห้ามใช้ token ตัวอย่างนี้จริง และห้าม commit `.env`

## 4. Empty Database Handover

Developer ต้องสร้าง database และ database user ตาม policy ของ environment แล้วกรอก connection ใน `.env` แต่ไม่ต้องสร้าง ERP tables และไม่ต้อง seed ERP data

สถานะ handover ที่ถูกต้อง:

```text
Source deployed
  -> .env configured
  -> APP_KEY ready
  -> empty database reachable
  -> storage/cache/queue infrastructure ready
  -> /setup reachable and token-protected
  -> hand over to Implementer
```

ห้ามตั้ง `SESSION_DRIVER=database`, `CACHE_STORE=database` หรือ database queue ในช่วงที่ `/setup` ยังต้องทำงานกับฐานข้อมูล 0 tables เว้นแต่ Installer pre-install runtime จะถูก implement และตรวจแล้วว่าใช้ fallback ที่ไม่ query tables ได้ ระบบปัจจุบันตั้งค่าเหล่านี้เป็น database driver จึงเป็น gap ที่ต้องแก้ใน Phase 1 ของ plan

## 5. Storage, Cache, Queue และ Scheduler

```bash
php artisan storage:link
```

ตรวจสอบให้ web user เขียนได้เฉพาะ `storage/` และ `bootstrap/cache/` ตาม ownership ของ deployment environment ห้ามใช้ `chmod -R 777`

`.env.example` ปัจจุบันใช้ database queue ดังนั้น production ต้องมี queue worker ที่เหมาะกับ infrastructure จริง หรือเปลี่ยน driver ที่รองรับก่อนเปิด feature ที่ใช้ jobs เช่น inventory recost:

```bash
php artisan queue:work --tries=3
```

Scheduler ให้ผูก `php artisan schedule:run` ทุกนาทีด้วย cron/service ของ environment หลังตรวจสอบ `routes/console.php` และ scheduled jobs รอบ release นั้นแล้ว

Redis และ mail เป็น optional ตาม `.env` ปัจจุบัน (Redis มี config แต่ default queue/cache/session ยังเป็น database; mail default เป็น `log`) ต้องทำ health check ตามค่าที่เลือกใช้จริง ไม่ประกาศว่าใช้งานได้เพียงเพราะมี config

## 6. Web Server และ Production

- Document root: `<project>/public`
- เปิด HTTPS และส่ง proxy headers ให้ถูกต้องเมื่ออยู่หลัง load balancer
- ตั้ง upload size/timeout ให้รองรับ Excel/PDF ที่ระบบใช้
- ปิด `.env`, `storage/logs` และ source files จาก public access
- Production ใช้ `APP_ENV=production`, `APP_DEBUG=false`
- รัน config/route/view cache เฉพาะ command ที่ตรวจแล้วว่ารองรับ release นี้

## 7. Health และ Handover Checklist

- [ ] Application URL เปิดได้
- [ ] HTTPS ใช้งานได้ (ถ้าเป็น production)
- [ ] `APP_ENV` และ `APP_DEBUG` ถูกต้อง
- [ ] `APP_KEY` พร้อม
- [ ] Database ว่างและเชื่อมต่อได้
- [ ] ไม่ได้สร้าง ERP tables/data ด้วย manual deployment
- [ ] `storage/` และ `bootstrap/cache/` เขียนได้
- [ ] cache/session strategy สำหรับ pre-install พร้อม
- [ ] queue/Redis พร้อมเมื่อเลือกใช้
- [ ] scheduler พร้อมเมื่อเลือกใช้
- [ ] mail พร้อมเมื่อเลือกใช้
- [ ] `ERP_SETUP_TOKEN` สุ่มและไม่อยู่ใน Git
- [ ] `/setup` เปิดได้
- [ ] `/setup` ไม่สามารถทำ critical action โดยไม่มี token
- [ ] ส่ง Application URL, Setup URL และ token ผ่านช่องทางปลอดภัย

## 8. Troubleshooting

### `/setup` ขึ้น 500 ก่อน migration

ตรวจ Laravel log, session/cache driver, middleware และ service provider ที่ query application tables ก่อนติดตั้ง ห้ามแก้ด้วยการ migrate manually; ใช้ pre-install fallback ตาม plan

### Database connection failed

ตรวจ `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, network และ firewall

### Permission denied

ตรวจ ownership/permission ของ `storage/` และ `bootstrap/cache/` สำหรับ web user

### Setup token invalid

ตรวจ environment/config cache และส่ง token ผ่านช่องทางปลอดภัย ห้ามสร้าง default token

### Queue/Scheduler ไม่ทำงาน

ตรวจ worker process, cron/service, queue connection และ failed jobs ตาม infrastructure ที่ใช้งานจริง

## 9. Security

- [ ] `APP_DEBUG=false`
- [ ] HTTPS enabled
- [ ] Setup token random, secret, not committed
- [ ] Database ไม่เปิด public โดยไม่จำเป็น
- [ ] `.env` และ logs ไม่ถูก serve
- [ ] Setup endpoint protected
- [ ] Setup endpoint lock ได้หลัง Go Live
- [ ] Dangerous reset actions จำกัด Super Admin และมี audit log
