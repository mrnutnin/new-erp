# Workflow Center Manual QA

ใช้หลัง login ด้วย user ที่มี Program context และเลือกคลังแล้ว โดยไม่ต้อง seed/migrate ระหว่างการทดสอบ

## Access และ navigation

- [ ] User ที่มี Program แต่ไม่มี action permission เปิด `/settings/workflow`, `/wms/workflow`, `/finance/workflow`, `/accounting/workflow` และ `/pos/workflow` ได้
- [ ] Workflow Center ไม่เปิดเผย route ปลอม; step ที่ยังไม่มีหน้าปลายทางแสดง blocker และไม่มีปุ่ม Play
- [ ] ปุ่ม Play ของ step ที่พร้อมทำงานพาไป route จริง และ user ไม่มี permission ยิง route ปลายทางได้ `403`
- [ ] กลับหน้าเลือกโปรแกรมอยู่ด้านบนของ Sidebar และ Workflow Center อยู่หลัง Dashboard

## Setup / daily และ status

- [ ] แท็บ `เริ่มใช้งานครั้งแรก` และ `งานประจำวัน` แสดงแยกกัน; ไม่มี step ของอีกโหมดปะปน
- [ ] ถ้า setup readiness ยังไม่ผ่าน ระบบเปิดแท็บ `เริ่มใช้งานครั้งแรก` เป็นค่าเริ่มต้น; เมื่อพร้อมแล้วเปิด `งานประจำวัน` เป็นค่าเริ่มต้น
- [ ] Sales/POS, Purchasing/WMS, Finance และ Accounting มีทั้งโหมดเริ่มใช้งานครั้งแรกและงานประจำวัน; Setup แสดงเฉพาะการเตรียมข้อมูล/นโยบาย และ Daily แสดงเฉพาะรายการที่ต้องทำซ้ำ
- [ ] ทุก step มีคำอธิบาย “ทำผิดหรือย้อนกลับอย่างไร”; Draft แก้/ยกเลิกได้ตามสิทธิ์ และ Approved/Posted ใช้ Void/Reverse/เอกสารแก้ไขตามกติกา
- [ ] บริษัท `TRADING` ที่ไม่ได้เปิด Production ไม่เห็น Production เป็นโปรแกรมหรือ blocker และ workflow หลักจบได้ที่ Purchasing/WMS → Sales/POS → Finance → Accounting
- [ ] หากโหมดใดไม่มี workflow ระบบแสดง empty state ที่อ่านเข้าใจได้ ไม่ปล่อยแท็บว่างหรือทำให้ผู้ใช้คิดว่าหน้าค้าง
- [ ] Production/Logistics/Asset ที่ยังไม่มี module shell ไม่ถูกแสดงเป็นเมนูหรือ route ปลอม; เมื่อเปิด module แล้ว catalog ต้องแยก Setup/Daily และแสดง blocker/recovery ก่อน action จริง
- [ ] ตรวจครบ 4 สถานะ: `พร้อมทำ` (เขียวอ่อน), `มีงานค้าง · N รายการ` (ฟ้าอ่อน), `ยังไม่พร้อม` (เหลืองอ่อน), `ไม่มีสิทธิ์` (เทาอ่อน)
- [ ] เมื่อ readiness ไม่พร้อม badge เป็น `ยังไม่พร้อม`, มีเหตุผลภาษาคน และมี next action ที่แก้ได้
- [ ] เมื่อ pending count เป็นศูนย์ไม่แสดงข้อความว่างหรือเลขติดลบ; เมื่อมากกว่าศูนย์แสดงจำนวนเป็นเลขคนอ่านได้
- [ ] ข้อมูล pending ของ WMS/Finance/Accounting/POS เปลี่ยนตาม Warehouse context และไม่รวมข้อมูลของคลังอื่น
- [ ] WMS Setup แสดง Inventory/COGS GL Mapping และ AVG/FIFO cost policy เป็น blocker ที่ต้องแก้ใน Accounting/Settings ก่อนเปิด posting
- [ ] WMS Daily แสดง Inventory→GL Preflight และ GL Reconciliation/Resolve เป็นขั้นตอน review; ไม่มีปุ่ม posting หรือ resolve ปลอมใน WMS
- [ ] WMS Daily แสดง Stock Flow `Receipt / Issue / Transfer` เป็นงานประจำวัน และพาไป Stock Card ตามสิทธิ์; ห้ามสื่อว่าคู่มือเป็นปุ่ม Post Inventory→GL
- [ ] Purchasing/WMS แสดง Receipt Draft/ตรวจรับเป็นขั้นตอน blocked จนกว่า source contract, inventory event และ cost layer พร้อม; ต้องเห็นลำดับ Draft → ตรวจรับ → approval/post โดยไม่มีปุ่มปลอม
- [ ] Inventory Posting แสดงเป็น readiness/preflight จนกว่า reconciliation difference เป็นศูนย์, allocation/linkage ครบ และ reversal gate ผ่าน; เห็นเหตุผล/แนวทางแก้ และไม่มีปุ่ม Post/Resolve ปลอม
- [ ] Readiness card แสดง blocker รายบรรทัดของ Movement/Allocation/Journal line พร้อม gate, ผู้รับผิดชอบ และ recovery action; ไม่ใช้ข้อความรวมแบบ generic อย่างเดียว
- [ ] ก่อนเปิด Post ต้องตรวจ transaction boundary ครบ Movement → Cost Allocation/Layer → Journal line → immutable linkage → status; failure ต้อง rollback ทั้งชุด
- [ ] Journal-line linkage ของรายการ Posted แก้หรือลบไม่ได้; recovery ต้องเป็น reversal/correction และสร้าง revision ใหม่โดยคง proof เดิมไว้
- [ ] Readiness card block เมื่อ exact Journal-line mapping ไม่ตรง `account/subledger/item/amount` หรือพบ duplicate; recovery ต้องชี้ไปแก้ mapping/source หรือทำ reversal/correction ไม่ใช่แก้ Posted line ด้วยมือ
- [ ] เมื่อ Posted inventory ผิด คู่มือบอก recovery สำหรับทีมเล็กชัดเจน: หยุด Post → ทำ reversal/recost revision ตามสิทธิ์ → ตรวจ stock/cost/GL/reconciliation; ห้ามแก้หรือลบรายการเดิม
- [ ] ก่อนเปิด Inventory→GL ต้องแนบ release evidence จาก preflight, atomic rollback/retry, exact Journal-line mapping, reversal/revision และ reconciliation zero; หากไม่ครบต้องคง feature gate ปิดและไม่มีปุ่ม Post
- [ ] WMS แสดง decision cards 4 จุด: Mapping, Cost Policy, Pending/Unlinked Preflight และ Reconciliation; card ที่ไม่มี route แสดง guidance อย่างเดียวและไม่สร้างปุ่มหลอก
- [ ] WMS แสดง user-facing readiness gates `inventory_purchase_event_wiring`, `atomic_journal_movement_allocation_linkage` และ `reconciliation_zero_gate` พร้อม blocker, จุดแก้ และผู้รับผิดชอบ; ทุก card ไม่มีปุ่ม Post จน gate ผ่าน
- [ ] เมื่อ pending cost, unlinked allocation หรือ mapping ยังไม่ครบ ต้องเห็น blocker/วิธีแก้ก่อนส่งต่อ Inventory→GL และไม่ให้ผู้ใช้เข้าใจว่า GL post สำเร็จแล้ว

