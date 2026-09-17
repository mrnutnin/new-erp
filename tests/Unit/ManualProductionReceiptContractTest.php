<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\ManualProductionReceiptContract;
use Illuminate\Support\Facades\Blade;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ManualProductionReceiptContractTest extends TestCase
{
    public function test_receipt_requires_gain_and_positive_quantity_and_value(): void
    {
        $this->expectException(ValidationException::class);

        ManualProductionReceiptContract::assert([
            'document_context' => ManualProductionReceiptContract::CONTEXT,
            'direction' => 'LOSS',
            'lines' => [['quantity' => '1', 'value' => '10']],
        ]);
    }

    public function test_preflight_keeps_deferred_event_closed(): void
    {
        $result = ManualProductionReceiptContract::preflight([
            'event_code' => 'production.finished_receipt',
            'ready' => false,
            'blockers' => [['code' => 'POSTING_EVENT_DEFERRED', 'message' => 'Event ยังไม่เปิด']],
        ]);

        self::assertFalse($result['ready']);
        self::assertSame('POSTING_EVENT_DEFERRED', $result['blockers'][0]['code']);
    }

    public function test_create_view_initializes_its_editing_state_in_compiled_php(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Wms/Views/production/finished-receipts/create.blade.php');
        $compiled = Blade::compileString($view);

        self::assertStringContainsString('$editing = filled($document);', $compiled);
        self::assertStringNotContainsString('@php', $compiled);
    }

    public function test_writer_and_gate_c_keep_the_multi_source_bridge_contract(): void
    {
        $request = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Wms/Requests/SaveInventoryAdjustmentRequest.php');
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Wms/Controllers/ProductionFinishedReceiptController.php');
        $posting = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Wms/Services/ManualProductionReceiptPostingService.php');
        $routes = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Wms/Routes/web.php');

        self::assertStringContainsString("'sources.*.issue_id'", $request);
        self::assertStringContainsString("'sources.*.consumed_value'", $request);
        self::assertStringContainsString('$this->sourceAllocator->allocate', $controller);
        self::assertStringContainsString("\$values['source_consumptions']", $controller);
        self::assertStringContainsString('finished-receipts/source-options', $routes);
        self::assertStringContainsString('SOURCE_COST_REVISION_CHANGED', $posting);
        self::assertStringContainsString("where('receipt_document_id', \$receiptId)", $posting);
    }
}
