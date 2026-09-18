# Project Module — ออกแบบระบบรับงานโครงการและงานรับเหมา

> สถานะ: Design สำหรับตรวจสอบก่อน Implement
>
> เป้าหมาย: รองรับการรับงานโครงการ/งานรับเหมา ตั้งแต่รับโอกาสงาน ทำสัญญา วางแผนต้นทุน ดำเนินงาน ติดตามความก้าวหน้า วางบิล รับเงิน และวัดกำไรจริงของแต่ละโครงการ

## 1. หลักการออกแบบ

1. **Project เป็นศูนย์กลางของงานหนึ่งงาน** ไม่ใช่แค่เอกสารขายหรือใบเสนอราคา
2. **ไม่สร้าง Customer, สินค้า, ยอดขาย หรือบัญชีซ้ำ**
   - Customer ใช้ `Party`/Customer role เดียวกับ CRM และ POS
   - สินค้า/วัสดุใช้ Item เดียวกับ WMS
   - ใบซื้อ/เบิก/ขาย/รับเงินใช้เอกสารของ Purchasing, WMS, POS และ Finance ตามเจ้าของโมดูล
3. **แยก Contract Value, Budget Cost, Committed Cost, Actual Cost และ Billed Amount** ให้ชัดเจน
4. **เอกสารที่ Post แล้วแก้ทับไม่ได้** ใช้ Revision, Change Order, Credit/Adjustment หรือการยกเลิกตามกติกาของโมดูลเจ้าของเอกสาร
5. **ทุกยอดต้องผูกได้ถึง Project + Cost Code + Phase** เพื่อวิเคราะห์กำไรและต้นทุน
6. **ใช้ Branch context** และตรวจสิทธิ์ที่ Server ทุก Endpoint
7. **รองรับทั้งงานขายสินค้าเป็นส่วนประกอบและงานบริการ/ก่อสร้าง** แต่ไม่บังคับ BOQ สำหรับทุกโครงการ
8. เริ่มจากข้อมูลที่ผู้จัดการใช้ตัดสินใจจริงก่อน ยังไม่ทำระบบ Scheduling แบบ Primavera หรือ ERP ขนาดใหญ่ใน MVP

## 2. ขอบเขตธุรกิจ

### ประเภทงานที่ควรรองรับ

- งานรับเหมาก่อสร้าง/ติดตั้ง
- งานผลิตตามสั่งและส่งมอบเป็นงวด
- งานซ่อม/บำรุงรักษาแบบมีขอบเขตงาน
- งานบริการที่มีระยะเวลาและ Milestone
- งานขายพร้อมบริการติดตั้ง

### สิ่งที่ไม่รวมใน MVP

- Payroll และคำนวณค่าแรงตามกฎหมาย
- GPS/Time attendance ของช่างภาคสนาม
- BIM/CAD และ Drawing version control เชิงวิศวกรรม
- Resource leveling ขั้นสูง
- E-Tax/E-Receipt ใหม่เฉพาะ Project
- Mobile Offline
- ระบบประมูลงานภายนอกแบบ Portal
- ระบบอนุมัติใหม่แยกจาก Approval ที่มีอยู่

## 3. Lifecycle หลัก

```text
CRM Opportunity
    → Project Proposal / Estimate
    → Contract Draft
    → Contracted / Approved
    → Planning
    → In Progress
    → Progress Claim / Billing
    → Substantial Completion
    → Warranty / Retention
    → Closed
```

### สถานะ Project

| สถานะ | ความหมาย | การทำงานที่อนุญาต |
|---|---|---|
| DRAFT | กำลังเตรียมข้อมูล | แก้ไข/ลบได้ถ้ายังไม่มีเอกสารอ้างอิง |
| PROPOSED | ส่งข้อเสนอ/รอผล | แก้ไขตามสิทธิ์ แต่ยังไม่รับต้นทุนจริง |
| CONTRACTED | มีสัญญาหรือได้รับงาน | สร้าง Budget, BOQ, Milestone และเอกสารต้นทุน |
| PLANNING | เตรียมเริ่มงาน | วางแผนงานและจัดสรรผู้รับผิดชอบ |
| IN_PROGRESS | กำลังดำเนินงาน | บันทึกต้นทุน ความก้าวหน้า และปัญหา |
| ON_HOLD | พักงาน | ดูข้อมูลได้ หยุดการสร้างรายการใหม่ที่กระทบงานตามนโยบาย |
| COMPLETED | งานเสร็จ | ปิดงานหลัก เหลือ Warranty/Retention ตามเงื่อนไข |
| CLOSED | ปิดโครงการถาวร | Read-only ยกเว้นเอกสารปรับปรุงที่ได้รับอนุมัติ |
| CANCELLED | ยกเลิกโครงการ | เก็บประวัติ ห้ามสร้างรายการใหม่ |

