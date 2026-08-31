# Accounting Foundation Manual QA

ใช้รายการนี้ตรวจ Chart of Accounts และ Fiscal Year/Period foundation หลัง fresh migration หรือก่อนเริ่ม Journal Kernel

## เตรียมระบบ

- [ ] `php artisan migrate --seed` ผ่านบน MySQL และมี Account Type 5 หมวด
- [ ] Admin มี Accounting permissions ตาม Seeder ครบและเข้าโปรแกรม Accounting ได้
- [ ] User ที่ไม่มี Accounting permission เข้า route ที่เกี่ยวข้องได้ 403 และไม่เห็น action

## Chart of Accounts

- [ ] DataTable โหลดผ่าน server-side route พร้อม search, pagination และ page length
- [ ] เพิ่ม root summary account และเพิ่ม child postable account ในหมวดเดียวกันได้
- [ ] ระบบ derive level, normal balance และ statement section จาก parent/type โดยผู้ใช้แก้เองไม่ได้
- [ ] เลือก parent ที่ inactive, postable, ต่างหมวด, เกิน 10 ระดับ หรือทำให้เกิด cycle ถูกปฏิเสธ 422
- [ ] บัญชีที่มีลูกเปลี่ยน parent/type, เปิด postable, ปิดขณะมี active child หรือลบไม่ได้
- [ ] Create/update/delete เขียน audit ใน transaction เดียวกันและ delete เป็น SoftDelete
- [ ] ค่า PAE/NPAE เป็น reporting scope เท่านั้น ไม่สร้าง ledger แยก

## Fiscal Year และ Period

- [ ] สร้างปีบัญชีจากวันแรกของเดือนได้และได้ 12 งวดต่อเนื่องโดยไม่มี gap/overlap
- [ ] ปีที่คร่อม leap year สร้างงวดกุมภาพันธ์ถึงวันที่ 29 ถูกต้อง
- [ ] วันเริ่มที่ไม่ใช่วันที่ 1, code ซ้ำ หรือช่วงปีซ้อนกันถูกปฏิเสธ 422
- [ ] การสร้างปีและ 12 งวดพร้อม audit อยู่ใน transaction เดียวกัน
- [ ] งวดเป็น company-wide ไม่มี `branch_id` หรือ `warehouse_id`
- [ ] หน้าจอไม่มี close/reopen action จน posting/reconciliation gates พร้อม

## Journal Entry Draft

- [ ] Sidebar จัด Group การนำทาง, รายการบัญชี และข้อมูลหลักบัญชี โดยไม่แสดงหัวข้อ Group ที่ไม่มีเมนูตามสิทธิ์
- [ ] รายการ DataTable แสดงเฉพาะ Warehouse session ปัจจุบัน
- [ ] เปิด URL ดู/แก้ไขรายการของ Warehouse อื่นโดยตรงได้ 404
- [ ] เพิ่ม/ลบบรรทัดแล้วชื่อ field และ validation ตรงกับบรรทัดปัจจุบัน โดยเหลืออย่างน้อย 2 บรรทัด
- [ ] รายการที่เดบิตไม่เท่ากับเครดิต, เป็นศูนย์ทั้งคู่ หรือใส่สองด้านในบรรทัดเดียวถูกปฏิเสธ 422
- [ ] สร้าง Draft ในงวด OPEN สำเร็จ มีเลข GJ ไม่ซ้ำ และบันทึก branch/warehouse จาก session
- [ ] แก้ไขได้เฉพาะ Draft ในงวดเดิม; วันที่อยู่นอกงวด OPEN ถูกปฏิเสธ 422
- [ ] Create/update เขียน Audit Log และผู้ไม่มี permission ไม่เห็น action พร้อมเข้า mutation route ได้ 403

## Manual Journal Approval และ Reversal

