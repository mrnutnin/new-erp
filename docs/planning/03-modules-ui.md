# Modules and UI Plan

รายละเอียดหน้าคู่มือการทำงานและ Workflow Center อยู่ใน `docs/planning/05-module-workflows.md` โดยทุก Module ต้องมี stepper, readiness และ next action ที่พาไปหน้าจริงตามสิทธิ์

UI ต้องใช้ Bootstrap 5 component และ utility class ที่มีอยู่ก่อนสร้าง CSS ใหม่ เช่น grid, `form-control`, `form-select`, button, card, table, spacing และ validation. ใช้ Boxicons `2.1.4` จาก CDN ที่ root layout เป็น icon family เดียว ห้ามผสม Material Icons/Font Awesome หรือ include CDN ซ้ำราย Blade; icon ทั่วไปต้องอยู่คู่ข้อความและเป็น decorative ด้วย `aria-hidden="true"`.

## 8. MVP scope by module

### 8.1 Human-error recovery UI

ทุกหน้าธุรกรรมต้องช่วยผู้ใช้แก้ความผิดพลาด ไม่ใช่เพียงแสดงข้อความ validation:

- แสดง field/row ที่ผิดพร้อมข้อความภาษาคนและตัวอย่างค่าที่ถูกต้อง
- แยก `แก้ไขได้ทันที`, `ต้องส่งกลับ/ยกเลิก`, และ `ต้องสร้างเอกสารแก้ไข` ให้เห็นชัด
- หลัง error จาก transaction หรือ posting ต้องไม่ทิ้งข้อมูลบางส่วน และมีปุ่มกลับไปแก้ Draft หรือ retry เมื่อปลอดภัย
- เอกสาร Posted ต้องอ่านอย่างเดียว พร้อมปุ่ม/ลิงก์ไป Credit Note, Adjustment หรือ Reversal ตาม contract; ห้ามปุ่มแก้ไขที่เขียนทับประวัติ
- แสดงสรุปผลกระทบก่อนยืนยัน เช่น GL, AR/AP, Stock, VAT/WHT และเลขเอกสารที่เกี่ยวข้อง

### Platform

- authentication และ session lifecycle
- เลือกโปรแกรมและคลังตามสิทธิ์ พร้อม recheck context ทุก request
- shared audit/file-storage contracts และ infrastructure ที่หลาย module ใช้

**Exit:** login/context ปลอดภัยและ module อื่นใช้ shared contract ได้โดยไม่เขียน auth/storage ซ้ำ

### Settings / Global Settings

- singleton company profile, branch, warehouse และ user access
- User Management: ค้นหา/แบ่งหน้า, เพิ่ม–แก้ไข, active/inactive, program/warehouse assignment; ไม่มี hard-delete action และป้องกัน self-lockout
- role/permission และ approval matrix
- party, product/item, UOM, tax, currency, payment term
- PAE/NPAE accounting profile, company-wide fiscal period, retention/SLA settings, license/subscription, audit log และ module toggles
- custom fields, document templates และ branding เป็น developer/vendor-managed configuration สำหรับทีมเจ้าของระบบเท่านั้น ไม่เปิด end-user designer ใน MVP

**Exit:** ตั้ง company/global settings และ master data จน module อื่นเริ่ม transaction ได้ พร้อมจำกัด program/branch/warehouse access; Settings ข้ามการเลือก warehouse เพราะเป็น company scope

### Purchasing

Purchasing and WMS are separate bounded contexts. Purchasing owns Supplier,
PR, PO, Goods Receipt and Purchase/AP documents; WMS owns item, stock,
movement and costing. The canonical Purchasing URL is `/purchasing` and its
route namespace is `purchasing.*`. `/wms` remains the inventory URL; the former
`/wms/purchase-*` compatibility surface has been retired after cutover.

- supplier and supplier terms
- purchase requisition (draft → submit → approve/reject)
- purchase order และ partial receipt tracking
- goods receipt เชื่อม WMS และรับต้นทุน/ภาษี
- purchase return/cancel และเอกสารค้างรับ
- purchase/AP handoff ให้ Finance/Accounting

**Exit:** PR → PO → partial/full receipt → stock movement → AP/journal posting ทำงานครบและ retry ไม่ซ้ำ

### WMS — Inventory

- item/variant/UOM/barcode และ warehouse/location
- beginning balance, receipt, issue, transfer และ adjustment
- stock card, on-hand, available, reserved และ valuation
- stock count/cycle count และ reconciliation
- costing engine รองรับ company-wide `AVG` หรือ FIFO, provisional negative allocations, recost/reversal และ optional lot/serial/expiry/warranty fields โดยไม่บังคับตาม item type
- transfer แบบ dispatch/accept/reject/partial; source stock ถูกตัดตอน dispatch และไม่มี Goods in Transit warehouse/account

**Exit:** movement ledger ตรงกับ balance/valuation/GL ทั้ง AVG และ FIFO และ concurrent issue ไม่ทำให้ stock หรือ cost layer ผิด policy

### POS — Sales

