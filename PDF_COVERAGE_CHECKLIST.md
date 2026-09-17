# New ERP — PDF Coverage Checklist

สำรวจล่าสุด: 16 กันยายน 2026
สถานะเอกสาร: สำรวจและจัดลำดับงาน ยังไม่ใช่การยืนยันว่า PDF ที่มีอยู่ผ่านมาตรฐานแล้ว

เอกสารนี้ตอบว่าเมนูใดควรมี PDF เมนูใดใช้ Excel/หน้าจอเป็นหลัก และควรพัฒนาอะไรก่อน การสร้างหรือแก้ PDF ทุกฉบับต้องทำตาม [`PDF_STANDARD.md`](PDF_STANDARD.md)

## วิธีอ่าน checklist

- `P0` — ต้องมี: เอกสารส่งคู่ค้า เอกสารภาษี หลักฐานรับ/จ่าย หรือเอกสารปฏิบัติงานที่ต้องพิมพ์/ลงนาม
- `P1` — ควรมี: รายงานปิดงวด รายงานตรวจสอบ หรือเอกสารภายในที่ต้องเก็บเป็นฉบับคงที่
- `P2` — ไม่เร่งด่วน: มีประโยชน์เฉพาะบาง workflow หรือมีช่องทางหลักอื่นอยู่แล้ว
- `มี route` — ระบบสร้าง PDF ได้แล้ว แต่ยังต้อง audit ตาม `PDF_STANDARD.md`
- `ยังไม่มี` — ควรพัฒนาเมื่อถึงลำดับงาน
- `ไม่ทำ` — ตั้งใจไม่เพิ่ม PDF; ใช้หน้าจอ, DataTable หรือ Excel เหมาะกว่า

หลักตัดสินใจสั้น ๆ:

1. เอกสารออกนอกบริษัท มีผลทางภาษี/การเงิน หรือต้องลงนาม ควรมี PDF
2. รายงานที่ต้องปิดยอด ณ เวลาใดเวลาหนึ่งหรือแนบชุดตรวจสอบ ควรมี PDF และ Excel
3. รายงานเพื่อวิเคราะห์บนจอให้ Excel เป็นช่องทางหลัก ไม่ต้องสร้าง PDF เพียงเพราะมี DataTable
4. หน้า create/edit, dashboard, workflow, queue, master data และหน้าควบคุมระบบไม่ควรมี PDF
5. ปุ่ม PDF อยู่หน้า detail เป็นหลัก ไม่ใส่ทุกแถวใน index หากทำให้ action แน่นหรือเสี่ยงพิมพ์เอกสารผิดสถานะ

## ภาพรวมที่พบในระบบ

| Module | PDF route ที่พบ | สถานะภาพรวม |
|---|---:|---|
| Accounting | 1 | มีเฉพาะหนังสือรับรองหัก ณ ที่จ่าย |
| Finance | 0 | เอกสารรับ/จ่ายสำคัญยังไม่มี PDF |
| POS | 7 | มีบางเอกสารขาย แต่ยังขาดใบรับชำระ ใบแจ้งหนี้ และใบวางบิล |
| Purchasing | 6 | มี PR, PO, GR, เอกสารซื้อ, Landed Cost และรายงานปฏิบัติการ |
| WMS | 1 | มีป้ายสินค้า Barcode/QR; เอกสารคลังและใบตรวจนับยังไม่มี PDF |
| Asset | 0 | มีหน้าพิมพ์ป้ายสินทรัพย์แบบ HTML แต่ไม่มี A4 PDF |
| Settings | 0 | ถูกต้องแล้ว; ไม่ควรมี business-document PDF |
| Dashboard | 0 | ถูกต้องแล้ว; ใช้หน้าจอเป็นหลัก |
| **รวม** | **15** | ทุก route ที่มีอยู่ยังต้อง audit layout, snapshot, permission และสถานะเอกสาร |

## Accounting