- [ ] Permission `submit`, `approve` และ `reverse` แยกจากกัน; ผู้ไม่มีสิทธิ์ไม่เห็น action และเข้า mutation route ได้ 403
- [ ] ส่งอนุมัติได้เฉพาะ Draft ที่ balanced ใช้บัญชีย่อย active/postable/non-control และอยู่ในงวด OPEN
- [ ] อนุมัติได้เฉพาะสถานะ VALIDATED และได้สถานะ POSTED พร้อมผู้อนุมัติ เวลา และเหตุผล
- [ ] POSTED เปิดหน้าแก้ไขตรงได้ 404 และไม่มี mutation ใดแก้ date, account, debit หรือ credit เดิม
- [ ] กลับรายการได้เฉพาะ POSTED โดยวันที่กลับรายการต้องอยู่ในงวด OPEN
- [ ] Reversal สร้างเอกสาร POSTED ใหม่ใน Warehouse/Branch เดิม สลับ debit-credit ทุกบรรทัด และเชื่อมถึงเอกสารต้นฉบับทั้งสองทาง
- [ ] เอกสารต้นฉบับเปลี่ยนเป็น REVERSED แต่ยังคง lines เดิมครบ; ยิง reversal ซ้ำถูกปฏิเสธ
- [ ] Submit/approve/reverse บังคับเหตุผลอย่างน้อย 10 ตัวอักษร และ workflow mutation + Audit Log อยู่ใน transaction เดียวกัน
- [ ] ทดสอบ role แยก maker/checker ตาม matrix ที่เจ้าของระบบยืนยัน; รุ่นปัจจุบันแยก permission แต่ยังไม่บังคับผู้สร้างห้ามอนุมัติเอง

## Idempotent Posting Contract

- [ ] Operational module เรียก `JournalPostingService` ภายใน transaction เดียวกับการเปลี่ยนสถานะเอกสารต้นทาง และไม่มี route/direct insert ข้าม contract
- [ ] typed `event_code` เลือก PJ/SJ/CR/CP/GJ ถูกต้อง; event ที่ไม่รองรับ, สมุด inactive หรืองวดไม่ OPEN ถูกปฏิเสธ
- [ ] Post สำเร็จเป็น `POSTED` ทันที ใช้ Branch/Warehouse จาก argument และเก็บ source type/event/id/reference ครบ
- [ ] debit-credit ไม่เท่ากัน, บรรทัดเป็นศูนย์/ใส่สองด้าน, account inactive/non-postable/deleted ถูกปฏิเสธก่อนสร้าง entry
- [ ] control account ไม่มี `subledger_type/subledger_id` หรือระบุมาไม่ครบคู่ถูกปฏิเสธ
- [ ] เรียกซ้ำด้วย source identity และ normalized payload เดิมคืน journal entry ID เดิม โดยจำนวน entries/lines ไม่เพิ่ม
- [ ] source identity เดิมแต่เปลี่ยนวันที่, Warehouse, account, subledger หรือ amount ถูกปฏิเสธจาก `posting_hash`
- [ ] ยิง source identity เดียวพร้อมกันได้ entry เดียวจาก book row lock + unique `idempotency_key`; sequence ต่อ book/period ไม่ซ้ำ
- [ ] automatic reversal รักษา account/subledger, สลับ debit-credit, เชื่อม original และ retry เดิมไม่สร้าง reversal ซ้ำ
- [ ] reversal identity เดิมแต่เปลี่ยนวันที่หรือเหตุผลถูกปฏิเสธ และ reversal ครั้งที่สองด้วย identity ใหม่ถูก state guard ปฏิเสธ
- [ ] ยอด journal และ reversal หักล้างกันเป็นศูนย์ พร้อม drill-down กลับ source document ได้

## General Ledger และ Trial Balance

- [ ] Permission `accounting.reports.view` แยกจาก mutation และผู้ไม่มีสิทธิ์ไม่เห็นเมนู/เข้า route ได้ 403
- [ ] เลือก period แล้ว Trial Balance แสดง opening, period debit/credit และ closing จากเฉพาะ `POSTED` lines ของ Warehouse session
- [ ] ยอดรวม Trial Balance debit/credit เท่ากันทั้ง opening, period และ closing; Draft/Validated/Reversed original ไม่ถูกนับซ้ำ
- [ ] บัญชีที่ถูก SoftDelete แต่มีประวัติยังแสดงในรายงานและ drill-down ได้
- [ ] General Ledger เลือกบัญชี/period แล้วแสดง entry date, number, book, source reference, description, subledger, debit และ credit พร้อม summary opening/closing
- [ ] กดดูจาก GL ไป Journal Entry ได้ และ URL ต่าง Warehouse ถูกปฏิเสธ 404 ตาม scope เดิม
- [ ] เปลี่ยน period/account แล้ว DataTable reload ถูกชุด
- [ ] Search/sort/pagination เป็น server-side และยอด summary ไม่เปลี่ยนผิดตาม pagination

