<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\WithholdingRealization;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Finance\Support\WhtRealizationCalculator;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Requests\ReceivePhysicalSalePaymentRequest;
use App\Modules\Pos\Services\PhysicalSaleReceiptService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PhysicalSaleReceiptController extends Controller
{
    public function create(Request $request, PhysicalSale $physicalSale, OpenItemService $openItems): View
    {
        $sale = $this->saleWithOpenItem($request, $physicalSale, $openItems);
        $openItem = $sale->getRelation('paymentOpenItem');
        $remaining = $openItems->remainingAt($openItem, today()->format('Y-m-d'));
        $withholding = $this->withholdingFor($openItem, today()->format('Y-m-d'));

        return view('Pos::physical-sales.receive-payment', [
            'sale' => $sale,
            'openItem' => $openItem,
            'remaining' => $remaining,
            'withholding' => $withholding,
            'net' => JournalBalance::subtract($remaining, $withholding),
            'bankAccounts' => BankAccount::query()->where('warehouse_id', $sale->warehouse_id)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(ReceivePhysicalSalePaymentRequest $request, PhysicalSale $physicalSale, PhysicalSaleReceiptService $receipts, OpenItemService $openItems): JsonResponse
    {
        $this->saleWithOpenItem($request, $physicalSale, $openItems);
        $settlement = $receipts->receive(
            $physicalSale,
            $request->validated(),
            $physicalSale->warehouse,
            $request->user(),
            $request,
        );

        return response()->json([
            'status' => true,
            'msg' => "บันทึกรับชำระ {$settlement->document_number} แล้ว",
            'redirect' => route('pos.physical-sales.show', $physicalSale),
        ]);
    }

    public function summary(Request $request, PhysicalSale $physicalSale, OpenItemService $openItems): JsonResponse
    {
        $values = $request->validate(['settlement_date' => ['required', 'date_format:Y-m-d'], 'allocation_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0']]);
        $sale = $this->saleWithOpenItem($request, $physicalSale, $openItems);
        $openItem = $sale->getRelation('paymentOpenItem');
        $remaining = $openItems->remainingAt($openItem, $values['settlement_date']);
        $allocation = JournalBalance::decimal($values['allocation_amount']);
        if ($allocation > $remaining) {
            throw ValidationException::withMessages(['allocation_amount' => 'ยอดรับชำระเกินยอดคงเหลือของ IV']);
        }
        $openItem->setAttribute('remaining_amount', $remaining);
        $withholding = $this->withholdingFor($openItem, $values['settlement_date']);

        return response()->json(['remaining' => $remaining, 'allocation' => $allocation, 'withholding' => $withholding, 'net' => JournalBalance::subtract($allocation, $withholding)]);
    }

    private function saleWithOpenItem(Request $request, PhysicalSale $physicalSale, OpenItemService $openItems): PhysicalSale
    {
        abort_unless((int) $physicalSale->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        if ($physicalSale->document_type !== 'IV' || $physicalSale->status !== 'POSTED' || ! $physicalSale->journal_entry_id) {
            throw ValidationException::withMessages(['physical_sale' => 'รับชำระหนี้ได้เฉพาะใบขายเชื่อ (IV) ที่ยืนยันขายแล้ว']);
        }

        $openItem = OpenItem::query()
            ->where('warehouse_id', $physicalSale->warehouse_id)
            ->where('party_id', $physicalSale->party_id)
            ->where('ledger_type', 'AR')
            ->where('party_type', 'CUSTOMER')
            ->where('balance_side', 'DEBIT')
            ->where('document_type', 'INVOICE')
            ->where('document_number', $physicalSale->document_number)
            ->whereHas('journalEntryLine', fn (Builder $query) => $query->where('journal_entry_id', $physicalSale->journal_entry_id))
            ->first();
        if (! $openItem) {
            throw ValidationException::withMessages(['physical_sale' => 'ไม่พบยอดลูกหนี้ของเอกสารนี้']);
        }

        $remaining = $openItems->remainingAt($openItem, today()->format('Y-m-d'));
        if ($remaining === '0.00') {
            throw ValidationException::withMessages(['physical_sale' => 'เอกสารนี้รับชำระครบแล้ว']);
        }

        $openItem->setAttribute('remaining_amount', $remaining);

        return $physicalSale->setRelation('paymentOpenItem', $openItem);
    }

    private function withholdingFor(OpenItem $item, string $date): string
    {
        if (! $item->withholding_tax_code_id || JournalBalance::decimal($item->withholding_amount) === '0.00') {
            return '0.00';
        }

        $allocated = Allocation::query()
            ->where(fn (Builder $query) => $query->where('debit_open_item_id', $item->id)->orWhere('credit_open_item_id', $item->id))
            ->where('allocation_date', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $date))
            ->sum('amount');
        $realized = WithholdingRealization::query()
            ->where('open_item_id', $item->id)->where('settlement_date', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $date))
            ->sum('tax_amount');

        return WhtRealizationCalculator::calculate(
            $item->original_amount, $item->withholding_base, $item->withholding_amount,
            $item->remaining_amount, (string) $allocated, (string) $realized,
        )['tax'];
    }
}
