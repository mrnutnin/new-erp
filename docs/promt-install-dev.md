# Prompt: ERP Developer Installation & Deployment

ฉันต้องการให้คุณจัดทำและปรับปรุงกระบวนการ **Developer Installation & Deployment** สำหรับระบบ ERP ที่พัฒนาด้วย Laravel

## เป้าหมาย

ทีม Developer / DevOps ต้องสามารถ Deploy ERP Environment ใหม่ได้จากเอกสารและโครงสร้างของ Project โดยไม่ต้องถาม Developer คนเดิม และส่งมอบระบบให้ทีม Implementer ในสถานะ:

- Application เปิดใช้งานได้
- `.env` ถูก Configure แล้ว
- Database ถูกสร้างและ Connection ใช้งานได้
- Database ยังเป็น Empty Database และยังไม่มี ERP Tables
- Storage / Cache / Queue / Redis / Scheduler / Mail ที่ระบบใช้ถูก Configure แล้ว
- `/setup` สามารถเปิดได้
- Setup endpoint ถูกป้องกันด้วย Installation Key / Setup Token
- ทีม Implementer สามารถรับช่วงต่อได้โดยไม่ต้อง SSH, แก้ Code หรือแก้ `.env`

หลักการแบ่งความรับผิดชอบ:

**Developer = Deploy & Infrastructure**  
**Implementer = ERP Initialization & Customer Configuration**

---

## 1. Analyze Existing Project First

ก่อนเขียนหรือแก้ไขอะไร ให้สำรวจ Existing Laravel Project ก่อน โดยตรวจสอบ:

- PHP / Laravel version
- `composer.json`
- `package.json`
- `.env.example`
- config files
- service providers
- middleware
- routes
- migrations
- seeders
- queue configuration
- cache configuration
- session configuration
- Redis
- mail
- filesystem / storage
- scheduler
- deployment files
- Docker / Kubernetes / Supervisor / systemd หากมี
- CI/CD หากมี
- Existing installation/setup logic หากมี

ห้ามเดา Version, Command หรือ Infrastructure หากสามารถตรวจสอบจาก Project ได้

ห้ามสร้างระบบซ้ำกับของเดิมโดยไม่จำเป็น

---

## 2. Create `INSTALLATION.md`

สร้างหรือปรับปรุงไฟล์:

`INSTALLATION.md`

สำหรับ Developer / DevOps โดยเฉพาะ

เอกสารต้องสามารถพา Developer ตั้งแต่ Source Code จนถึงสถานะ Ready for Implementer ได้

อย่างน้อยต้องมี:

1. System Requirements
2. Source Code Setup
3. Environment Configuration
4. Database Creation
5. Application Key
6. Storage / File Permissions
7. Cache / Session
8. Redis
9. Queue Worker
10. Scheduler / Cron
11. Mail
12. Web Server
13. HTTPS
14. Production Optimization
15. Setup Security
16. Health Check
17. Handover Checklist
18. Troubleshooting

ใช้ค่าจริงจาก Existing Project

---

## 3. Environment Setup

อธิบายการสร้าง `.env` จาก `.env.example` และ Environment Variable ที่ Project ใช้จริง

จัดกลุ่มอย่างน้อย:

### Application

- APP_NAME
- APP_ENV
- APP_KEY
- APP_DEBUG
- APP_URL

### Database

- DB_CONNECTION
- DB_HOST
- DB_PORT
- DB_DATABASE
- DB_USERNAME
- DB_PASSWORD

### Cache / Session

- CACHE_STORE
- SESSION_DRIVER

### Queue

- QUEUE_CONNECTION

### Redis

- REDIS_HOST
- REDIS_PORT
- REDIS_PASSWORD หากใช้

### Mail

Environment Variable ที่ Existing Project ใช้จริง

รวมถึง Environment Variable เฉพาะของ ERP ที่ตรวจพบใน Project

ห้ามใส่ Secret จริงลง Git

---

## 4. Empty Database Is the Handover Starting Point

Developer มีหน้าที่:

1. สร้าง Empty Database
2. Configure Database Connection
3. ตรวจสอบว่า Application เชื่อม Database ได้

ใน Installation ปกติ Developer **ไม่ต้อง Run**:

```bash
php artisan migrate
php artisan db:seed
```

เพราะ Migration และ ERP Default Data จะถูก Initialize ผ่าน `/setup` โดยทีม Implementer

