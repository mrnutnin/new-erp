# CRM Reports & Analytics — Design Specification

> สถานะ: เริ่ม Implement แล้ว
>
> เป้าหมาย: รวมข้อมูลวิเคราะห์ CRM ที่มีอยู่แล้วและเพิ่มรายงานกิจกรรม โดยไม่สร้างยอดขาย/นิยามตัวเลขซ้ำกับ POS หรือ Finance

## 1. ขอบเขต

สร้างหน้าเดียวชื่อ **รายงานและวิเคราะห์** ภายใต้ CRM แบ่งเป็น 4 แท็บ:

1. Pipeline
2. ผลการขาย
3. กิจกรรมทีมขาย
4. วิเคราะห์การสูญเสีย

ห้ามสร้าง 4 เมนูหลักหรือ 4 ระบบแยกกัน ใช้หน้าและ Filter ร่วมกัน แต่โหลดข้อมูลเฉพาะแท็บที่เปิดผ่าน AJAX เพื่อลด Query ที่ไม่จำเป็น

### สิ่งที่ไม่ทำในรอบนี้

- Generic Report Builder
- PDF report
- Background report generation
- กราฟหรือ Chart dependency ใหม่
- การคำนวณยอดขายจริงซ้ำใน CRM
- การอ้างว่ากิจกรรมใด “ทำให้” ปิดการขายสำเร็จ; แสดงได้เพียงว่ากิจกรรมนั้นอยู่ใน Opportunity ที่ WON
- Custom date range ใน MVP: ใช้ **เดือนรายงาน** ให้ตรงกับ Target และโครงสร้าง Forecast เดิม

## 2. ผู้ใช้และคำถามที่หน้าต้องตอบ

### พนักงานขาย

- เดือนนี้ต้องติดตามดีลใดก่อน
- Pipeline ของตนเพียงพอต่อเป้าหมายหรือไม่
- งานใดเกินกำหนด

### หัวหน้าทีมขาย

- ทีม/คนใดมี Pipeline, Forecast หรือ Activity ผิดปกติ
- Stage ใดใช้เวลานาน
- ดีลสูญเสียด้วยเหตุผลใด

### ผู้บริหาร

- Target, Actual และ Forecast ต่างกันเท่าใด
- Conversion, Win rate และ Sales cycle เป็นอย่างไร
- จุดเสี่ยงใดต้องให้ทีมลงมือทำต่อ

## 3. โครงสร้างหน้า

เรียงจากบนลงล่าง:

1. Page header
2. Shared filter card
3. Tab navigation
4. KPI summary ของแท็บปัจจุบัน
5. รายละเอียด/การเปรียบเทียบ
6. รายการที่ต้องลงมือทำต่อพร้อม Drill-down

### Page header

- Heading: `รายงานและวิเคราะห์`
- Description: `ติดตาม Pipeline ผลการขาย กิจกรรม และสาเหตุที่สูญเสียจากข้อมูล CRM และยอดจริงจาก POS`
- ปุ่มขวาบน: `ส่งออก Excel` (`bx-download`, `btn-app-soft`)
- ปุ่ม Export ต้องระบุ Scope ของแท็บและ Filter ปัจจุบัน ไม่ส่งออกทุกแท็บโดยอัตโนมัติ

### Shared filter card

- `เดือนรายงาน` — native `<input type="month">`, ค่าเริ่มต้นเดือนปัจจุบัน
- `ทีมขาย` — เฉพาะทีมที่ผู้ใช้มีสิทธิ์
- `ผู้รับผิดชอบ` — Select2 AJAX และสัมพันธ์กับทีมที่เลือก
- ปุ่ม `ค้นหา`
- ปุ่ม `ล้างตัวกรอง` อยู่ขวาบนของ Filter card และคืนค่าเป็นเดือนปัจจุบัน/ทุกทีม/ทุกคนที่มีสิทธิ์

