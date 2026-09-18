# CRM Implementation Checklist

> เป้าหมาย: CRM ที่ใช้ข้อมูลลูกค้ากลางร่วมกับ POS, ใช้งานง่ายบนมือถือ และติดตั้ง/อัปเกรดผ่าน Installer ได้

## 1. Foundation & Installer

- [x] ลงทะเบียนโปรแกรม `crm` (เลือกสาขา แต่ยังไม่บังคับคลัง)
- [x] ลงทะเบียน `CrmServiceProvider`, routes และ namespaced views
- [x] เพิ่ม permissions สำหรับ Dashboard, Opportunity, Activity และการส่งต่อ POS
- [x] เพิ่มสิทธิ์ CRM ใน role template พนักงานขาย
- [x] เพิ่ม versioned defaults (`core.rbac`, `core.programs`, `core.role_templates`)
- [x] เพิ่ม migration แบบ reversible สำหรับ `crm_opportunities` และ `crm_activities`
- [x] เพิ่ม CRM tables/columns ใน Installer schema guard
- [x] เพิ่ม icon CRM ในหน้าเลือกโปรแกรม
- [ ] ทดสอบ clean install ผ่าน Web Installer บน MySQL จริง
- [ ] ทดสอบ System Defaults update จากฐานข้อมูลเวอร์ชันก่อน CRM

## 2. Customer & Contact 360

- [x] ใช้ `parties` และ `party_roles` เป็นข้อมูลลูกค้ากลาง ไม่สร้าง customer ซ้ำ
- [x] ค้นหาและผูกลูกค้าที่ active และมี role `CUSTOMER`
- [x] รองรับ lead ที่ยังไม่ผูก customer ด้วยชื่อผู้ติดต่อ โทรศัพท์ และอีเมล
- [x] แสดงข้อมูลลูกค้า/ผู้ติดต่อในหน้า Opportunity
- [ ] หน้า Customer 360 รวมยอดขาย เอกสารค้างชำระ กิจกรรม และ Opportunity
- [ ] ผู้ติดต่อหลายคนต่อหนึ่งลูกค้า
- [ ] Consent/PDPA และช่องทางที่อนุญาตให้ติดต่อ
- [ ] ตรวจและรวมข้อมูลลูกค้าซ้ำ (deduplication/merge)

## 3. Lead & Opportunity Pipeline

- [x] สร้าง แก้ไข ดู และลบร่าง Opportunity
- [x] Pipeline 7 ขั้น: ใหม่, ติดต่อแล้ว, คัดกรอง, เสนอขาย, เจรจา, Won, Lost
- [x] มูลค่าคาดการณ์, probability, expected close date และ weighted value
- [x] ผู้รับผิดชอบ, แหล่งที่มา, next action และ lost reason
- [x] ล็อกการแก้ไขเมื่อ Won/Lost และจำกัดการลบรายการที่มีประวัติ
- [x] Dashboard สรุปจำนวน, pipeline value, weighted value และกิจกรรมเกินกำหนด
- [x] List Card โหลดผ่าน AJAX หลังเปิดหน้า พร้อม server-side search, filter และ pagination โดยไม่พึ่ง DataTable
- [ ] Kanban drag-and-drop (เพิ่มเมื่อผู้ใช้ต้องการใช้งาน pipeline ปริมาณมากจริง)
- [ ] Lead scoring อัตโนมัติ
- [ ] Sales target/forecast รายเดือนและรายทีม

## 4. Activities & Follow-up

- [x] กิจกรรมประเภทโทรศัพท์ นัดหมาย งานติดตาม และบันทึก
- [x] กำหนดผู้รับผิดชอบและวันเวลา
- [x] ทำเครื่องหมายเสร็จ พร้อม Audit Log
- [x] อัปเดต next action จากกิจกรรมที่เร็วที่สุด
- [x] แสดงงานใกล้ถึงกำหนด/เกินกำหนดบน Dashboard
- [ ] Notification ในระบบและอีเมลก่อนครบกำหนด
- [ ] Calendar รายวัน/สัปดาห์
- [ ] Recurring activity

## 5. POS Integration

- [x] Opportunity ใช้ customer (`Party`) ชุดเดียวกับ POS
- [x] สร้าง Sales Intake จาก Opportunity โดย prefill ลูกค้า, source และรายละเอียด
- [x] บังคับ permission ทั้ง CRM conversion และ POS create
- [x] ตรวจ branch/customer และ lock transaction ก่อนเชื่อมเอกสาร
- [x] เก็บ `sales_intake_id` กลับใน Opportunity และป้องกันเชื่อมซ้ำ
- [x] เปลี่ยน pipeline เป็น Proposal หลังสร้าง Sales Intake
- [x] เปิด Sales Intake เดิมจาก CRM ได้
- [ ] แสดง document trail ต่อถึง RFQ, Quotation, Order, Invoice และ Receipt ใน CRM
- [ ] อัปเดต Won อัตโนมัติตามจุดที่ธุรกิจกำหนด (Order/Invoice/Paid)
- [ ] สร้าง Opportunity จากลูกค้า/เอกสาร POS