สถานะที่เปลี่ยนต้นทุน/รายได้ต้องผ่าน Server validation, Audit Log และ Transaction lock

## 4. โครงสร้างหน้าและเมนู

### Main Menu: Project

เมนูที่ใช้ประจำควรเป็น Main Menu:

- Dashboard โครงการ
- โครงการของฉัน/โครงการในสาขา
- สร้างโครงการ
- งานที่ต้องทำ
- Progress และปัญหา

### Submenu: เอกสารและการควบคุม

- Contract และ Change Order
- BOQ / Budget
- Billing / Progress Claim
- Subcontractor
- Warranty / Retention

### Submenu: รายงาน

- Project Profitability
- Cost vs Budget
- Cash Flow / Billing
- Progress และ Overdue

### Detail Page ของ Project

เรียงเนื้อหา:

1. Header: เลขที่โครงการ ชื่อลูกค้า สถานะ ผู้จัดการโครงการ และปุ่ม Next action
2. Alert: เกินกำหนด งบเกิน ปัญหาเปิด หรือเอกสารรออนุมัติ
3. Contract Summary
4. Progress และ Milestones
5. BOQ/Budget เทียบ Actual
6. Commitments และต้นทุนค้างรับ
7. Billing/รับเงิน/Retention
8. Open Issues และ Change Orders
9. เอกสารที่เกี่ยวข้อง
10. Audit/History

## 5. Project Master

### ข้อมูลหลัก

- Project number: เลขรันตาม Document Sequence ห้าม reuse
- Project name
- Project type
- Customer จาก Party
- สาขา
- สถานที่ทำงาน/ที่อยู่หน้างาน
- Project manager
- Sales owner จาก CRM
- Contract start/end date
- Planned start/end date
- Warranty start/end date
- Currency และ Tax profile ตามบริษัท/เอกสารการเงิน
- Priority
- Status
- Description และ Internal notes

### การตรวจสอบ

- Customer ต้อง Active และมี Customer role ตอนสร้างงานใหม่
- Project ต้องอยู่ใน Branch context ปัจจุบัน
- วันที่สิ้นสุดต้องไม่น้อยกว่าวันเริ่มต้น
- Project manager ต้องเป็นผู้ใช้ Active และอยู่ในสาขา
- ห้ามลบ Project ที่มี Contract, Cost, Billing, Payment หรือเอกสารธุรกิจ
- Soft delete เฉพาะ DRAFT ที่ยังไม่มีการอ้างอิง

## 6. Contract และ Commercial

### Contract

- Contract number และวันที่
- ลูกค้า/คู่สัญญา
- Contract value ก่อนภาษี ภาษี และยอดรวม
- Payment terms
- Retention percentage/amount
- Advance percentage/amount
- Warranty terms
- Liquidated damages/ค่าปรับ หากมี
- เอกสารแนบส่วนตัวผ่าน Private Object Storage
- ผู้อนุมัติและประวัติการอนุมัติ

### Change Order

ใช้เมื่อขอบเขตงาน ราคา หรือเวลาเปลี่ยนหลัง Contracted:

- Change order number
- เหตุผล
- รายการเพิ่ม/ลด
- มูลค่าเปลี่ยนแปลง
- วันเวลาที่เปลี่ยน
- ผลกระทบต่อ Budget, Margin และ End date
- สถานะ Draft/Submitted/Approved/Rejected/Cancelled
- ต้องอนุมัติก่อนรวมใน Contract value หรือ Budget ที่ใช้งานจริง

**ห้ามแก้ Contract เดิมโดยตรงหลัง Approved**

### Commercial Summary