| เมนู/หน้า | PDF ที่ควรมี | Class | Priority | ปัจจุบัน | Checklist/หมายเหตุ |
|---|---|---|---|---|---|
| Journal Entry / สมุดรายวันทุกประเภท | ใบสำคัญการลงบัญชี (Journal Voucher) | INTERNAL | P0 | มี route | [x] แสดง Debit/Credit, source, ผู้จัดทำ/ผู้ตรวจสอบ/ผู้อนุมัติ และเลข Journal; DRAFT มีลายน้ำ |
| รายงานภาษีหัก ณ ที่จ่าย — ค่าใช้จ่าย | หนังสือรับรอง 50 ทวิ | WITHHOLDING_CERTIFICATE | P0 | มี route | [ ] Audit แบบฟอร์ม ข้อมูลผู้หัก/ผู้ถูกหัก ประเภทเงิน ภ.ง.ด. ลายเซ็น และสำเนา |
| General Ledger | รายงานบัญชีแยกประเภทตามตัวกรอง | INTERNAL | P1 | ยังไม่มี | [ ] PDF สำหรับชุดตรวจสอบ; Excel ยังเป็นช่องทางวิเคราะห์หลัก |
| Trial Balance | งบทดลองตามงวด | INTERNAL | P1 | ยังไม่มี | [ ] แสดงช่วงงวด สาขา ยอดยกมา เคลื่อนไหว และยอดคงเหลือ |
| Working Paper | กระดาษทำการ | INTERNAL | P1 | ยังไม่มี | [ ] รองรับหลายหน้าและหัวตารางซ้ำ |
| Balance Sheet | งบฐานะการเงิน | INTERNAL | P1 | ยังไม่มี | [ ] แสดงงวดเปรียบเทียบและวันที่ออกรายงาน |
| Profit & Loss / Comparative Income | งบกำไรขาดทุนและงบเปรียบเทียบ | INTERNAL | P1 | ยังไม่มี | [ ] ยอดรวมและหมายเหตุช่วงเวลาต้องตรงหน้าจอ/Excel |
| Cash Flow | งบกระแสเงินสด | INTERNAL | P1 | ยังไม่มี | [ ] ระบุวิธีและงวดรายงานให้ชัด |
| รายงานภาษี / VAT Input / VAT Output | รายงานภาษีประจำงวด | INTERNAL/STATUTORY | P0 | ยังไม่มี | [ ] Accounting/Tax owner ต้องยืนยันรูปแบบและข้อมูลก่อนใช้ยื่น/ตรวจ |
| รายงาน WHT จ่าย/รับ | รายงานสรุปหัก ณ ที่จ่าย | INTERNAL/STATUTORY | P1 | ยังไม่มี | [ ] แยกจากหนังสือรับรองรายรายการ |
| Bank Reconciliation | รายงานกระทบยอดธนาคารที่ reconcile แล้ว | INTERNAL | P1 | ยังไม่มี | [ ] พิมพ์เฉพาะ snapshot ที่ระบุ statement/date และผู้ตรวจ |
| AR/AP Reconciliation และ Control Account Reconciliation | รายงานกระทบยอด | INTERNAL | P1 | ยังไม่มี | [ ] แสดง filter, variance และเวลาที่สร้างรายงาน |
| Period Close | ชุดสรุปผลการปิดงวด | INTERNAL | P2 | ยังไม่มี | [ ] ทำเมื่อกำหนด closing package ชัดเจน ไม่พิมพ์หน้า readiness ตรง ๆ |
| Dashboard, Workflow, Approval Queue, Posting Errors, Reversed Entries | — | — | — | ไม่ทำ | [-] เป็นหน้าควบคุม/ติดตาม ใช้ DataTable และ Excel |
| Chart of Accounts, Mapping, Journal Books, Tax Codes, Fiscal setup | — | — | — | ไม่ทำ | [-] เป็น master/configuration ใช้ Excel export เมื่อจำเป็น |
| Audit Log | — | — | — | ไม่ทำ | [-] ใช้ bounded server-side export; PDF ไม่เหมาะกับ raw log จำนวนมาก |