## งบกำไรขาดทุนและงบดุล

- [ ] ผู้มี `accounting.reports.view` เห็นเมนูและเข้า P&L/Balance Sheet ได้; ผู้ไม่มีสิทธิ์ได้ 403
- [ ] P&L แสดงเฉพาะรายการ `POSTED` ใน Warehouse และช่วงงวดที่เลือก พร้อมยอดรายได้ ค่าใช้จ่าย และกำไร(ขาดทุน)สุทธิตรงกับ Trial Balance
- [ ] Balance Sheet ใช้ยอดสะสมถึงวันสิ้นงวด แยกสินทรัพย์ หนี้สิน และทุน พร้อม calculated current-period/retained earnings component ใน summary โดยไม่ post journal เพิ่ม
- [ ] เปลี่ยนงวดหรือ Warehouse แล้วผลรวมเปลี่ยนตามข้อมูล และ drill-down ไป account/journal ได้

## รายงานเปรียบเทียบรายได้

- [ ] ผู้มี `accounting.reports.comparative-income.view` เห็นเฉพาะเมนูรายงานนี้และเข้า index/data ได้; ผู้ไม่มีสิทธิ์ได้ 403
- [ ] เปรียบเทียบ 2 งวดแล้วรายได้แต่ละบัญชีและยอดรวมมาจาก `POSTED` revenue lines ของ Warehouse session เท่านั้น; บัญชีที่มีเพียงงวดใดงวดหนึ่งยังแสดง
- [ ] ผลต่างและร้อยละเปลี่ยนแปลงถูกต้อง; งวดเปรียบเทียบเป็นศูนย์ต้องแสดง `—` แทนการหารด้วยศูนย์
- [ ] ปุ่มดู GL เปิดงวดของแต่ละคอลัมน์ถูกต้อง

## VAT และ Withholding Tax MVP

- [ ] Manual Journal เลือก Tax Code (VAT IN/VAT OUT/NONE VAT/custom WHT), ฐาน/ยอดภาษี และวันที่ Tax Point/รับจ่ายจริงได้
- [ ] เอกสารเลือก VAT IN, VAT OUT หรือ NONE VAT และเลือก VAT-inclusive/exclusive ต่อเอกสารได้ (operational document ยังรอ POS/Finance)
- [ ] Tax point ใช้วันที่เอกสาร แต่ VAT เข้า GL ตอนรับ/จ่ายเงินจริงตามวิธีชำระเงินที่กำหนด
- [ ] WHT รองรับประเภท/อัตรา custom และหักตอนรับเงินจริง พร้อมยอด reconcile กับ GL
- [ ] ปัดเศษระดับบรรทัดและยอดรวมเอกสารตาม `tax_decimal_places`; e-Tax และ e-Withholding ยังไม่อยู่ใน MVP
- [ ] รายงานภาษีเลือกงวดและ basis ได้ระหว่างวันที่รับ/จ่ายจริงกับ Tax Point และแสดง VAT IN/VAT OUT/WHT จาก `POSTED` lines

## Finance Document Sequence