## Finance — ทีมเล็กและรายการที่ยังไม่เปิดใน MVP

- [ ] Finance Setup แสดงบัญชีเงินสด/ธนาคาร, เงื่อนไขการชำระเงิน, รายได้/รายจ่ายอื่น, เลขที่เอกสาร และ Account Mapping/งวดบัญชี แยกจาก Daily อย่างชัดเจน
- [ ] Finance Daily แสดงลำดับ Open Item → Voucher/Settlement → allocation → VAT/WHT realization → Aging/รายงาน และ pending count ต้อง scope ตาม Warehouse ที่เลือก
- [ ] ขั้น `เงินรับล่วงหน้า / เงินมัดจำ`, `Petty Cash` และ `เงินทดรองพนักงาน` ต้องแสดง `ยังไม่พร้อม` พร้อมเหตุผล ผลกระทบ และ recovery guidance; ต้องไม่มีปุ่ม Play หรือ route ปลอมจนกว่า source, subledger, GL mapping และ reversal contract จะครบ
- [ ] ผู้ใช้ต้องไม่เลือก invoice แบบเดาเพื่อเคลียร์ยอด Advance/Deposit และต้องไม่สร้าง Journal แทน Petty Cash/Employee Advance ที่ยังไม่มีเอกสารต้นทาง
- [ ] ทีมเล็ก 1–2 คนทำ Voucher/Settlement ได้ครบตาม approval policy; คู่มือห้ามระบุว่าต้องมีผู้อนุมัติคนที่สองเสมอ
- [ ] เมื่อแก้ข้อมูลผิด: Draft แก้ได้, Submitted/Approved ใช้ transition/Void ตามสิทธิ์, Posted ใช้ reversal/เอกสารแก้ไขพร้อมเหตุผลและ audit; ห้ามแก้ Journal หรือ Open Item เดิมโดยตรง

## Recovery และ human guidance