- Sales Order system เป็นหลัก ไม่มี cash drawer/shift management หรือ POS hardware scope
- customer, price list, promotion, discount rule/approval และ credit term
- ใบขอราคา → ใบเสนอราคา → sales order → ใบสั่งผลิต → ใบเบิกผลิต → ใบรับผลิต → HS/IV ตาม document mapping ที่ยืนยัน
- quotation → sales order → fulfillment/invoice flow ต้องเชื่อม Production เมื่อเป็น Make-to-Order
- cash/credit sale, receipt, discount, VAT และ withholding tax ที่เกี่ยวข้อง
- sales return, credit note และ payment status
- ใบเสร็จ/ใบกำกับภาษีและรายงานยอดขายรายวัน; ใช้งาน web/online เท่านั้น ไม่มี offline mode

**Exit:** ขายเงินสดและเงินเชื่อครบวงจร กระทบ stock, AR/cash และ journal ถูกต้อง

### Production

- แยกคำให้ชัด: **BOM/Recipe** คือสูตรผลิตต่อหน่วยสินค้า; **BOQ** คือประมาณการปริมาณ/ต้นทุนของงานหรือ project และไม่ทำให้ stock เปลี่ยนจนกว่าจะ convert/release เป็น work order หรือ material issue
- BOM/Recipe มี revision/version, status `draft → approved → obsolete`, effective date, finished/semi-finished item, base output quantity/UOM และ material lines
- material line ระบุ item/variant, quantity/UOM, wastage/scrap allowance, issue warehouse และ substitute ที่อนุมัติ; conversion ต้องใช้ UOM contract กลาง
- BOM หลายระดับรองรับผ่าน semi-finished item และต้องตรวจ circular dependency; work order ต้อง snapshot BOM revision/quantities ตอน release เพื่อไม่ให้การแก้สูตรกระทบงานเก่า
- BOQ มี revision/approval และ line ขั้นต่ำสำหรับ material, labor และ overhead พร้อม planned quantity/cost; สามารถ convert เป็น material demand/work order โดยรักษา source reference และไม่สร้างซ้ำเมื่อ retry
- production รองรับทั้ง Make-to-Stock และ Make-to-Order; ไม่ทำ routing, work center, machine capacity หรือ subcontract manufacturing ใน MVP
- production plan/work order รองรับหลาย output/by-product; BOM กำหนด standard allocation weight/rate ที่ฝ่ายบัญชีอนุมัติเพื่อแบ่ง actual WIP cost ไปแต่ละ output
- material requirement explosion/availability, reservation, actual issue/return และ substitution ที่ต้องให้ฝ่ายบัญชีอนุมัติ
- finished/semi-finished/by-product receipt, scrap และ yield
- actual material cost จาก AVG/FIFO engine + standard labor/overhead พร้อม planned-vs-actual variance
- WIP และสถานะงานผลิต

**Exit:** BOQ/BOM revision ถูก snapshot ไป work order, เบิกวัตถุดิบตามสูตรหรือ substitution ที่บัญชีอนุมัติ, รับหลาย output/by-product และ reconcile actual material/WIP/outputs/variance กับ GL ได้ทั้ง AVG และ FIFO

### Finance

- Finance เป็นเจ้าของ operational subledger ของลูกหนี้/เจ้าหนี้ รวม allocation, aging, รับ–จ่าย, มัดจำ, เงินสดย่อย และเงินทดรอง; Accounting เก็บ GL/control accounts, tax, reconciliation และงบ โดยรับรายการผ่าน posting contract
- Purchasing เป็นเจ้าของ Supplier และเอกสาร PR/PO/GR/ใบแจ้งหนี้ซื้อที่สร้างต้นทางเจ้าหนี้ ส่วน Sales/POS เป็นเจ้าของ Customer และเอกสารขายที่สร้างต้นทางลูกหนี้
- เมนู **ลูกหนี้ (AR):** รายการลูกหนี้คงค้าง, รับชำระและ allocate, เงินรับล่วงหน้า/มัดจำลูกค้า และ Aging ลูกหนี้
- เมนู **เจ้าหนี้ (AP):** รายการเจ้าหนี้คงค้าง, Pre-Payment Voucher สำหรับเตรียม/ขออนุมัติจ่าย, Payment Voucher, Payment Supplier, เงินจ่ายล่วงหน้า/มัดจำ Supplier และ Aging เจ้าหนี้
- เมนู **เงินสดและธนาคาร:** รับเงิน/จ่ายเงิน, โอนเงินระหว่างบัญชี, Petty Cash และ Bank Reconciliation
- เมนู **เงินทดรอง:** เบิกเงินทดรองพนักงาน, เคลียร์ค่าใช้จ่าย, คืนเงินคงเหลือหรือจ่ายเพิ่ม โดยอ้างอิงพนักงานจาก HR เมื่อ Module พร้อม
- cash flow, outstanding และ Finance/AR/AP reports

**Exit:** รับ/จ่ายและ allocate หลาย invoice ได้ ยอดคงค้างตรงกับ GL control accounts

### Accounting

- รองรับ accounting/reporting profile ทั้ง PAE และ NPAE บน ledger kernel เดียวกัน โดยแยก chart/report/disclosure configuration ที่ต่างกัน
- chart of accounts template ที่ปรับตามธุรกิจได้ และกำหนด control/postable/statement accounts
- บัญชี 5 เล่ม: Purchase, Sales, Cash Receipt, Cash Payment และ General Journal
- journal entry, typed auto-posting rules, source trace, approval และ reversal
- VAT input/output, withholding tax และเอกสาร/รายงานภาษีที่กำหนดใน tax profile; แบบฟอร์มและ tax point ต้องให้ผู้ทำบัญชียืนยัน
- subsidiary ledgers/reconciliation, trial balance, general ledger, P&L และ balance sheet
- period close/lock/reopen ทั้งบริษัท, reversal, cost adjustment และ opening balance; ฝ่ายบัญชีเป็นผู้มีสิทธิ์อนุมัติ Manual Journal/reopen/adjustment
- opening Trial Balance/subledger/stock/asset import contracts และ cutover reconciliation สำหรับ Migration Import กลาง

