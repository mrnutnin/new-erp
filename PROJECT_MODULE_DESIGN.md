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
3. **แยก Contract Value, Budget Cost, Committed Cost, Actual Cost และ Billed Amount** ให้ชัดเจนเมื่อเริ่มเชื่อมการเงินใน Phase 2
4. **เอกสารที่ Post แล้วแก้ทับไม่ได้** ใช้ Revision, Change Order, Credit/Adjustment หรือการยกเลิกตามกติกาของโมดูลเจ้าของเอกสาร
5. **ยอดต้นทุนใน Phase 2 ต้องผูกได้ถึง Project + Cost Code + Phase**; MVP ใช้ BOQ Lite/Cost Code เพื่อวางแผนเท่านั้น
6. **ใช้ Branch context** และตรวจสิทธิ์ที่ Server ทุก Endpoint
7. **รองรับทั้งงานขายสินค้าเป็นส่วนประกอบและงานบริการ/ก่อสร้าง** โดยเมื่อเปิดใช้ Project Module แล้ว ทุก Project ต้องมี BOQ อย่างน้อย 1 รายการ
8. Project เป็น **Optional Module ของบริษัท** ไม่ใช่ส่วนบังคับของ ERP ทุกบริษัท
9. เริ่มจากข้อมูลที่ผู้จัดการใช้ตัดสินใจจริงก่อน ยังไม่ทำระบบ Scheduling แบบ Primavera หรือ ERP ขนาดใหญ่ใน MVP
10. **ออกแบบ Small Contractor First**: บริษัทที่มีพนักงานไม่ถึง 10 คนต้องเริ่มใช้งานได้โดยไม่ต้องมีฝ่าย Project, Cost Control หรือ Accountant แยกกัน
11. ฟอร์ม MVP ต้องกรอกเฉพาะข้อมูลที่จำเป็น ช่องขั้นสูงซ่อนอยู่ และมีค่าเริ่มต้นจาก Context/ผู้ใช้ปัจจุบัน
12. หนึ่งคนอาจทำหลายหน้าที่ได้ จึงไม่บังคับแยกบทบาทหรือขั้นอนุมัติหลายชั้นใน MVP
13. งานที่ไม่เกี่ยวกับ Project โดยตรงต้องไม่ปรากฏในเมนู MVP

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

### การเป็น Optional Module ของ ERP

Project เป็นโปรแกรมเสริมที่บริษัทเลือกเปิดใช้เอง ไม่ใช่เมนูหรือความสามารถที่ทุกบริษัทต้องเห็น

- บริษัทที่ไม่ได้เปิดใช้: ไม่แสดงโปรแกรม `Project`, Sidebar, Dashboard, Notification และเมนูที่เกี่ยวข้อง
- Route และ API ต้องถูกป้องกันด้วย Program/Capability check ฝั่ง Server ไม่ใช่ซ่อนด้วย UI อย่างเดียว
- การติดตั้ง ERP ต้องยังผ่านได้โดยไม่เปิดใช้ Project แต่ Migration และ Schema guard ต้องรองรับการติดตั้งแบบ Optional
- เมื่อเปิดใช้แล้ว ทุก Project ใหม่ต้องมี BOQ อย่างน้อย 1 รายการ
- ห้ามปิดใช้ Module หากมี Project ที่ยังเปิดอยู่ เว้นแต่เลือกโหมด Read-only และมีผู้มีสิทธิ์ยืนยัน
- การเปิดใช้/ปิดใช้ต้องบันทึก Audit Log และไม่กระทบ Customer, CRM, WMS, Purchasing, POS หรือ Finance ของบริษัทที่ไม่ได้ใช้ Project
- การเชื่อม CRM/WMS/Purchasing/POS/Finance เป็นความสามารถระยะถัดไปและต้องตรวจว่า Module ปลายทางเปิดใช้อยู่ก่อน

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

MVP ใช้สถานะเพียง `DRAFT`, `ACTIVE`, `ON_HOLD`, `COMPLETED`, `CANCELLED` เพื่อให้ผู้ใช้เข้าใจง่าย สถานะ PROPOSED, CONTRACTED, PLANNING, IN_PROGRESS และ CLOSED เป็นสถานะระยะถัดไปหรือใช้เป็นรายละเอียดภายในภายหลัง