Filter ต้องอยู่ใน URL และรองรับ Browser Back/Forward:

```text
/crm/reports?tab=pipeline&month=YYYY-MM&team_id=1&owner_id=2
```

เมื่อสลับทีมให้ล้างผู้รับผิดชอบ เมื่อสลับแท็บให้คง Filter เดิม

### Tabs

- `Pipeline`
- `ผลการขาย`
- `กิจกรรมทีมขาย`
- `วิเคราะห์การสูญเสีย`

บนมือถือ Tabs เลื่อนแนวนอนได้ ไม่ตัดข้อความและไม่เปลี่ยนเป็น Dropdown

## 4. รายงานที่ 1 — Pipeline

### ชุดข้อมูล

Opportunity ของสาขาปัจจุบันที่ Stage ยังเปิด (`NEW`, `CONTACTED`, `QUALIFIED`, `PROPOSAL`, `NEGOTIATION`) และ `expected_close_date` อยู่ในเดือนรายงาน ตาม Team/Owner scope

### KPI

- จำนวน Opportunity เปิด
- Pipeline Value = `SUM(expected_value)`
- Weighted Forecast = `SUM(expected_value × probability / 100)`
- อายุ Pipeline เฉลี่ย (วัน)
- จำนวนดีลเสี่ยง

### เนื้อหา

1. **Pipeline ตาม Stage**
   - Stage
   - จำนวน Opportunity
   - Pipeline Value
   - Weighted Forecast
   - สัดส่วนมูลค่าต่อ Pipeline รวม
   - อายุเฉลี่ยใน Stage

2. **Pipeline Aging**
   - 0–7 วัน
   - 8–14 วัน
   - 15–30 วัน
   - มากกว่า 30 วัน
   - อายุ Stage เริ่มจากเวลาที่เปลี่ยนเข้า Stage ล่าสุดใน Audit Log; หากไม่มี Audit ให้ใช้ `created_at`

3. **Opportunity ต้องตรวจสอบ** สูงสุด 12 รายการ
   - ไม่มี Next Action
   - Next Action เกินกำหนด
   - เลย Expected Close Date
   - ไม่มีความเคลื่อนไหวเกินค่าที่กำหนด
   - แสดงชื่อดีล ลูกค้า เจ้าของ มูลค่า วันที่คาดปิด เหตุผลเสี่ยง และปุ่ม `เปิด Opportunity`

### Drill-down

คลิก Stage, Aging bucket หรือ KPI เสี่ยงแล้วเปิด Opportunity List พร้อม Filter ที่สอดคล้อง หากหน้า Opportunity ยังไม่รองรับ Filter นั้น ให้เพิ่มเฉพาะ Filter ที่จำเป็น ห้ามส่ง Parameter ที่หน้าเป้าหมายไม่ใช้

## 5. รายงานที่ 2 — ผลการขาย

### Source of Truth

- Target: `pos_branch_sales_targets`, `pos_employee_sales_targets`
- Actual: Physical Sale สถานะ `POSTED` หัก Sales Return ที่ `POSTED` ตาม logic เดิมใน `SalesForecastService::actualSales()`
- Forecast/Pipeline: CRM Opportunity
- ห้ามสร้างตารางยอดขายสรุปใหม่ใน CRM

### KPI

- เป้าหมาย
- ยอดขายจริงสุทธิ
- Weighted Forecast
- Projected Total = Actual + Weighted Forecast
- Forecast Gap = Target − Projected Total
- Pipeline Coverage = Pipeline / Target × 100

หากไม่มี Target ให้แสดง `ยังไม่กำหนด` และห้ามหารเป็น 0

### เนื้อหา

1. **Target / Actual / Forecast รายพนักงาน**
   - พนักงาน
   - จำนวน Opportunity
   - Pipeline
   - Weighted Forecast
   - Actual
   - Target
   - Projected Total
   - Gap
   - Coverage
   - ปุ่ม `ดู Opportunity`

