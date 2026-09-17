# CRM Sales Enablement Roadmap

> หลักการ UI: หน้ารายการที่เน้นการอ่านและลงมือทำ เช่น ลูกค้า งานติดตาม และ Opportunity ให้ใช้ **AJAX List Card + server-side pagination** เป็นค่าเริ่มต้น ใช้ DataTable เฉพาะงานเปรียบเทียบข้อมูลหลายคอลัมน์หรือรายงานที่ต้อง sort/export จริง

## Phase 1 — ลดงานประจำวันของพนักงานขาย

### My Work
- [x] หน้างานของฉัน: วันนี้, งานถัดไป, เกินกำหนด, เสร็จล่าสุด และยังไม่มีงานถัดไป
- [x] งานติดตามอิง `assigned_to` ส่วนโอกาสที่ยังไม่มีงานอิงผู้รับผิดชอบ Opportunity
- [x] Quick action โทรศัพท์, บันทึกผล, ทำเสร็จ และกำหนดงาน
- [x] AJAX List Card pagination เฉพาะงานของผู้ใช้และสาขาปัจจุบัน
- [x] มุมมองงานระดับทีมสำหรับหัวหน้าขาย พร้อมภาระงานรายคนและตัวกรองผู้รับผิดชอบ
- [x] กำหนดทีมขาย หัวหน้าทีม สมาชิก และจำกัด Team Work ตามทีม/สาขาฝั่ง Server
- [x] In-app Daily Digest และ notification ก่อนครบกำหนดแบบ Queue/Scheduler พร้อม idempotency
- [x] Browser Web Push แบบ VAPID พร้อม opt-in ต่ออุปกรณ์และ Service Worker

### Customer 360
- [x] หน้าสรุปลูกค้าเดียวรวมข้อมูลติดต่อ กิจกรรม Opportunity และเอกสารขายล่าสุด
- [x] AJAX Customer List Card พร้อม search และ server-side pagination
- [x] สร้าง Customer Master กลางจาก CRM พร้อมตรวจข้อมูลซ้ำและใช้ต่อใน POS ได้ทันที
- [x] Quick action โทร อีเมล และสร้าง Opportunity โดย prefill ลูกค้า
- [x] แสดงยอดขายล่าสุด 12 เดือน ยอดรับคืน ลูกหนี้คงค้าง และสถานะวงเงินเครดิตจาก POS/Finance
- [x] Document trail: Intake → RFQ → Quotation → Order → Invoice → Payment (10 เส้นทางล่าสุดต่อ Customer)
- [x] Quick action สร้างกิจกรรมจาก Customer 360 พร้อมเลือก Opportunity และผู้รับผิดชอบ

### Contact & Timeline
- [x] Activity timeline โหลดผ่าน AJAX พร้อม search/filter และ pagination ครั้งละ 8 รายการ
- [x] ผู้ติดต่อหลายคนต่อหนึ่งลูกค้า พร้อมผู้ติดต่อหลักและสถานะใช้งาน
- [x] ระบุตำแหน่ง บทบาทผู้ตัดสินใจ และช่องทางติดต่อที่สะดวก
- [x] Customer Timeline รวมกิจกรรม การเปลี่ยนสถานะ Opportunity และเอกสารขายผ่าน AJAX pagination
- [x] Quick log สำหรับติดต่อไม่ได้ ขอให้โทรกลับ ส่งข้อมูลแล้ว และติดต่อสำเร็จ พร้อมตรวจสอบก่อนบันทึก
- [x] บันทึกสถานะสิทธิ์การติดต่อ ฐานการประมวลผล และช่องทางที่อนุญาต พร้อม Audit Log (ไม่ใช้แทนการประเมิน PDPA)

## Phase 2 — ช่วยบริหาร Pipeline

### Kanban Pipeline
- [x] Kanban แยกตามขั้นตอนแบบ bounded AJAX พร้อมมุมมอง List Card เป็นทางเลือก
- [x] ลากเปลี่ยนขั้นตอนพร้อม validation, transaction lock และ Audit Log ตาม workflow
- [x] บังคับเหตุผลเมื่อ Lost และมูลค่า/วันที่ปิดการขายเมื่อ Won
- [x] กรองพนักงาน ทีม สาขาปัจจุบัน มูลค่า และเดือนที่คาดปิด
- [x] แสดงคำเตือนบน Opportunity ที่ไม่มีความเคลื่อนไหวเกิน 14 วัน

### Product Interest & POS
- [x] บันทึกสินค้า จำนวน หน่วยหลัก ราคาเป้าหมาย งบประมาณ และความต้องการของลูกค้า
- [x] แสดงราคาตาม Price List, Promotion ที่แนะนำ และ Stock พร้อมใช้รวมในสาขาแบบประมาณการจากระบบเดิม
- [x] ส่ง Product Interest ไปเตรียม Sales Intake โดยไม่กรอกซ้ำ ใช้คลังอัตโนมัติเมื่อมีสิทธิ์คลังเดียว และให้ POS คำนวณใหม่ก่อนบันทึกร่าง
- [x] อัปเดต Won อัตโนมัติเมื่อ HS/IV ลงบัญชี พร้อม Audit Log และ Notification แบบ idempotent
- [x] เปิดเอกสาร POS/Finance ที่เกี่ยวข้องจาก CRM ได้ทุกขั้นผ่าน context ที่ตรวจสิทธิ์