สถานะที่เปลี่ยนความรับผิดชอบหรือข้อมูลสำคัญต้องผ่าน Server validation, Audit Log และ Transaction lock

## 4. โครงสร้างหน้าและเมนู

### Main Menu: Project — MVP

เมนูหลักที่ใช้ทุกวันมีเพียง:

- Dashboard
- โครงการ
- สร้างโครงการ
- งาน/ปัญหาที่ต้องติดตาม

### ภายในหน้าโครงการ

ใช้ Tab หรือ Section ในหน้า Detail แทนการแตกเมนู:

- ภาพรวม
- สัญญาและ BOQ Lite
- Progress
- หลักประกัน
- ปัญหา
- เอกสารและประวัติ

### เมนูระยะถัดไป

ค่อยแยกเมนูเมื่อข้อมูลมากพอ:

- Billing/Progress Claim
- Cost Control
- Change Order
- Subcontractor
- Warranty
- รายงานเชิงลึก

MVP ไม่ควรมี Sidebar หลายชั้นหรือบังคับให้ผู้ใช้รู้ศัพท์ Project Management ก่อนเริ่มงาน

### Detail Page ของ Project — MVP

เรียงเนื้อหา:

1. Header: เลขที่โครงการ ชื่อลูกค้า สถานะ ผู้รับผิดชอบ และปุ่ม Next action
2. Alert: ใกล้ครบกำหนด Progress ค้าง ปัญหาเปิด หรือหลักประกันใกล้หมดอายุ
3. Contract Summary และ BOQ Lite (ต้องมีอย่างน้อย 1 รายการ)
4. Progress ล่าสุดและวันสิ้นสุดตามแผน
5. หลักประกัน/เงินค้ำประกัน
6. งานหรือปัญหาที่ต้องติดตาม
7. เอกสารแนบและ Audit/History

Cost, Commitment, Billing, Payment และ Margin แสดงใน Detail ต่อเมื่อเชื่อมโมดูลเดิมใน Phase 2

## 5. Project Master

### ข้อมูลหลัก

- Project number: เลขรันตาม Document Sequence ห้าม reuse
- Project name
- Project type
- Customer จาก Party
- สาขา
- สถานที่ทำงาน/ที่อยู่หน้างาน
- Project manager — ค่าเริ่มต้นเป็นผู้สร้าง และไม่บังคับแยกจากผู้รับผิดชอบ
- Sales owner จาก CRM — ไม่บังคับ ใช้เฉพาะเมื่อสร้างจาก Opportunity
- Contract start/end date
- Planned start/end date
- Warranty start/end date
- Currency และ Tax profile ตามบริษัท/เอกสารการเงิน
- Priority
- Status
- Description และ Internal notes

**ฟอร์ม MVP แบ่งเป็น:**

- จำเป็น: Project name, Customer, Branch, ผู้รับผิดชอบ, วันที่เริ่ม/สิ้นสุดตามแผน และสถานะ
- ไม่บังคับ: Project type, Site address, Sales owner, Contract dates, Warranty dates, Priority, Currency, Tax profile และ Notes
- ค่าเริ่มต้น: Branch จาก Context, ผู้รับผิดชอบเป็นผู้สร้าง และสถานะ `DRAFT`

### การตรวจสอบ

- Customer ต้อง Active และมี Customer role ตอนสร้างงานใหม่
- Project ต้องอยู่ใน Branch context ปัจจุบัน
- วันที่สิ้นสุดต้องไม่น้อยกว่าวันเริ่มต้น
- ผู้รับผิดชอบต้องเป็นผู้ใช้ Active และอยู่ในสาขา หากไม่ระบุให้ใช้ผู้สร้าง
- ห้ามลบ Project ที่มี Contract, Cost, Billing, Payment หรือเอกสารธุรกิจ
- Soft delete เฉพาะ DRAFT ที่ยังไม่มีการอ้างอิง

## 6. Contract และ Commercial

### Contract — MVP แบบสรุป

ข้อมูลที่ต้องกรอก:

- Contract number และวันที่
- ลูกค้า/คู่สัญญา
- Contract value และหมายเหตุภาษี (ยังไม่คำนวณ Tax ใน Project)
- Payment terms แบบข้อความ
- Retention percentage/amount
- Advance percentage/amount
- Warranty terms แบบข้อความ
- เอกสารแนบส่วนตัวผ่าน Private Object Storage

