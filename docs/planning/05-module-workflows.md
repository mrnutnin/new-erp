# Module Workflow Center — แผนหน้าสื่อการสอนการทำงาน

## เป้าหมาย

ทุก Module ต้องมีเมนู **คู่มือการทำงาน** ที่อธิบายลำดับงานจริงให้ผู้ใช้ทำตามได้ โดยไม่ต้องจำว่าเอกสารใดต้องสร้างก่อนหลัง หน้านี้เป็นทั้ง onboarding, workflow navigator และจุดตรวจ readiness ของระบบ ไม่ใช่คู่มือเอกสารยาวแยกจากหน้าทำงาน

หลักสำคัญคือผู้ใช้ใหม่ต้องเริ่มงานได้ด้วยตนเอง แม้ไม่เคยใช้ ERP มาก่อน: คู่มือใช้ภาษางานจริง, บอกสิ่งที่ต้องเตรียมและผลลัพธ์, ชี้ขั้นตอนถัดไป, อธิบายผลกระทบ GL/สต็อกแบบสั้น ๆ และให้ทางแก้เมื่อระบบยังไม่พร้อม โดยไม่บังคับให้จำรหัสบัญชีหรือชื่อ event ภายใน

## รูปแบบเมนู

- วางใต้ Dashboard ของแต่ละ Module และเปิดให้ผู้ใช้ที่ Login และผ่าน Program context ของ Module เข้าได้ทุกคน; ไม่ใช้ permission gate สำหรับการอ่านคู่มือ
- ชื่อเมนูตามผู้ใช้: `คู่มือการทำงาน` หรือ `Workflow Center`
- หน้าแรกแสดง workflow cards ตามงานหลักของ Module; card ที่ยังไม่พร้อมต้องแสดงสถานะ `รอการตั้งค่า` พร้อมเหตุผลและลิงก์ไปหน้าที่ต้องแก้
- Workflow Center เป็น public-within-program (ไม่ใช่ public internet): route ยังบังคับ `auth` และ Program/Warehouse context แต่ไม่บังคับ permission รายบุคคล; action link ภายในแต่ละ step ยังตรวจ permission ของหน้าปลายทางตามปกติ
- Finance/Accounting ใช้คนละคู่มือ เพราะ Finance ทำ operational subledger ส่วน Accounting ทำ GL/report/close

## โหมดการใช้งานของ Workflow Center

Workflow Center ของทุก Module ต้องแยกการนำเสนอเป็น 2 โหมด เพื่อไม่ให้ผู้ใช้ใหม่สับสนกับงานที่ต้องทำซ้ำทุกวัน:

1. **เริ่มใช้งานครั้งแรก (First-time setup)** — แสดงเฉพาะข้อมูลหลัก, การตั้งค่า, opening balance, mapping, สิทธิ์ และ readiness ที่ต้องทำก่อนเริ่มธุรกรรมจริง; แต่ละ card ต้องบอกว่า “ต้องเตรียมอะไร”, “ทำครั้งเดียวหรือทำเมื่อมีการเปลี่ยนแปลง” และปุ่มไปหน้าตั้งค่าที่เกี่ยวข้อง
2. **งานประจำวัน (Daily operations)** — แสดงงานตามลำดับที่ผู้ใช้ทำเป็นประจำ เช่น สร้างเอกสาร, ตรวจสอบ, อนุมัติ, รับ/จ่าย, จัดส่ง, ปิดยอด และติดตามรายการค้าง; card ต้องแสดงจำนวนงานค้าง, ขั้นตอนถัดไป และวันครบกำหนดเมื่อมีข้อมูลจริง

กติกาของสองโหมด:

