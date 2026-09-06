# Executive Dashboard Module Plan

เอกสารนี้เป็นแผนสร้าง module ใหม่ชื่อ `Dashboard` สำหรับผู้บริหาร เพื่อดูภาพรวมทั้งองค์กรและเจาะลงระดับสาขา โดยแยกจาก Dashboard เฉพาะ module เช่น POS, WMS, Finance และ Accounting

## สถานะการดำเนินงาน

- `[x]` Phase 1 foundation: module/provider, `/dashboard`, AJAX endpoint, filter request, branch scope และ permission seed
- `[x]` Phase 2 UI shell: KPI strip, trend/branch charts, attention center, decision center และ responsive layout
- `[~]` Phase 3 data integration: เชื่อมยอดขาย, เงินรับ–จ่าย, Stock, Gross Profit, AR/AP และ comparison period แล้ว; Business Unit master ยังรอ contract ระดับองค์กร
- `[ ]` Phase 4 performance hardening และ Phase 5 QA/rollout

## 1. เป้าหมาย

Dashboard ต้องตอบคำถามผู้บริหารได้ทันที:

1. ตอนนี้ธุรกิจเป็นอย่างไร?
2. มีอะไรผิดปกติหรือควรให้ความสนใจ?
3. มีเรื่องอะไรที่ควรตัดสินใจ?

หลักการ UI:

- Clean, Minimal, Professional และ Data-driven
- KPI สำคัญเห็นได้ทันทีโดยไม่ต้อง scroll บน desktop
- ใช้พื้นที่อย่างมีประสิทธิภาพ ไม่ใช้ card ใหญ่เกินความจำเป็น
- ใช้สีตามความหมาย: เขียว = ดีขึ้น, แดง = ต้อง attention, เหลือง/ส้ม = warning, เทา = neutral
- มี animation ขนาดเล็กเฉพาะตอนโหลด/เปลี่ยนค่า ไม่ใช้ animation ที่รบกวนการอ่านข้อมูล
- รองรับ desktop เป็นหลัก และ responsive บน tablet/mobile

## 2. ขอบเขต MVP

### 2.1 Global filter bar

วางด้านบนสุดของ Dashboard ใน card ขนาดกะทัดรัด:

- Date Range: `เริ่มวันที่` ถึง `สิ้นสุดวันที่`
- Company
- Branch: ทุกสาขาที่ผู้ใช้มีสิทธิ์
- Business Unit
- ปุ่ม `ใช้ตัวกรอง` และ `ล้างตัวกรอง`
- แสดงข้อความช่วงข้อมูลที่ใช้งานอยู่ และ timestamp ของการ refresh ล่าสุด
- Default Date Range เป็นวันแรกถึงวันปัจจุบันของเดือนปัจจุบัน
- เปลี่ยน filter แล้วโหลดข้อมูลผ่าน AJAX/fetch โดยไม่ reload หน้า
- เก็บ filter ใน query string เพื่อให้ copy URL และกลับมาใช้มุมมองเดิมได้

> หากระบบปัจจุบันยังไม่มี Business Unit master ให้เริ่มด้วย read-only option จากข้อมูลที่มี หรือแสดง `ทุกหน่วยธุรกิจ`; ห้ามสร้าง filter ที่เลือกได้แต่ไม่มีข้อมูลจริง

### 2.2 Executive KPI strip

แสดง 6 KPI แบบ compact ในแถวแรก:

1. ยอดขายสุทธิ
2. กำไรขั้นต้น หรือ Margin (ถ้าต้นทุนพร้อม)
3. กระแสเงินสดสุทธิ
4. ลูกหนี้คงค้าง
5. เจ้าหนี้คงค้าง
6. มูลค่าสินค้าคงเหลือ

แต่ละ KPI ควรมี:

- ค่าปัจจุบัน
- เปรียบเทียบกับช่วงก่อนหน้าแบบช่วงเวลาเดียวกัน
- percentage change
- สีและ icon แสดงทิศทาง
- link ไปยังหน้ารายงาน/transaction ที่เกี่ยวข้อง
- แสดง `—` หรือ `ข้อมูลไม่พร้อม` เมื่อ metric ยังไม่มี contract แทนการเดาค่า

### 2.3 Business health

จัดเป็น 2 card ขนาด `col-lg-8` และ `col-lg-4`:

- แนวโน้มยอดขาย / รับเงิน / จ่ายเงิน แบบ line หรือ bar chart
- สัดส่วนผลประกอบการตามสาขา หรือ business unit แบบ horizontal bar

Chart ใช้ ApexCharts ผ่าน CDN เพื่อรองรับกราฟแบบ responsive และ tooltip ที่อ่านง่าย

แนวทาง chart:

