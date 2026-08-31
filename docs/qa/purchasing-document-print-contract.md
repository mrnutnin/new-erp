# Purchasing document print/PDF contract (MVP preparation)

สถานะ: shared renderer/profile foundation พร้อมแล้ว ยังไม่เปิด document-specific print route

## ขอบเขตเอกสาร

เอกสารทั้งสี่ต้องใช้ renderer กลางชุดเดียวกัน โดยเปลี่ยนเฉพาะ document presenter:

- PR — ความต้องการซื้อ, จำนวน, หน่วย, ผู้ขอ/ผู้อนุมัติ และสถานะ
- PO — Supplier, เงื่อนไขชำระเงิน, วันที่คาดรับ, ราคา และยอดรวม
- Goods Receipt — PO, Supplier, วันที่รับ, จำนวนรับ, UOM/cost snapshot และสถานะ
- Credit Purchase — Supplier, วันที่เอกสาร/วันที่ลงบัญชี, รายการ, VAT/WHT, ยอดรวม และ 3-way match status

## Shared renderer contract

แต่ละเอกสารควรส่ง presenter DTO เดียวกันให้ renderer:

```php
[
    'profile' => 'a4'|'dot_matrix',
    'company' => ['name', 'tax_id', 'address', 'logo_url'],
    'document' => ['type', 'number', 'document_date', 'status', 'warehouse'],
    'party' => ['code', 'name', 'address', 'tax_id'],
    'references' => [['label', 'number', 'url']],
    'lines' => [['line_number', 'item', 'description', 'uom', 'quantity', 'unit_price', 'amount']],
    'totals' => ['subtotal', 'discount', 'tax', 'rounding', 'grand_total'],
    'history' => [['label', 'actor', 'at']],
]
```

กติกากลาง:

- format วัน จำนวนเงิน สถานะ และเลขเอกสารต้องเป็น human-readable ก่อนส่งเข้า view
- A4 รองรับหลายหน้าและ repeat header/footer
- Dot Matrix ใช้ตารางเรียบ, fixed-width, page length/profile configurable และห้ามพึ่ง Bootstrap/CSS3
- แยก print CSS/template ออกจาก screen layout
- ใส่ company profile/logo จาก Settings; ห้าม hard-code asset ใน WMS
- เอกสารทุกใบต้องแสดง reference ที่จำเป็น เช่น PR → PO → GR → Credit Purchase

## Permission and scope

เพิ่ม permission แยกในรอบ implement ถัดไป:

- `wms.purchase-requisitions.print`
- `wms.purchase-orders.print`
- `wms.purchase-receipts.print`
- `wms.purchase-documents.print`

ทุก print/download route ต้องตรวจ `auth`, module/program, selected warehouse/document scope และ permission print แยกจาก view/update/approve/post

## Package และ blocker

- โปรเจกต์ติดตั้ง `mpdf/mpdf` แล้ว (v8.3.1) สำหรับภาษาไทยและ multipage
- `App\\Modules\\Platform\\Services\\DocumentPdfRenderer` รองรับ profile `a4` และ `dot_matrix` แล้ว
- มี shared view contract ที่ `resources/views/pdf/document.blade.php`
- Company Settings มีชื่อบริษัท/เลขภาษี/รูปแบบวันที่ แต่ยังไม่มี field หรือ setting สำหรับ logo/address แบบเป็นทางการ
- ยังไม่มี print permission ใน RbacSeeder และยังไม่มี document-specific print routes/presenters

## ลำดับ implement หลังยืนยัน

1. เพิ่ม company logo/address settings และ validation ที่ Settings
2. เพิ่ม permission/seed และ route print แยกต่อเอกสาร
3. สร้าง presenter สำหรับ PR/PO/GR/Credit Purchase ให้แปลงข้อมูลเข้าสัญญากลาง
4. ทดสอบตัวอย่างหลายหน้าและเอกสารภาษาไทย ก่อน manual print sign-off

ไม่ควรสร้าง fake PDF หรือ route ที่ดาวน์โหลด HTML ใน MVP ก่อน package และ company profile contract พร้อม เพราะจะทำให้เอกสารที่ผู้ใช้พิมพ์มีหน้าตา/ข้อมูลไม่คงที่