2. **Conversion Funnel**
   - Opportunity ที่สร้างในเดือนรายงานเป็น Cohort เริ่มต้น
   - แสดงจำนวนที่ไปถึงแต่ละ Stage และ Conversion จาก Stage ก่อนหน้า

3. **Sales Performance**
   - Opportunity ใหม่
   - WON ในเดือน
   - LOST ในเดือน
   - Win Rate = WON / (WON + LOST)
   - Average Sales Cycle ของ Opportunity ที่ WON ในเดือน
   - Average Stage Duration จาก Audit Log

ต้องมีข้อความอธิบาย Cohort เพราะ Conversion กับ WON/LOST ใช้ฐานวันที่ต่างกัน

## 6. รายงานที่ 3 — กิจกรรมทีมขาย

### ชุดข้อมูล

Activity ที่ `due_at` อยู่ในเดือนรายงาน และ Opportunity อยู่ในสาขาปัจจุบัน ตาม Team/Assignee scope

### นิยาม

- Planned = Activity ที่ `due_at` อยู่ในเดือน
- Completed = Planned ที่มี `completed_at`
- Completed on time = `completed_at <= due_at`
- Completed late = `completed_at > due_at`
- Overdue = `due_at < now()` และยังไม่มี `completed_at`
- Completion Rate = Completed / Planned × 100
- WON-linked = Activity ใน Opportunity ที่มี `won_at` อยู่ในเดือนรายงาน; ใช้ข้อความว่า `กิจกรรมใน Opportunity ที่ WON` ไม่ใช้คำว่า Activity ทำให้ WON

### KPI

- Planned
- Completed
- Overdue
- Completion Rate
- Completed on time
- กิจกรรมใน Opportunity ที่ WON

### เนื้อหา

1. **กิจกรรมตามประเภท**
   - CALL, MEETING, TASK, NOTE
   - Planned, Completed, Overdue และ Completion Rate

2. **ผลงานรายพนักงาน**
   - พนักงาน
   - Planned
   - Completed
   - On time
   - Late
   - Overdue
   - Completion Rate
   - WON-linked

3. **งานเกินกำหนดที่ต้องติดตาม** สูงสุด 12 รายการ
   - Subject, Type, Due date, Assignee, Opportunity, Customer
   - ปุ่ม `เปิด Opportunity`
   - รายงานเป็น Read-only; การ Complete ให้ทำใน My Work, Calendar หรือ Opportunity เดิม

## 7. รายงานที่ 4 — วิเคราะห์การสูญเสีย

### ชุดข้อมูล

Opportunity ที่ `lost_at` อยู่ในเดือนรายงาน ตาม Branch/Team/Owner scope

### KPI

- จำนวน LOST
- Lost Value = `SUM(expected_value)`
- มูลค่าเฉลี่ยต่อ LOST
- จำนวน/สัดส่วนที่ไม่ระบุ Lost Reason

### เนื้อหา

1. **Lost Reason**
   - เหตุผล
   - จำนวน Opportunity
   - Lost Value
   - สัดส่วนต่อจำนวน LOST ทั้งหมด

2. **Stage ก่อน LOST**
   - อ่าน Stage เดิมจาก Audit Log รายการที่เปลี่ยนเป็น `LOST`
   - หากไม่มี Audit ให้แสดง `ไม่ทราบขั้นก่อนหน้า`

3. **Source ที่สูญเสีย**
   - Source
   - จำนวน Opportunity
   - Lost Value

4. **Product Interest ในดีลที่ LOST**
   - สินค้า
   - จำนวน Opportunity ที่สนใจ
   - จำนวน Product Interest
   - Budget รวม
   - ห้ามรวม `expected_value` ซ้ำต่อสินค้า เพราะหนึ่ง Opportunity มีหลายสินค้า