- [ ] Permission `view/create/update/delete` แยกกันครบ; ผู้ไม่มีสิทธิ์ไม่เห็นเมนู/action และเข้า mutation route ได้ 403
- [ ] แต่ละ Warehouse มีรูปแบบรับเงินและจ่ายเงินได้ประเภทละหนึ่งชุด พร้อม reset แบบไม่ reset/รายปี/รายเดือน
- [ ] รูปแบบต้องมี `{NUMBER:n}` หนึ่งครั้ง รองรับเฉพาะ `{PREFIX}`, `{YYYY}`, `{MM}` และเลขที่ได้ยาวไม่เกิน 40 ตัวอักษร
- [ ] สร้าง Receipt/Payment Draft แล้วระบบออกเลขจาก Warehouse/ประเภท/วันที่รับจ่ายอัตโนมัติและไม่ซ้ำ
- [ ] รูปแบบที่ออกเอกสารแล้วเปลี่ยนได้เฉพาะชื่อ/สถานะ ลบไม่ได้แม้เอกสารหรือบัญชีธนาคารถูก SoftDelete
- [ ] ลบรูปแบบที่ยังไม่เคยใช้แล้วสร้างประเภทเดิมใหม่ได้ พร้อม Audit Log create/update/delete และ Warehouse scope ครบ
- [ ] แก้วันที่ของเอกสาร Draft ได้เฉพาะ Draft: ระบบออกเลขใหม่จากรอบของวันที่ใหม่, เก็บเลขเดิมเป็น `SUPERSEDED` และไม่ลบ history
- [ ] เปลี่ยนวันที่ย้อนหลังของ Draft ต้องถูกปฏิเสธเมื่อ reset rule เดินย้อนรอบเลขล่าสุด และ transaction ต้อง rollback ไม่ทิ้ง history ค้าง
- [ ] เอกสาร Approved/Posted เปลี่ยนวันที่หรือเลขไม่ได้; ต้องใช้ Void/Reverse/เอกสารแก้ไขตาม domain และไม่เรียก `replaceDraftNumber`
- [ ] เมื่อมีประวัติเลขหรือเอกสารทางการเงินแล้ว การแก้ prefix/format/reset/type และการลบ sequence ต้องถูกปฏิเสธหรือจำกัดตาม policy พร้อม Audit reason
- [ ] ทดสอบ concurrent issue ใน Warehouse/ประเภทเดียวกันว่าเลขไม่ซ้ำและ `last_reset_key`/`next_number` สอดคล้องกัน
- [ ] ช่องบัญชี GL ของ Journal Entry, Bank/Cash และ Other Income/Expense ใช้ Select2 AJAX ค้นรหัส/ชื่อแบบ debounce และโหลดหน้าถัดไปได้โดยไม่ส่งผังบัญชีทั้งหมดมากับ Blade
- [ ] Bank/Cash แสดงเฉพาะบัญชีคุม CASH/BANK ตามประเภท และ Other Income/Expense แสดงเฉพาะบัญชี P&L ที่มี normal balance ตรงกับประเภท
- [ ] ช่องบัญชีแม่ในผังบัญชีใช้ Select2 AJAX, แสดงเฉพาะบัญชีรวมที่ active ในหมวดเดียวกัน, ไม่เลือกตัวเอง และคงค่าบัญชีแม่เดิมตอนแก้ไข

## Finance Receipt/Payment workflow

- [ ] Finance Bank/Cash, Payment Term, Other Income/Expense, Document Sequence และ Settlement โหลดแบบ server-side พร้อม search, pagination และ page length
- [ ] Bank/Cash, Document Sequence และ Settlement ไม่แสดงข้อมูล Warehouse อื่น; Other Income/Expense และ Payment Term คง company scope
- [ ] edit/delete/approve/void URL ไม่ถูกส่งใน DataTable JSON เมื่อผู้ใช้ไม่มี permission
- [ ] ผู้มีสิทธิ์ approve เปลี่ยนได้เฉพาะ DRAFT เป็น APPROVED พร้อมเหตุผลอย่างน้อย 10 ตัวอักษร และเก็บผู้อนุมัติ/เวลา/Audit Log
- [ ] ผู้มีสิทธิ์ void เปลี่ยนได้เฉพาะ DRAFT หรือ APPROVED เป็น VOID พร้อมเหตุผล โดยเอกสาร POSTED ต้องถูกปฏิเสธ
- [ ] ผู้ใช้ข้าม Warehouse เปิด action URL ของเอกสารอื่นไม่ได้ และผู้ไม่มี permission ได้ 403
- [ ] หน้า Draft ค้นคู่ค้าและ open item ด้วย Select2 AJAX, เลือกจัดสรรหลาย invoice ได้ และไม่โหลดรายการทั้งหมดมากับ Blade
- [ ] Receipt เลือกเฉพาะ AR/CUSTOMER/DEBIT และ Payment เลือกเฉพาะ AP/SUPPLIER/CREDIT ของคู่ค้า/บัญชีคุม/Warehouse เดียวกัน
- [ ] ยอด allocation intent ต้องไม่เกินยอดคงเหลือทั้ง ณ วันที่รับ/จ่ายและทุก effective-date ถัดไป; เปลี่ยนประเภท/วันที่/คู่ค้าต้อง clear รายการเดิม
- [ ] DataTable แสดงจำนวนและยอด allocation intent โดยยังไม่ถือเป็น final allocation จนกว่าจะ Post
- [ ] ยังไม่มีปุ่ม Post GL จนกว่า AR/AP allocation, control-account mapping และ reversal contract จะพร้อม

## Finance AR/AP open items และ Aging