ผู้อนุมัติและประวัติใช้ Audit Log เดิม ไม่ทำ Approval หลายชั้นใน MVP

### Contract ระยะถัดไป

- Liquidated damages/ค่าปรับ
- Payment schedule แบบคำนวณ
- Tax calculation
- Contract revision และ Approval workflow

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

### Commercial Summary — ระยะถัดไปหลังเชื่อม Finance

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

### BOQ Lite — MVP

รายการแบบง่ายสำหรับ Project ที่คิดราคาตามงาน/ปริมาณ:

- ลำดับ
- รายละเอียดงาน
- หน่วย
- ปริมาณตามสัญญา
- ราคาต่อหน่วย
- มูลค่างาน
- ต้นทุนงบประมาณ
- Phase/Cost Code
- ปริมาณทำจริงและ Progress แบบพื้นฐาน

BOQ Lite เป็นข้อบังคับสำหรับทุก Project แต่ Project แบบ Lump Sum ใช้ BOQ เพียง 1 รายการได้ และยังไม่มี Revision, Change Order หรือ Progress Claim

### BOQ เต็มรูปแบบ — Phase 3

- รหัส BOQ และโครงสร้าง WBS หลายระดับ
- Rate/ต้นทุนหลายชั้น
- Progress quantity และ Progress value ที่ผ่านการวัด/รับรอง
- Revision และ Change Order
- สถานะและประวัติการแก้ไข

Project แบบ Lump Sum ใช้ BOQ รายการเดียวชื่อ `งานเหมารวม` ได้ โดยไม่ต้องแตกเป็นหลายรายการ

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
- Labor: เชื่อม Finance หรือ HR ใน Phase 2/3
- Equipment: เชื่อม Finance หรือ Asset ใน Phase 3
- Subcontract: เชื่อม Purchasing/Finance ใน Phase 3

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

## 10. Billing, Payment และ Retention — Phase 2

> ไม่อยู่ใน MVP ยกเว้นการเก็บเงื่อนไข Advance/Retention ใน Contract และทะเบียนหลักประกันแบบติดตามสถานะ

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

### หลักประกันและเงินที่เกี่ยวข้อง

Project ต้องมี **ทะเบียนติดตาม** ตั้งแต่ MVP แต่ยังไม่ทำบัญชีรับ-คืนเงินเอง โดยรองรับ:

- `ADVANCE` — เงินมัดจำ/เงินล่วงหน้า
- `RETENTION` — เงินประกันผลงานที่หักจากค่างวด
- `PERFORMANCE_BOND` — หลักประกันสัญญา
- `BID_BOND` — หลักประกันซอง (ถ้ามีตั้งแต่ช่วงเสนอราคา)
- `ADVANCE_GUARANTEE` — หลักประกันเงินมัดจำ
- `WARRANTY_BOND` — หลักประกันช่วงรับประกัน
- `CASH_SECURITY_DEPOSIT` — เงินประกันเป็นเงินสด
- `BANK_GUARANTEE` — หนังสือค้ำประกันธนาคาร

ข้อมูลขั้นต่ำ:

- ประเภทและผู้วางหลักประกัน
- จำนวนเงินหรือเปอร์เซ็นต์
- เลขที่เอกสาร/เลขที่ Bank Guarantee และธนาคาร
- วันที่เริ่มต้น/วันหมดอายุ
- จำนวนที่ถือไว้ คืนแล้ว เคลมแล้ว หรือคงเหลือ
- สถานะ `ACTIVE`, `PARTIAL_RETURNED`, `RETURNED`, `CLAIMED`, `EXPIRED`, `CANCELLED`
- เงื่อนไขการคืน/การเคลม และเอกสารแนบ

MVP แสดงยอดและแจ้งเตือนวันหมดอายุเท่านั้น การรับเงิน คืนเงิน บันทึกหนี้สิน และการเคลมต้องส่งต่อ Finance ใน Phase 2/3 ห้าม Project Post GL เอง

### Boundary กับ POS/Finance

Project ทำหน้าที่เตรียม Scope, Progress และ Billing Request

