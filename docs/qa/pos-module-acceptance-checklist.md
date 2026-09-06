# POS — Module Acceptance Checklist

สถานะ: พร้อมตรวจรับ UAT

เอกสารนี้เป็น checklist ปัจจุบันของ POS ที่ใช้ปิดงาน MVP โดยแยกงานที่ส่งมอบแล้วออกจากงานที่ต้องทดสอบด้วยข้อมูลจริง และงานที่ตั้งใจเลื่อนไว้ เพื่อไม่ให้สถานะในแผนเก่าปะปนกัน

## 1. Context และสิทธิ์

- [x] Top bar แสดงสาขาปัจจุบัน ไม่แสดงคลังเป็น context หลัก
- [x] เอกสารขายบันทึก `branch_id` เสมอ
- [x] จุดที่เกี่ยวกับสินค้า/ส่งมอบเลือกคลังได้เฉพาะคลังของสาขาปัจจุบัน
- [x] Sidebar POS รองรับย่อ/ขยาย, เมนูย่อย และ responsive
- [x] เมนูและ action ใหม่ผูก permission และ Admin seed แล้ว
- [ ] UAT เปลี่ยนสาขาแล้วตรวจว่าเอกสาร, รายงาน และ Dashboard ไม่ข้ามสาขา
- [ ] UAT ทดสอบ role ผู้ใช้งานจริง: ดู/สร้าง/แก้ไข/อนุมัติ/ส่งการเงิน ตามสิทธิ์

## 2. ข้อมูลหลักและราคา

- [x] Customer และ Customer Group ใช้ Party/Role กลาง
- [x] Price List รองรับ CRUD, กลุ่มลูกค้า, ช่วงมีผล, ลำดับความสำคัญ, Select2 และ snapshot ราคา
- [x] Promotion รองรับต่อรายการและท้ายบิล, ช่วงเวลา, กลุ่มลูกค้า, ขั้นต่ำ, priority และ policy ใช้ร่วมกัน (stackable)
- [x] Promotion มี priority สูงกว่า Price List และ freeze snapshot ในเอกสารขาย
- [x] Promotion ใช้หน่วย Stock ของสินค้าแบบ read-only
- [x] หน้าสร้าง/แก้ไข Promotion มีคำอธิบาย field และความสัมพันธ์ของการคำนวณ
- [ ] UAT ทดสอบ Price List fallback เมื่อไม่มี Promotion ที่เข้าเงื่อนไข
- [ ] UAT ทดสอบ Promotion ต่อรายการ/ท้ายบิล, stackable/non-stackable, VAT และยอดรวม

## 3. เอกสารขาย

- [x] ใบรับข้อมูลเบื้องต้น: สร้าง/แก้ไข, เลือกสินค้าแบบอ่านง่ายแม้ชื่อยาว, เลือก Promotion, ยอดคำนวณจาก server
- [x] ใบขอราคา, ใบเสนอราคา และใบสั่งขาย: linkage, สถานะ, PDF และ DataTable
- [x] ขายสด/ขายเชื่อ (HS/IV): สร้างจาก Sales Order, เลขเอกสาร, ภาษี, Detail และสถานะเอกสาร
- [x] ใบลดหนี้/รับคืน: source-line guard, filter, PDF และการหักยอดขายสุทธิในรายงาน
- [x] ใบรับมัดจำ/การรับชำระเชื่อม Finance ตาม contract กลาง
- [ ] UAT สร้างเอกสารครบ flow Intake → RFQ/Quotation → Sales Order → HS/IV
- [ ] UAT ทดสอบเลขเอกสาร HS/IV ของสาขาและประเภทเอกสารที่เปิดใช้
- [ ] UAT ทดสอบ void/return และตรวจยอดสุทธิ, AR และเอกสารอ้างอิง

## 4. รายงานและ Dashboard