## Finance

| เมนู/หน้า | PDF ที่ควรมี | Class | Priority | ปัจจุบัน | Checklist/หมายเหตุ |
|---|---|---|---|---|---|
| รับเงิน / จ่ายเงิน (Settlement) | ใบรับเงินหรือหลักฐานจ่ายเงินตามทิศทางรายการ | RECEIPT/INTERNAL | P0 | ยังไม่มี | [ ] ชื่อเอกสารต้องเปลี่ยนตาม RECEIVE/PAY; แสดง allocation, WHT, เงินสุทธิ และ GL reference |
| ใบขอจ่ายล่วงหน้า | ใบขอจ่าย | INTERNAL | P0 | ยังไม่มี | [ ] พิมพ์เมื่อ submit/approve; DRAFT มีลายน้ำ |
| ใบสำคัญจ่าย | ใบสำคัญจ่าย | INTERNAL | P0 | ยังไม่มี | [ ] แสดงผู้ขอ ผู้อนุมัติ คู่ค้า บัญชีเงิน และเอกสารที่จัดสรร |
| เงินทดรองพนักงาน | ใบขอ/รับเงินทดรอง | INTERNAL | P0 | ยังไม่มี | [ ] รองรับ signer ตาม workflow |
| เคลียร์เงินทดรอง | ใบเคลียร์เงินทดรอง | INTERNAL | P0 | ยังไม่มี | [ ] แสดงเงินรับเดิม ค่าใช้จ่าย คืนเงิน/จ่ายเพิ่ม และเอกสารแนบอ้างอิง |
| เงินสดย่อย | ใบสำคัญเงินสดย่อย | INTERNAL | P0 | ยังไม่มี | [ ] แสดงกองเงิน ผู้เบิก หมวดค่าใช้จ่าย และผู้อนุมัติ |
| เติมวงเงินสดย่อย | ใบเติมวงเงิน | INTERNAL | P0 | ยังไม่มี | [ ] แสดงยอดก่อน/หลังและบัญชีต้นทาง |
| เคลียร์เงินสดย่อย | ใบเคลียร์เงินสดย่อย | INTERNAL | P0 | ยังไม่มี | [ ] แสดง voucher ที่นำมาเคลียร์และผลต่าง |
| โอนเงินภายใน | ใบโอนเงินระหว่างบัญชี | INTERNAL | P0 | ยังไม่มี | [ ] แสดงบัญชีต้นทาง/ปลายทาง วันที่จริง ค่าธรรมเนียม และ Journal |
| ชุดจ่ายคอมมิชชั่น | สรุปชุดจ่ายและใบจ่ายรายผู้รับ | INTERNAL | P1 | ยังไม่มี | [ ] แยก summary กับรายบุคคล; จำกัดการเห็นข้อมูลตาม permission |
| AR/AP Open Items | Statement รายคู่ค้า ณ วันที่ | COMMERCIAL/INTERNAL | P1 | ยังไม่มี | [ ] ใช้ party/date filter และยอดยกมา/คงเหลือ |
| AR/AP Aging | รายงานอายุหนี้ | INTERNAL | P1 | ยังไม่มี | [ ] PDF สำหรับปิดงวด; Excel สำหรับวิเคราะห์ |
| รายงาน Payment Activity / Settlement Allocation | รายงานกิจกรรมรับจ่าย | INTERNAL | P1 | ยังไม่มี | [ ] ใช้ filter เดียวกับหน้าจอและระบุเวลาสร้าง |
| รายงาน Petty Cash / Cash Position / Expected Cash | รายงานการเงินตามช่วงเวลา | INTERNAL | P1 | ยังไม่มี | [ ] ทำเฉพาะ report view ไม่พิมพ์ DataTable index ตรง ๆ |
| รายงาน Employee Advances / Finance-vs-GL | รายงานควบคุมและกระทบยอด | INTERNAL | P1 | ยังไม่มี | [ ] แสดง variance และสถานะ reconciliation |
| Dashboard, Workflow | — | — | — | ไม่ทำ | [-] เป็นหน้าติดตาม |
| Bank Accounts, Petty Cash Funds, Payment Terms, Other Categories, Sequences | — | — | — | ไม่ทำ | [-] เป็น master/configuration |