5. **Opportunity ที่สูญเสียมูลค่าสูงสุด** สูงสุด 12 รายการ
   - Opportunity, Customer, Owner, Lost Reason, Lost Value และปุ่ม `เปิด Opportunity`

## 8. UX States และ Accessibility

ทุกแท็บต้องมี:

- Loading state และ `aria-busy`
- Empty state ที่บอกว่าควรเปลี่ยน Filter หรือตรวจข้อมูลใด
- Error state พร้อมปุ่ม `ลองใหม่`
- Abort AJAX request เดิมเมื่อเปลี่ยน Filter/Tab
- ป้องกัน Response เก่าทับ Response ใหม่
- ข้อความและข้อมูลจาก Server ต้อง Escape ก่อนประกอบ HTML
- KPI ต้องมี Label ไม่สื่อความหมายด้วยสีอย่างเดียว
- เงินและจำนวนจัดชิดขวาในตาราง
- Wide table ต้องเลื่อนแนวนอนบนมือถือ
- ไม่มี Tooltip ที่ซ่อนคำอธิบายสำคัญ
- ปุ่ม/Tab ใช้งานด้วย Keyboard และมี Visible focus

## 9. Routes, Permission และ Scope

### Route ที่แนะนำ

```text
GET /crm/reports                         crm.reports.index
GET /crm/reports/{report}/data           crm.reports.data
GET /crm/reports/{report}/export         crm.reports.export
GET /crm/reports/owner-options           crm.reports.owner-options
```

จำกัด `{report}` เป็น `pipeline|sales|activities|losses`

รักษา `/crm/forecast` และ route name เดิมไว้เป็น Redirect ไป `crm.reports.index?tab=sales` เพื่อไม่ทำลาย Bookmark, Notification และลิงก์เดิม จากนั้นอัปเดตลิงก์ภายในให้ใช้ route ใหม่

### Permission

- Reuse `crm.forecast.view` สำหรับ Report Center ทั้งหมดในรอบนี้
- ไม่เพิ่ม Permission ใหม่จนกว่าจะมีความต้องการแยกสิทธิ์จริง
- ทุก Endpoint อยู่หลัง `auth`, `program:crm`, `branch`, `permission:crm.forecast.view`
- Reuse Team/Owner scope จาก `SalesForecastService::teams()` และ `ownerIds()` หรือย้ายเป็น shared CRM report scope helper เพียงจุดเดียว
- ผู้ใช้ที่ไม่มี `crm.team-work.view-all` ดูได้เฉพาะตนเองและทีมที่ตนอยู่
- Validate ว่า Team อยู่ในสาขาและอยู่ใน Scope ก่อน Query; `exists` อย่างเดียวไม่เพียงพอ

## 10. AJAX Contract

Response ขั้นต่ำ:

```json
{
  "summary": {},
  "sections": {},
  "actions": [],
  "meta": {
    "report": "pipeline",
    "month": "YYYY-MM",
    "truncated": false
  }
}
```

ไม่บังคับให้ทุกแท็บมี Shape ภายใน `sections` เหมือนกัน แต่ต้องคืนค่าเฉพาะข้อมูลของแท็บนั้น รายการ Action จำกัด 12 รายการ และการจัดอันดับ Top list จำกัด 8 รายการ

## 11. Excel Export

- Export เฉพาะแท็บและ Filter ปัจจุบัน
- ชื่อไฟล์: `crm-{report}-YYYY-MM-YYYYMMDD-HHmmss.xlsx`
- ใช้ Spreadsheet helper/dependency ที่ติดตั้งแล้ว ห้ามเพิ่ม Package ใหม่
- Workbook มี Sheet `Summary` และ Sheet รายละเอียดของรายงานนั้น
- ใส่ชื่อสาขา เดือน ทีม ผู้รับผิดชอบ และเวลาส่งออกในหัวรายงาน
- ใช้นิยามและ Query เดียวกับหน้าจอ ห้ามเขียนสูตรธุรกิจชุดที่สองใน Controller
- จำกัดรายละเอียดสูงสุด 10,000 แถว; หากเกินให้ Validation Error เพื่อให้ผู้ใช้กรองข้อมูลเพิ่ม
- ไม่ Export ข้อมูลติดต่อส่วนบุคคลที่รายงานไม่จำเป็นต้องใช้

