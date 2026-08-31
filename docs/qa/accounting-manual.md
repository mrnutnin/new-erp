# Accounting Manual QA

## Chart of Accounts และ Excel Import

1. Login เป็นผู้ใช้ที่มีสิทธิ์ Accounting แล้วเลือก Program และ Warehouse
2. เข้า Accounting ต้องพบ Dashboard ก่อน และเมนูกลับหน้าเลือกโปรแกรมอยู่บนสุด
3. เปิดผังบัญชีและสร้างโครงสร้างบัญชีระดับ 1 ถึง 5 โดยบัญชีระดับ 1–4 เป็นบัญชีรวม
4. สร้างบัญชีย่อยและบัญชีคุมที่ระดับ 5; บัญชีคุมต้องบังคับเลือก Control Type
5. ทดลองสร้างระดับ 6 ต้องถูกปฏิเสธ และบัญชีต่างหมวดหรือบัญชีที่ลงรายการได้ต้องใช้เป็นบัญชีแม่ไม่ได้
6. ดาวน์โหลด `COA-1.0` template แล้วตรวจว่ามี sheet `Accounts`, `Examples`, `Data Dictionary` และ `_meta`
7. อัปโหลดไฟล์ที่ถูกต้อง ต้องเห็นจำนวนทั้งหมด/ผ่าน/ผิดพลาดก่อน Import และยังไม่มีบัญชีถูกสร้าง
8. อัปโหลดไฟล์ที่มี code ซ้ำ, parent อยู่หลัง child, parent ต่างหมวด, ระดับเกิน 5, formula หรือ Control Type ผิด ต้องเป็น INVALID และ Commit ไม่ได้
9. ดาวน์โหลด Error Workbook แล้วตรวจว่า row number, row key, code และสาเหตุครบ
10. Commit batch ที่ผ่าน ต้องสร้างครบทุกแถวตามลำดับ parent-child, batch เป็น COMMITTED และอัปโหลดไฟล์เดิมซ้ำต้องไม่สร้างบัญชีซ้ำ
11. ตรวจ Audit Log ต้องพบ account created ทุกบัญชีและ `migration.chart_of_accounts.committed`
12. ผู้มีเพียงสิทธิ์ import ต้อง Stage/Preview ได้แต่ Commit ไม่ได้; ผู้ไม่มีสิทธิ์ import ต้องเข้า route และไม่เห็นปุ่มไม่ได้

หมายเหตุ: ระยะนี้เก็บ checksum และ staged rows ในฐานข้อมูล ส่วนไฟล์ต้นฉบับ/error workbook บน private GCS จะเปิดใช้เมื่อ Platform storage contract พร้อม
