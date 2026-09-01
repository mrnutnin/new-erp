# Asset Phase 1 Manual QA

ทดสอบด้วยผู้ใช้ที่เลือก Program Asset และสาขา HQ โดยไม่รวม Capitalization/GL ซึ่งเป็น Phase 2.

- เปิดทะเบียนสินทรัพย์: DataTable ต้องแสดงเฉพาะ Asset ของสาขาที่เลือก, ค้นหา/แบ่งหน้า/Excel export ทำงาน และค่า/สถานะเป็นภาษาอ่านง่าย.
- สร้าง Category โดยเลือกบัญชีสินทรัพย์ที่เป็น `FIXED_ASSET`; บัญชีที่ inactive, non-postable หรือ type ไม่ตรงต้องเลือกหรือบันทึกไม่ได้. เมื่อ Category ถูกอ้างอิงแล้ว ต้องลบไม่ได้และให้ปิดใช้งานแทน.
- สร้าง Location แบบอาคารและห้องใต้สาขา HQ; ห้ามเลือก parent, warehouse หรือแก้ location ให้ข้ามสาขา และห้ามทำ hierarchy วนกลับ.
- สร้าง Draft Asset พร้อมวันขึ้นทะเบียน, หมวด, ชื่อ, วันซื้อ, ต้นทุน, สกุลเงิน และอัตราแลกเปลี่ยน. เลขทะเบียนต้องอิง `registration_date` และ counter ของสาขา HQ.
- แก้ `registration_date` ขณะ Draft: เลขทะเบียนต้องเปลี่ยนตามวันที่เอกสารโดยไม่ reuse เลขเดิม. หลังสถานะไม่ใช่ Draft การแก้ไขและลบต้องถูกปฏิเสธ.
- เลือก warehouse, location, custodian และ parent Asset: ทุกตัวต้องอยู่สาขาเดียวกัน; custodian ต้องมี warehouse assignment ที่ active ในสาขา.
- ตรวจหน้า Detail: ภาพรวม, มูลค่า/Book-Tax, ตำแหน่ง/ผู้ดูแล, เอกสารแนบ และประวัติแสดงข้อมูลของ Asset ที่เลือกเท่านั้น.
- อัปโหลดไฟล์ตัวอย่างที่อนุญาต, ดาวน์โหลด และลบ: ต้องถูก authorize ตาม Asset/สาขา, ไฟล์ไม่ถูกเปิดเป็น public URL และ Audit/Asset history ต้องมีรายการที่เกี่ยวข้อง.
- เปิด printable QR/Barcode label: ต้องมี asset number, tag/barcode และข้อมูลสาขา; ใช้ browser print ได้โดยไม่เรียก printer driver.
- เปลี่ยนไปสาขาอื่นแล้วเปิด route ของ Asset HQ โดยตรง: ต้องไม่เห็นข้อมูลและต้องไม่ดาวน์โหลด attachment ได้.
- ตรวจ Audit Log และ Asset history ของ create/update/delete draft/upload/delete attachment: actor, เวลา, reason/reference และ before/after ต้องตรวจย้อนกลับได้.