- เอกสารภาษี/ใบขาย/Invoice ให้ใช้โมดูล POS หรือ Finance ที่เป็นเจ้าของเอกสาร
- Project เก็บ reference ไปยังเอกสารจริงและแสดง Document Trail
- Payment และยอดรับจริงอ่านจาก Finance
- ห้ามสร้าง Invoice ซ้ำใน Project
- การส่งต่อเอกสารต้องตรวจ Permission, Branch, Customer, Project relationship และป้องกันสร้างซ้ำ

## 11. Subcontractor

### ขอบเขตระยะถัดไป

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

### KPI ผู้จัดการโครงการ — MVP

- จำนวน Project แยกตามสถานะ
- Project ที่กำลังดำเนินการ
- Project ที่ใกล้/เลยวันสิ้นสุด
- Progress ล่าสุดเทียบแผน
- Milestone ที่ใกล้ครบกำหนด
- Issue ระดับ High/Critical
- หลักประกันหรือ Bank Guarantee ใกล้หมดอายุ

### Action List — MVP

- Milestone ใกล้ครบกำหนด
- Project ไม่มี Progress ล่าสุด
- Issue เกินกำหนด
- Contract/หลักประกันใกล้หมดอายุ
- Change ที่รอการบันทึกหรืออนุมัติ

Cost overrun, Commitment, Billing overdue และ Forecast Final Margin ให้แสดงเมื่อมี Integration กับ WMS/Purchasing/Finance ใน Phase 2

ทุก KPI ต้อง Drill-down ไปยัง Project หรือเอกสารต้นทางได้

## 14. Reports ที่ควรมี

### MVP Report เดียว

**Project Control**

- Project number, name, customer, manager และสถานะ
- Contract value และวันเริ่ม/สิ้นสุด
- Progress ล่าสุดและ Milestone ถัดไป
- Issue เปิด/เกินกำหนด
- หลักประกันแต่ละประเภท ยอดคงเหลือ และวันหมดอายุ
- ตัวกรอง Branch, ประเภท, สถานะ, ผู้จัดการ และช่วงวันที่
- Drill-down ไปยัง Project และเอกสารต้นทาง
- Export Excel แบบ bounded

ยังไม่สรุป Actual Cost, Commitment, Billed, Collected หรือ Margin ใน MVP เพราะต้องรอ Source of Truth จาก WMS/Purchasing/Finance

### รายงานระยะถัดไป

1. **Project Profitability** — Contract, Billed, Collected, Budget, Commitment, Actual, Forecast Final Cost และ Margin
2. **Cost vs Budget** — Budget, Commitment, Actual, Available และ Variance
3. **Progress & Billing** — Planned/Actual Progress, Billing, Retention และ Outstanding
4. **Project Portfolio** — จำนวน/มูลค่าแยกสถานะ ประเภท สาขา ผู้จัดการ ลูกค้า และ Aging

- Subcontractor performance
- Change Order impact
- Cash flow forecast
- Material usage/variance
- Warranty claims
- Customer/project history

ทุกรายงานต้องใช้ Filter สาขา Project type ผู้จัดการ สถานะ และช่วงวันที่ตามความเหมาะสม พร้อม Drill-down และ Excel export แบบจำกัดขอบเขต

## 15. สิทธิ์และการควบคุม

Permission ที่จำเป็นใน MVP:

- `projects.view`
- `projects.create`
- `projects.update`
- `projects.delete-draft`
- `projects.contract.view`
- `projects.contract.manage`
- `projects.progress.view`
- `projects.progress.create`
- `projects.issues.manage`
- `projects.guarantees.view`
- `projects.guarantees.manage`
- `projects.reports.view`
- `projects.close`

### รูปแบบบทบาทสำหรับบริษัทเล็ก

MVP ไม่ต้องบังคับให้สร้าง Role ใหม่หลายแบบ ใช้สิทธิ์ตามหน้าที่แบบง่าย:

- **ผู้ดูแล/เจ้าของกิจการ**: ดูและแก้ทุก Project ใน Branch, ปิด Project และจัดการหลักประกัน
- **ผู้ทำงานโครงการ**: สร้าง/แก้ Project ที่รับผิดชอบ, บันทึก Progress และ Issue
- **ผู้ทำบัญชี/การเงิน**: ดู Contract และหลักประกัน; เข้ามารับช่วง Finance ใน Phase 2

