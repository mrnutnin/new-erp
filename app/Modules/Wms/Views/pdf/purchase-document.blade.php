<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <style>
        body{font-family:dejavusans,sans-serif;color:#20242a;font-size:11px}
        .head{border-bottom:2px solid #20242a;padding-bottom:10px;margin-bottom:16px}
        .brand{width:100%}.brand td{vertical-align:top}.logo{max-height:55px;max-width:180px}
        .title{font-size:20px;font-weight:bold}.muted{color:#66707a}.meta{width:100%;margin:8px 0 16px}.meta td{padding:3px 0}
        .table{width:100%;border-collapse:collapse}.table th{background:#edf1f4}.table th,.table td{border:1px solid #cbd2d8;padding:6px}.right{text-align:right}.status{font-weight:bold;color:#286b4f}
    </style>
</head>
<body>
    @php($wmsDecimal = \App\Modules\Wms\Support\WmsDecimal::class)
    <div class="head">
        <table class="brand"><tr>
            <td>@if(!empty($company['logo']))<img class="logo" src="{{ $company['logo'] }}" alt="">@endif</td>
            <td><div class="title">{{ $company['name'] ?? config('app.name') }}</div>@if(!empty($company['address']))<div class="muted">{{ $company['address'] }}</div>@endif</td>
            <td class="right"><div class="title">{{ $title }}</div><div class="muted">เอกสารสำหรับใช้งานภายใน</div></td>
        </tr></table>
    </div>
    <table class="meta">
        <tr><td><b>เลขที่เอกสาร:</b> {{ $number }}</td><td><b>วันที่:</b> {{ $date?->format($dateFormat) ?: '-' }}</td></tr>
        <tr><td><b>คู่ค้า:</b> {{ $party ?: '-' }}</td><td><b>สถานะ:</b> <span class="status">{{ ['DRAFT'=>'ร่าง','SUBMITTED'=>'รออนุมัติ','APPROVED'=>'อนุมัติแล้ว','POSTED'=>'ลงบัญชีแล้ว','VOID'=>'ยกเลิก'][$status] ?? $status }}</span></td></tr>
    </table>
    @if($description)<p><b>รายละเอียด:</b> {{ $description }}</p>@endif
    <table class="table"><thead><tr><th>#</th><th>รายการ</th><th>รายละเอียด</th><th>หน่วย</th><th class="right">จำนวน</th><th class="right">มูลค่า</th></tr></thead><tbody>
    @forelse($rows as $i=>$row)<tr><td>{{ $i+1 }}</td><td>{{ $row[0] ?: '-' }}</td><td>{{ $row[1] ?: '-' }}</td><td>{{ $row[2] ?: '-' }}</td><td class="right">{{ is_numeric($row[3]) ? $wmsDecimal::format($row[3]) : ($row[3] ?: '-') }}</td><td class="right">{{ is_numeric($row[4]) ? $wmsDecimal::format($row[4]) : ($row[4] ?: '-') }}</td></tr>@empty<tr><td colspan="6" style="text-align:center">ไม่พบรายการ</td></tr>@endforelse
    </tbody></table>
</body>
</html>