สถานะที่ต้องการ:

```text
Application Running
        ↓
Environment Ready
        ↓
Empty Database Connected
        ↓
/setup Accessible
        ↓
Ready for Implementer
```

---

## 5. Critical: Application Must Work With Zero Tables

Database เริ่มต้นไม่มี Table เลย

ตรวจสอบว่า Request ไปยัง `/setup` ก่อน Migration ไม่พึ่งพา Database Tables เช่น:

- users
- sessions
- cache
- jobs
- settings
- permissions
- installation state

ระวัง Configuration เช่น:

```env
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

หากทำให้ `/setup` Query Table ที่ยังไม่มี ต้องออกแบบ Pre-Installation Mode ที่เหมาะสม

อาจใช้ File-based Session / Cache ก่อน Initialization หรือ Bootstrap Logic ที่ไม่พึ่ง Database

ห้ามแก้ด้วยการบังคับให้ Developer Run Migration ก่อนเปิด `/setup`

---

## 6. Setup Endpoint Security

`/setup` ต้องไม่เปิดให้ Public Initialize ERP ได้โดยไม่มีการป้องกัน

ออกแบบ Setup Token / Installation Key ที่ไม่พึ่ง Database ก่อน Migration

ตัวอย่าง Environment Configuration:

```env
ERP_SETUP_ENABLED=true
ERP_SETUP_TOKEN=<secure-random-token>
```

ข้อกำหนด:

- Token ต้องสุ่มอย่างปลอดภัย
- ห้ามมี Default Token เช่น admin / 123456 / secret
- ห้าม Commit Token ลง Repository
- Setup endpoint ต้อง Validate Token ก่อนทำ Critical Action
- หลัง Go Live Initial Setup ต้องถูก Disable หรือ Lock
- ต้องมีวิธี Recovery สำหรับ Developer ที่ปลอดภัยและมี Documentation

---

## 7. Application Key

หาก Existing Project ต้องใช้ ให้ระบุขั้นตอน:

```bash
php artisan key:generate
```

ตรวจสอบก่อนว่า APP_KEY พร้อมใช้งานก่อน Handover

---

## 8. Storage & Permissions

ตรวจสอบและ Document:

- `storage/`
- `bootstrap/cache/`
- `storage:link` หากระบบใช้

ห้ามใช้ `chmod -R 777` เป็น Default Solution

ระบุ Ownership / Permission ที่เหมาะสมกับ Deployment Environment จริง

---

## 9. Queue

ตรวจสอบว่า ERP ใช้ Queue หรือไม่และใช้ Driver ใด

Document วิธี Run Worker ตาม Existing Infrastructure เช่น:

- Supervisor
- systemd
- Docker
- Kubernetes

ห้ามเพิ่ม Infrastructure ใหม่โดยไม่มีเหตุผล

---

## 10. Scheduler

ตรวจสอบ Laravel Scheduler และ Scheduled Jobs ที่ Project ใช้

Document วิธี Configure Scheduler ตาม Deployment Environment จริง

หากใช้ Cron แบบมาตรฐาน อาจเป็น:

```cron
* * * * * php /path/to/artisan schedule:run
```

แต่ต้องปรับตาม Existing Project

---

## 11. Redis / Cache / Session

ตรวจสอบ Dependency และ Configuration จริง

ต้องแยกให้ชัดเจนระหว่าง:

- Pre-Installation
- Normal Production หลัง Initialize Database

หากหลัง Go Live ต้องเปลี่ยน Driver หรือ Cache Strategy ให้ Document ขั้นตอนอย่างชัดเจน และพยายามออกแบบให้ไม่ต้องแก้ Code

---

## 12. Web Server

Document ตาม Infrastructure ที่ Project ใช้จริง เช่น Nginx / Apache / Container

Laravel Document Root ต้องชี้ไป `public/`

ตรวจสอบ:

- Rewrite
- HTTPS
- Upload size
- Timeout ที่จำเป็น
- Proxy headers หากอยู่หลัง Load Balancer / Reverse Proxy

---

## 13. Production Configuration

ก่อน Handover ตรวจสอบอย่างน้อย:

```text
APP_ENV=production
APP_DEBUG=false
```

รวมถึง Production Cache Commands ที่ Project รองรับ เช่น:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

ห้าม Run Command ที่ Existing Project ไม่รองรับโดยไม่ตรวจสอบก่อน

---

## 14. Health Check

สร้าง Developer Deployment Checklist หรือ Health Check ที่ตรวจสอบ:

```text
[ ] Application URL works
[ ] HTTPS works
[ ] APP_ENV=production
[ ] APP_DEBUG=false
[ ] APP_KEY configured
[ ] Database exists
[ ] Database connection works
[ ] Database is ready for first initialization
[ ] Storage writable
[ ] Storage link ready if required
[ ] Cache works
[ ] Redis works if required
[ ] Queue worker works if required
[ ] Scheduler works
[ ] Mail works if required
[ ] Setup token generated
[ ] /setup accessible
[ ] /setup protected
[ ] No ERP default data manually created
[ ] Ready for Implementer
```

---

## 15. Handover Information

Developer ต้องส่งต่อให้ Implementer อย่างน้อย:

- Application URL
- Setup URL
- Setup Token / Installation Key ผ่านช่องทางที่ปลอดภัย
- Customer / Environment identifier หากจำเป็น

ตัวอย่าง:

```text
Application:
https://erp.customer.example