- แสดงข้อมูลเท่าที่จำเป็นและอ่านได้ใน 3 วินาที
- tooltip แสดงตัวเลขละเอียดเฉพาะเมื่อ hover
- ไม่ใช้ 3D, gradient หนัก หรือ label ทับกัน
- รองรับ empty state และ loading state
- destroy chart เดิมก่อนวาดใหม่ทุกครั้งหลังเปลี่ยน filter

### 2.4 Attention center

ส่วน `สิ่งที่ต้องให้ความสนใจ` แบ่งเป็นรายการที่คลิก drill-down ได้:

- ยอดขายลดลงผิดปกติ
- สินค้าต่ำกว่า Min / stock-out
- เงินสดหรือรายการรับ–จ่ายรอ Post
- ลูกหนี้เกินกำหนด
- เจ้าหนี้ใกล้ครบกำหนด
- เอกสารผิดปกติหรือไม่มี Journal linkage
- Reconciliation มีผลต่าง

ใช้ severity:

- แดง: ต้องดำเนินการทันที
- ส้ม: ควรตรวจสอบ
- เทา: ข้อมูลประกอบ

### 2.5 Decision center

ส่วน `เรื่องที่ควรตัดสินใจ` แสดงเฉพาะเรื่องที่ต้องการ action จากผู้บริหาร เช่น:

- สาขาที่ margin ต่ำกว่าค่าเป้าหมาย
- สาขาที่มี stock-out หรือ overstock สูง
- รายการอนุมัติวงเงิน/ค่าใช้จ่ายที่ค้าง
- ผลต่าง reconciliation ที่เกิน threshold
- ค่าใช้จ่ายเพิ่มขึ้นจากช่วงก่อนหน้าอย่างมีนัยสำคัญ

แต่ละรายการต้องมี:

- เหตุผลสั้น ๆ
- ตัวเลขสนับสนุน
- ระดับความสำคัญ
- ปุ่ม `ดูรายละเอียด` ไปยังหน้าต้นทาง

## 3. โครงสร้าง module ที่เสนอ

สร้าง `app/Modules/Dashboard` ตาม convention ของ module ปัจจุบัน:

```text
app/Modules/Dashboard/
├── Controllers/
│   └── ExecutiveDashboardController.php
├── Requests/
│   └── DashboardFilterRequest.php
├── Services/
│   ├── ExecutiveDashboardService.php
│   ├── DashboardScopeService.php
│   └── DashboardCacheService.php
├── Routes/
│   └── web.php
├── Views/
│   ├── dashboard.blade.php
│   └── partials/
│       ├── filters.blade.php
│       ├── kpis.blade.php
│       ├── attention.blade.php
│       └── decisions.blade.php
└── Providers/
    └── DashboardServiceProvider.php
```

Route ที่เสนอ:

- `GET /dashboard` หน้า Executive Dashboard
- `GET /dashboard/data` endpoint สำหรับข้อมูลทั้งหมดตาม filter
- `GET /dashboard/data/{section}` ใช้เมื่อจำเป็นต้องแยกโหลด chart/ตารางหนัก

ไม่ควรใช้ `/dashboard` ซ้ำกับ Platform controller เดิมโดยไม่กำหนด migration plan; ให้ย้าย route เดิมมาอยู่ภายใต้ module ใหม่ หรือให้ Platform route ทำหน้าที่ handoff ไปยัง Dashboard module อย่างชัดเจน

## 4. Data contract

Request filter:

```json
{
  "date_from": "2026-09-01",
  "date_to": "2026-09-30",
  "company_id": null,
  "branch_id": "all",
  "business_unit_id": "all"
}
```

Response ที่แนะนำ:

```json
{
  "filters": {"date_from": "2026-09-01", "date_to": "2026-09-30"},
  "refreshed_at": "2026-09-06T13:00:00+07:00",
  "kpis": {},
  "trend": {"labels": [], "sales": [], "receipts": [], "payments": []},
  "branches": [],
  "attention": [],
  "decisions": [],
  "meta": {"partial": false, "warnings": []}
}
```

กติกาสำคัญ:

- ทุก query ต้อง scope ตาม company/branch/business unit และ permission ของผู้ใช้
- ห้ามให้ client ส่ง branch ที่ผู้ใช้ไม่มีสิทธิ์แล้วเห็นข้อมูลได้
- แยก `0`, `ไม่มีข้อมูล`, `ข้อมูลยังไม่พร้อม` ให้ชัดเจน
- เงินและจำนวนใช้ decimal-safe aggregation ฝั่ง server
- คำนวณ comparison period ฝั่ง service เดียวกันเพื่อให้ทุก widget ใช้ฐานเดียวกัน

## 5. Performance plan