**Exit:** ทั้ง 5 เล่ม post แบบ balanced/idempotent, reconcile subledgers กับ GL, ปิด period และ drill-down งบทดลอง/งบกำไรขาดทุน/งบดุลกลับถึงเอกสารต้นทางได้; opening data จาก migration batch reconcile กับ source control totals และ retry ไม่สร้างยอดซ้ำ

### Logistics

- delivery order/shipment จาก sales order
- shipment plan, vehicle, driver, route/trip และ load
- dispatch, delivery status, failed delivery และ proof of delivery
- transport charge/cost และการส่งคืนสินค้า

**Exit:** shipment trace จาก order ถึง POD ได้ และ stock/finance เปลี่ยน ณ event ที่ตั้งค่าไว้

### Asset

- asset category/register/location/custodian
- acquisition จาก purchase หรือ manual capitalization
- depreciation schedule และ periodic run
- transfer, maintenance note, impairment และ disposal
- journal integration

**Exit:** asset lifecycle ตั้งแต่ capitalize ถึง depreciate/dispose เชื่อม GL และ audit ได้

## 9. End-to-end flows that define the MVP

1. **Procure-to-pay:** PR → approval → PO → goods receipt → stock → supplier invoice/AP → payment → journal
2. **Order-to-cash:** quotation → sales order → reserve/issue stock → delivery → invoice/AR → receipt → journal
3. **Plan-to-produce:** work order → reserve/issue material → WIP → finished goods → production cost → journal
4. **Delivery:** sales fulfillment → shipment plan → dispatch → POD/return → transport cost
5. **Asset lifecycle:** purchase/capitalize → assign/transfer → depreciation → disposal → journal
6. **Period close:** reconcile stock/AR/AP/cash/assets → post adjustments → lock period → financial statements

MVP ยังไม่ถือว่าเสร็จหากแต่ละหน้าจอใช้ได้แยกกันแต่ flow เหล่านี้ยังไม่จบแบบ end-to-end

## 10. UI system

### 9.5 Beginner-friendly ERP experience

ระบบต้องใช้งานได้สำหรับบริษัทและผู้ใช้ที่ไม่เคยใช้ ERP มาก่อน โดยทุก module และทุก flow ต้องยึดหลักต่อไปนี้:

- ใช้ชื่อเมนูและข้อความตามงานที่ผู้ใช้คุ้นเคย เช่น “รับสินค้า”, “จ่ายเงิน”, “ตรวจสอบก่อนบันทึก” และแสดงคำศัพท์บัญชี/ระบบเป็นคำอธิบายรองเมื่อจำเป็น
- ทุกหน้าที่เป็นจุดเริ่มงานต้องบอก `เริ่มจากอะไร`, `ต้องเตรียมอะไร`, `ขั้นตอนถัดไปคืออะไร` และ `ผลลัพธ์หลังบันทึก` ในบริเวณที่เห็นได้โดยไม่ต้องเปิดเอกสารอื่น
- ใช้ Workflow Center/compact mapping เป็นตัวนำทางหลัก: แสดงลำดับก่อน–หลัง, ขั้นที่ทำเสร็จ, ขั้นที่กำลังทำ, ขั้นที่ถูกบล็อก และปุ่มไปหน้าจริง
- เมื่อระบบยังไม่พร้อม ห้ามแสดงเพียงปุ่ม disabled หรือข้อความ technical; ต้องบอกสาเหตุเป็นภาษาคน พร้อมลิงก์ไปตั้งค่าหรือแก้ข้อมูลที่เกี่ยวข้อง
- ใช้ safe defaults ที่ไม่ทำให้เกิดผลกระทบทางการเงิน/สต็อกโดยไม่ตั้งใจ เช่น เริ่มเป็น Draft, ใช้ context สาขาที่เลือกไว้, วันที่ธุรกิจตาม timezone บริษัท และไม่เลือกบัญชี GL แรกโดยอัตโนมัติ
- ฟอร์มยาวให้แบ่งเป็นกลุ่มตามงาน, แสดงตัวอย่าง/placeholder ที่ถูกต้อง, คำนวณยอดให้ดูทันที และสรุปผลกระทบ GL/สต็อกก่อน Approve/Post
- ซ่อนรายละเอียดที่ไม่จำเป็นในขั้นแรก แต่เปิด “ดูรายละเอียดเพิ่มเติม” สำหรับผู้ตรวจสอบ; ไม่ให้ผู้ใช้ใหม่ต้องเข้าใจ debit/credit, posting event หรือรหัสภายในเพื่อทำงานพื้นฐาน
- ข้อความสำเร็จ/ผิดพลาดต้องบอกสิ่งที่เกิดขึ้นและวิธีไปต่อ ไม่ใช้รหัส exception, raw JSON, ชื่อ table หรือข้อความจากฐานข้อมูลเป็นข้อความผู้ใช้
- ทุก module ต้องมี empty state ที่สอนผู้ใช้ว่าต้องสร้างข้อมูลแรกอย่างไร และมีตัวอย่าง workflow/manual QA สำหรับงานประจำวัน