แสดงแยกกันอย่างน้อย:

- Original Contract Value
- Approved Change Orders
- Revised Contract Value
- Billed to Date
- Collected to Date
- Outstanding
- Retention Held
- Forecast to Complete
- Estimated Final Margin

## 7. Scope, BOQ และ Cost Code

### Work Breakdown Structure

โครงการควรแบ่งเป็น:

```text
Project
  └── Phase / Work Package
        └── BOQ Item / Cost Code
              └── Material / Labor / Subcontract / Other Cost
```

### Cost Code

MVP ใช้ Cost Code แบบกำหนดเองต่อบริษัท/ประเภทงาน โดยมี:

- Code
- Name
- Cost type: MATERIAL, LABOR, SUBCONTRACT, EQUIPMENT, OTHER
- Unit และปริมาณถ้ามี
- Active/Inactive

ไม่ควรสร้าง Cost Code เป็นบัญชี GL ใหม่ทุกครั้ง ให้เก็บ Mapping ไปบัญชี/หมวดต้นทุนเมื่อจำเป็น

### BOQ Item

- ลำดับและรหัส
- รายละเอียดงาน
- หน่วย
- ปริมาณตามสัญญา
- ราคาขายต่อหน่วย
- มูลค่าขาย
- ต้นทุนงบประมาณต่อหน่วย
- Budget cost
- Phase/Cost Code
- Progress quantity และ Progress value
- สถานะ

รองรับ Project ที่ไม่มี BOQ โดยบันทึกเป็น Lump Sum และใช้ Cost Code/Phase สำหรับต้นทุน

## 8. Budget, Commitment และ Actual Cost

### Budget

แสดงอย่างน้อย:

- Budget revenue
- Budget cost แยก Phase/Cost type
- Budget gross margin และ Margin percentage
- Approved revisions
- Baseline budget และ Current budget

Budget หลังเริ่มงานต้องแก้ผ่าน Revision หรือ Change Order ไม่แก้ตัวเลขเดิมโดยไม่มี Audit

### Commitment

ต้นทุนที่ผูกพันแล้วแต่ยังไม่เป็น Actual:

- Purchase Request/PO ที่ผูก Project
- Subcontract ที่อนุมัติแล้ว
- วัสดุหรือบริการที่สั่งแต่ยังไม่รับ

แสดง `Committed Cost` แยกจาก Actual เพื่อไม่ให้ผู้จัดการเข้าใจว่างบยังเหลือทั้งที่มีภาระผูกพันแล้ว

### Actual Cost

แหล่งข้อมูลที่ควรเชื่อม:

- WMS: เบิกวัสดุเข้า Project/Phase/Cost Code
- Purchasing: PO/รับสินค้า/ใบแจ้งหนี้ที่ผูก Project
- Finance: ค่าใช้จ่ายและเจ้าหนี้ที่ผูก Project
- Labor: บันทึกแรงงานแบบ Manual ใน MVP หรือเชื่อม HR ภายหลัง
- Equipment: ค่าเช่า/ค่าใช้เครื่องจักรแบบ Manual หรือจาก Asset ภายหลัง
- Subcontract: ใบงาน/ใบแจ้งหนี้ผู้รับเหมาช่วง

สูตรหลัก:

```text
Available Budget = Current Budget - Actual Cost - Committed Cost
Forecast Final Cost = Actual Cost + Cost to Complete
Estimated Final Margin = Revised Contract Value - Forecast Final Cost
```

ห้ามนับยอดเดียวกันจาก PO, Receipt และ Finance ซ้ำกัน ต้องกำหนดว่าแต่ละรายงานใช้สถานะใดเป็น Source of Truth

## 9. Progress และ Milestone

### Milestone

- ชื่อและลำดับ
- Planned start/end
- Actual start/end
- Weight percentage
- Owner
- Dependency แบบง่าย
- Status: OPEN, IN_PROGRESS, COMPLETED, BLOCKED
- Completion note

ผลรวม Weight ต้องเท่ากับ 100% หากใช้ Weighted Progress

### Progress Entry

- วันที่รายงาน
- Milestone/BOQ item
- ปริมาณหรือเปอร์เซ็นต์ความสำเร็จ
- Evidence/note
- ผู้บันทึก
- Approved by และ approved_at
- รูป/เอกสารหลักฐาน Private Object Storage ถ้าธุรกิจต้องการ