## 6. UX, Mobile & Accessibility

- [x] Layout และ sidebar เฉพาะ CRM ที่ใช้ shared application shell
- [x] หน้า Dashboard, list, form และ detail responsive ด้วย Bootstrap grid
- [x] Dashboard แสดงงานถัดไป KPI ที่ลงมือทำต่อได้ เมนูลัด และสถานะ Pipeline
- [x] ติดตั้ง CRM เป็น PWA shortcut บนมือถือได้โดยไม่ cache ข้อมูลธุรกิจแบบ Offline
- [x] มีเมนูคู่มือการทำงานและ Process Workflow ตามรูปแบบ shared workflow center
- [x] รายการโอกาสการขายใช้ AJAX List Card และ server-side pagination ทั้ง desktop/mobile แทน DataTable
- [x] ตัวกรองยุบ/ขยายได้ ปุ่ม action มีข้อความ และ form action ติดด้านล่างบนมือถือ
- [x] action group wrap บนจอเล็ก และตาราง desktop เลื่อนแนวนอน
- [x] ใช้ shared semantic buttons และ pastel status badges
- [x] ใช้ Select2 ค้นหาลูกค้าแบบ AJAX
- [x] ใช้ native date/datetime input และ validation ข้าง field
- [x] icon-only action มี `title`/`aria-label`; decorative icon มี `aria-hidden`
- [x] Browser QA ที่ความกว้าง 360, 768 และ 1440 px
- [ ] Keyboard-only และ screen-reader smoke test
- [ ] Empty/loading/error/offline UX test

## 7. Security, Audit & Data Quality

- [x] ทุก route อยู่หลัง `auth`, `program:crm`, `branch` และ permission middleware
- [x] ตรวจ branch scope บน show/update/delete/activity/conversion
- [x] Server-side validation ทุก write endpoint
- [x] Transaction และ row lock ใน workflow ที่เชื่อม POS
- [x] Audit Log สำหรับ Opportunity, Activity และ POS linkage
- [x] Escape ข้อมูลผู้ใช้ใน Blade และ List Card
- [ ] Feature tests สำหรับ cross-branch, unauthorized role และ concurrent conversion
- [ ] Retention/anonymization policy สำหรับข้อมูลส่วนบุคคล

## 8. Reporting & Future Extensions

> แบบหน้าจอ นิยามตัวเลข Routes และ Acceptance Criteria: [`CRM_REPORTS_DESIGN.md`](CRM_REPORTS_DESIGN.md)

- [x] Conversion funnel, Win rate, average sales cycle และ Stage duration
- [x] Lost reason, source และ Product Interest performance
- [x] Forecast เทียบ Target และ Actual ที่ POSTED จาก POS
- [ ] รวม Sales Forecast เดิมเป็นศูนย์ `รายงานและวิเคราะห์` โดยไม่สร้าง query หรือนิยามตัวเลขซ้ำ
- [ ] Activity productivity รายพนักงาน/ทีม: Planned, Completed, Overdue, Completion rate และความสัมพันธ์กับ WON
- [ ] ตัวกรองช่วงวันที่/ทีม/ผู้รับผิดชอบ, Server-side scope, Drill-down และ Excel export สำหรับรายงาน CRM
- [ ] Generic Report Builder และ PDF report — เลื่อนจนกว่าจะมี Use case ที่รายงานมาตรฐานรองรับไม่ได้
- [ ] Campaign/segment management
- [ ] Customer service case/ticket และ SLA
- [ ] Import/export leads แบบตรวจสอบข้อมูลก่อน commit
- [ ] Public API/Webhook integration

## Release Gate

- [ ] `php artisan migrate` ผ่านบน clean MySQL
- [ ] `php artisan db:seed --class=RbacSeeder` ผ่าน
- [ ] Installer schema guard ผ่าน
- [x] CRM contract tests ผ่าน
- [x] POS Sales Intake regression tests ผ่าน
- [x] `php artisan route:list --path=crm` ถูกต้อง
- [x] `php artisan view:cache` ผ่าน
- [x] `git diff --check` ผ่าน
- [ ] UAT: Lead → Opportunity → Activity → Sales Intake สำเร็จด้วย role พนักงานขาย