## POS

| เมนู/หน้า | PDF ที่ควรมี | Class | Priority | ปัจจุบัน | Checklist/หมายเหตุ |
|---|---|---|---|---|---|
| รับข้อมูลการขาย (Sales Intake) | ใบรับข้อมูลการขาย | INTERNAL | P2 | มี route | [ ] ยืนยันกับ owner ว่ายังใช้งานการพิมพ์จริงหรือถอดปุ่มออก; ไม่ใช่เอกสารภาษี |
| RFQ | ใบคำขอเสนอราคา | COMMERCIAL | P2 | มี route | [ ] Audit ชื่อเอกสารและผู้รับ; คงไว้เฉพาะ workflow ที่ส่งให้ลูกค้า/ทีมขาย |
| ใบเสนอราคา | ใบเสนอราคา | COMMERCIAL | P0 | มี route | [ ] Audit อายุใบเสนอราคา เงื่อนไข ผู้เสนอ/อนุมัติ และหลายหน้า |
| ใบสั่งขาย | ใบยืนยันคำสั่งขาย | COMMERCIAL | P0 | มี route | [ ] Audit ที่อยู่จัดส่ง เงื่อนไขส่งมอบ และ reference quotation |
| ขายหน้าร้าน HS/IV | ใบเสร็จ/ใบกำกับภาษีเต็มรูปหรืออย่างย่อ ตามชนิดจริง | RECEIPT/TAX_INVOICE | P0 | มี route | [ ] แยก validation และ layout ตาม document type; Tax sign-off บังคับ |
| รับเงินมัดจำล่วงหน้า | ใบรับเงินมัดจำ | RECEIPT | P0 | มี route | [ ] ระบุว่าเป็นมัดจำ อ้างอิงการนำไปใช้/คืน และผล VAT ตาม policy |
| คืนสินค้า/ลดหนี้ | ใบรับคืนหรือใบลดหนี้ตามผลทางบัญชีจริง | CREDIT_NOTE/INTERNAL | P0 | มี route | [ ] ถ้าเป็นใบลดหนี้ต้องอ้างใบกำกับเดิม เหตุผล มูลค่าเดิม/ถูกต้อง และ VAT ต่าง |
| รับชำระหนี้ (Receipts) | ใบเสร็จรับเงิน | RECEIPT | P0 | มี route | [ ] แสดง invoice allocation, WHT, payment method, ผู้รับเงิน และเลข Journal; รอ Accounting ตรวจรับ wording/กระบวนการออกเอกสาร |
| Sales Documents | ใบแจ้งหนี้/ใบกำกับภาษีตาม classification จริง | COMMERCIAL/TAX_INVOICE | P0 | ยังไม่มี | [ ] ห้ามซ้ำหรือขัดกับ Physical Sale; ต้องกำหนด source of truth ก่อนพัฒนา |
| Billing Notes | ใบวางบิล | COMMERCIAL | P0 | ยังไม่มี | [ ] แสดงรายการ invoice, due date, ยอดคงค้าง และผู้รับวางบิล |
| ลูกหนี้คงค้าง / Aging | Statement และรายงานอายุหนี้ลูกค้า | COMMERCIAL/INTERNAL | P1 | ยังไม่มี | [ ] Statement รายลูกค้ากับ aging ภายในใช้คนละ layout |
| รายงานยอดขายรายวัน/ลูกค้า/สินค้า/กำไรขั้นต้น | รายงานสรุปตามตัวกรอง | INTERNAL | P1 | ยังไม่มี | [ ] PDF เฉพาะสรุปเพื่อประชุม/ปิดงวด; Excel เป็นช่องทางหลัก |
| รายงาน Promotion / Campaign ROI / Target Performance | รายงานบริหาร | INTERNAL | P2 | ยังไม่มี | [ ] ทำเมื่อมีความต้องการแจกจ่ายฉบับคงที่จริง |
| Sales–Receipts–AR Reconciliation | รายงานกระทบยอด | INTERNAL | P1 | ยังไม่มี | [ ] แสดง variance และเวลา snapshot |
| Sales Commission / Payment Batch | ใบสรุปคอมมิชชั่น | INTERNAL | P1 | ยังไม่มี | [ ] ใบจ่ายเงินจริงให้ Finance เป็นเจ้าของ PDF; POS แสดง calculation statement |
| Dashboard, Workflow | — | — | — | ไม่ทำ | [-] เป็นหน้าติดตาม |
| Customers, Customer Groups, Price Lists, Promotions, Plans, Targets | — | — | — | ไม่ทำ | [-] เป็น master/configuration ใช้ Excel export |