- Initial HTML ต้องโหลดเฉพาะ shell, filter และ skeleton; ไม่ query ข้อมูลทุก module หนัก ๆ ใน controller view
- ใช้ endpoint เดียวสำหรับ MVP เพื่อลด request overhead แล้วแยก section เมื่อ profiling พบว่าช้า
- ใช้ aggregate query และ `select` เฉพาะ column ที่จำเป็น
- ห้าม query รายสาขาแบบ N+1; group ด้วย branch ใน query เดียว
- Cache ตาม user scope + filter hash + permission scope โดย TTL เริ่มต้น 30–60 วินาที
- invalidate cache เมื่อมีการ Post/Void/Reversal รายการสำคัญ หรือยอมรับ short-lived cache ใน MVP
- รองรับ loading, empty, error และ stale-data state
- ติดตาม response time เป้าหมาย: p95 ไม่เกิน 1.5 วินาทีสำหรับข้อมูลที่ cache แล้ว และไม่เกิน 3 วินาทีสำหรับ uncached filter

## 6. Permission และ menu

เพิ่ม permission แยกจาก dashboard ของแต่ละ module:

- `dashboard.executive.view`
- `dashboard.executive.view_all_companies`
- `dashboard.executive.view_all_branches`
- `dashboard.executive.export` (เลื่อนได้หลัง MVP)

Sidebar ให้แสดง `Executive Dashboard` เป็นเมนูระดับ Global หลังเลือก program หรือในหน้า program selector ตามแนวทาง UX ที่ตกลงกัน โดยไม่ลบ dashboard เฉพาะ module

## 7. Implementation phases

### Phase 1 — Foundation

- สร้าง module/provider/route/controller/request
- ตัดสินใจ ownership ของ `/dashboard` ระหว่าง Platform กับ Dashboard module
- สร้าง permission และ menu
- สร้าง filter contract พร้อม current-month default
- สร้าง scope service สำหรับ company/branch/business unit

### Phase 2 — Executive UI shell

- สร้าง layout, filter bar, skeleton และ responsive grid
- สร้าง KPI strip ให้เห็นใน viewport แรก
- เพิ่ม color/status system และ empty/error state
- ใช้ Boxicons และ Chart.js CDN ที่มีอยู่ ไม่เพิ่ม dependency ใหม่

### Phase 3 — Data integration

- เชื่อมยอดขายจาก POS
- เชื่อมรับ–จ่ายและ cash flow จาก Finance
- เชื่อม AR/AP และ GL status จาก Accounting
- เชื่อม stock/value จาก WMS
- เชื่อม approval/decision items จากแต่ละ module

### Phase 4 — AJAX refresh และ performance

- เปลี่ยน filter แล้วโหลดข้อมูลโดยไม่ reload หน้า
- debounce เฉพาะ filter ที่เป็น text/search; select/date ใช้ apply ครั้งเดียว
- cache และ aggregate query
- เพิ่ม refresh timestamp, retry และ stale-data indicator

### Phase 5 — QA และ rollout

- permission/scope test ระดับ company และ branch
- filter test: current month, custom range, all branch, single branch
- comparison-period และ timezone test
- empty/partial/error response test
- visual QA desktop/tablet/mobile
- performance test พร้อมข้อมูลหลายสาขา
- owner sign-off ก่อนเปิดเป็น default entry route

## 8. Acceptance criteria

- ผู้บริหารเห็น KPI หลักและสถานะสำคัญได้โดยไม่ต้อง scroll บน desktop ความละเอียดมาตรฐาน
- เปลี่ยน Date Range, Company, Branch และ Business Unit แล้วข้อมูลทุก widget เปลี่ยนตาม filter เดียวกันโดยไม่ reload หน้า
- ข้อมูลทุกตัว drill-down ไปยัง module ต้นทางได้
- ผู้ใช้เห็นเฉพาะข้อมูลตามสิทธิ์ของตนเอง
- ไม่มี query N+1 ใน endpoint หลัก
- เมื่อ metric ใดไม่มี contract หรือข้อมูลไม่พร้อม ระบบแสดงสถานะอย่างตรงไปตรงมา ไม่แสดงตัวเลขเดา
- Dashboard ยังใช้งานได้เมื่อ chart CDN โหลดไม่สำเร็จ โดยมี fallback เป็น KPI/ตาราง
- สีและสถานะสอดคล้องกันทั้งหน้า และไม่ใช้สีเป็นตัวบอกความหมายเพียงอย่างเดียว

## 9. สิ่งที่ยังไม่อยู่ใน MVP

- AI-generated business insight
- Forecast และ predictive analytics
- Drag-and-drop dashboard customization
- Scheduled email/PDF snapshot
- Real-time WebSocket streaming
- Cross-company consolidation ที่มี currency conversion ซับซ้อน

เพิ่มความสามารถเหล่านี้เมื่อ data contract และ KPI พื้นฐานมีความน่าเชื่อถือ พร้อมมีผู้ใช้จริงยืนยันความต้องการ