- ใช้ compact mapping card/node รูปแบบเดียวกัน แต่ใช้หัวข้อ, progress และ next action แยกกันชัดเจน ห้ามรวม Setup กับธุรกรรมรายวันไว้ในเส้นทางเดียว
- First-time setup ต้องแสดงก่อนเมื่อบริษัท/คลังยังไม่ผ่าน readiness; เมื่อพร้อมแล้วให้เปิด Daily operations เป็นแท็บเริ่มต้น
- งานประจำวันต้องไม่พาผู้ใช้กลับไปตั้งค่าโดยไม่มีเหตุผล หากติด blocker ให้แสดงสาเหตุ, ผลกระทบ และลิงก์ไปแก้ที่ Setup step โดยตรง
- ตัวเลขงานค้างและสถานะต้องอ่านจากข้อมูลจริงแบบ server-side; ไม่มีข้อมูลต้องแสดง empty state พร้อมคำแนะนำงานแรก
- การอ่าน Workflow Center ยังคงเปิดให้ผู้ใช้ทุกคนใน Program เห็นได้ ส่วนปุ่มทำรายการปลายทางยังตรวจ permission ตามเดิม
- Metadata ของ workflow ต้องระบุ `mode` เป็น `setup` หรือ `daily` และทุก step ต้องมี `depends_on`, `readiness`, `next_action` และ `recovery_hint`

### Runtime readiness และ daily pending contract

ก่อนนำ readiness หรือจำนวนงานค้างไปแสดงบน card ให้ module ส่งข้อมูลผ่าน read-only runtime snapshot เดียวกัน โดยห้ามส่งรายการเอกสารหรือ model collection เข้า view:

```text
WorkflowRuntimeSnapshot {
  module: string,
  readiness: [{
    code, status, missing_count, block_reason, next_action,
    permission, route
  }],
  pending: [{
    code, count, label, route, permission
  }]
}
```

กติกา query:

- ใช้เฉพาะ `exists`, `count`, `sum` หรือ aggregate ที่คืน scalar/กลุ่มเล็ก ๆ; ห้าม `get()`, `all()` หรือส่ง rows ของ transaction เข้า Workflow Center
- ทุก query ที่เป็น operational data ต้อง scope ด้วย `selectedWarehouse`; Settings ที่เป็น company scope ไม่ต้องใช้ Warehouse
- ถ้าไม่มี permission ของ source ให้คืน `status = NO_PERMISSION` และไม่เปิดเผยจำนวนข้อมูล (`count = null`); ห้ามแสดงเป็น `ยังไม่มีข้อมูล`
- ถ้ามี permission แต่ข้อมูล/setting ยังไม่ครบ ให้คืน `status = NOT_READY`, เหตุผลภาษาคน, `next_action` และ route แก้ไขที่ตรวจ server แล้ว
- ถ้าพร้อมให้คืน `status = READY`; daily counter ที่ไม่มีรายการคืน `count = 0` พร้อม empty-state guidance
- snapshot เป็น read-only และต้องไม่เปลี่ยน status, lock หรือสร้างเอกสารระหว่างการเปิด Workflow Center

แหล่งข้อมูลและ permission ขั้นต่ำของ MVP:

| Module | Readiness source (scalar/aggregate) | Daily pending source (scalar/aggregate) | Permission ที่ใช้ตรวจ |
|---|---|---|---|
| Settings | `GlobalSettings::missingFor`, active Branch/Warehouse/User/Role counts | รายการ setup ที่ยังไม่ผ่าน readiness (ไม่ใช่ transaction) | `settings.company.view`, `settings.branches.view`, `settings.warehouses.view`, `settings.users.view`, `settings.roles.view` |
| WMS/Purchasing | inventory Global Settings, active Item/Category/UOM counts, active account mapping ที่เกี่ยวข้อง | Purchase Document สถานะ Draft/Approved และ Cost Recalculation ที่ยังไม่ resolved ตาม Warehouse | `wms.items.view`, `wms.purchase-documents.view`, `wms.stock.view`, `wms.stock-valuation.view` |
| Finance | active Bank/Cash, Payment Term, required Account Mapping และ Open Item source readiness | Payment Voucher/Settlement Draft–Approved และ outstanding Open Item ตาม Warehouse | `finance.bank-accounts.view`, `finance.payment-terms.view`, `finance.payment-vouchers.view`, `finance.settlements.view`, `finance.ar-open-items.view`, `finance.ap-open-items.view` |
| Accounting | active/postable COA count, active Account Mapping count, open Fiscal Period count | Journal Draft/Submitted/Approved และรายการ reconciliation ที่ผิดปกติ ตาม Warehouse | `accounting.accounts.view`, `accounting.account-mappings.view`, `accounting.periods.view`, `accounting.journal-entries.view`, `accounting.reports.view` |
| POS/Sales | active Customer count, active Item/Price configuration ตาม capability ที่เปิด | Sales Document Draft/Approved และรายการรอ Post ตาม Warehouse | `pos.customers.view`, `pos.sales-documents.view` |