เกณฑ์ตรวจรับ UX: ผู้ใช้ใหม่ควรเปิดคู่มือแล้วสร้าง Draft แรกได้โดยไม่ต้องถามผู้ดูแลเรื่องลำดับเอกสาร, เข้าใจว่าข้อมูลใดจะกระทบ GL/สต็อก, และแก้ blocker ได้จากลิงก์ที่ระบบให้มา

### 10.1 Frontend conventions

- Blade เป็น view layer หลัก และใช้ Blade components/partials สำหรับ form field, status badge, modal, action buttons, empty state และ pagination ที่ใช้ซ้ำ
- View ของ module อยู่ที่ `app/Modules/<Module>/Views` และเรียกผ่าน namespace เช่น `Inventory::stock.index`
- แต่ละหน้าใช้ `@extends`, `@section` และ `@push/@stack` ในรูปแบบที่ทีม `minterp` คุ้นเคย
- ใช้ shared root layout หนึ่งชุด; module layout เป็น thin wrapper ที่ extend root layout ห้ามคัดลอก CSS/JS/vendor assets ไปไว้ทุก module แบบ `minterp`
- เมื่อผู้ใช้เลือก Program และ Warehouse (ถ้า module นั้นต้องใช้) สำเร็จ ต้องเข้า Dashboard ของ module นั้นก่อนเสมอ ห้ามใช้หน้า list, CRUD หรือ report เป็น entry page โดยตรง
- Sidemenu ของทุก module ต้องวางเมนู “กลับหน้าเลือกโปรแกรม” เป็นรายการแรกบนสุดเสมอ ตามด้วย Dashboard แล้วจึงเป็นเมนูงานภายใน module
- เมนูงานภายใน module ต้องจัดเป็น Group ตาม workflow ที่ผู้ใช้เข้าใจ เช่น รายการประจำวัน, ข้อมูลหลัก, รายงาน และตั้งค่า; แสดงหัวข้อ Group เฉพาะเมื่อผู้ใช้มีสิทธิ์เห็นเมนูในกลุ่ม และไม่สร้างเมนู placeholder ที่กดไม่ได้
- ทุกเมนูหรือ route ใหม่ต้องเพิ่ม permission ใน `RbacSeeder`, ผูก permission middleware ที่ route และซ่อน/แสดงใน Sidebar ด้วย permission เดียวกันใน change เดียวกัน พร้อมผูก permission ใหม่กับ role `admin` ใน Seeder เสมอ เพื่อให้ Admin เห็นหลัง seed และผู้ใช้ทั่วไปไม่เห็นเมนูที่ไม่มีสิทธิ์; ก่อน handoff ต้องรัน Seeder บน local และตรวจสิทธิ์ Admin จริง
- ทุกหน้าต้องประกอบจาก root layout, shared Blade components และ shared CSS/design tokens ชุดเดียวกัน; ห้ามมี `<style>` block, `style="..."` หรือ page/module stylesheet ใน Blade เพื่อแต่งหน้าเฉพาะกิจ
- CSS ของระบบอยู่ที่ `public/css/app.css` กลางและครอบคลุม app shell, layout, typography, spacing, card, form, button, table, modal, status และ responsive behavior; ถ้าพบ UI pattern ใหม่ที่ใช้จริงหลายหน้าให้เพิ่มครั้งเดียวใน shared component/style ไม่ copy rule ไปแต่ละ Blade
- import stylesheet ทางการของ Select2, Flatpickr/date picker, DataTables, SweetAlert2 และ library อื่นจาก package โดยไม่แก้ vendor file และไม่เขียน selector override เพื่อเปลี่ยนหน้าตา library; override ได้เฉพาะ compatibility/accessibility defect ที่พิสูจน์แล้วและบันทึกเหตุผลไว้ส่วนกลาง
- ใช้ jQuery เป็นค่าเริ่มต้นสำหรับ DOM selection, event binding, delegated events, AJAX, modal และ DataTables
- ใช้ `$(function () { ... })` และ `.on()`; ห้าม inline `onclick`, global function และ script ที่ผูกกับ DOM ด้วยหลาย convention ในหน้าเดียว
- JavaScript ที่ใช้เฉพาะหน้าให้วางท้าย Blade เดียวกันใน `@push('scripts')` เป็นค่าเริ่มต้น เพื่อให้ทีมเปิดไฟล์เดียวแล้วแก้ view, filter, DataTable และ action ได้; logic กลางหรือ logic ที่ reuse จริงให้อยู่ใน `public/js/` และแยก page script เมื่อยาวจน Blade ดูแลยากเท่านั้น
- ภายใน script ของหน้าใช้ `$(function () { ... })` เพียงชุดเดียว, local variables และ delegated `.on()`; ห้ามคัดลอก legacy `onclick`, global function/variable, `console.log`, ready ซ้ำ หรือ destroy/reinitialize DataTable ทุกครั้งที่ค้นหา
- ใช้ native browser API ได้เมื่อ jQuery ไม่เพิ่มคุณค่า เช่น `Intl`, `URL`, `FormData` และ file APIs แต่ไม่เปลี่ยนหน้าเป็น vanilla-JS architecture คนละแบบ

