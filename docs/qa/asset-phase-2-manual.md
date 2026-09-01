# Asset Phase 2 Manual QA

ใช้ Program Asset และสาขา HQ. ทดสอบบนข้อมูล Purchase Invoice และ Draft Asset ที่อยู่สาขาเดียวกัน.

- ตั้งค่า Item Master ให้ “รับรู้เป็นสินทรัพย์ได้” พร้อมหมวดสินทรัพย์เริ่มต้น: เฉพาะบรรทัด Invoice ที่ใช้ Item นี้จึงต้องปรากฏใน source lookup และ Draft Asset ที่เลือกต้องเป็นหมวดเดียวกัน. Item อื่นหรือ free-text ต้องไม่แสดง.
- ผู้ไม่มีสิทธิ์ exception ต้องไม่ค้นหา Item/free-text ที่ไม่เข้าเกณฑ์ได้. ผู้มีสิทธิ์ต้องติ๊กข้อยกเว้น, ระบุเหตุผลอย่างน้อย 10 ตัวอักษร แล้วจึงเลือกได้; Detail ของเอกสารต้องแสดงเหตุผลนั้น.
- สร้าง Draft capitalization จาก Purchase Invoice สถานะ `APPROVED`: เลือกและบันทึกร่างได้ แต่ส่งอนุมัติไม่ได้จนเอกสารต้นทางเป็น `POSTED`.
- จาก Purchase Invoice `POSTED` หนึ่งบรรทัด สร้าง Draft Asset สองหรือมากกว่า แล้วแบ่งต้นทุนให้แต่ละ Asset. ยอดรวมเท่ากับหรือน้อยกว่า `net_amount` ต้องผ่าน; ยอดเกินต้องถูกปฏิเสธ.
- ระบุต้นทุนต่ำกว่า capitalization threshold ของหมวด: ระบบต้อง block ก่อนส่งอนุมัติ. เปลี่ยนวันที่ Post ไปอยู่งวดที่ปิด/ล็อก: ระบบต้อง block และแจ้งให้เปิดงวดก่อน.
- สร้าง `MANUAL_RECLASS` จากทะเบียน Asset ร่าง: ต้องเลือกได้เฉพาะบัญชีย่อยที่ลงรายการได้, ระบุหมายเหตุอย่างน้อย 10 ตัวอักษร, และหลัง Post ต้องเป็น Dr Fixed Asset / Cr บัญชีเครดิตที่เลือก. ห้ามเลือกบัญชีคุมเป็นฝั่งเครดิต.
- ส่งอนุมัติ, อนุมัติ และ Post ตามสิทธิ์. หลัง Post ทุก Asset ต้องเป็น `ACTIVE`, ต้นทุน/value event/history ต้องตรงกับแต่ละบรรทัด และ Journal เป็น Dr Fixed asset / Cr expense หรือ clearing account ไม่ใช่ AP.
- กด Post ซ้ำหรือเปิดเอกสารเดิมอีกครั้ง: ต้องไม่สร้าง Journal หรือ value event ซ้ำ.
- ใบที่ `APPROVED` แต่ยังไม่ Post: ยกเลิกได้เมื่อระบุเหตุผลอย่างน้อย 10 ตัวอักษร, สถานะเป็น `VOID`, ไม่มี Journal/value event เพิ่ม และต้องแสดงผู้ยกเลิก/เวลา/เหตุผลใน audit.
- กลับรายการด้วยวันที่และเหตุผลอย่างน้อย 10 ตัวอักษร: ต้องสร้าง reversal journal/event, ลดต้นทุนในทะเบียน และเปลี่ยนเอกสารเป็น `REVERSED`; กลับซ้ำต้องไม่สร้างซ้ำ.
- เปลี่ยนสาขาแล้วเรียก URL ของ capitalization หรือ API options โดยตรง: ต้องไม่เห็นเอกสาร, Purchase source, Draft Asset หรือ account ของสาขาอื่น.
- เตรียม Opening Balance staging batch ที่ผ่าน Validate แล้ว Commit: ต้องสร้าง Asset `ACTIVE`, opening value event/history และผูก staging line กลับไปที่ Asset โดยไม่มี Journal/GL ใหม่. Commit batch เดิมซ้ำต้องถูกปฏิเสธหรือ idempotent ตามสถานะ.