## Purchasing

| เมนู/หน้า | PDF ที่ควรมี | Class | Priority | ปัจจุบัน | Checklist/หมายเหตุ |
|---|---|---|---|---|---|
| Purchase Requisition | ใบขอซื้อ | INTERNAL | P1 | มี route | [ ] Audit requester, cost center/project, approver และสถานะ DRAFT/VOID |
| Purchase Order | ใบสั่งซื้อ | COMMERCIAL | P0 | มี route | [x] รองรับ supplier tax/address, delivery, payment terms, VAT snapshot, signer และหลายหน้า |
| Goods Receipt | ใบรับสินค้า | INTERNAL | P0 | มี route | [ ] แสดง PO/reference, warehouse, ผู้ส่ง/ผู้รับ และ quantity accepted/rejected |
| Purchase Invoice | ใบตั้งหนี้ซื้อ (สำเนาภายใน) | INTERNAL | P0 | มี route ร่วม | [ ] ห้ามใช้แทนใบกำกับภาษีจาก supplier; แสดง source document และ 3-way match |
| Purchase Credit Note | ใบลดหนี้ซื้อ (สำเนาภายใน) | INTERNAL | P0 | มี route ร่วม | [ ] แสดงเอกสารต้นทาง เหตุผล และผลต่างบัญชี/ภาษี |
| Landed Cost | ใบสรุปต้นทุนแฝงและการปันส่วน | INTERNAL | P1 | มี route | [x] แสดงวิธีปันส่วน source receipts และยอดก่อน/หลัง; รองรับหลายหน้า |
| Purchasing Operations Report | รายงานปฏิบัติการจัดซื้อ | INTERNAL | P1 | มี route | [x] PDF เป็น filtered summary ตามช่วงวันที่และสิทธิ์คลัง; Excel/DataTable ยังเป็นช่องทางวิเคราะห์รายละเอียด |
| Dashboard, Workflow, Suppliers | — | — | — | ไม่ทำ | [-] Dashboard/workflow ไม่พิมพ์; Supplier เป็น master data |

## WMS