### 10.2 AJAX form contract

- CRUD form ใช้ jQuery AJAX submit เป็นค่าเริ่มต้น แต่ form ต้องเป็น semantic HTML และมี `action`, `method`, CSRF token และ server-side validation ครบ
- แต่ละหน้า register form ผ่าน `window.erpAjaxForm({ form, url, method, reload, redirect, alert })`; `url`/`method` ไม่ระบุให้อ่านจาก form, `reload` ค่าเริ่มต้นเป็น `false`, `reload: true` ใช้เฉพาะเมื่อต้อง refresh ทั้งหน้า, string selector ใช้ reload DataTable และ `redirect: true` จึงจะใช้ `response.redirect`
- หน้า Create ใช้ `redirect: true` เมื่อสำเร็จเพื่อไปหน้า Edit และกันสร้างซ้ำ; หน้า Update ใช้ `reload: false` เป็นค่าเริ่มต้นเพื่อคงหน้าเดิม ส่วน login/program/warehouse context ใช้ `alert: false, redirect: true`
- Delete action ให้แต่ละหน้า register ผ่าน `window.erpAjaxDelete({ button, url, method, reload, redirect, confirm })`; ค่าเริ่มต้นใช้ `DELETE` และไม่ reload/redirect, หน้า DataTable ระบุ selector ใน `reload` เพื่อ refresh เฉพาะข้อมูลและคง pagination
- ใช้ `serialize()` สำหรับ form ธรรมดา และ `FormData` พร้อม `processData: false`, `contentType: false` เมื่อมีไฟล์/array ที่ต้องรักษาโครงสร้าง
- ทุกปุ่ม/action ที่ส่ง AJAX ต้อง lock ก่อนส่ง request: `<button>` ใช้ `disabled`, link/action control ใช้ class + `aria-disabled="true"` และ event guard; เก็บ label เดิม แสดง spinner/ข้อความกำลังทำงาน และปลด lock ใน `complete` ทั้ง success/error/timeout
- confirmation ต้องเสร็จก่อน lock; เมื่อผู้ใช้ยืนยันแล้วจึง lock control ที่กดหรือทั้ง form ตามขอบเขตงาน ห้าม rebind handler หรือปลดปุ่มจากหลาย callback จนยิง request ซ้ำ
- การ disable ปุ่มเป็น UX เท่านั้น งาน stock, finance, accounting, approval, posting และ document transition ต้องมี transaction/idempotency/state guard ฝั่ง server เสมอ
- งาน background ที่ใช้เวลานานให้ AJAX ตอบ job/reference status แล้วเปลี่ยน UI เป็น pending/progress; ห้ามค้างปุ่ม disabled รอ queue job จบโดยไม่มีทาง recover
- Controller รับค่าผ่าน Form Request และตอบ JSON contract เดียวกัน: `status`, `msg`, optional `data`, `redirect` และ validation `errors`
- HTTP status ต้องมีความหมาย: `200/201` สำเร็จ, `422` validation, `403` forbidden, `404` not found, `409` state/concurrency conflict และ `500` unexpected error
- แสดง validation error ใกล้ field, focus field แรก, แสดงข้อความ server และห้ามกลืน error ด้วย generic success/failure text
- หลัง CRUD ในหน้า list ให้ใช้ `table.ajax.reload(null, false)` แทน full-page reload เมื่อ state เดิมยังใช้ได้
- mutation ใช้ `POST/PUT/PATCH/DELETE`; ห้ามใช้ GET สำหรับ create/update/delete และห้ามส่ง CSRF token ผ่าน query string
- modal create/edit ต้อง reset form, errors และ stale data ทุกครั้ง พร้อมโหลด record ใหม่จาก server เมื่อเปิด edit
- Authorization, branch/warehouse scope, business rule และ accounting invariant ตรวจฝั่ง server เสมอ JavaScript มีหน้าที่ UX ไม่ใช่ security

### 10.3 Alerts and confirmations

- CRUD save แสดง SweetAlert2 จาก `status`/`msg` ของ Controller แล้วทำเฉพาะ `reload`/`redirect` ที่หน้าเปิด option ไว้; validation ยังคงแสดงข้าง field และ navigation action เช่น login/context selection ใช้ `alert: false`
- เมื่อจำเป็นต้องใช้ popup, confirmation, warning, blocking error หรือ toast ให้ใช้ SweetAlert2 ผ่าน shared helper/adapter กลางเท่านั้น; ห้ามใช้ `window.alert()`, `window.confirm()`, `window.prompt()` หรือ plugin alert ตัวที่สอง
- pin SweetAlert2 ไว้ใน `public/vendor` และ include ผ่าน root layout กลาง; module ห้าม import ซ้ำ, ใช้ CDN รายหน้า หรือเรียก `Swal.fire()` ด้วย theme/default ที่ต่างกันกระจายทั่ว code
- shared adapter กำหนด behavior/ข้อความของ `success`, `error`, `warning`, `confirm`, `toast` และ loading ให้สม่ำเสมอ โดยใช้ appearance/CSS มาตรฐานของ SweetAlert2; ไม่สร้าง SweetAlert theme หรือ CSS override เอง
- ข้อความจาก server/user ต้องส่งเป็น text และ escape เป็นค่าเริ่มต้น ห้ามใส่ unsanitized HTML ลง SweetAlert; validation error ราย field ยังคงแสดงใกล้ field ไม่ย้ายทั้งหมดไป popup
- destructive/irreversible action ต้องใช้ explicit confirmation ที่บอกเอกสาร/ผลกระทบชัดเจน; หลัง confirm ให้ใช้ AJAX action-lock contract และแสดงผลจาก response จริง ห้ามแสดง success ก่อน server สำเร็จ
- network/timeout/`403/409/500` ใช้ SweetAlert2 เมื่อ user ต้องหยุดและรับรู้; `422` แสดง field errors/focus โดยไม่เปิด popup ซ้ำ และ `404` เลือก inline/redirect/popup ตามว่าหน้าปัจจุบัน recover ได้หรือไม่

