<?php

namespace App\Modules\Finance\Services;

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Warehouse;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\PaymentVoucher;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PaymentVoucherSettlementService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly OpenItemService $openItems,
    ) {}

    public function create(PaymentVoucher $voucher, int $warehouseId, Request $request, AuditLogger $audit): Settlement
    {
        return DB::transaction(function () use ($voucher, $warehouseId, $request, $audit): Settlement {
            $voucher = PaymentVoucher::query()->with('lines')->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            $warehouse = Warehouse::query()->with('branch')->findOrFail($warehouseId);

            if ((int) $voucher->warehouse_id !== $warehouseId) {
                throw ValidationException::withMessages(['voucher' => 'ใบสำคัญไม่อยู่ในสาขาที่เลือก']);
            }
            if (! $warehouse->branch) {
                throw ValidationException::withMessages(['voucher' => 'คลังของใบสำคัญไม่มีสาขา']);
            }
            if ($voucher->voucher_type === 'PRE_PAYMENT') {
                throw ValidationException::withMessages(['voucher' => 'ยังสร้าง Settlement จากใบขอจ่ายล่วงหน้าไม่ได้ กรุณาใช้ขั้นตอน Advance/Deposit ที่รองรับก่อน']);
            }
            if ($voucher->status !== 'APPROVED') {
                throw ValidationException::withMessages(['voucher' => 'สร้าง Settlement ได้เฉพาะใบสำคัญที่อนุมัติแล้ว']);
            }
            if ($voucher->settlement_id) {
                return Settlement::query()->findOrFail($voucher->settlement_id);
            }
            if (! $voucher->party_id) {
                throw ValidationException::withMessages(['voucher' => 'ใบสำคัญต้องระบุ Supplier ก่อนสร้าง Settlement']);
            }
            if (! $voucher->bank_account_id) {
                throw ValidationException::withMessages(['voucher' => 'ใบสำคัญต้องระบุบัญชีเงินก่อนสร้าง Settlement']);
            }

            $party = Party::query()->whereKey($voucher->party_id)->where('is_active', true)->sharedLock()->first();
            $role = $party ? PartyRole::query()->where('party_id', $party->id)->where('role', 'SUPPLIER')->where('is_active', true)->sharedLock()->first() : null;
            $bank = BankAccount::query()->whereKey($voucher->bank_account_id)->where('warehouse_id', $warehouseId)->where('is_active', true)->lockForUpdate()->first();
            if (! $party || ! $role) {
                throw ValidationException::withMessages(['voucher' => 'Supplier ของใบสำคัญต้องยังเปิดใช้งานอยู่']);
            }
            if (! $bank) {
                throw ValidationException::withMessages(['voucher' => 'บัญชีเงินของใบสำคัญไม่ตรงกับสาขาที่เลือกหรือถูกปิดใช้งาน']);
            }

            $lines = $voucher->lines;
            if ($lines->isEmpty() || $lines->contains(fn ($line) => ! $line->open_item_id)) {
                throw ValidationException::withMessages(['voucher' => 'ใบสำคัญต้องมีรายการจัดสรรเจ้าหนี้ครบทุกบรรทัดก่อนสร้าง Settlement']);
            }
            $lineTotalDecimal = $lines->reduce(
                fn (string $total, $line): string => JournalBalance::add($total, $line->amount),
                '0.00'
            );
            $voucherTotal = JournalBalance::decimal($voucher->amount);
            if ($lineTotalDecimal !== $voucherTotal) {
                throw ValidationException::withMessages(['voucher' => "ยอดจัดสรร {$lineTotalDecimal} ต้องเท่ากับยอดใบสำคัญ {$voucherTotal}"]);
            }

            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PAYMENT')->where('is_active', true)->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['voucher' => 'ยังไม่ได้ตั้งค่าเลขเอกสารสำหรับ Payment Settlement']);
            }
            $documentDate = $voucher->document_date->format('Y-m-d');
            $documentNumber = $this->sequences->issueForBranch($sequence, $warehouse->branch, $voucher->document_date);
            $openItems = OpenItem::query()->whereKey($lines->pluck('open_item_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($openItems->count() !== $lines->count()) {
                throw ValidationException::withMessages(['voucher' => 'ไม่พบ Open Item บางรายการ กรุณาแก้ไขบรรทัดใบสำคัญแล้วลองใหม่']);
            }

            $intentRows = [];
            foreach ($lines as $line) {
                $item = $openItems->get($line->open_item_id);
                if ((int) $item->warehouse_id !== $warehouseId || $item->ledger_type !== 'AP' || $item->party_type !== 'SUPPLIER' || (int) $item->party_id !== (int) $voucher->party_id || $item->balance_side !== 'CREDIT'
                    || $item->document_number !== $line->open_item_document_number || JournalBalance::decimal($item->original_amount) !== JournalBalance::decimal($line->open_item_original_amount)) {
                    throw ValidationException::withMessages(['voucher' => 'Open Item ไม่ตรงกับ snapshot ของใบสำคัญ หรือไม่ใช่เจ้าหนี้ Supplier ของสาขานี้']);
                }
                $this->openItems->assertAmountAvailable($item, $documentDate, $line->amount, "line_{$line->line_number}");
                $intentRows[] = ['open_item_id' => $item->id, 'line_number' => $line->line_number, 'amount' => JournalBalance::decimal($line->amount)];
            }

            $settlement = Settlement::query()->create([
                'document_type' => 'PAYMENT', 'document_number' => $documentNumber, 'document_date' => $documentDate, 'settlement_date' => $documentDate,
                'party_type' => 'SUPPLIER', 'party_id' => $voucher->party_id, 'bank_account_id' => $bank->id,
                'gross_amount' => $voucherTotal, 'tax_amount' => '0.00', 'withholding_amount' => '0.00', 'net_amount' => $voucherTotal,
                'status' => 'DRAFT', 'description' => $voucher->description, 'created_by' => $request->user()->id,
            ]);
            $settlement->allocationIntents()->createMany($intentRows);
            $voucher->update(['settlement_id' => $settlement->id]);
            $audit->record('finance.payment_voucher.settlement_created', $voucher, [], ['settlement_id' => $settlement->id, 'settlement_number' => $settlement->document_number, 'allocation_intents' => $intentRows], $request->user(), $request);

            return $settlement;
        }, 3);
    }
}