| เมนู/หน้า | PDF ที่ควรมี | Class | Priority | ปัจจุบัน | Checklist/หมายเหตุ |
|---|---|---|---|---|---|
| ป้ายสินค้า | สติกเกอร์ Barcode/QR จากรหัสสินค้า | LABEL | P0 | มี route | [x] รองรับ 40×30, 50×30, 60×40, 100×50 มม. และ A4 แบบ 21/40 ดวง; เลือกหลายสินค้า/จำนวนสำเนา และใช้ shared renderer |
| โอนออก / โอนเข้า | ใบโอนสินค้าและใบรับโอน | INTERNAL | P0 | ยังไม่มี | [ ] แสดงคลังต้นทาง/ปลายทาง ผู้ส่ง/ผู้รับ serial/lot เมื่อมี และสถานะ dispatch/complete |
| เบิกสินค้า (Issue) | ใบเบิกสินค้า | INTERNAL | P0 | ยังไม่มี | [ ] แสดงประเภทเบิก ผู้เบิก คลัง cost center/reference และผู้อนุมัติ |
| คืนจากการเบิก | ใบคืนสินค้า | INTERNAL | P0 | ยังไม่มี | [ ] อ้าง issue/movement เดิมและเหตุผล |
| เบิกวัตถุดิบผลิต | ใบเบิกวัตถุดิบ | INTERNAL | P0 | ยังไม่มี | [ ] อ้าง production reference และ quantity/UOM |
| คืนวัตถุดิบผลิต | ใบคืนวัตถุดิบ | INTERNAL | P0 | ยังไม่มี | [ ] อ้างใบเบิกเดิมและผู้รับคืน |
| รับสินค้าสำเร็จรูป | ใบรับสินค้าสำเร็จรูป | INTERNAL | P0 | ยังไม่มี | [ ] แสดง source production, warehouse และ cost reference |
| Stock Count | ใบตรวจนับแบบ blind และใบสรุปผลต่าง | INTERNAL | P0 | ยังไม่มี | [ ] แยกแบบไม่มี system quantity สำหรับผู้ตรวจนับกับผลหลังอนุมัติ |
| Inventory Adjustment | ใบปรับปรุงสินค้าคงคลัง | INTERNAL | P0 | ยังไม่มี | [ ] แสดงเหตุผล ผลต่าง quantity/value ผู้อนุมัติ และ Journal reference |
| Opening Balance | ใบ/รายงานนำเข้ายอดยกมา | INTERNAL | P1 | ยังไม่มี | [ ] พิมพ์ snapshot หลัง import/ก่อน post เพื่อ audit |
| Stock Card | Stock Card ตามสินค้า/คลัง/ช่วงวันที่ | INTERNAL | P1 | ยังไม่มี | [ ] ใช้ running quantity/value จาก read model เดียวกับหน้าจอ; รองรับหลายหน้า |
| Inventory Valuation | รายงานมูลค่าสินค้าคงเหลือ ณ วันที่ | INTERNAL | P1 | ยังไม่มี | [ ] แสดง costing method, UOM, quantity, unit cost, value และ as-of date |
| Dashboard, Workflow | — | — | — | ไม่ทำ | [-] เป็นหน้าติดตาม |
| Revaluation, Manual Trigger, Emergency Rebuild, Legacy Review, Lineage | — | — | — | ไม่ทำ | [-] เป็น costing control/queue; ใช้ DataTable, Excel และ audit log |
| Categories, UOM, Conversion, Min/Max, Issue Types | — | — | — | ไม่ทำ | [-] เป็น master/configuration |

## Asset