### 10.4 DataTables and Yajra

- Laravel 12/PHP 8.2 ใช้ `yajra/laravel-datatables-oracle:^12.0`; DataTables/Buttons/Excel frontend files ที่ compatible ให้ pin ใน `public/vendor` และ include กลางเมื่อสร้าง reference table
- DataTable ทุกตัวต้องแสดงปุ่ม Export Excel, pagination, page-length selector และ global search โดยเปิด `paging`, `lengthChange` และ `searching` ผ่าน shared defaults เดียวกัน ห้าม module ปิดเองหากไม่มี requirement ที่อนุมัติ
- รายงาน Trial Balance และ General Ledger ใช้ posted journal lines ชุดเดียวกัน, รับ period/Warehouse scope จาก server และ export ต้องส่ง filter period/account ชุดเดียวกับ DataTable
- ใช้ `processing: true` และ Yajra `serverSide: true` เป็นค่าเริ่มต้นสำหรับรายการจากฐานข้อมูลที่โตได้, transaction/master, ตารางหลายสาขา หรือรายการที่มี filter/sort/search; ใช้ client-side ได้เฉพาะชุดข้อมูลเล็ก/คงที่และต้องบันทึกเหตุผลใน code review
- ตารางข้อมูลน้อยและคงที่ใช้ Blade table ปกติ ไม่บังคับ DataTables ทุกหน้า
- แยก web route ที่ render หน้าออกจาก named data route ที่คืน DataTables JSON เช่น `items.index` และ `items.data`
- `index()` ของหน้า DataTable คืน Blade เท่านั้นและห้าม query/compact/paginate row dataset ไป render ด้วย `@foreach`; JavaScript รายหน้าต้อง initialize ตารางแล้ว AJAX ไป `data()` route. อนุญาตให้ compact เฉพาะ reference/filter options ขนาดเล็กที่ไม่ได้เป็นแถวหลักของตาราง
- CRUD controller คืน JSON contract `status: boolean`, `msg: string` และ optional `redirect`; jQuery form submit แสดง SweetAlert2 จาก response ก่อน action ที่ page เปิด option ไว้. Row delete ใช้ delete permission แยก, `erpAjaxDelete()`, SweetAlert confirm, action lock, server-side transaction/domain guard/audit และ reload DataTable เมื่อ `status=true`; ห้าม hard delete master/history โดยไม่มีกฎ domain อนุมัติ
- Query ต้อง select เฉพาะ column ที่ใช้, eager-load relation ที่จำเป็น, scoped ด้วย branch/warehouse permission และตรวจ N+1
- กำหนด allowlist ของ searchable/orderable columns; ห้ามนำชื่อ column จาก request ไปต่อ raw SQL
- escape output เป็นค่าเริ่มต้น; `rawColumns` อนุญาตเฉพาะ action/status HTML ที่สร้างจาก trusted Blade partial และไม่มี user-supplied HTML
- action column ต้อง `searchable: false`, `orderable: false` และสร้างปุ่มตาม permission ฝั่ง server
- filter หลักต้องส่งเป็น AJAX parameters ที่อ่านซ้ำได้ และ reload table โดยไม่ reinitialize DataTable
- server ต้องคืนจำนวน total/filtered ถูกต้อง และ query ต้องมี index รองรับ filter/order ที่ใช้จริง
- DataTable ทุกหน้าใช้ DataTables Buttons `excelHtml5` เป็นค่าเริ่มต้น; client-side table export แถวตาม search ทั้งหมดที่ browser มี ส่วน server-side table export เฉพาะ page/แถวที่โหลดอยู่ใน browser
- backend full-dataset export ไม่ใช่ค่าเริ่มต้น เพิ่มเฉพาะหน้าที่เจ้าของระบบระบุภายหลัง และต้องใช้ filter, sort, branch/warehouse scope กับ permission ชุดเดียวกับ data route
- export ปริมาณมากทำผ่าน queued export/report พร้อม progress/download status ไม่ดึงทุกแถวเข้า browser หรือทำ request ยาวผ่าน DataTables
- DataTables error ต้องแสดงข้อความที่เป็นมิตรใน production และ log correlation/reference ฝั่ง server ห้ามเปิด SQL/debug payload
- ค่าทุก column ที่แสดงต่อผู้ใช้ต้องเป็น human-readable ห้ามแสดงค่า raw จาก database/API โดยตรง: วันที่เอกสารและ business date ใช้ company date format, datetime แปลงเป็น company timezone ก่อนแสดง, status/boolean ใช้ label ที่อ่านเข้าใจและแปลตาม locale, structured value ต้อง render ไม่แสดง raw JSON/HTML entity และค่าว่างใช้ `-` ให้สม่ำเสมอ; หาก sort/filter ต้องใช้ค่า ISO หรือตัวเลขดิบ ให้แยกเป็น internal data ไม่ใช้เป็นข้อความแสดงผล

