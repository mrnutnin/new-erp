<?php

namespace App\Modules\Pos\Support;

use App\Modules\Accounting\Support\PostingIdentity;
use Illuminate\Validation\ValidationException;

/**
 * Deterministic, side-effect-free plan for posting a physical HS/IV sale.
 *
 * The eventual application service must execute this plan in one outer
 * transaction: persist/post every stock intent, allocate final cost, post
 * COGS, then post revenue/AR and link both journal entries to the sale.
 */
final class PhysicalSalePostingPlan
{
    /**
     * Build a posting plan only after the side-effect-free readiness gate has
     * accepted the sale. The existing build() method remains available for
     * callers that only need identity/line planning.
     *
     * @return array{identity_key:string,readiness:array<string,mixed>,stock_intents:list<array<string,mixed>>,revenue_journal:array<string,mixed>}
     */
    public static function buildReady(array $sale): array
    {
        $readiness = PhysicalSalePostingReadiness::assertReady($sale);

        return [
            ...self::build($sale),
            'readiness' => $readiness,
        ];
    }

    /**
     * @return array{identity_key:string,stock_intents:list<array<string,mixed>>,revenue_journal:array<string,mixed>}
     */
    public static function build(array $sale): array
    {
        $saleId = (int) ($sale['id'] ?? 0);
        $number = trim((string) ($sale['document_number'] ?? ''));
        if ($saleId < 1 || $number === '') {
            throw ValidationException::withMessages(['sale' => 'ต้องมีรหัสและเลขที่เอกสารขาย']);
        }

        $stockIntents = PhysicalSaleStockPostingIntent::build([
            ...$sale,
            'physical_sale_id' => $saleId,
        ]);
        $revenueJournal = PhysicalSaleJournalPostingIntent::build($sale);

        if ((string) $revenueJournal['source_id'] !== (string) $saleId
            || (string) $revenueJournal['source_reference'] !== $number
            || (string) $revenueJournal['source_type'] !== 'POS') {
            throw ValidationException::withMessages(['sale' => 'Identity ของ Revenue Journal ไม่ตรงกับเอกสารขาย']);
        }

        $stockLineIds = collect($stockIntents)->pluck('line_id')->map(fn ($id): int => (int) $id)->values()->all();
        if ($stockLineIds === [] || count($stockLineIds) !== count(array_unique($stockLineIds))) {
            throw ValidationException::withMessages(['lines' => 'รายการ Stock ของเอกสารขายต้องไม่ซ้ำกัน']);
        }

        $lineNumbers = collect($stockIntents)->pluck('line_number')->map(fn ($n): int => (int) $n)->values()->all();
        $saleLineNumbers = collect(array_values($sale['lines'] ?? []))
            ->map(fn (array $line): int => (int) ($line['line_number'] ?? 0))
            ->values()->all();
        sort($lineNumbers);
        sort($saleLineNumbers);
        if ($lineNumbers !== $saleLineNumbers) {
            throw ValidationException::withMessages(['lines' => 'Stock intent ต้องครบทุกรายการขายและเรียงตาม line number']);
        }

        foreach ($stockIntents as $intent) {
            if ((string) $intent['source_id'] !== (string) $saleId
                || (string) $intent['source_reference'] !== $number
                || (string) $intent['source_type'] !== 'POS') {
                throw ValidationException::withMessages(['lines' => 'Identity ของ Stock Movement ไม่ตรงกับเอกสารขาย']);
            }
        }

        return [
            'identity_key' => PostingIdentity::key('POS', 'physical_sale.posting', (string) $saleId),
            'stock_intents' => $stockIntents,
            'revenue_journal' => $revenueJournal,
        ];
    }
}
