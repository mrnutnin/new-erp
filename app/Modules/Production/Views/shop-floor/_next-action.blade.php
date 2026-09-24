<section class="sf-next" id="shop-floor-next-action" aria-label="ขั้นตอนถัดไป">
    <span class="sf-next-label"><i class="bx bx-right-arrow-circle" aria-hidden="true"></i>ขั้นตอนถัดไป</span>
    <div class="sf-next-content">
        @if(in_array($order->status, ['RELEASED', 'IN_PROGRESS'], true) && ! $issue && ! $order->started_at && ! $order->held_at)
            @if(! $order->materials_count)
                <p>WO ยังไม่มีวัตถุดิบ กรุณาให้หัวหน้างานจัดเตรียมรายการก่อนเบิก</p>
            @elseif(! ($materialReadiness['ready'] ?? false))
                <p class="text-danger">วัตถุดิบในคลังไม่พอสำหรับสร้างใบเบิก ตรวจสอบยอดขาดในแท็บวัตถุดิบ แล้วเติม Stock ก่อนทำต่อ</p>
                <button class="btn btn-app-soft" type="button" data-sf-tab="materials">ดูวัตถุดิบที่ขาด</button>
            @else
                <p>เบิกวัตถุดิบและลง Stock ก่อนเริ่มงาน · ไม่จำเป็นต้องมี Routing</p>
                <form method="POST" action="{{ route('production.orders.material-issue', $order) }}" class="js-shop-create">@csrf<button class="btn btn-app-primary btn-lg" type="submit"><i class="bx bx-package me-1" aria-hidden="true"></i>สร้างร่างใบเบิกวัตถุดิบ</button></form>
            @endif
        @elseif(in_array($order->status, ['RELEASED', 'IN_PROGRESS'], true) && $issue?->status === 'DRAFT' && ! $order->started_at && ! $order->held_at)
            <p>ใบเบิกยังเป็นร่าง ต้องอนุมัติก่อนลง Stock</p><button class="btn btn-app-primary btn-lg js-shop-action" type="button" data-url="{{ route('production.orders.material-issues.approve', [$order, $issue]) }}"><i class="bx bx-check me-1" aria-hidden="true"></i>อนุมัติใบเบิก</button>
        @elseif(in_array($order->status, ['RELEASED', 'IN_PROGRESS'], true) && $issue?->status === 'APPROVED' && ! $order->started_at && ! $order->held_at)
            <p>ใบเบิกอนุมัติแล้ว ลง Stock เพื่อตัดวัตถุดิบเข้าผลิต</p><button class="btn btn-app-primary btn-lg js-shop-action" type="button" data-url="{{ route('production.orders.material-issues.post', [$order, $issue]) }}"><i class="bx bx-send me-1" aria-hidden="true"></i>ลง Stock ใบเบิก</button>
        @elseif($order->status === 'IN_PROGRESS' && ! $issue)
            <p class="text-danger">ยังไม่พบใบเบิกวัตถุดิบที่ลง Stock แล้ว กรุณาให้หัวหน้างานตรวจสอบ WO นี้</p>
        @elseif($order->status === 'IN_PROGRESS' && $order->held_at)
            <p>พักงาน: {{ $order->hold_reason }}</p>@if(auth()->user()->hasPermission('production.orders.hold'))<button class="btn btn-app-primary btn-lg js-shop-resume" type="button" data-url="{{ route('production.shop-floor.resume', $order) }}"><i class="bx bx-play-circle me-1" aria-hidden="true"></i>เปิดงานผลิตต่อ</button>@endif
        @elseif($order->status === 'IN_PROGRESS' && $issue?->status !== 'POSTED')
            <p class="text-danger">ต้องลง Stock ใบเบิกวัตถุดิบก่อนเริ่มงานผลิต</p>
        @elseif($order->status === 'IN_PROGRESS' && ! $order->started_at)
            <p>ตรวจสอบสินค้าและวัตถุดิบ แล้วกดยืนยันเมื่อเริ่มผลิตจริง</p><button class="btn btn-app-primary btn-lg js-shop-action" type="button" data-url="{{ route('production.shop-floor.start', $order) }}"><i class="bx bx-play-circle me-1" aria-hidden="true"></i>ยืนยันเริ่มงานผลิต</button>
        @elseif($order->status === 'IN_PROGRESS' && $receiptDocument?->status === 'APPROVED')
            <p>ใบรับผลิตอนุมัติแล้ว ลง Stock เพื่อรับสินค้าและปิดงาน</p><button class="btn btn-app-primary btn-lg js-shop-action" type="button" data-url="{{ route('production.orders.finished-receipts.post', [$order, $receiptDocument]) }}"><i class="bx bx-send me-1" aria-hidden="true"></i>ลง Stock และจบงาน</button>
        @elseif($order->status === 'IN_PROGRESS' && $receiptDocument?->status === 'DRAFT')
            <p>ตรวจสอบใบรับผลิตก่อนอนุมัติ · ยังไม่มีการรับ Stock</p><button class="btn btn-app-primary btn-lg js-shop-action" type="button" data-url="{{ route('production.orders.finished-receipts.approve', [$order, $receiptDocument]) }}"><i class="bx bx-check me-1" aria-hidden="true"></i>อนุมัติใบรับผลิต</button>
        @elseif($order->status === 'IN_PROGRESS')
            <p>ผลิตครบแล้วจึงสร้างใบรับผลิต · Routing ไม่ใช่เงื่อนไขในการรับผลิต</p><form method="POST" action="{{ route('production.orders.finished-receipt', $order) }}" class="js-shop-create">@csrf<input type="hidden" name="reason" value="รับสินค้าผลิตเสร็จจาก WO {{ $order->document_number }}"><button class="btn btn-app-primary btn-lg" type="submit"><i class="bx bx-package me-1" aria-hidden="true"></i>สร้างร่างรับผลิตเสร็จ</button></form>
        @elseif($order->status === 'COMPLETED')
            <p>รับผลิตและปิดงานแล้ว · ดูข้อมูลย้อนหลังได้จากแท็บด้านล่าง</p>
        @elseif($order->status === 'CANCELLED')
            <p>เอกสารถูกยกเลิกแล้ว · ดูข้อมูลย้อนหลังได้จากแท็บด้านล่าง</p>
        @else
            <p>งานยังไม่พร้อมทำรายการ กรุณาให้หัวหน้างานตรวจสอบสถานะใบสั่งผลิต</p>
        @endif
    </div>
</section>