### 10.5 Shared components and public libraries

- ก่อนสร้าง component/helper/plugin ใหม่ ให้ค้นของกลางและของ module ที่มีอยู่ก่อน; ย้ายเป็น shared เมื่อถูกใช้จริงอย่างน้อยสอง module หรือเป็น platform invariant ไม่สร้าง abstraction เผื่ออนาคต
- shared Blade components อยู่ใน `resources/views/components/`, JavaScript initializer/adapter อยู่ใน `public/js/` และ shared style อยู่ใน `public/css/app.css`; shared PHP helper อยู่ใน `app/Support/` เฉพาะเมื่อไม่มี owner module ที่เหมาะสม
- baseline library ของ MVP คือ Select2 สำหรับ searchable/multiple select, Flatpickr ชุดเดียวสำหรับ date/datetime/range, Dropzone สำหรับ drag-and-drop file/image upload, SweetAlert2 สำหรับ alert/confirm/toast และ DataTables/Yajra สำหรับ server-side tables
- ดาวน์โหลด official distribution, pin version/source/checksum ใน `public/vendor/manifest.json`, include ผ่าน root layout กลาง และ initialize ผ่าน adapter/data attribute กลาง เช่น `data-ui="select2|date|datetime|dropzone"`
- ห้ามแปะ CDN ใน Blade รายหน้า ห้ามมี vendor file ซ้ำหลาย directory และห้ามให้แต่ละ module ตั้ง locale/theme/default/options คนละชุด; การอัปเดต library ทำที่ `public/vendor` และ manifest จุดเดียว
- adapter กลางมีหน้าที่กำหนด Thai locale, timezone/date format, AJAX/error contract, shared DataTables features และ lifecycle ใน modal/dynamic DOM เท่านั้น ห้าม rewrite ความสามารถหรือ CSS/appearance ของ public library
- ตัวอย่าง component ที่สร้างเมื่อมีหน้าจอใช้งานจริง: `<x-form.select>`, `<x-form.date>`, `<x-form.datetime>`, `<x-form.file-upload>`, `<x-form.errors>`, `<x-modal>`, `<x-status-badge>` และ `<x-data-table>`
- Select2 remote data ต้อง paginate/debounce, escape output และ scope ตาม branch/warehouse permission; date/datetime ต้องส่งรูปแบบมาตรฐานที่ server parse แบบ explicit; Dropzone ต้องใช้ CSRF, upload limit, attachment ID และ authorization/cleanup contract ของ Platform
- Select option ที่อาจมีข้อมูลจำนวนมากต้องใช้ Select2 AJAX แบบ remote search + pagination/debounce โดยเฉพาะ Chart of Accounts, Customer, Supplier, Item และเอกสารอ้างอิง; ห้ามโหลด option ทั้งหมดลงหน้าเดียวเมื่อจำนวนอาจสูงตามข้อมูลของบริษัท. Native select ใช้ได้เฉพาะรายการเล็กและคงที่ เช่น ประเภท/สถานะ/Tax kind
- สถานะการใช้งาน: Journal Entry, Account parent, Other Income/Expense, Bank/Cash, Receipt/Payment references, Customer และ Supplier ใช้ Select2 AJAX พร้อม pagination/debounce แล้ว; Item ยังเป็น backlog ของ WMS และต้องใช้ endpoint แบบเดียวกันก่อนปิด module
- dependency ใหม่ต้องตรวจ license, maintenance, security, Laravel/browser compatibility และเหตุผลที่ native HTML หรือ baseline library เดิมไม่พอ แล้วเพิ่มใน registry นี้ก่อนใช้ทั้ง project

| Capability | Library/owner | Project convention |
|---|---|---|
| Searchable/multiple select | Select2 | shared initializer + `<x-form.select>` |
| Date, datetime, range | Flatpickr | shared locale/time adapter; ไม่เพิ่ม date-picker ตัวที่สอง |
| File/image upload | Dropzone + Platform FileStorageService | shared upload component; metadata อยู่ใน attachments |
| Alert, confirm, toast | SweetAlert2 | shared adapter; ห้าม native alert/confirm/prompt |
| Server-side table | DataTables + Buttons/Excel + Yajra v12 | shared defaults มี Excel, pagination, page length, search; query/export/permission อยู่ฝั่ง server |
| Dashboard chart | Chart.js 4.5.1 | local-pinned UMD ผ่าน root layout; หน้า Dashboard ใช้ legend/tooltip ที่อธิบายความหมายของข้อมูล |

### 10.6 Visual system