### Data Quality
- [x] เตือนลูกค้าซ้ำจาก Tax ID, โทรศัพท์, อีเมล และชื่อใกล้เคียงหลัง Normalize รูปแบบนิติบุคคล
- [x] Workflow ตรวจสอบ/ยกเว้น/รวมข้อมูลลูกค้าซ้ำพร้อม Preview, Transaction lock, Soft Delete และ Audit Log (บล็อกลูกค้าที่มีเอกสาร POS/Finance)
- [x] กำหนดเจ้าของลูกค้า เขตขาย ทีม และผู้แทนสำรองแยกตามสาขา พร้อมตรวจสมาชิกทีมและ Audit Log
- [x] โอนเจ้าของเมื่อพนักงานย้ายทีมหรือลาออก พร้อม Preview, scope, Transaction lock, Audit Log และแจ้งเตือนผู้รับโอน

## Phase 3 — Forecast และการบริหารทีม

### Forecast & Targets
- [x] Forecast จากมูลค่า × Probability รายคน/ทีม/สาขา/เดือน พร้อม bounded AJAX cards
- [x] Pipeline coverage เทียบเป้าหมายและยอดขายจริงที่ POSTED จาก POS
- [x] Conversion rate, Win rate, Sales cycle และระยะเวลาเฉลี่ยแต่ละขั้นตามเดือน/ทีม/ผู้รับผิดชอบ
- [x] วิเคราะห์แหล่งที่มา สินค้าที่สนใจ และเหตุผล Lost ตามเดือน/ทีม/ผู้รับผิดชอบแบบ Top 8
- [x] แจ้งดีลเสี่ยงจาก Next action, วันที่คาดปิด และ inactivity ผ่าน Forecast พร้อม In-app/Web Push รายวันแบบ idempotent

### Lead Management — เลื่อนไปหลัง MVP
- [ ] รับ Lead จากเว็บ, QR และ Import Excel
- [ ] มอบหมายตามสาขา เขต ประเภทลูกค้า หรือ workload
- [ ] SLA เวลาติดต่อครั้งแรกและ escalation
- [ ] Lead scoring แบบ rule-based
- [ ] แปลง Lead เป็น Customer/Opportunity โดยไม่สร้างข้อมูลซ้ำ

### Approval
- [ ] เชื่อม workflow อนุมัติส่วนลดและราคาต่ำกว่ามาตรฐานจาก POS
- [ ] อนุมัติเงื่อนไขพิเศษโดยไม่สร้างระบบอนุมัติซ้ำ
- [ ] แสดงสถานะและเหตุผลตีกลับใน CRM timeline

## Phase 4 — ทีมขายภาคสนามและ Integration

- [ ] Mobile visit: check-in, ผลการเข้าพบ และ follow-up
- [ ] แนบรูปการเข้าพบผ่าน private object storage
- [ ] Business card scanner พร้อมให้ผู้ใช้ตรวจยืนยันก่อนบันทึก
### Calendar & Scheduling
- [x] Calendar รายวัน/สัปดาห์/เดือนแบบ mobile-first จาก Activity และ Next action พร้อม AJAX, Filter และ Browser History
- [x] สร้าง แก้ไข เลื่อนเวลา และทำ Activity ให้เสร็จจาก Calendar โดยตรวจ branch/team scope พร้อม Mobile datetime action และ Desktop drag & drop
- [ ] Recurring activity พร้อมกำหนดสิ้นสุด ยกเว้นวัน และป้องกันสร้างงานซ้ำ — ข้ามหลัง MVP จนกว่ามีการใช้งานซ้ำจริง
- [x] Reminder บน Calendar เชื่อม In-app/Web Push และการตั้งค่ารายผู้ใช้
- [x] Filter ผู้รับผิดชอบ ทีม ประเภทกิจกรรม และสถานะ พร้อม Browser History
- [x] ส่งออก Activity ตามตัวกรองเป็น ICS แบบ One-way และเพิ่มกิจกรรมเข้า Google Calendar ผ่านหน้า TEMPLATE โดยไม่ใช้ OAuth; Background Sync รอความต้องการ Production
- [ ] Offline draft เมื่อยืนยันความจำเป็นจากทีมภาคสนาม
- [ ] Public API/Webhook สำหรับแหล่ง Lead ภายนอก

## UX Acceptance Criteria

- [x] Opportunity ใช้ AJAX List Card + server-side pagination แทน DataTable
- [ ] หน้ารายการใหม่ประเมิน List Card ก่อนเลือก DataTable
- [ ] การ์ดแสดงสถานะ เจ้าของ งานถัดไป และ primary action โดยไม่ต้องเปิดรายละเอียด
- [x] Search/filter ทำงานบน server และคงค่าเมื่อเปลี่ยนหน้า
- [ ] Empty, loading, validation และ error state เข้าใจง่าย
- [ ] ปุ่มแตะบนมือถือสูงอย่างน้อย 44px และใช้งานได้ที่ความกว้าง 360px
- [ ] ข้อมูลสำคัญและ next action ไม่พึ่ง tooltip หรือสีเพียงอย่างเดียว
- [ ] Browser QA ที่ 360, 768 และ 1440px
