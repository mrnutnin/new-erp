# Party Masters Manual QA

ตรวจ Customer ใน POS และ Supplier ใน Purchasing โดยไม่รวมการทดสอบไฟล์ Export ตามขอบเขต MVP.

- ใช้ user ที่มีเฉพาะ permission `view` ยืนยันว่าเห็นรายการ แต่ไม่เห็นปุ่มเพิ่ม แก้ไข หรือลบ; ทดสอบ route mutation โดยตรงต้องถูกปฏิเสธ.
- สร้าง Customer และ Supplier คนละราย แล้วค้นหาด้วยรหัส ชื่อ Tax ID และโทรศัพท์จาก DataTable/Select2; ข้อมูลแสดงเป็น label ที่มนุษย์อ่านได้และค่าว่างเป็น `—`.
- เพิ่มบทบาท Supplier ให้ Party ที่เป็น Customer อยู่แล้วด้วยรหัสและ Tax ID เดิม; ต้องได้ Party ID เดิมและมีสอง role โดยไม่สร้าง Tax identity ซ้ำ.
- ปิด Customer role ของ Party ที่ยังมี Supplier role; Supplier ต้องยังเลือกใช้งานได้ ส่วน Customer ต้องไม่อยู่ใน active options.
- แก้ Payment Term/Credit Limit ของหนึ่ง role; อีก role ต้องไม่เปลี่ยน และเอกสารเดิมต้องคง due date ที่ snapshot ไว้.
- Party ที่มี Open Item หรือ Settlement ต้องลบบทบาทที่เกี่ยวข้องไม่ได้และแนะนำให้ปิดใช้งาน; การแก้รหัส/ประเภท/Tax identity ต้องถูกปฏิเสธ.
- ตรวจ Audit Log ของ create, update, attach role และ delete role ว่า before/after เป็น structured human-readable data และไม่มี HTML entity/raw JSON บนหน้าจอ.
- เปลี่ยน Warehouse context แล้วเปิด Customer/Supplier master; master ต้องเป็น company scopeเหมือนเดิม แต่ Open Item/Settlement ยังคง scope ตาม Warehouse ที่เลือก.
- ตรวจ Finance Receipt/Payment และ AR/AP filters ว่าแสดง `รหัส · ชื่อคู่ค้า`, ส่ง Party ID แบบตัวเลข และวันที่แสดงตาม Company date format.