แยกชัดเจน:

- Physical progress = งานทำเสร็จจริง
- Billing progress = ความก้าวหน้าที่อนุมัติให้วางบิล
- Revenue recognition = การลงบัญชีตามนโยบายบัญชี

MVP ไม่ควรทำ Revenue Recognition อัตโนมัติจนกว่าจะยืนยันนโยบายบัญชีของบริษัท

## 10. Billing, Payment และ Retention

### Billing Schedule

รองรับรูปแบบ:

- มัดจำ/Advance
- งวดตาม Milestone
- งวดตามเปอร์เซ็นต์งาน
- งวดตาม BOQ quantity
- งวดสุดท้าย/หัก Retention

ข้อมูล:

- งวดที่
- Due date
- Condition
- Planned amount
- Approved claim amount
- Billed amount
- Collected amount
- Retention amount
- Status: PLANNED, CLAIMED, INVOICED, PARTIAL, PAID, OVERDUE, CANCELLED

### Boundary กับ POS/Finance

Project ทำหน้าที่เตรียม Scope, Progress และ Billing Request

- เอกสารภาษี/ใบขาย/Invoice ให้ใช้โมดูล POS หรือ Finance ที่เป็นเจ้าของเอกสาร
- Project เก็บ reference ไปยังเอกสารจริงและแสดง Document Trail
- Payment และยอดรับจริงอ่านจาก Finance
- ห้ามสร้าง Invoice ซ้ำใน Project
- การส่งต่อเอกสารต้องตรวจ Permission, Branch, Customer, Project relationship และป้องกันสร้างซ้ำ

## 11. Subcontractor

### ขอบเขต MVP

- คู่ค้า/ผู้รับเหมาช่วงจาก Supplier/Party กลาง
- Subcontract package ผูก Phase/Cost Code
- ขอบเขตงาน ปริมาณ มูลค่า และระยะเวลา
- งวดจ่ายและ Retention
- สถานะ Draft/Approved/In Progress/Completed/Cancelled
- Actual cost จากเอกสารซื้อ/Finance ที่เชื่อมโยง
- เอกสารแนบและ Audit Log

ไม่ทำระบบประเมินผู้รับเหมาขั้นสูงหรือ Portal ภายนอกใน MVP

## 12. Issues, Risks และ Site Diary

### Issue/Risk

- เลขที่
- ประเภท: SCOPE, COST, DELAY, QUALITY, SAFETY, CUSTOMER
- Severity
- รายละเอียด
- ผู้รับผิดชอบ
- Due date
- Impact ต่อ Cost/Time/Quality
- Mitigation
- Status OPEN/IN_PROGRESS/RESOLVED/CLOSED

### Site Diary แบบเบา

- วันที่/กะ
- สภาพงานและความคืบหน้า
- คน/ผู้รับเหมาช่วงที่เข้าหน้างานแบบจำนวนหรือข้อความ
- วัสดุที่รับ/ใช้
- อุปสรรค
- รูปหลักฐานถ้าจำเป็น

ไม่ทำ GPS หรือบันทึกพิกัดจนกว่าจะมีความต้องการและนโยบายข้อมูลส่วนบุคคลชัดเจน

## 13. Dashboard

### KPI ผู้จัดการโครงการ

- โครงการกำลังดำเนินการ
- มูลค่าสัญญาที่ยังไม่วางบิล
- จำนวนโครงการเกินกำหนด
- จำนวนโครงการงบเกิน
- Progress เฉลี่ยเทียบแผน
- Forecast Final Margin
- Billing overdue
- Issues ระดับ High/Critical

### Action List

- Milestone ใกล้ครบกำหนด
- Project ไม่มี Progress ล่าสุด
- Cost ใช้เกิน Budget
- PO/Commitment รอรับหรือรอเอกสาร
- Billing งวดถึงกำหนด
- Change Order รออนุมัติ
- Issue เกินกำหนด

ทุก KPI ต้อง Drill-down ไปยัง Project หรือเอกสารต้นทางได้

## 14. Reports ที่ควรมี

### MVP Reports

