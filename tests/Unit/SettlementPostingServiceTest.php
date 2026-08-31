<?php

namespace Tests\Unit;

use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Services\SettlementPostingService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SettlementPostingServiceTest extends TestCase
{
    public function test_it_applies_settlement_date_to_tax_lines_and_delegates(): void
    {
        $posting = $this->createMock(JournalPostingService::class);
        $posting->expects($this->once())->method('post')->with(
            $this->callback(fn (array $data) => $data['entry_date'] === '2026-08-20'
                && $data['document_date'] === '2026-08-20'
                && ! isset($data['settlement_date'])
                && $data['lines'][0]['tax_settlement_date'] === '2026-08-20'),
            $this->isInstanceOf(Warehouse::class),
            null,
        )->willReturn(new JournalEntry);

        $service = new SettlementPostingService($posting);
        $service->post([
            'event_code' => 'customer_payment',
            'settlement_date' => '2026-08-20',
            'lines' => [['tax_code_id' => 1]],
        ], new Warehouse);
    }

    public function test_it_rejects_non_settlement_events(): void
    {
        $this->expectException(ValidationException::class);
        (new SettlementPostingService($this->createMock(JournalPostingService::class)))->post(['event_code' => 'sales_invoice'], new Warehouse);
    }
}