ผู้ใช้หนึ่งคนมีได้หลายหน้าที่ และค่าเริ่มต้นควรใช้ Role Template เดิมเท่าที่ทำได้

Permission ระยะถัดไป:

- `projects.change-orders.create`
- `projects.change-orders.approve`
- `projects.budget.view`
- `projects.budget.manage`
- `projects.progress.approve`
- `projects.billing.view`
- `projects.billing.create`
- `projects.cost.view`
- `projects.subcontractors.manage`

หลักการ Scope:

- ทุก Query จำกัด Branch จาก Context
- Project manager เห็น Project ที่ได้รับมอบหมายตาม Policy
- ผู้บริหารที่มีสิทธิ์ `view-all` เห็นทุก Project ใน Branch ที่อนุญาต
- การอนุมัติห้ามอนุมัติรายการที่ตนเองสร้าง หาก Policy บริษัทห้ามทำหน้าที่ซ้ำ
- MVP ปิด Project ได้เมื่อไม่มี Issue ระดับ Critical เปิดอยู่ และมีการบันทึก Progress/เอกสารที่จำเป็นครบ
- Outstanding billing, Retention และ Cost ที่ยังไม่ครบ ให้เป็นเงื่อนไขการปิดใน Phase 2 หลังเชื่อม Finance
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

### ตารางที่จำเป็นสำหรับ MVP เท่านั้น

- `projects`
- `project_boq_items` — BOQ Lite ที่ต้องมีอย่างน้อย 1 รายการต่อ Project
- `project_progress_entries`
- `project_issues`
- `project_guarantees`
- `project_attachments` หรือใช้รูปแบบ Attachment ที่มีอยู่

`project_contracts`, `project_change_orders`, `project_budgets`, `project_billing_schedules`, `project_subcontracts` และ Link tables เชิง Integration ให้เพิ่มเมื่อ Phase ที่เกี่ยวข้องเริ่มจริง

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

## 18. MVP ที่จำเป็นจริง

### MVP — Project Control ขั้นพื้นฐาน

เป้าหมายคือให้บริษัทรับงานและควบคุมโครงการได้ โดยยังไม่แก้โครงสร้าง WMS/Purchasing/POS/Finance:

1. **Project Master**
   - Project number, name, type, customer, branch และ manager
   - วันเริ่ม/สิ้นสุด สถานะ และรายละเอียด
   - สร้างจาก CRM Opportunity ได้ แต่ไม่บังคับ

2. **Contract Summary**
   - Contract number, มูลค่าสัญญา, เงื่อนไขชำระ, Warranty และเอกสารแนบ
   - แก้ไขได้เฉพาะ Draft; Approved แล้วใช้ Revision/Change ในอนาคต

3. **Phase / Cost Code และ BOQ Lite — จำเป็นทุก Project**
   - ทุก Project ต้องมี BOQ อย่างน้อย 1 รายการก่อนบันทึกใช้งาน
   - Project แบบ Lump Sum ใช้รายการ BOQ ชื่อ `งานเหมารวม` เพียง 1 รายการได้
   - BOQ Lite มีรายการงาน หน่วย ปริมาณ ราคาต่อหน่วย มูลค่างาน และต้นทุนงบประมาณ
   - แต่ละรายการผูก Phase/Cost Code ได้ โดย Phase/Cost Code ไม่ต้องกรอกถ้าไม่จำเป็น
   - ยังไม่เชื่อมต้นทุนจริงจาก WMS/Purchasing/Finance

4. **Milestone และ Progress**
   - Planned/Actual date, Weight และเปอร์เซ็นต์ความก้าวหน้า
   - บันทึก Progress ระดับ Milestone หรือ BOQ Lite ได้
   - Progress ของ BOQ Lite ใช้ปริมาณทำจริงเทียบปริมาณตามสัญญาแบบพื้นฐาน
   - บันทึก Progress พร้อมผู้บันทึกและ Audit Log
   - ยังไม่ทำ Progress Claim หรือ Revenue Recognition

5. **Issue/Risk**
   - รายละเอียด ผู้รับผิดชอบ Due date Severity และสถานะ
   - Dashboard แสดงรายการที่ต้องติดตาม