- พื้นหลังขาว/เทาอ่อน, ตัวอักษรดำ/เทาเข้ม, border เทากลาง
- สีสถานะใช้เท่าที่จำเป็นและต้องมีข้อความ/icon ร่วมด้วย ห้ามสื่อความหมายด้วยสีอย่างเดียว
- เพิ่มสีสันแบบ subtle accent ได้เล็กน้อยใน badge, status, icon และปุ่มรอง เพื่อให้หน้าจอมีชีวิตชีวาคล้ายแนวทางเว็บธุรกิจสมัยใหม่ เช่น Soft Steel แต่ไม่เปลี่ยนระบบเป็นโทนจัดหรือ dashboard สีเข้ม
- สี accent กลางให้ใช้ชุด semantic เดียวกัน: `primary` น้ำเงิน/indigo สำหรับ action หลัก, `success` เขียวสำหรับสำเร็จ/ใช้งาน, `warning` amber สำหรับรอตรวจสอบ/soft close, `danger` แดงสำหรับผิดพลาด/ลบ, `info` ฟ้าอมเขียวสำหรับข้อมูล และ `neutral` เทาสำหรับปิดใช้งาน
- ปุ่มหลักใช้สี accent ได้หนึ่งจุดต่อกลุ่ม action; ปุ่มรองใช้ outline/neutral เป็นหลัก และ badge ต้องมีข้อความชัดเจนพร้อม contrast ที่อ่านได้บนพื้นขาว
- ลำดับปุ่มของเอกสาร/Workflow ให้เรียงจากซ้ายไปขวาเป็น **การดำเนินการหลัก** (เช่น อนุมัติ/ลงบัญชี/บันทึก) → **การดำเนินการทำลายหรือยกเลิก** → **กลับ/รายการทั้งหมด**; ปุ่มหลักอยู่ด้านขวาของกลุ่ม action เมื่อ layout หรือ responsive order ทำให้ต้องจัดกลุ่มใหม่ และเอกสาร Posted ใช้เฉพาะปุ่ม Reversal/เอกสารแก้ไขตาม contract
- ปุ่ม `primary` ใช้กับ next action ที่ระบบคาดหวัง, `success` ใช้กับการอนุมัติ/เสร็จสิ้น, `dark` ใช้กับการลงบัญชีหรือ action สำคัญ, `outline-danger` ใช้กับยกเลิก/ลบ/กลับรายการ, และ `outline-dark/secondary` ใช้กับกลับหรือรายการทั้งหมด; หนึ่งกลุ่มมี primary action เด่นได้เพียงหนึ่งปุ่ม
- Badge ทุก Module ต้องเป็น pastel/soft fill ตาม semantic color (ประมาณฟ้าอ่อน เทาอ่อน แดงอ่อน เขียวอ่อน เหลืองอ่อน ม่วง/ชมพูอ่อนเมื่อมี semantic เพิ่ม), ตัวอักษรต้องเข้มพออ่านง่าย, ห้ามใช้พื้นสีเข้มจัด/neon/gradient และห้ามกำหนดสีเฉพาะหน้าแทน shared tokens
- ชื่อคลาส Bootstrap เดิม เช่น `text-bg-success`, `text-bg-warning`, `text-bg-danger`, `text-bg-info`, `text-bg-secondary` และ `text-bg-dark` ใช้ได้เฉพาะเพื่อ compatibility; shared CSS ต้อง map ไปยัง pastel token เดียวกับ `app-status-success/warning/danger/info/neutral/primary` เสมอ
- ห้ามใส่ gradient, glow, สี neon หรือสีสุ่มรายหน้า; สีใหม่ต้องเพิ่มที่ shared design token/component ใน `public/css/app.css` และใช้ซ้ำได้หลาย module
- central design tokens สำหรับ color, spacing, radius, shadow และ typography
- layout เดียวสำหรับทุก module: sidebar, topbar, breadcrumb, page actions และ content
- app shell และ component ของระบบเน้นขาว ดำ เทากลาง เรียบง่าย และใช้ border radius กลางชุดเดียวให้ card, form control, button, modal และ container ดูโค้งมนสม่ำเสมอ; library widget คง appearance จาก stylesheet ทางการ
- table ต้องอ่านง่าย มี filter, pagination, sticky action เมื่อเหมาะสม และ responsive fallback
- หน้าที่มี Filter ต้องวาง Filter ไว้ใน card แยกจาก card ตาราง/รายงานเสมอ และมีปุ่ม `ล้างตัวกรอง` ที่ล้างค่าทุก field (รวม Select2/AJAX select) แล้ว reload ข้อมูลโดยไม่ reinitialize DataTable; ปุ่มล้างใช้ `outline-secondary` และอยู่ท้ายแถว Filter
- form แบ่ง section สั้น ๆ, label ชัด, error ใกล้ field และ keyboard accessible
- form control ทุกหน้าต้องมี readable minimum width ตามชนิดข้อมูล: searchable select/บัญชี GL, คำอธิบาย, จำนวนเงิน, Tax และวันที่ห้ามถูกบีบจนอ่านไม่ออก; ตารางฟอร์มที่มีหลายคอลัมน์ให้ใช้ horizontal scroll/responsive wrapper แทนการลดขนาด input ต่ำกว่าที่ใช้งานได้ และใช้ shared CSS utility ไม่เขียน inline style ซ้ำรายหน้า
- document status และ primary action ต้องอยู่ตำแหน่งสม่ำเสมอ
- พิมพ์เอกสารใช้ print stylesheet/template แยกจาก screen layout
- ห้ามให้แต่ละ module สร้าง theme, button หรือ form component ชุดใหม่เอง
- ห้ามทำ CSS รายหน้าเพื่อแก้ spacing/สี/radius; แก้ที่ shared token/component เมื่อเป็น pattern ของระบบ หรือใช้โครงสร้าง/component ที่มีอยู่เมื่อเป็นความแตกต่างเฉพาะเนื้อหา