การแยกข้อความต้องคงที่: `NO_PERMISSION` = “ไม่มีสิทธิ์เข้าถึงข้อมูลส่วนนี้”, `NOT_READY` = “ระบบยังไม่พร้อม” พร้อมเหตุผล/ทางแก้, `READY` = “พร้อมทำงาน”; ห้ามใช้ข้อความเดียวกันจนผู้ใช้แยกสาเหตุไม่ได้ การ wire เข้า UI ให้ทำหลัง resolver ของแต่ละ moduleผ่าน Unit Test และตรวจ query path แล้วเท่านั้น

## โครงสร้าง UI/UX ของหน้า

1. **Workflow cards** — ชื่อกระบวนการ, เป้าหมาย, ระยะเวลาโดยประมาณ, บทบาทที่เกี่ยวข้อง, สถานะความพร้อม และปุ่ม `เริ่มทำงาน`
2. **Stepper แนวนอน/แนวตั้ง** — ขั้นตอน numbered พร้อมสถานะ `ถัดไป`, `กำลังทำ`, `เสร็จแล้ว`, `ถูกบล็อก`
3. **Step detail** — สิ่งที่ต้องเตรียม, หน้าที่ใช้, สิทธิ์ที่ต้องมี, input/output, accounting/stock effect และข้อผิดพลาดที่พบบ่อย
4. **Next action** — ปุ่มไปหน้าสร้าง/ตรวจสอบเอกสารจริง โดย permission-gated และไม่สร้าง placeholder route
5. **Progress summary** — เอกสารค้าง, จำนวนที่รออนุมัติ, readiness checks และลิงก์รายงานที่เกี่ยวข้อง
6. **Beginner help** — คำอธิบายสั้น ๆ “ทำไมต้องทำขั้นตอนนี้”, ตัวอย่างข้อมูลที่ต้องเตรียม, คำศัพท์สำคัญ และ empty state ที่พาไปสร้างข้อมูลแรก

กติกา visual: ใช้ Bootstrap card/stepper, shared pastel status badges, Boxicons, whitespace แบบ Glassmorphism, สี accent อ่อน และ responsive layout โดยแสดง step เป็น **visual mapping แบบ compact node** มี node หมายเลข, action icon และเส้นเชื่อมลำดับบนจอใหญ่; รูปแบบนี้ต้องใช้กับ Workflow Center ของ Settings, Purchasing/WMS, Sales/POS, Finance, Accounting, Production, Logistics และ Asset; ห้ามใช้ภาพตกแต่งที่ทำให้ขั้นตอนอ่านยากหรือสร้าง CSS เฉพาะหน้าโดยไม่เพิ่มใน shared token

## Workflow ที่ต้องมีตาม Module

| Module | Workflow หลัก | ลำดับขั้นต่ำ |
|---|---|---|
| Settings | เริ่มต้นบริษัท | Company → Branch → Warehouse → User/Role/Permission → Global Settings → Readiness |
| Purchasing | Procure-to-Pay | Supplier → PR → Approval → PO → Goods Receipt → Credit Purchase → AP → Payment |

PO มี 2 ทางเข้าที่ถูกต้อง:

- **สร้างจาก PR ที่อนุมัติแล้ว**: ฝ่ายจัดซื้อเปิด PR ที่ Approved เลือก Supplier ที่ใช้งานได้ แล้วสร้าง PO โดยคง source linkage และ idempotency ของ PR
- **สร้าง PO โดยตรง**: ฝ่ายจัดซื้อสร้าง PO จากเมนู Purchase Order ได้โดยไม่ต้องมี `purchase_requisition_id`; ต้องเลือก Supplier, Item/UOM, จำนวน, ราคา และ Warehouse ให้ครบ