6. **ทะเบียนหลักประกันและเงินค้ำประกัน**
   - Advance, Retention, Performance Bond, Advance Guarantee, Warranty Bond, Cash Deposit และ Bank Guarantee
   - ติดตามยอด วันหมดอายุ สถานะ และเอกสาร
   - ยังไม่รับ/คืนเงินและไม่ Post GL ใน Project

7. **Dashboard และ Project Control Report**
   - แสดงสถานะ Progress, Milestone, BOQ Lite, Issue และหลักประกัน
   - แสดงมูลค่า BOQ และ Progress ตามรายการแบบพื้นฐาน
   - Filter Branch/Status/Manager/ช่วงวันที่ และ Export แบบ bounded

8. **พื้นฐานระบบ**
   - Permission, Branch scope, Audit Log, Soft Delete เฉพาะ Draft, Private Object Storage และ Installer schema guard

### Phase 2 — Integration ที่จำเป็นเมื่อเริ่มคุมต้นทุนและวางบิล

- WMS material issue link
- Purchasing commitment link
- Finance actual cost link
- Billing Schedule และ Billing Request
- POS/Finance handoff
- รับ/คืน Advance, Retention และ Security Deposit ผ่าน Finance
- Progress Claim และ Cost vs Budget
- Project Profitability

### Phase 3 — งานรับเหมาระดับลึก

- BOQ เต็มรูปแบบ: Revision, Rate แบบหลายชั้น และการวัดปริมาณหน้างาน
- Change Order approval เต็มรูปแบบ
- Subcontract package
- Site Diary และ evidence
- Warranty claims
- Cash flow forecast
- GL Dimension และ Revenue Recognition ตามนโยบายบัญชี
- Mobile field workflow

### สิ่งที่ตั้งใจไม่ทำใน MVP

- ไม่มี Actual Cost, Commitment, Invoice, Payment หรือ GL Posting
- ไม่มี Progress Claim หรือ Revenue Recognition
- ไม่มี BOQ เต็มรูปแบบที่มี Revision, Rate แบบหลายชั้น หรือการวัดปริมาณหน้างานละเอียด
- ไม่มี Change Order approval เต็มรูปแบบ
- ไม่มี Subcontractor workflow
- ไม่มี Site Diary/GPS/Offline mobile
- ไม่มี Project Profitability หรือ Margin ที่อ้างว่าเป็นยอดจริง
- ไม่มีการรับ/คืนเงินหรือเคลมหลักประกันใน Project; มีเพียงทะเบียนติดตามและวันหมดอายุ

**เหตุผล:** MVP ต้องตอบให้ได้เพียงว่า “รับงานอะไร อยู่ขั้นไหน ใครรับผิดชอบ คืบหน้าเท่าไร มีปัญหาอะไร และหลักประกันใดต้องติดตาม” ส่วนกำไรจริงและการวางบิลค่อยทำหลังนิยาม Source of Truth กับ Finance/WMS/Purchasing ชัดเจน

### เกณฑ์ความง่ายสำหรับ MVP

- สร้าง Project ใหม่ได้ในหน้าเดียว ไม่เกิน 8 ช่องที่จำเป็น
- สร้าง Project ได้โดยไม่ต้องสร้าง BOQ; BOQ Lite เป็นปุ่ม/Section ทางเลือก
- บันทึก Progress ได้ด้วยเปอร์เซ็นต์เดียว โดยไม่ต้องสร้าง Milestone ก็ได้
- เพิ่ม Issue หรือหลักประกันจากหน้า Project เดียว ไม่ต้องเปิดเมนูหลายชั้น
- ผู้รับผิดชอบเริ่มต้นเป็นผู้สร้าง ไม่ต้องตั้งทีมก่อนใช้งาน
- ผู้ใช้บริษัทเล็กหนึ่งคนทำได้หลายหน้าที่ด้วย Permission ชุดเล็ก
- หน้าหลักตอบได้ทันทีว่า Project ไหนต้องลงมือทำต่อ
- ใช้งานบนมือถือได้ และปุ่มสำคัญมีขนาดแตะง่าย

## 19. Acceptance Criteria ก่อน Implement