- [x] รายงานยอดขายรายวัน, ตามลูกค้า และตามสินค้า พร้อม filter, DataTable และ Excel
- [x] รายงานกำไรขั้นต้นและรายงานผล Promotion ใช้ข้อมูล Post/snapshot และรองรับ return
- [x] Campaign ROI แสดงเป้ายอดขาย/GP, งบประมาณ, ค่าใช้จ่ายจริง, Contribution และ ROI
- [x] ตั้งเป้าสาขา/พนักงาน และรายงานผลเทียบเป้า แยกตารางสาขาและพนักงาน พร้อม Chart/เปอร์เซ็นต์
- [x] POS Dashboard แยก API ตาม section เพื่อลด DB load; แสดงยอดสุทธิ, HS, IV, ลดหนี้, trend, document mix, เป้า, งานค้าง, สินค้าขายดี และเอกสารที่อนุมัติแล้ว
- [x] สินค้าขายดีเรียงยอดขายสุทธิจากมากไปน้อย และใช้หน่วย Stock
- [x] Toast แจ้งเตือนลูกหนี้ใกล้ครบกำหนดชำระ พร้อมลิงก์ไปยังรายการลูกหนี้
- [ ] UAT เทียบยอด Dashboard/รายงานกับ HS, IV, return และ Finance Open Item จริง
- [ ] UAT ทดสอบ Dashboard พร้อมผู้ใช้หลายคน และตรวจ API/DB response time ตามข้อมูลจริง
- [ ] UAT ทดสอบ Toast ด้วยลูกหนี้ที่ครบกำหนดภายใน 3 วัน

## 5. คอมมิชชั่นขายและส่งต่อ Finance

- [x] ตั้งค่าแผนคอมมิชชั่น, ผู้รับ, เป้าหมาย/เงื่อนไข และรายการคอมมิชชั่น
- [x] รายการคอมมิชชั่นอนุมัติ/ไม่อนุมัติได้; เหตุผลบังคับเฉพาะไม่อนุมัติ
- [x] สร้างชุดจ่าย (CB) จากช่วงวันที่และเลือกผู้รับทั้งหมดหรือบางรายได้
- [x] ชุดจ่ายแสดงรายละเอียด reference ต่อพนักงาน, สถานะ และประวัติเอกสาร
- [x] POS ส่งชุดให้ Finance; Finance ตรวจสอบ, สร้างใบขอจ่าย (CPR), อนุมัติ, สร้างใบสำคัญจ่าย (PV) และจ่ายเงินจริง
- [x] รองรับ action ทั้งชุด, ป้องกันสร้าง CPR/PV ซ้ำ, reuse Supplier พนักงาน และ audit trail
- [x] ยกเลิกตามลำดับเอกสาร พร้อมแยกเหตุผล POS/Finance และคงสถานะรายการคอมมิชชั่นตามกติกา
- [ ] UAT ทดสอบครบ flow CB → Finance review → CPR → PV → settlement/post
- [ ] UAT ทดสอบการยกเลิกทั้งก่อน/หลังสร้าง CPR และก่อน/หลังสร้าง PV

## 6. การควบคุมและการตรวจรับสุดท้าย

- [x] หน้า DataTable/รายงานใช้ข้อมูลอ่านง่าย, filter, search, pagination และ Excel export
- [x] Audit trail แสดงเวลา timezone บริษัท (ประเทศไทย UTC+7), ผู้ดำเนินการ, เหตุผล และสถานะสำคัญ
- [x] Unit tests, Pint และ Blade compilation ผ่านตามงานที่พัฒนา
- [ ] ยืนยัน Global Settings ที่จำเป็น: เลขเอกสาร HS/IV, VAT/Tax Code, payment term และ account mapping
- [ ] ตรวจสอบข้อมูล mockup ที่ใช้ทดสอบ แล้วลบหรือแยกจากข้อมูลใช้งานจริงตามนโยบายก่อนเปิดใช้
- [ ] ทำ UAT sign-off ตามหัวข้อที่ยังไม่ติ๊กในเอกสารนี้

## 7. นอกขอบเขต MVP / ยังไม่ควรประกาศว่าเสร็จ

- [ ] Campaign เงื่อนไขซับซ้อน เช่น ช่องทางขาย/e-commerce หรือ rule engine หลายมิติ
- [ ] HS/IV stock issue, COGS และ GL posting สำหรับสินค้าคงคลัง จนกว่า WMS costing/Inventory-to-GL gate จะเปิด
- [~] ใบวางบิล MVP — รวม Posted Invoice ที่มียอดคงค้างตามลูกค้า, Draft/ออก/ยกเลิก, เลขเอกสารตามสาขา และไม่สร้าง GL ซ้ำ; PDF/พิมพ์และใบเพิ่มหนี้ยังไม่รวม
- [ ] Cashier shift/cash reconciliation และ payment channel analytics
- [ ] e-Commerce/payment gateway/webhook/e-Tax Invoice integration

## เกณฑ์ปิดงาน POS MVP

ให้ปิดงานหลัง UAT หัวข้อที่เกี่ยวกับ capability ที่เปิดใช้ผ่านครบ, ยอดขาย/AR/commission กระทบยอดได้, role จริงเข้าเมนูได้ถูกต้อง และไม่มี mockup data ปะปนกับข้อมูล production.
