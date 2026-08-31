# DataTable / Query Performance Audit

อัปเดต: 22 สิงหาคม 2026

## ผลตรวจ

- หน้ารายการที่มีโอกาสโตใช้ `index()` คืนเฉพาะ Blade และโหลดข้อมูลผ่าน Yajra/AJAX
- Select2 AJAX ใช้ pagination แบบ bounded สูงสุด 31 แถวต่อคำขอ (แสดงจริง 30 แถว)
- ไม่พบ `index()` ใดโหลด dataset หลักด้วย `get()` ในรอบตรวจนี้
- Recost request ที่อาจมีจำนวนมากเปลี่ยนเป็น `chunkById(250)` แล้ว

## `get()` ที่ยอมรับได้

- Journal Books: มีรายการระบบตายตัว 5 เล่ม ใช้ client-side form ตาม contract
- Account types, permissions, fiscal periods และ mapping metadata: เป็น reference/config ขนาดเล็ก ไม่ใช่ dataset ธุรกรรม
- Posting/locking paths: `get()` ใช้หลัง lock เพื่ออ่านบรรทัด/intent/layer ที่ต้องตรวจความครบถ้วนและจัดลำดับตาม transaction contract ไม่ใช่ endpoint DataTable
- Select2 options: query ถูกจำกัดไว้ที่ 31 แถวและมี search/order index ที่เหมาะสม

## กติกาต่อเนื่อง

1. ห้ามย้าย query ธุรกรรมจำนวนมากเข้า `index()` หรือ Blade
2. รายการใหม่ให้ใช้ `DataTables::eloquent()` หรือ `DataTables::query()` และส่งข้อมูลผ่าน AJAX
3. หากต้องอ่านชุดข้อมูลเพื่อคำนวณ/lock ให้กำหนดเพดานจาก business contract หรือใช้ `chunkById()` เมื่อไม่จำเป็นต้องรักษาลำดับทั้งชุด
4. งาน Recost ใช้ queue/scheduler เฉพาะ correctness path; รายงานทั่วไปยัง synchronous ตามขอบเขต MVP