ทั้งสองทางเข้าสู่ Goods Receipt และ Credit Purchase ตามกฎ 3-way matching เดียวกัน โดย PO ที่สร้างตรงจะไม่มี PR ให้จับคู่ แต่ยังต้องมี PO → Receipt → Credit Purchase linkage สำหรับสินค้าคงคลัง
| WMS | Inventory Operations | Item/Category/UOM → Opening Balance → Receipt → Issue/Reserve → Transfer → Count/Adjust → Valuation |
| POS/Sales | Order-to-Cash | Customer/Group → Credit/Price List → Preliminary/RFQ → Quotation → Sales Order → Fulfillment → HS/IV → Invoice/Billing → AR → Receipt/Deposit |
| Production (optional) | Plan-to-Produce | Item/BOM/BOQ → Work Order → Reserve → Material Issue → WIP → Finished Goods → Cost/GL |
| Finance | AR/AP & Cash | Open Items → Receipt/Payment → Allocation → VAT/WHT realization → Aging → Reconciliation |
| Accounting | Record-to-Report | COA/Mapping → Journal → Approval → Posting → GL/Trial Balance → Tax Reports → Close |
| Logistics | Delivery | Sales Order → Shipment → Dispatch → Delivery/POD → Return/Cost |
| Asset | Asset Lifecycle | Acquisition → Capitalize → Assign → Depreciate → Transfer/Repair → Dispose |

Purchasing มีข้อยกเว้นสำหรับบริการ/ค่าใช้จ่ายที่ไม่เข้าสต็อก: ไม่ต้องสร้าง Goods Receipt แต่ต้องสร้าง Credit Purchase พร้อมเลือกบัญชีค่าใช้จ่าย และผ่าน AP/Payment ตามปกติ ส่วนสินค้าคงคลังต้องตรวจ 3-way match (PO, Goods Receipt, Credit Purchase) ก่อน Post เพื่อไม่ให้ Stock/Cost ถูกบันทึกซ้ำ

### WMS workflow detail — ตรวจนับสินค้าและผลต่าง

- **สร้างเอกสารตรวจนับ:** เลือกคลัง/วันที่ และเพิ่มสินค้าได้หลายรายการ ระบบบันทึกยอดระบบ (snapshot) ณ วันที่เอกสาร แล้วให้ผู้ใช้นับยอดจริงและระบุเหตุผลของผลต่าง
- **ตรวจสอบผลต่าง:** แสดงยอดระบบ, ยอดนับจริง, จำนวนต่าง และมูลค่าต่างต่อรายการ; ห้ามมีสินค้าเดียวกันซ้ำในเอกสารเดียว
- **อนุมัติและปิดผล:** เมื่ออนุมัติแล้ว ระบบแยกเอกสาร Adjustment เป็น `เพิ่ม` และ `ลด` ตามทิศทางของผลต่าง แล้ว Post ผ่าน Stock Movement, Cost Allocation และ GL ใน transaction เดียวแบบ idempotent
- **การแก้ Human Error:** เอกสาร Draft แก้ไข/ลบได้; เอกสาร Approved ที่ยังไม่ Post ใช้ยกเลิก; เอกสาร Posted ใช้กลับรายการ Adjustment ที่เชื่อมโยงเท่านั้น พร้อมเหตุผลและ Audit History ห้ามแก้ทับ stock ledger หรือ Journal
- **ข้อผิดพลาดที่ต้องบอกผู้ใช้:** หากเอกสาร Posted ไม่มี Adjustment linkage หรือ Adjustment post ไม่ครบ ระบบต้องหยุดการย้อนกลับและแสดงทางแก้ ไม่สร้างรายการชดเชยแบบเดาเอง
- **ผลลัพธ์หลัง Post:** หน้า Detail ต้องลิงก์กลับเอกสารตรวจนับ, Adjustment เพิ่ม/ลด, Stock Movement, Cost Allocation และ Journal ตามสิทธิ์; ตัวเลขและวันที่ใช้ Global Settings/company format

ในหน้า Workflow Center ให้แสดง 3-way match เป็นจุดตรวจแยกก่อน Post: ตรวจ Supplier/Warehouse, สินค้า/หน่วย, จำนวนที่สั่ง–รับ–เรียกเก็บ และราคา/ต้นทุน พร้อมบอกผลต่างที่พบ ผู้ใช้ต้องกลับไปแก้ Draft หรือ Void เอกสารที่อนุมัติแล้วตามสิทธิ์; ห้ามแก้ Journal เพื่อบังคับให้ยอดตรง และห้าม Post จนกว่า variance จะถูกตรวจและอนุมัติตาม policy ที่รองรับ

### Finance workflow detail — small-team safe path