- [ ] เมนูลูกหนี้/เจ้าหนี้และ Aging แสดงแยกตาม permission ทั้ง 4 รายการ และผู้ไม่มีสิทธิ์เข้า route ได้ 403
- [ ] รายการและ Select2 คู่ค้าแสดงเฉพาะ open item ของ Warehouse session และไม่รับ `warehouse_id` จาก client
- [ ] ยอด ณ วันที่รวมเฉพาะ allocation ที่เกิดไม่เกินวันนั้น และนำ allocation ที่ reversal แล้วออกตั้งแต่ `reversal_date`
- [ ] AR แสดง Debit เป็นบวก/Credit เป็นลบ ส่วน AP แสดง Credit เป็นบวก/Debit เป็นลบ
- [ ] Aging แบ่งยังไม่ครบกำหนด, 1–30, 31–60, 61–90 และมากกว่า 90 วัน โดยยอดรวมตรงกับรายการคงค้าง ณ วันเดียวกัน
- [ ] ไม่มีปุ่มเพิ่ม/แก้ไข/ลบ open item ด้วยมือ; รายการจริงต้องสร้างจาก Journal ที่ Post ผ่าน source posting service เท่านั้น

## Period close gates

- [ ] Soft close ทำได้เฉพาะงวด OPEN และ Lock ทำได้เฉพาะงวด SOFT_CLOSE
- [ ] Lock ถูกปฏิเสธเมื่อมี Journal สถานะ Draft/Validated หรือ Journal ที่ยอดไม่สมดุลในงวด
- [ ] Reopen เก็บเหตุผล ผู้ดำเนินการ และ audit ครบ; งวด LOCKED ยังห้าม post จนกว่าจะ reopen อย่างมีสิทธิ์

## Control-account reconciliation foundation

- [ ] รายงานแสดง AR/AP/Inventory control accounts ของ Warehouse และยอดสะสมถึงสิ้นงวด
- [ ] ยอด GL เทียบกับเฉพาะ journal lines ที่มี `subledger_type` และ `subledger_id` พร้อมผลต่างและ drill-down ไป GL
- [ ] ยังไม่ mark reconcile สำเร็จจน operational subledger พร้อม

## Automated checks

- [ ] `vendor/bin/pint --test` ผ่าน
- [ ] `php artisan test --testsuite=Unit` ผ่าน
- [ ] `php artisan route:list --except-vendor` ผ่าน
- [ ] `php artisan view:cache` ผ่าน

บันทึกวัน ผู้ทดสอบ environment และผล reconciliation เมื่อเปิด close/reopen ใน release evidence ของรอบนั้น
### Sales/Purchase Invoice และ Credit Note POST

- [ ] ผู้ไม่มีสิทธิ์ `.post` ไม่เห็นปุ่ม/ไม่ได้ `post_url`, เรียก route ได้ 403 และเอกสาร Warehouse อื่นได้ 404
- [ ] Post ได้เฉพาะ `APPROVED`; วันที่ต้องไม่ก่อนวันที่เอกสาร/เอกสารต้นทางและอยู่ในงวด `OPEN`; เมื่อผิดพลาดต้องไม่มี Journal, Open Item หรือ Allocation ค้างบางส่วน
- [ ] Sales Invoice ลง Dr AR mapping / Cr Revenue; Purchase Invoice ลง Dr Expense/Asset / Cr AP mapping และ control line มี Party subledger กับยอดตรงเอกสาร
- [ ] Purchase รองรับ `NONE VAT` หรือ `VAT_IN` ตาม gate ของเอกสาร; Sales ยัง `NONE VAT`; Party/Role/Payment Term/Tax Code/Account ที่ปิดใช้, บัญชีที่ลงรายการไม่ได้หรือ due date ไม่ตรงต้องถูกปฏิเสธ
- [ ] Post ซ้ำวันเดิมคืน Journal/Open Item/Allocation เดิมโดยจำนวนแถวไม่เพิ่ม; วันอื่นหรือ linkage ไม่ตรงต้องถูกปฏิเสธ
- [ ] การเปลี่ยน AR/AP mapping ก่อน Post ใช้ mapping ล่าสุด; หลัง Post ไม่แก้ Journal เดิม และ Credit Note ยังใช้ control account ของ Invoice ต้นทาง
- [ ] Sales Credit Note ลง Dr Revenue / Cr AR และ allocate Invoice Debit → Credit Note Credit
- [ ] Purchase Credit Note ลง Dr AP / Cr Expense/Asset และ allocate Credit Note Debit → Invoice Credit
- [ ] Sales VAT_OUT Invoice ลง Dr AR gross / Cr Revenue tax base + Cr Deferred Output VAT และ Sales VAT_OUT Credit Note กลับด้านพร้อม tax snapshot
- [ ] Credit Note ต่าง Party/Warehouse, source ไม่ได้ Post, account ไม่อยู่ใน source, ยอดสะสมรวม/รายบัญชีหรือยอดคงเหลือไม่พอ ต้องถูกปฏิเสธ
- [ ] Journal source/event/id/reference, posting date, document status/link/audit และ AR/AP Aging/Reconciliation ต้องตรงกัน