| เมนู/หน้า | PDF ที่ควรมี | Class | Priority | ปัจจุบัน | Checklist/หมายเหตุ |
|---|---|---|---|---|---|
| Asset Register / Detail | ใบทะเบียนสินทรัพย์รายรายการ | INTERNAL | P1 | ยังไม่มี | [ ] แสดง acquisition, location, custodian, depreciation และสถานะ ณ วันที่ |
| Asset Label | ป้าย barcode/asset tag | LABEL | P0 | มี HTML print | [ ] คงเป็น label profile ไม่บังคับ A4; ไม่ต้องย้ายเข้า A4 PDF โดยไม่มีเหตุผล |
| Capitalization | ใบรับรู้สินทรัพย์ | INTERNAL | P0 | ยังไม่มี | [ ] แสดง source, capitalization value, useful life, accounts และ approver |
| Addition | ใบเพิ่มมูลค่าสินทรัพย์ | INTERNAL | P0 | ยังไม่มี | [ ] แสดงมูลค่าเดิม/เพิ่มใหม่ เหตุผล และผลต่อค่าเสื่อม |
| Asset Transfer | ใบโอนย้ายสินทรัพย์ | INTERNAL | P0 | ยังไม่มี | [ ] แสดงสถานที่/ผู้ดูแลเดิมและใหม่ พร้อมผู้ส่ง/ผู้รับ |
| Asset Count | ใบตรวจนับและใบสรุปผล | INTERNAL | P0 | ยังไม่มี | [ ] รองรับ blind count และผลต่างหลังตรวจ |
| Impairment | ใบด้อยค่าสินทรัพย์ | INTERNAL | P0 | ยังไม่มี | [ ] แสดง carrying amount, recoverable amount, loss, เหตุผล และ Journal |
| Disposal | ใบจำหน่าย/ตัดสินทรัพย์ | INTERNAL | P0 | ยังไม่มี | [ ] แสดง proceeds, gain/loss, buyer เมื่อมี และ authorization |
| Depreciation Run | รายงานค่าเสื่อมประจำงวด | INTERNAL | P1 | ยังไม่มี | [ ] แสดง asset totals และ reconciliation กับ Journal |
| Depreciation Policy Change | บันทึกเปลี่ยนนโยบายค่าเสื่อม | INTERNAL | P1 | ยังไม่มี | [ ] แสดงก่อน/หลัง effective date เหตุผลและผู้อนุมัติ |
| Maintenance Request | ใบงานซ่อมบำรุง | INTERNAL | P0 | ยังไม่มี | [ ] แสดงอาการ งานที่มอบหมาย อะไหล่ วันที่ และผู้รับงาน/ปิดงาน |
| Depreciation / Maintenance / Asset-vs-GL Reports | รายงานตามตัวกรอง | INTERNAL | P1 | ยังไม่มี | [ ] PDF สำหรับ audit/ปิดงวด; Excel สำหรับวิเคราะห์ |
| Dashboard, Workflow, Import Queue | — | — | — | ไม่ทำ | [-] เป็นหน้าควบคุม |
| Categories, Locations, Maintenance Schedules | — | — | — | ไม่ทำ | [-] เป็น master/configuration; schedule รายงานผ่าน maintenance report ได้ |

## Settings และหน้ากลาง

| เมนู/หน้า | ผลตัดสิน | เหตุผล |
|---|---|---|
| Company Settings | ไม่ทำ PDF | เป็นแหล่งข้อมูล logo/ชื่อ/ที่อยู่/เลขภาษีสำหรับ PDF อื่น |
| Branches / Warehouses | ไม่ทำ PDF | เป็น master data; ต้องเติมข้อมูลสาขาที่เอกสารภาษีต้องใช้ |
| Document Sequences | ไม่ทำ PDF | เป็น configuration |
| Users / Roles | ไม่ทำ PDF | เป็น security master; User Profile ต้องรองรับลายเซ็นในอนาคต |
| Settings Audit | ไม่ทำ PDF | ใช้ server-side Excel export สำหรับข้อมูลจำนวนมาก |
| Program selector / Module dashboards | ไม่ทำ PDF | เป็น navigation/monitoring ไม่ใช่เอกสารธุรกิจ |

## ลำดับพัฒนาที่แนะนำ

### Phase 0 — Foundation ก่อนเพิ่ม route ใหม่

- [ ] ทำ payload/presenter และ Blade primitives กลางสำหรับ header, company, party, lines, totals, watermark, page footer และ signatures
- [ ] Audit `DocumentPdfRenderer` ให้รองรับ CSS ที่จำเป็นโดยไม่ให้แต่ละ module สร้าง renderer เอง
- [ ] เพิ่มข้อมูลสาขาสำหรับเอกสารภาษี: ที่อยู่ เลขผู้เสียภาษี รหัสสาขา และ snapshot contract
- [ ] เพิ่ม User Profile signature/position, protected storage, permission, audit และ signature snapshot
- [ ] กำหนด permission `.print` และ policy การพิมพ์ DRAFT/APPROVED/POSTED/VOID กลาง
- [ ] กำหนด source of truth ระหว่าง Physical Sale กับ Sales Document เพื่อไม่ออกใบกำกับภาษีซ้ำ

