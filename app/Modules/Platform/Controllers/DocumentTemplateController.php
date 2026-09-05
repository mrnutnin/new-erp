<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Modules\Platform\Models\DocumentTemplate;
use App\Modules\Platform\Models\DocumentTemplateVersion;
use App\Modules\Platform\Services\DocumentTemplateService;
use App\Modules\Platform\Support\DocumentFieldRegistry;
use App\Modules\Platform\Support\NormalizedDocumentPayloadContract;
use App\Modules\Platform\Services\DocumentTemplateRenderService;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\RedirectResponse;

final class DocumentTemplateController extends Controller
{
    public function index(Request $request): View
    {
        return view('Platform::document-templates.index', ['templates' => $this->templates(), 'editVersion' => null, 'formMode' => false, 'documentTypes' => $this->documentTypes(), 'fields' => DocumentFieldRegistry::fields('PURCHASE_ORDER')]);
    }

    public function create(): View
    {
        return view('Platform::document-templates.index', ['templates' => collect(), 'editVersion' => null, 'formMode' => true, 'documentTypes' => $this->documentTypes(), 'fields' => DocumentFieldRegistry::fields('PURCHASE_ORDER')]);
    }

    public function edit(DocumentTemplateVersion $version): View
    {
        abort_unless((int) $version->template?->company_setting_id === 1 && $version->status === 'DRAFT', 404);
        return view('Platform::document-templates.index', ['templates' => collect(), 'editVersion' => $version->load('template'), 'formMode' => true, 'documentTypes' => $this->documentTypes(), 'fields' => DocumentFieldRegistry::fields('PURCHASE_ORDER')]);
    }

    private function templates()
    {
        return DocumentTemplate::query()->where('company_setting_id', 1)->where('status', 'ACTIVE')->with(['versions' => fn ($query) => $query->latest('version')])->orderBy('document_type')->orderBy('name')->get();
    }

    private function documentTypes(): array
    {
        return ['PURCHASE_ORDER', 'PURCHASE_REQUISITION', 'GOODS_RECEIPT', 'PURCHASE_INVOICE', 'CREDIT_NOTE', 'LANDED_COST'];
    }

    public function update(Request $request, DocumentTemplateVersion $version, DocumentTemplateService $service): JsonResponse|RedirectResponse
    {
        abort_unless((int) $version->template?->company_setting_id === 1, 404);
        $data = $request->validate(['definition' => ['required', 'json']]);
        $updated = $service->updateDraft($version, json_decode($data['definition'], true), $request->user());
        if (! $request->expectsJson()) return redirect()->route('settings.document-templates.index')->with('success', 'อัปเดต Template Draft แล้ว');
        return response()->json(['status' => true, 'version' => $updated->version, 'message' => 'อัปเดต Template Draft แล้ว']);
    }

    public function newVersion(Request $request, DocumentTemplate $template, DocumentTemplateService $service): JsonResponse|RedirectResponse
    {
        abort_unless((int) $template->company_setting_id === 1 && $template->status === 'ACTIVE', 404);
        $version = $service->createNextVersion($template, $request->user());
        if (! $request->expectsJson()) return redirect()->route('settings.document-templates.edit', $version)->with('success', 'สร้าง Version Draft ใหม่แล้ว');
        return response()->json(['status' => true, 'version_id' => $version->id, 'version' => $version->version]);
    }

    public function archive(Request $request, DocumentTemplate $template, DocumentTemplateService $service): JsonResponse|RedirectResponse
    {
        abort_unless((int) $template->company_setting_id === 1, 404);
        $service->retire($template, $request->user());
        if (! $request->expectsJson()) return redirect()->route('settings.document-templates.index')->with('success', 'Archive Template แล้ว');
        return response()->json(['status' => true, 'message' => 'Archive Template แล้ว']);
    }