1. **Project Profitability**
   - Contract/Revised value
   - Billed/Collected
   - Budget/Committed/Actual
   - Forecast final cost
   - Gross margin และ Margin %
   - แยก Project/Phase/Cost type

2. **Cost vs Budget**
   - Budget, Commitment, Actual, Available
   - Variance และ Variance %
   - Top cost code ที่เกินงบ

3. **Progress & Billing**
   - Planned progress เทียบ Actual progress
   - Billing progress
   - งวดที่ถึงกำหนด/เกินกำหนด
   - Retention และ Outstanding

4. **Project Portfolio**
   - จำนวนและมูลค่าแยกสถานะ ประเภท สาขา ผู้จัดการ และลูกค้า
   - Aging ของโครงการ
   - โครงการเสี่ยง/ไม่มีความเคลื่อนไหว

### รายงานระยะถัดไป

- Subcontractor performance
- Change Order impact
- Cash flow forecast
- Material usage/variance
- Warranty claims
- Customer/project history

ทุกรายงานต้องใช้ Filter สาขา Project type ผู้จัดการ สถานะ และช่วงวันที่ตามความเหมาะสม พร้อม Drill-down และ Excel export แบบจำกัดขอบเขต

## 15. สิทธิ์และการควบคุม

Permission ที่แนะนำ:

- `projects.view`
- `projects.create`
- `projects.update`
- `projects.delete-draft`
- `projects.contract.view`
- `projects.contract.manage`
- `projects.change-orders.create`
- `projects.change-orders.approve`
- `projects.budget.view`
- `projects.budget.manage`
- `projects.progress.create`
- `projects.progress.approve`
- `projects.billing.view`
- `projects.billing.create`
- `projects.cost.view`
- `projects.reports.view`
- `projects.subcontractors.manage`
- `projects.issues.manage`
- `projects.close`

หลักการ Scope:

- ทุก Query จำกัด Branch จาก Context
- Project manager เห็น Project ที่ได้รับมอบหมายตาม Policy
- ผู้บริหารที่มีสิทธิ์ `view-all` เห็นทุก Project ใน Branch ที่อนุญาต
- การอนุมัติห้ามอนุมัติรายการที่ตนเองสร้าง หาก Policy บริษัทห้ามทำหน้าที่ซ้ำ
- การปิด Project ต้องตรวจ Open issue, Outstanding billing, Retention และ Cost ที่ยังไม่ครบตามกติกา
- Download รูป/เอกสารต้องผ่าน Authorized controller ไม่เปิด Object Storage path ตรง

## 16. Data Model ระดับแนวคิด

```text
projects
  ├── project_contracts
  ├── project_change_orders
  ├── project_phases
  │     ├── project_cost_codes / boq_items
  │     ├── project_budgets
  │     ├── project_milestones
  │     └── project_progress_entries
  ├── project_billing_schedules
  ├── project_subcontracts
  ├── project_issues
  ├── project_site_diaries
  └── project_document_links
```

ตาราง Link ที่ควรใช้แทนการแก้ตารางโมดูลอื่นโดยตรง:

- `project_source_links`: CRM Opportunity, POS document, quotation/order
- `project_cost_links`: WMS/Purchasing/Finance document และ line
- `project_billing_links`: Billing schedule กับ POS/Finance document

ทุกตารางธุรกิจควรมี Branch/Project reference ที่จำเป็น, created_by, updated_by, timestamps, Audit Log และ Soft Delete เฉพาะ entity ที่เหมาะสม

## 17. การเชื่อมต่อกับโมดูลเดิม

### CRM

- ปุ่ม `สร้าง Project จาก Opportunity` เมื่อ Opportunity ถึง Stage ที่กำหนดหรือ WON ตามนโยบาย
- Prefill Customer, Contact, Sales owner, Title และมูลค่าคาดการณ์
- เก็บ Link กลับ CRM
- ไม่เปลี่ยน Opportunity เป็น WON จาก Project; การปิดการขายจริงยังอิง POS/Finance ตามนโยบายเดิม

### WMS

- เบิกวัสดุเข้า Project/Phase/Cost Code
- รับคืนวัสดุจาก Project
- แสดง Material actual cost จาก WMS
- ตรวจ Warehouse และ Branch context
- ไม่คัดลอก Stock balance มาเก็บใน Project