- [ ] เปิด `ทำผิดหรือย้อนกลับอย่างไร` ในทุก step แล้วเห็นแนวทาง Draft/Approved/Posted ที่เหมาะสม
- [ ] Draft ที่ยังไม่ถูกอ้างอิง: ถ้าหน้าปลายทางมีปุ่มและผู้ใช้มี delete permission จึงลบได้; ถ้าไม่มีปุ่มลบให้แก้ไขหรือ Void ตาม state contract
- [ ] WMS Item/Category/UOM และ Supplier ที่มีประวัติไม่ใช้ hard delete; คู่มือแนะนำปิดใช้งานหรือ Soft Delete ผ่าน domain guard/audit
- [ ] ถ้ามี Delete Draft action ต้องใช้ข้อความยืนยันที่ระบุเลขเอกสาร/ผลกระทบ และต้องถามเหตุผลเมื่อเป็นการ Void/ยกเลิก; กด Cancel แล้วต้องไม่ยิง mutation
- [ ] หลังลบ/ยกเลิก Draft ตรวจว่าเลขเอกสารที่ถูกจ่ายไปแล้วไม่ถูกนำกลับมาใช้ซ้ำ และเลขถัดไปยังเดินต่ออย่างปลอดภัย
- [ ] Approved/Posted ไม่มีปุ่มลบและยิง delete route ไม่ได้; การแก้ไขต้องใช้ Void/Reverse/Credit Note/เอกสารแก้ไขตามประเภทเอกสาร
- [ ] Step ที่ blocked บอกจุดที่ต้องแก้ ผลกระทบ และหน้าปลายทาง; ห้ามจบด้วยข้อความ generic อย่างเดียว
- [ ] WMS Stock Valuation แยก Current projection กับ Historical / ณ วันที่ และแจ้งข้อจำกัด historical valuation
- [ ] WMS Receipt/Issue/Transfer แสดง recovery guidance ว่าตรวจคลัง/สินค้า/หน่วย/วันที่ก่อน Post และใช้ reversal/เอกสารแก้ไขเมื่อ Post ผิด ห้ามลบหรือแก้ทับ ledger
- [ ] Receipt Draft ที่ข้อมูลผิดแก้ได้ก่อน approval; หลังเอกสาร Posted ให้ใช้ reversal/correction ตาม state contract และไม่อ้างว่า Draft สร้าง stock/cost layer แล้ว
- [ ] วันที่/สถานะในหน้ารายงานปลายทางเป็น human-readable ตาม company date format และ timezone

## Safety

- [ ] เปิด Workflow Center ไม่สร้าง/แก้ไข/lock เอกสาร และไม่เปลี่ยนสถานะรายการ
- [ ] ตรวจ Network/SQL log ว่า snapshot ใช้ scalar/aggregate (`exists`, `count`, `sum`) และไม่มี transaction row collection หรือ `get()` ใน Workflow path

## Inventory / Trading — ทีมเล็ก 1–2 คน

ใช้กับบริษัทที่ไม่เปิด Production โดยให้ผู้ใช้คนเดียวมีสิทธิ์ตาม policy ที่จำเป็นเท่านั้น; ไม่สร้างผู้อนุมัติปลอมเพื่อให้ผ่าน checklist

- [ ] เปิด WMS Workflow Center แล้วเห็น Setup แยกจาก Daily และไม่มี Production เป็น blocker ของ Receipt, Stock Flow, Finance หรือ Accounting
- [ ] ใช้ข้อมูล Receipt Draft ที่ตั้งใจกรอกผิด (สินค้า, หน่วย, คลัง หรือวันที่) ตรวจว่าระบบบอกจุดผิดและยังไม่สร้าง Stock Movement, Cost Layer หรือ Journal
- [ ] แก้ Receipt Draft ให้ถูกต้องก่อน approval/post แล้วตรวจว่า Draft เดิมถูกปรับปรุงอย่างปลอดภัยและไม่มี ledger ซ้ำ
- [ ] เมื่อ source contract, inventory event หรือ cost layer ยังไม่พร้อม ต้องเห็น blocker และไม่มีปุ่ม Post/GL ที่ทำให้เข้าใจว่าเปิดใช้งานแล้ว
- [ ] ตรวจกรณี Post/Stock Movement ผิด: ห้ามแก้หรือลบ ledger เดิม และต้องมีคำแนะนำให้ใช้ reversal/correction พร้อมเหตุผลและ audit
- [ ] ตรวจ Count/Adjust ผิด: แก้ Draft ก่อน Post; หลัง Post ใช้ adjustment/reversal ตาม state contract และไม่แก้ยอดเดิมโดยตรง
- [ ] ตรวจทีมเล็กที่มีผู้ใช้คนเดียว: ทำขั้นตอนที่ policy อนุญาตได้ครบโดยไม่ติด second approver; หากเปิด maker-checker ให้ทดสอบ maker แก้ไม่ได้หลังส่ง และ checker อนุมัติได้
- [ ] บันทึกหลักฐาน Warehouse, document number, status ก่อน/หลัง, blocker, recovery action และผลว่าไม่มี Stock/Cost/GL mutation จากการเปิดคู่มือ
- [ ] จำลอง failure ระหว่าง Movement → Cost Allocation → Journal: ต้อง rollback ทั้งชุด ไม่มีรายการค้างครึ่งทาง และ Workflow Center แสดง blocker พร้อม recovery action ที่ทำได้จริง
- [ ] Retry ด้วย source/event/revision เดิมหลัง rollback ต้องใช้ idempotency identity เดิมและไม่สร้าง Movement, Allocation หรือ Journal ซ้ำ; บันทึกหลักฐาน request identity, status ก่อน/หลัง และจำนวนรายการก่อน/หลัง