Finance Workflow Center แยกเป็น `finance-first-time-setup` และ `record-to-cash` เพื่อให้บริษัทที่มีพนักงานการเงินเพียง 1–2 คนเห็นลำดับงานชัดเจน:

- **Setup:** บัญชีเงินสด/ธนาคาร → เงื่อนไขการชำระเงิน → หมวดรายได้/รายจ่ายอื่น → เลขที่เอกสาร → Account Mapping และงวดบัญชี
- **Daily:** ตรวจ AR/AP Open Item → ใบขอจ่าย/ใบสำคัญจ่าย → รับเงิน/จ่ายเงิน → จัดสรร → VAT/WHT realization → Aging/รายงาน
- **Advance/Deposit:** แสดงเป็นขั้นตอนแยกและไม่ให้ผู้ใช้เดา invoice เพื่อเคลียร์ยอด; จนกว่า UI, subledger, GL mapping และ reversal contract จะครบ ให้แสดง blocker และ recovery guidance แทนปุ่มสร้างรายการ
- **Petty Cash / Employee Advance:** ไม่สร้าง Journal หรือ placeholder document ใน MVP หากยังไม่มี source contract; ผู้ใช้เห็นเหตุผล ผลกระทบ และเมนูที่ต้องรออย่างชัดเจน
- **Human-error recovery:** Draft แก้ไขได้, Submitted/Approved ใช้ transition หรือ Void ตามสิทธิ์, Posted ห้ามแก้ทับและต้องใช้ reversal/รายการแก้ไขพร้อมเหตุผลและ audit

## Contract ของแต่ละ Step

ทุก step ต้องระบุข้อมูลเดียวกันใน metadata/view model: `code`, `label`, `sequence`, `status`, `route`, `permission`, `depends_on`, `required_setup`, `input_documents`, `output_documents`, `gl_effect`, `stock_effect`, `next_action` และ `block_reason` เมื่อไม่พร้อม