### Purchasing

- ผูก PR/PO/Receipt กับ Project และ Cost Code
- คำนวณ Commitment ตามสถานะเอกสารที่กำหนด
- ไม่สร้าง PO ซ้ำใน Project

### POS/Finance

- Project Billing Request ส่งต่อเอกสารจริงไป POS/Finance
- แสดง Document Trail และยอดสถานะจากระบบเจ้าของ
- ยอดรับเงินจริงมาจาก Finance
- ไม่ทำ Tax, Promotion, Payment หรือ GL logic ซ้ำใน Project

### Asset

- ระบุ Asset/Equipment ที่ใช้ใน Projectได้ในระยะถัดไป
- ค่าเสื่อม/ต้นทุน Asset ต้องอ่านจาก Asset module ไม่คำนวณซ้ำ

## 18. MVP ที่แนะนำ

### Phase 1 — รับงานและควบคุมโครงการ

- Project master และสถานะ
- Customer/Branch/Project manager
- Contract summary
- Phase/Cost Code
- Budget baseline
- Milestone และ Progress แบบเปอร์เซ็นต์
- Issue/Risk
- Dashboard พื้นฐาน
- Audit และ Document links

### Phase 2 — ต้นทุนจริงและการวางบิล

- WMS material issue link
- Purchasing commitment link
- Finance cost link
- Billing schedule
- Progress claim
- POS/Finance handoff
- Retention/Advance
- Project Profitability และ Cost vs Budget

### Phase 3 — งานรับเหมาที่ลึกขึ้น

- BOQ quantity/value
- Change Order approval
- Subcontract package
- Site diary และ evidence
- Warranty
- Cash flow forecast
- Mobile field workflow

**เหตุผล:** Phase 1 ทำให้รู้ว่า Project มีอะไรและคืบหน้าอย่างไร; Phase 2 ทำให้รู้ว่ากำไรจริงเป็นเท่าไร; Phase 3 จึงค่อยเพิ่มความละเอียดของงานหน้างาน

## 19. Acceptance Criteria ก่อน Implement

- [ ] ยืนยันประเภท Project และ Workflow ที่ต้องใช้จริง
- [ ] ยืนยันว่า Project ต้องเริ่มจาก CRM Opportunity ได้หรือไม่
- [ ] ยืนยันว่าเอกสารขาย/Invoice ของ Project ใช้ POS, Finance หรือระบบอื่น
- [ ] ยืนยันนิยาม Actual Cost และ Commitment ของแต่ละเอกสาร
- [ ] ยืนยันว่าต้องใช้ BOQ ทุก Project หรือเฉพาะงานรับเหมา
- [ ] ยืนยันวิธีรับรอง Progress และผู้มีสิทธิ์อนุมัติ
- [ ] ยืนยัน Retention, Advance, ภาษีหัก ณ ที่จ่าย และภาษีมูลค่าเพิ่มกับฝ่ายบัญชี
- [ ] ยืนยันว่าต้องมี Subcontract ใน MVP หรือ Phase 3
- [ ] ยืนยัน Cost Code และ Mapping กับบัญชี/หมวดต้นทุน
- [ ] ยืนยันข้อมูลที่ต้องแนบรูปและระยะเวลาเก็บรักษา
- [ ] ทำตัวอย่าง Project จริง 2–3 แบบ: Lump Sum, BOQ, งานบริการเป็นงวด
- [ ] กำหนดตัวเลขที่ผู้บริหารต้องเห็นใน Dashboard และ Reports

## 20. ข้อเสนอเพื่อเริ่มงาน

ก่อนเขียน Migration หรือ Code ให้เลือก Scope ขั้นแรกดังนี้:

```text
Project Master
→ Contract Summary
→ Phase / Cost Code
→ Budget
→ Milestone / Progress
→ Issue
→ Dashboard
```

จากนั้นทดสอบกับ Project จริงหนึ่งโครงการก่อนเชื่อม WMS/Purchasing/Finance เพราะการกำหนดนิยามต้นทุนและการวางบิลผิดตั้งแต่ต้นจะทำให้รายงานกำไรทั้งระบบผิดตามไปด้วย