- [ ] ยืนยันประเภท Project และ Workflow ที่ต้องใช้จริง
- [ ] ยืนยันว่า Project ต้องเริ่มจาก CRM Opportunity ได้หรือไม่
- [ ] ยืนยันว่าเอกสารขาย/Invoice ของ Project ใช้ POS, Finance หรือระบบอื่น
- [ ] ยืนยันนิยาม Actual Cost และ Commitment ของแต่ละเอกสาร
- [x] MVP บังคับ BOQ Lite ทุก Project; Project Lump Sum ใช้รายการ `งานเหมารวม` รายการเดียวได้
- [ ] ยืนยันว่ารายการ BOQ Lite ต้องใช้วางบิลใน Phase 2 หรือไม่
- [ ] ยืนยันวิธีรับรอง Progress และผู้มีสิทธิ์อนุมัติ
- [ ] ยืนยัน Retention, Advance, ภาษีหัก ณ ที่จ่าย และภาษีมูลค่าเพิ่มกับฝ่ายบัญชี
- [ ] ยืนยันว่าต้องมี Subcontract ใน MVP หรือ Phase 3
- [ ] ยืนยันประเภทหลักประกันที่ใช้จริง: Advance, Retention, Bond, Guarantee และ Cash Deposit
- [ ] ยืนยันว่า MVP ต้องเพียงติดตามหลักประกัน หรือให้ Finance รับ/คืนเงินได้ทันที
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

## 21. Impact Analysis กับโมดูลเดิม

### กระทบน้อย — ใช้โมดูลเดิมเป็นเจ้าของข้อมูล

| โมดูล | ผลกระทบ | แนวทาง |
|---|---:|---|
| CRM | ต่ำ | เพิ่มปุ่มสร้าง Project จาก Opportunity และเก็บ Link กลับ โดยไม่ย้าย Customer หรือเปลี่ยน Opportunity lifecycle |
| Party/Customer | ต่ำ | ใช้ `Party` และ Customer role เดิมผ่าน `projects.party_id` ไม่สร้าง Customer Master ใหม่ |
| Audit Log | ต่ำ | ใช้ Audit Logger เดิมกับ Status, Contract, Budget, Progress, Billing และ Close |
| File Storage | ต่ำ | ใช้ `FileStorageService` เดิม กำหนด module folder เป็น `project` และส่งไฟล์ผ่าน Authorized Controller |
| Asset | ต่ำใน MVP | ระยะแรกบันทึก Equipment Cost ผ่าน Finance; ค่อยเชื่อม Asset/ค่าเสื่อมใน Phase 3 |

### กระทบ Platform และ Installer

**ระดับปานกลาง — ต้องทำใน Phase 1**

ต้องเพิ่ม:

- โปรแกรม `project`
- Service Provider, Routes และ Namespaced Views
- Branch/Program middleware
- Sidebar และ Workflow Catalog
- Permissions และ Role Template
- Installer schema guard และ versioned defaults
- `ModuleCapability` หากบริษัทต้องการเปิด/ปิด Project จาก Settings
- Document Sequence สำหรับเลข Project, Contract, Change Order และ Billing Request

ทุก Route ต้องตรวจ Branch context และ Permission ฝั่ง Server ไม่พึ่งการซ่อนเมนูจาก UI

### กระทบ WMS

**ระดับสูง — Phase 2**

การเบิกวัสดุต้องระบุ:

```text
Project → Phase → Cost Code
```

ทางเลือกที่ต้องตัดสินใจก่อน Implement:

1. เพิ่ม Nullable `project_id`, `project_phase_id`, `project_cost_code_id` ในรายการต้นทุนของ WMS — Query และ Foreign Key ชัดเจน แต่ต้องแก้ Form/Request/Service หลายจุด
2. ใช้ `project_cost_links` กลาง — ลดการแก้ WMS แต่ Query และการป้องกัน Link ซ้ำซับซ้อนกว่า

ข้อเสนอ: ใช้คอลัมน์ตรงในรายการต้นทุนหลัก และใช้ Link table เฉพาะเอกสารย้อนหลังหรือเอกสารที่แก้โครงสร้างไม่ได้

### กระทบ Purchasing

**ระดับสูง — Phase 2**

ต้องรองรับ PR/PO/Receipt ที่ผูก Project, Phase และ Cost Code พร้อมคำนวณ Commitment

นิยามเบื้องต้น:

```text
Commitment Cost = Approved PO ที่ยังไม่กลายเป็น Actual Cost
Actual Cost = Receipt หรือ Finance ตามนโยบายที่บริษัทเลือก
```