### Phase 1 — เอกสารภายนอกและหลักฐานรับ/จ่าย

- [ ] Audit PDF เดิมทั้งหมด 12 endpoint ตาม `PDF_STANDARD.md`
- [ ] POS: ใบเสร็จรับเงิน, Sales Document และใบวางบิล
- [ ] Finance: Settlement, ใบขอจ่าย, ใบสำคัญจ่าย, เงินทดรอง และเงินสดย่อย
- [ ] Accounting: Journal Voucher และ 50 ทวิให้ผ่าน Accounting/Tax sign-off
- [ ] Purchasing: PO และเอกสารซื้อให้แยก commercial/internal/tax meaning ชัดเจน

### Phase 2 — เอกสารปฏิบัติงาน

- [ ] WMS: Transfer, Issue/Return, Production, Count และ Adjustment
- [ ] Asset: Capitalization, Addition, Transfer, Count, Impairment, Disposal และ Maintenance Work Order
- [ ] Purchasing: PR, GR และ Landed Cost allocation sheet

### Phase 3 — รายงานปิดงวดและตรวจสอบ

- [ ] Accounting financial/tax/reconciliation reports
- [ ] Finance cash, aging, advance และ reconciliation reports
- [ ] POS sales/AR reports ที่ต้องแจกจ่ายเป็นฉบับคงที่
- [ ] WMS Stock Card และ Inventory Valuation
- [ ] Asset depreciation, maintenance และ Asset-vs-GL reports

## Definition of Done ต่อหนึ่ง PDF

- [ ] มี classification, owner และสถานะที่พิมพ์ได้ชัดเจน
- [ ] Route เป็น read-only และตรวจ auth, `.print`, company/branch/warehouse scope
- [ ] ใช้ `DocumentPdfRenderer`, A4/Noto Sans Thai และ shared PDF primitives
- [ ] ข้อมูล logo/company/branch/party/document/tax มาจาก source หรือ snapshot ที่ถูกต้อง
- [ ] จำนวนเงินใช้ Decimal และตรง document, allocation, tax และ GL
- [ ] DRAFT/VOID/reprint แสดง watermark/copy control ถูกต้อง
- [ ] Signature แสดงเฉพาะ workflow ที่ลงนามแล้วและใช้ snapshot ของ signer
- [ ] ทดสอบ 0, 1, 30 และ 100+ lines, ภาษาไทยยาว, page break, totals และ signature block
- [ ] ทดสอบ permission/scope, missing logo/signature และ response `%PDF-`
- [ ] Accounting/Tax owner sign-off สำหรับเอกสารภาษี/ใบรับ/50 ทวิ
- [ ] ปุ่ม `พิมพ์` อยู่หน้า detail ตาม action order ใน `AGENTS.md`; index มีเฉพาะเมื่อมีเหตุผลชัดเจน

## ประเด็นที่ owner ต้องยืนยันก่อนเริ่ม implementation

- [ ] Sales Intake และ Sales RFQ ยังต้องพิมพ์จริงหรือควรถอด PDF ที่มีอยู่เพื่อลดเอกสารซ้ำ
- [ ] Physical Sale และ Sales Document ประเภทใดเป็น source หลักของใบเสร็จ/ใบกำกับภาษี
- [ ] Settlement ฝั่งรับเงินออก “ใบเสร็จรับเงิน” ได้ทุกกรณีหรือบางกรณีเป็นเพียง “ใบรับเงิน”
- [ ] รายงานใดถูกใช้ยื่น/ส่งหน่วยงานภายนอก เทียบกับรายงานภายในเพื่อกำหนด legal layout
- [ ] เอกสารใดบังคับภาพลายเซ็น และบทบาทใดลงนามในแต่ละสถานะ
