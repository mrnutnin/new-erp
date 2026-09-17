<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ItemLabelController extends Controller
{
    private const SIZES = [
        '40x30' => ['label' => 'เครื่องพิมพ์ฉลาก 40 × 30 มม.', 'profile' => 'label_40x30', 'large' => false],
        '50x30' => ['label' => 'เครื่องพิมพ์ฉลาก 50 × 30 มม.', 'profile' => 'label_50x30', 'large' => false],
        '60x40' => ['label' => 'เครื่องพิมพ์ฉลาก 60 × 40 มม.', 'profile' => 'label_60x40', 'large' => true],
        '100x50' => ['label' => 'เครื่องพิมพ์ฉลาก 100 × 50 มม.', 'profile' => 'label_100x50', 'large' => true],
        'a4_3x7' => ['label' => 'กระดาษ A4 · 21 ดวง (3 × 7)', 'profile' => 'label_a4', 'large' => true, 'columns' => 3, 'rows' => 7, 'cell_height' => 40.4],
        'a4_4x10' => ['label' => 'กระดาษ A4 · 40 ดวง (4 × 10)', 'profile' => 'label_a4', 'large' => false, 'columns' => 4, 'rows' => 10, 'cell_height' => 28.3],
    ];

    public function create(Request $request): View
    {
        return view('Wms::items.labels', [
            'items' => $this->items($request->validate($this->itemRules())['item_ids']),
            'sizes' => self::SIZES,
        ]);
    }

    public function print(Request $request, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $values = $request->validate([
            ...$this->itemRules(),
            'size' => ['required', Rule::in(array_keys(self::SIZES))],
            'symbol' => ['required', Rule::in(['BARCODE', 'QR', 'BOTH'])],
            'copies' => ['required', 'integer', 'min:1', 'max:20'],
        ]);
        $items = $this->items($values['item_ids']);
        if ($items->count() * (int) $values['copies'] > 500) {
            throw ValidationException::withMessages(['copies' => 'พิมพ์ได้ไม่เกิน 500 ดวงต่อครั้ง']);
        }
        if ($values['symbol'] !== 'QR') {
            $invalid = $items->first(fn (Item $item): bool => ! mb_check_encoding($item->code, 'ASCII') || ! preg_match('/^[ -~]+$/', $item->code));
            if ($invalid) {
                throw ValidationException::withMessages(['symbol' => "รหัสสินค้า {$invalid->code} ใช้ Barcode ไม่ได้ กรุณาเลือก QR Code"]);
            }
        }

        $layout = self::SIZES[$values['size']];
        $bytes = $renderer->renderView('Wms::pdf.item-labels', [
            'items' => $items,
            'companyName' => (string) ($settings->value('company_name') ?: config('app.name')),
            'symbol' => $values['symbol'],
            'copies' => (int) $values['copies'],
            'large' => $layout['large'],
            'labelSize' => $values['size'],
            'sheet' => isset($layout['columns']),
            'columns' => $layout['columns'] ?? 1,
            'rows' => $layout['rows'] ?? 1,
            'cellHeight' => $layout['cell_height'] ?? null,
        ], $layout['profile']);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="item-labels-'.now()->format('Ymd-His').'.pdf"',
        ]);
    }

    private function itemRules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /** @param array<int, int|string> $ids */
    private function items(array $ids): Collection
    {
        $ids = collect($ids)->map(fn ($id): int => (int) $id)->values();
        $items = Item::query()->with(['category:id,code,name', 'baseUom:id,code,name'])->whereIn('id', $ids)->get()->keyBy('id');
        if ($items->count() !== $ids->count()) {
            throw ValidationException::withMessages(['item_ids' => 'พบสินค้าที่ไม่มีอยู่หรือถูกลบ']);
        }

        return $ids->map(fn (int $id): Item => $items->get($id));
    }
}