    public function store(Request $request, DocumentTemplateService $service): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['document_type' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:160'], 'definition' => ['required', 'json'], 'is_default' => ['nullable', 'boolean']]);
        $definition = json_decode($data['definition'], true);
        if (! is_array($definition)) {
            throw ValidationException::withMessages(['definition' => 'Definition JSON ไม่ถูกต้อง']);
        }
        $template = $service->createTemplate(CompanySetting::query()->findOrFail(1), $data['document_type'], $data['name'], $request->user(), (bool) ($data['is_default'] ?? false));
        $version = $service->createVersion($template, $definition, $request->user());
        if (! $request->expectsJson()) {
            return redirect()->route('settings.document-templates.index')->with('success', 'สร้าง Template Draft แล้ว');
        }
        return response()->json(['status' => true, 'template_id' => $template->id, 'version_id' => $version->id, 'message' => 'สร้าง Template Draft แล้ว']);
    }

    public function publish(Request $request, DocumentTemplateVersion $version, DocumentTemplateService $service): JsonResponse|RedirectResponse
    {
        $template = $version->template;
        abort_unless((int) $template->company_setting_id === 1, 404);
        $published = $service->publish($version, $request->user());
        if (! $request->expectsJson()) {
            return redirect()->route('settings.document-templates.index')->with('success', 'เผยแพร่ Template แล้ว');
        }
        return response()->json(['status' => true, 'message' => 'เผยแพร่ Template แล้ว', 'version' => $published->version]);
    }

    public function preview(Request $request, DocumentTemplateRenderService $renderer): Response
    {
        $data = $request->validate(['document_type' => ['required', 'string', 'max:80'], 'definition' => ['required', 'json']]);
        $definition = json_decode($data['definition'], true);
        if (! is_array($definition)) {
            throw ValidationException::withMessages(['definition' => 'Definition JSON ไม่ถูกต้อง']);
        }
        $logoPath = app('App\\Modules\\Settings\\Services\\GlobalSettings')->value('logo_path');
        $logo = $logoPath && Storage::disk('public')->exists($logoPath)
            ? ($request->boolean('pdf') ? Storage::disk('public')->path($logoPath) : Storage::disk('public')->url($logoPath))
            : null;
        $payload = NormalizedDocumentPayloadContract::normalize($data['document_type'], [
            'company' => ['logo' => $logo, 'name' => 'บริษัทตัวอย่าง จำกัด', 'address' => '99 ถนนสุขุมวิท กรุงเทพฯ', 'tax_id' => '0105559999999'],
            'party' => ['name' => 'Supplier ตัวอย่าง จำกัด', 'address' => 'ที่อยู่ Supplier ตัวอย่าง'],
            'document' => ['title' => 'ใบสั่งซื้อ', 'number' => 'PO-DEMO-0001', 'date' => now()->format('d/m/Y'), 'status' => 'DRAFT'],
            'lines' => [['item' => 'ITEM-001 · สินค้าตัวอย่าง', 'description' => 'รายการตัวอย่าง', 'uom' => 'ชิ้น', 'quantity' => '10.00', 'amount' => '1,000.00']],
            'totals' => ['subtotal' => '1,000.00', 'vat' => '70.00', 'grand_total' => '1,070.00'],
            'signatures' => ['prepared_by' => 'ผู้จัดทำ', 'approved_by' => 'ผู้อนุมัติ'],
        ]);

        return response($renderer->render($data['document_type'], $definition, $payload));
    }

    public function previewVersion(Request $request, DocumentTemplateVersion $version, DocumentTemplateRenderService $renderer, DocumentPdfRenderer $pdf): Response
    {
        abort_unless((int) $version->template?->company_setting_id === 1, 404);
        $html = $this->preview($request->merge(['pdf' => true, 'document_type' => $version->template->document_type, 'definition' => json_encode($version->definition)]), $renderer)->getContent();
        return response($pdf->render($html), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="template-v'.$version->version.'.pdf"']);
    }
}
