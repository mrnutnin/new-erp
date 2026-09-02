# Manual QA — Feature Posting Configuration / Phase 1

## ขอบเขต

Phase 1 เป็น Accounting foundation เท่านั้น จึงยังไม่มีหน้าจอตั้งค่าแบบ event-specific
และไม่เปลี่ยนพฤติกรรมการ Post ของ Feature เดิม

## ตรวจรับบน local/staging

- [ ] เปิด Accounting > Account Mapping และยืนยันว่า mapping เดิมยังแสดง/สร้าง/แก้ไขได้ตามปกติ
- [ ] แก้ mapping เดิม 1 รายการ แล้วตรวจ audit ว่าบันทึกผู้ทำ เวลา ค่าเดิม/ใหม่ และ version
- [ ] Post เอกสาร Feature เดิมที่มี mapping พร้อมใช้งาน 1 รายการ แล้ว retry request เดิม
      เพื่อยืนยันว่าได้ Journal เดิม ไม่สร้างซ้ำ
- [ ] เปิด Journal ที่ Post จากการทดสอบ แล้วตรวจ `posting_metadata` ในฐานข้อมูล:
      ไม่มีใน Journal เก่า และ Journal ใหม่ที่ส่ง metadata ต้องเก็บ event, role, account, source และ version
- [ ] Reverse Journal ที่มี metadata แล้วตรวจว่า Journal กลับรายการเก็บ original metadata
      และไม่เปลี่ยนตาม mapping ที่แก้ภายหลัง
- [ ] ยืนยันว่าไม่มีเมนูหรือ Global Settings ใหม่สำหรับเลือก Account ID / Debit-Credit mapping

## เกณฑ์ผ่าน

ไม่มี regression ของ Account Mapping และ Journal Posting เดิม, retry ไม่สร้าง Journal ซ้ำ,
และ Phase 2 ยังไม่ถูกเปิดใช้กับ Feature ใด