- route และ permission ต้องตรวจจาก server; ห้ามให้ JavaScript เปิดข้ามสิทธิ์
- readiness ต้องอ่านจากข้อมูลจริง เช่น mapping, active period, UOM, account, warehouse และ permission
- Step ที่เป็น Draft ยังไม่กระทบ GL/Stock ต้องแสดงอย่างชัดเจน
- Step ที่ Post แล้วต้องแสดงเลข Journal/Open Item/Stock Movement และลิงก์ drill-down ตามสิทธิ์
- วันที่/จำนวนเงิน/สถานะในคู่มือและ progress ต้อง human-readable ตาม company settings
- ผู้ใช้ใหม่ต้องเห็นคำแนะนำและ next action ก่อนรายละเอียดเชิงเทคนิค; technical code แสดงเป็นรายละเอียดรองเท่านั้น
- ทุก blocker ต้องมีข้อความภาษาคน, สาเหตุ, ผลกระทบ และลิงก์แก้ไขที่ทำได้จริง; ห้ามจบด้วย `ไม่พร้อมใช้งาน` โดยไม่มีทางไปต่อ
- ทุก error และความผิดพลาดของผู้ใช้ต้องมี recovery guidance แบบสั้นในหน้าเดียวกัน: จุดที่ผิด, ค่าที่ระบบคาดหวัง, เมนู/เอกสารที่ต้องกลับไปแก้, และปุ่ม “ลองอีกครั้ง” หรือ “กลับไปแก้ไข” ที่เหมาะสม; ถ้าเอกสารถูก Post แล้วให้แสดงเหตุผลว่าห้ามแก้ทับและพาไปเอกสารแก้ไข/Reverse/Contra ที่ถูกต้องแทน
- Delete Draft ต้องแสดงยืนยันที่มีเลขเอกสารและผลกระทบก่อน mutation; ลบได้เฉพาะ Draft ที่ยังไม่ถูกอ้างอิงและมี delete permission/ปุ่มของหน้าปลายทาง, การยกเลิกหรือย้อนกลับต้องถามเหตุผล; Approved/Posted ห้ามมีปุ่มลบและต้องใช้ Void/Reverse/Credit Note ตาม state contract โดยเลขเอกสารที่จ่ายแล้วห้าม reuse
- การย้อนกลับต้องแยกตามสถานะ: Draft แก้ไขได้, Approved ย้อนกลับได้เฉพาะ transition ที่กำหนด, Posted ใช้ reversal/credit note/adjustment เท่านั้น และทุกทางต้องบันทึกผู้ทำ เวลา เหตุผล และผลกระทบ GL/Stock
- card และ node ต้องกระชับ อ่านได้ในครั้งเดียว และไม่ใส่ข้อมูลมากเกินกว่าที่จำเป็นต่อการตัดสินใจขั้นถัดไป
- ถ้า company profile เป็น `TRADING` หรือ capability Production ถูกปิด ให้ซ่อน Production workflow จากหน้าเริ่มต้นและไม่แสดงเป็น blocker; แสดงเฉพาะข้อความ “ไม่ได้เปิดใช้สำหรับประเภทธุรกิจนี้” ในหน้า Module catalogue เมื่อผู้ดูแลต้องการดูรายละเอียด
- Core daily workflow ของธุรกิจซื้อมาขายไปต้องจบได้ที่ Purchasing/WMS → Sales/POS → Finance → Accounting โดยไม่ผ่าน Production; step ที่เป็น BOM/Work Order/WIP ห้ามถูกแทรกเป็น dependency ของ Receipt, Invoice, AR/AP หรือ stock valuation ทั่วไป
- สำหรับบริษัท `TRADING` และทีมเล็ก 1–2 คน Workflow Center ต้องแสดง WMS/Inventory เป็นเส้นทางหลัก โดยแยก Setup (Item/Category/UOM, Opening Balance, AVG/FIFO และ GL mapping) ออกจาก Daily (Receipt/Issue/Transfer, Count/Adjust, Valuation และ reconciliation) และไม่บังคับ approval หลายชั้นเกิน policy
- Receipt ต้องสื่อสถานะตามลำดับ `Receipt Draft → ตรวจรับ → รอ approval/post → inventory event + cost layer พร้อม → จึงส่งต่อ Cost/GL`; หาก source contract หรือ cost layer ยังไม่พร้อมต้องแสดง blocker/recovery และห้ามมีปุ่ม Post หรือข้อความที่ทำให้เข้าใจว่าสร้าง Stock/GL แล้ว
- Inventory Posting ให้ถือเป็น `readiness/preflight` จนกว่า reconciliation difference จะเป็นศูนย์, allocation/linkage ครบ และ reversal gate ผ่าน; UI ต้องบอกเหตุผลกับแนวทางแก้ แสดงชัดว่าอยู่ในโหมด Preview เมื่อ feature flag ปิด และไม่สร้างปุ่ม Post/Resolve ที่ยังไม่มี implementation จริง
- เมื่อผู้ใช้ทำผิด ให้แก้ Draft ก่อน approval; เอกสาร Posted ต้องใช้ reversal/correction และห้ามลบหรือแก้ทับ stock ledger, cost layer หรือประวัติ GL

## Definition of Done

- ผู้ใช้ใหม่เปิด Workflow Center แล้วรู้ว่าต้องเริ่มจากเอกสารใด
- ปุ่ม `เริ่มทำงาน` พาไปหน้าจริงของระบบและรักษา Program/Warehouse context
- แสดง blocker พร้อมวิธีแก้ ไม่ใช่เพียงปุ่ม disabled
- ครบ permission isolation, audit/readiness และ manual QA ของลำดับงาน
- มีอย่างน้อยหนึ่ง Unit Test สำหรับการคำนวณสถานะ/step ที่มี business rule และมี manual QA checklist ใน `docs/qa/`

## ลำดับการพัฒนา

1. สร้าง shared workflow contract และ Workflow Center ของ Settings/WMS/Purchasing ก่อน (ชุดแรกเริ่มใช้งานแล้ว)
2. เชื่อม Finance/Accounting เมื่อ AR/AP, posting และ period gate พร้อม (หน้า Workflow Center และเส้นทางเริ่มงานมีแล้ว; readiness เชิงข้อมูลและ cross-module reconciliation ยังต่อ)
3. เพิ่ม Sales/Production/Logistics/Asset ตาม source document ที่ใช้งานจริง
4. ปิดงานด้วย cross-module end-to-end walkthrough ทั้ง 6 flow ใน `docs/planning/03-modules-ui.md`