Setup:
https://erp.customer.example/setup
```

ห้ามใส่ Credential จริงใน `INSTALLATION.md`

---

## 16. Separation of Responsibility

### Developer รับผิดชอบ

- Deploy Source Code
- Install Dependencies
- Configure `.env`
- Generate APP_KEY
- Create Database
- Configure Database Connection
- Storage
- Cache
- Redis
- Queue
- Scheduler
- Mail
- Web Server
- HTTPS
- Setup Security
- ตรวจสอบ `/setup`

### Developer ไม่ควรทำใน Normal Installation

- Seed Roles
- Seed Permissions
- Seed Document Numbers
- Seed ERP Master Defaults
- Create Company
- Create Branch
- Create Warehouse
- Create ERP Administrator
- Configure Accounting
- Configure Inventory
- Configure Customer-specific Master Data

สิ่งเหล่านี้เป็นหน้าที่ของ ERP Setup Wizard

---

## 17. Troubleshooting

`INSTALLATION.md` ต้องมี Troubleshooting อย่างน้อย:

### `/setup` เปิดแล้ว 500 ก่อน Migration

ตรวจสอบ:

- Laravel log
- Session driver
- Cache driver
- Middleware
- Service Provider
- Database-backed configuration ที่ถูก Query ก่อน Migration

### Database Connection Failed

ตรวจสอบ DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD และ Network / Firewall

### Permission Denied

ตรวจสอบ `storage/` และ `bootstrap/cache/`

### Setup Token Invalid

ตรวจสอบ Environment Configuration และ Config Cache

### Queue / Scheduler ไม่ทำงาน

ระบุวิธีตรวจสอบตาม Infrastructure จริง

---

## 18. Security Checklist

```text
[ ] APP_ENV=production
[ ] APP_DEBUG=false
[ ] HTTPS enabled
[ ] Secure setup token
[ ] Setup token not committed to Git
[ ] Database not publicly exposed unless explicitly required
[ ] .env cannot be downloaded
[ ] Laravel logs not publicly accessible
[ ] Correct storage permissions
[ ] Setup endpoint protected
[ ] Setup endpoint can be locked after Go Live
```

---

## 19. Do Not Over-engineer

เป้าหมายของงานนี้ไม่ใช่สร้าง Deployment Platform ใหม่

ให้ใช้ Existing Infrastructure และ Existing Project Architecture ก่อน

แก้เฉพาะสิ่งที่จำเป็นเพื่อให้:

**Dev สามารถ Deploy ERP และส่งต่อ Empty Database + Working `/setup` ให้ Implementer ได้อย่าง Repeatable และปลอดภัย**

---

## 20. Definition of Done

งานฝั่ง Developer ถือว่าเสร็จเมื่อสามารถทำ Flow นี้ได้:

```text
Clone / Deploy Source
        ↓
Install Dependencies
        ↓
Configure Environment
        ↓
Create Empty Database
        ↓
Configure Infrastructure Services
        ↓
Start Application
        ↓
Verify Database Connection
        ↓
Verify /setup
        ↓
HANDOVER TO IMPLEMENTER
```

โดยไม่ต้อง Initialize ERP Business Data ด้วยตัวเอง

หลังจาก Handover ทีม Implementer จะเป็นผู้ดำเนินการ Migration, Default Data, Company Configuration และ Go Live ผ่าน Web Setup Wizard