## 12. แนวทางโค้ดสำหรับ Agent

### Reuse ก่อนสร้างใหม่

- `app/Modules/Crm/Controllers/ForecastController.php`
- `app/Modules/Crm/Services/SalesForecastService.php`
- `app/Modules/Crm/Services/OpportunityRiskService.php`
- `app/Modules/Crm/Views/forecast/index.blade.php`
- `app/Modules/Crm/Views/forecast/_cards.blade.php`
- AJAX/History/Select2 pattern จาก Calendar และ Forecast ปัจจุบัน
- Shared semantic classes ใน `public/css/app.css`

### โครงสร้างขั้นต่ำที่แนะนำ

- เปลี่ยน `ForecastController` เป็น Report controller หรือสร้าง `ReportController` แล้วให้ Forecast เดิม Redirect
- แยก Service ตามรายงานเฉพาะเมื่อ Query ชุดนั้นมีความรับผิดชอบชัดเจน; ห้ามสร้าง Interface/Factory สำหรับ 4 รายงาน
- Shared method สำหรับ validate Filter และ resolve Team/Owner scope
- Blade หลักหนึ่งไฟล์ และ partial เฉพาะ Section ที่ Server render จริง
- ใช้ JavaScript ชุดเดียวสลับ Tab และโหลด Endpoint ตาม `report`

## 13. Acceptance Criteria

- [ ] หน้าเดียวมี 4 Tabs ตามลำดับที่กำหนด
- [ ] เปิดหน้าเริ่มต้นที่ Pipeline และคง Tab/Filter ใน URL
- [ ] สลับ Tab แล้วไม่โหลด Query ของแท็บอื่น
- [ ] Filter เดือน/ทีม/ผู้รับผิดชอบทำงานฝั่ง Server และ Reset ครบ
- [ ] Browser Back/Forward คืน Tab และ Filter ถูกต้อง
- [ ] Pipeline metrics ตรงกับ Opportunity ชุดเดียวกัน
- [ ] Actual ตรงกับยอด POSTED สุทธิหลัง Return จาก POS
- [ ] Activity metrics ตรงตามนิยาม Planned/Completed/Overdue
- [ ] LOST metrics อิง `lost_at` และไม่คูณ Lost Value ซ้ำเมื่อมีหลาย Product Interest
- [ ] Team/Branch scope ผ่านการทดสอบ Unauthorized และ Cross-branch
- [ ] Drill-down เปิดรายการต้นทางด้วย Filter ที่ใช้งานจริง
- [ ] Excel ตรงกับหน้าจอและถูกจำกัดไม่เกิน 10,000 แถว
- [ ] Loading, Empty, Error, Retry และ AJAX race ผ่าน
- [ ] Mobile 360px, Tablet 768px และ Desktop 1440px ผ่าน
- [ ] Keyboard navigation และ accessible names ผ่าน Smoke test
- [ ] `php artisan view:cache`, CRM contract tests และ `git diff --check` ผ่าน

## 14. ลำดับ Implement

1. สร้าง Report Center shell, shared Filter, Tabs, URL state และ Redirect จาก Forecast
2. ย้ายหน้าผลการขายเดิมเข้า Tab โดยไม่เปลี่ยนนิยามตัวเลข
3. แยก Pipeline และ Loss Analysis จากข้อมูลเดิม พร้อม Drill-down
4. เพิ่ม Activity report เป็น Query ใหม่เพียงชุดเดียว
5. เพิ่ม Excel export โดย reuse Query เดียวกับหน้าจอ
6. เพิ่ม Contract/Feature tests และ Browser QA