### Purchase VAT_IN expense/service POST

- [ ] VAT_IN ต้องเลือก Tax Code `VAT_IN` ที่ active ทุกบรรทัด; `NONE_VAT` ห้ามเลือก Tax Code
- [ ] Invoice ลง Dr Expense/Asset ตาม tax base + Dr Deferred Input VAT ตาม tax amount และ Cr AP ตามยอด gross
- [ ] Credit Note ลงรายการกลับด้านและจัดสรร AP Credit Note กับ Invoice ต้นทางตามยอด gross
- [ ] AP Open Item ใช้ยอด gross พร้อม tax snapshot/tax point วันที่เอกสาร และ POST ซ้ำวันเดิมไม่สร้าง Journal/Open Item ซ้ำ
- [ ] บัญชี `DEFERRED_INPUT_VAT` ต้อง active/postable และชนิด `INPUT_VAT`; ปิด mapping/account ต้องปฏิเสธโดยไม่สร้างข้อมูลบางส่วน

### Finance Settlement POST (MVP: settlement tax fields NONE VAT / WHT 0)

- [ ] ผู้ไม่มี `finance.settlements.post` ไม่เห็นปุ่ม/ไม่ได้ `post_url`; เรียก route ได้ 403 และ Warehouse อื่นได้ 404
- [ ] Post ได้เฉพาะ `APPROVED`; ไม่มี intent, ยอด intent ไม่เท่ากับ gross/net, VAT หรือ WHT ไม่เป็นศูนย์ ต้องถูกปฏิเสธโดยไม่เหลือ Journal/Open Item/Allocation ครึ่งรายการ
- [ ] Receipt ลง Dr Bank/Cash และ Cr AR แยกตามบัญชีคุม; Payment ลง Dr AP และ Cr Bank/Cash พร้อม Party subledger แบบ numeric
- [ ] แต่ละ AR/AP control line สร้าง Open Item ฝั่ง Receipt/Payment และ intent ทุกแถวสร้าง Allocation ตาม `settlement:{id}:intent:{id}`
- [ ] Post ซ้ำคืน Journal/Open Item/Allocation เดิม ไม่เพิ่มแถว; Journal, วัน, source reference หรือ allocation linkage ไม่ตรงต้องถูกปฏิเสธ
- [ ] Bank ต้องอยู่ Warehouse/session เดียวกัน, active, THB และ GL control type ตรง BANK/CASH; Party/Role และ target Open Item ต้อง active/ตรงบัญชี/คลัง/คู่ค้า
- [ ] VAT realization แบบ partial payment ใช้สัดส่วนยอดชำระ และ allocation สุดท้ายใช้ tax remainder ให้ยอดภาษีรวมตรงเอกสาร; ยังไม่เปิด POST จนมี tax snapshot/ledger จริง

### Finance Settlement VAT realization

- [ ] Receipt/Payment ที่จัดสรร Invoice VAT สร้าง Deferred → Actual VAT Journal lines ใน Journal เดียวกับ Bank/AR/AP
- [ ] การจ่าย/รับบางส่วนคำนวณ VAT ตามสัดส่วน และ allocation สุดท้ายใช้เศษภาษีที่เหลือให้รวมเท่ากับ tax snapshot
- [ ] `finance_tax_realizations` มี allocation เดียวต่อรายการ, tax point เป็นวันที่เอกสาร และ settlement date เป็นวันที่รับ/จ่าย
- [ ] Post ซ้ำไม่สร้าง Journal, Allocation หรือ Tax Realization ซ้ำ; mapping actual/deferred ที่ปิดใช้งานต้องปฏิเสธทั้ง transaction