ต้องป้องกันการนับต้นทุนซ้ำระหว่าง PO, Receipt และ Invoice และต้องเลือกว่าจะผูกข้อมูลที่ Header หรือ Line โดยแนะนำให้ผูกระดับ Line

### กระทบ Finance

**ระดับสูง — Phase 2**

ค่าใช้จ่าย ค่าแรง ค่าเช่า และต้นทุนหน้างานต้องผูกกับ Project/Phase/Cost Code ที่ระดับ Finance line หากเอกสารหนึ่งใบมีหลาย Project หรือหลาย Cost Code

Project ไม่สร้างระบบลงบัญชีใหม่ แต่เก็บ Reference ไปยังรายการ Finance จริง

### กระทบ POS และ Billing

**ระดับปานกลางถึงสูง — Phase 2**

Project ทำหน้าที่สร้าง Billing Schedule และ Billing Request เท่านั้น ส่วน POS/Finance ยังคงเป็นเจ้าของ:

- Invoice/เอกสารภาษี
- Tax calculation
- Payment
- Document posting

ต้องตรวจว่า POS รองรับ Service Item และการวางบิลแบบ Lump Sum/งวดงานหรือไม่ หากไม่รองรับ ให้ Finance เป็นเจ้าของ Project Invoice แทน ห้ามให้ Project คำนวณ Tax หรือสร้าง Invoice ซ้ำ

### กระทบ Accounting/GL

**ระดับสูง — Phase 2/3**

Project ไม่ Post GL เอง ให้เอกสารจาก WMS, Purchasing หรือ Finance Post ตามระบบเดิม แล้วเพิ่ม Dimension:

```text
Project / Phase / Cost Code
```

ต้องยืนยันกับฝ่ายบัญชีก่อนทำจริง:

- Project เป็น Cost Center หรือไม่
- Dimension อยู่ระดับ Journal Line หรือไม่
- วิธีรับรู้รายได้และต้นทุน
- การตัด Advance และ Retention
- นโยบาย Percentage of Completion

ห้ามทำ Revenue Recognition อัตโนมัติใน MVP จนกว่านโยบายบัญชีจะชัดเจน

## 22. ลำดับลดผลกระทบต่อระบบเดิม

### Phase 1 — Project Standalone

ไม่แก้ WMS, Purchasing, POS หรือ Finance โดยตรง:

```text
Project Master
→ Contract Summary
→ Phase / Cost Code
→ Budget
→ Milestone / Progress
→ Issue / Risk
→ Dashboard
```

### Phase 2 — Transaction Integration

ค่อยเพิ่มจุดเชื่อมที่จำเป็น:

```text
WMS Material Cost
Purchasing Commitment
Finance Expense
Billing Request
POS/Finance Handoff
Retention
```

### Phase 3 — Contractor และ Accounting เชิงลึก

```text
BOQ Quantity
Change Order
Subcontractor
Site Diary
Warranty
GL Dimension
Revenue Recognition
```

## 23. Decisions ที่ต้องปิดก่อน Implement Phase 2

- [ ] ต้นทุน Actual ของแต่ละเอกสารมาจาก Receipt, Invoice หรือ Finance
- [ ] Commitment ใช้ยอด PO แบบใด และหัก Receipt อย่างไร
- [ ] จะเก็บ Project/Phase/Cost Code ที่ Source line หรือ Link table
- [ ] POS รองรับ Service Item และ Billing แบบงวดงานหรือไม่
- [ ] ใครเป็นเจ้าของ Invoice และ Tax ของ Project
- [ ] Project/Phase/Cost Code จะเป็น Accounting Dimension หรือไม่
- [ ] วิธีคำนวณ Advance, Retention และภาษีหัก ณ ที่จ่าย
- [ ] วิธีรับรู้รายได้ตามนโยบายบัญชี
- [ ] BOQ จำเป็นสำหรับทุก Project หรือเฉพาะงานรับเหมา

**สรุปผลกระทบ:** Phase 1 ทำได้โดยกระทบโมดูลเดิมน้อยที่สุด ส่วนการวัดกำไรจริงจะกระทบ WMS, Purchasing, Finance และ Accounting อย่างหลีกเลี่ยงไม่ได้ จึงต้องปิดนิยามต้นทุนและเจ้าของ Billing ก่อนเริ่ม Phase 2
