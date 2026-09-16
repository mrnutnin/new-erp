# new-erp Agent Guidelines

These instructions apply to the entire `new-erp` repository. User instructions for a specific task take precedence.

## File upload and object storage standard

Apply this section whenever adding or changing an image/file upload.

- Reuse `<x-platform::file-uploader>` from `app/Modules/Platform/Views/components/file-uploader.blade.php`; do not create a page-specific dropzone or initialize FilePond again. Set `accept`, `multiple`, `max-files`, and `max-file-size` explicitly. The native file input fallback must remain usable when the CDN is unavailable.
- For a handwritten user signature, reuse `<x-platform::user-signature-fields>` and `<x-platform::signature-pad>`. Support either one uploaded image or one canvas drawing, validate both server-side, and store them through the same private object-storage lifecycle. A signature image is presentational and must not be described as a digital signature.
- Validate every upload server-side with Laravel rules. Treat client-side file type, size, and count checks as UX only. Show validation beside the shared component, including errors for array members such as `files.0`.
- Store private uploads through `App\Modules\Platform\Services\FileStorageService`. The configured private disk must be S3-compatible and must never be `public`.
- Persist the storage `disk` and object `path` (plus metadata returned by `FileStorageService` when the domain needs it), never a bucket URL or temporary URL. Stream/download private objects through an authenticated, authorized controller action; do not expose the S3 path directly to the browser.
- Use a stable module folder name and the current company code when calling `FileStorageService::store()`. Do not build object keys independently in feature modules.
- Upload new objects before committing database references. If the database operation fails, delete newly uploaded objects. Replace/delete old objects only after the database commit succeeds. A soft-deleted business record keeps its objects until a separate, explicit retention/purge process exists.
- Keep upload and view/download routes behind the same module, branch/warehouse context, and permission boundary as the owning record. Do not rely on an unguessable path as authorization.
- Any new upload metadata columns require a normal migration with a reversible `down()`, model `$fillable`/cast updates, and an entry in `DatabasePreparationService::requiredSchema()` so the web installer verifies the deployed schema. If S3 is required, preserve the installer object-storage write/delete health check.
- Add the smallest contract test covering the shared uploader options, server validation, private storage service, authorized delivery route, migration, model cast, and installer schema check.

## PDF document standard

Whenever creating, reviewing, or changing a PDF/print document, read and follow [`PDF_STANDARD.md`](PDF_STANDARD.md). Use [`PDF_COVERAGE_CHECKLIST.md`](PDF_COVERAGE_CHECKLIST.md) for approved coverage and implementation priority. Classify the document before implementation, reuse the shared mPDF renderer and Thai font profile, and complete the legal/tax checklist when the document is within Revenue Department scope. A visible signature image is not a digital signature and must never be presented as e-Tax/e-Receipt compliance.

## UX/UI standard

Apply this section whenever creating, reviewing, or changing a user-facing page. The goal is a predictable ERP interface: users must understand the page purpose, current document status, and next valid action without asking what to click next.

### Inspect before changing

- Inspect the target index/detail/form Blade files, controller and AJAX endpoint, routes, permissions, document lifecycle, shared partials, and relevant tests.
- Reuse existing shared helpers and classes before adding anything. In particular, inspect `public/js/datatables.js`, `public/css/app.css`, and relevant shared Blade partials.
- Use `app/Modules/Wms/Views/issues/index.blade.php` and `show.blade.php` as starting examples, not as unquestioned sources of truth; known inconsistencies are listed below.
- Preserve authorization, validation, idempotency, audit logging, and accounting/stock safeguards while changing presentation.
- Prefer the smallest change at the shared source when several pages have the same inconsistency.

### Every page must answer

1. Where is the user?
2. What record or dataset is shown?
3. What is its current status?
4. What is the next valid action?
5. What will that action change?

Use one clear page heading. Index pages include a short purpose statement. Detail pages show the document number and status prominently. Put page-level actions at the upper right in a wrapping group. Show a single useful success/error message, not duplicate banners.

### Index pages

Use this layout order:

1. Page identity and primary create action.
2. Filter card.
3. DataTable card.

- Use `สร้าง{ชื่อเอกสาร}` with `bx-plus` for creation and place it at the upper right.
- Provide only useful server-side filters, normally status, relevant document/entity, and date range.
- Use `ค้นหา` for applying filters and `ล้างตัวกรอง` for reset.
- Reset must clear all native inputs and Select2 values, then reload the table.
- Put `ล้างตัวกรอง` at the upper right of the filter-card header, never as a field in the filter grid. Use `btn btn-sm btn-app-soft` with `bx bx-reset`.
- Keep filters active while paging, sorting, and exporting.
- Do not load an unbounded transaction collection in the index action or Blade.

### DataTables are AJAX/server-side

Transaction or potentially large tables must:

- use Yajra `DataTables::eloquent()` or `DataTables::query()` through a separate AJAX data route;
- initialize from `window.erpDataTableDefaults`;
- retain processing, server-side paging, global Search, pageLength, pagination, horizontal scrolling, and empty/error states;
- always include `buttons: [window.erpExcelButton(table)]`;
- escape user/data text with `$.fn.dataTable.render.text()` or an equivalent safe renderer before composing HTML;
- mark the action column non-orderable and non-searchable; disable search/sort on another displayed column only with a concrete reason;
- select/eager-load only required fields, avoid N+1 queries, validate filter values, and use deterministic ordering with a stable tie-breaker such as ID.

#### Search what users see

The global Search box must find the exact meaningful text rendered in cells, not only raw IDs or hidden database codes.

- Map ordinary columns with the correct Yajra `name`.
- Map derived or formatted columns with a SQL expression, join, `whereHas`, or `filterColumn`.
- Search both code and name when both are displayed for an item, customer, supplier, warehouse, or account.
- Search document numbers, reasons, and other displayed text directly.
- Search both the stored status and displayed Thai status label when practical.
- Support displayed date text when practical while keeping dedicated raw date filters.
- For combined summaries, search each meaningful displayed part.
- Test by copying text from a rendered cell into Search. A visible Search input alone is not proof that search works.

The Excel button is mandatory. With server-side processing, confirm whether the installed client export covers only loaded rows. If users expect all filtered results, implement a bounded server-side export and label the export scope accurately.

### Index actions versus detail actions

Do not duplicate every detail action in the DataTable.

Index rows prioritize navigation and low-risk maintenance in this fixed order:

1. View: `bx-show`.
2. Edit when allowed: `bx-edit`.
3. Print/download when useful: `bx-printer` / `bx-download`.
4. Delete draft when allowed: `bx-trash`, always last.

Use compact icon-only buttons in DataTables. Every icon-only control must have matching Thai `title` and `aria-label`. Use `btn-app-soft` for ordinary actions and the shared danger treatment for deletion.

Approval, posting, receiving, completion, cancellation, reversal, and other stock/accounting-changing actions belong on the detail page by default, where the user can inspect context. Add one to an index only when explicitly requested and the row provides enough context for a safe decision.

Detail actions use icon plus text and are grouped in this order:

1. Navigation: `กลับหน้ารายการ` with `bx-arrow-back`.
2. Utilities: edit, print, download.
3. One visually dominant next workflow action: approve, submit, post, receive, or complete.
4. Destructive actions: `ยกเลิกเอกสาร`, then `ลบร่าง`; destructive actions stay last and are visually separated when space permits.

Show only actions valid for the current status and permission. Do not show a large set of disabled actions.

### Document cancellation and draft deletion

- Use `ยกเลิกเอกสาร` for every user-facing button that cancels, voids, or reverses a posted business document. Do not label a button `กลับรายการ`; internal routes/services may retain `reverse` terminology.
- A DRAFT document must show `ลบร่าง` when the user has delete permission and domain rules allow deletion.
- `ลบร่าง` removes an uncommitted draft according to the existing soft-delete/audit contract.
- `ยกเลิกเอกสาร` changes lifecycle state while preserving the business record and audit history.
- If both are valid, confirmation text must explain the difference.

### Shared button classes, text, and icons

Use semantic classes already defined in `public/css/app.css`. Do not create page-specific colors, inline hex colors, or duplicate button classes when an existing shared class covers the role.

- Primary workflow: `btn-app-primary` or the current shared primary equivalent. Primary is dark/black; do not introduce purple primary actions.
- Ordinary/utility: `btn-app-soft`.
- Navigation: the established outline-secondary treatment.
- Destructive: the shared danger treatment (`btn-app-danger` or the established outline-danger variant).

Use these labels and Boxicons consistently:

| Intent | User-facing text | Icon |
|---|---|---|
| Create | `สร้าง{ชื่อเอกสาร}` | `bx-plus` |
| View | `ดูรายละเอียด` | `bx-show` |
| Edit | `แก้ไข` | `bx-edit` |
| Approve | `อนุมัติ` | `bx-check` |
| Post stock/accounting | `ลง Stock` / `ลงบัญชี` | `bx-send` |
| Print | `พิมพ์` | `bx-printer` |
| View GL | `ดู GL` | `bx-book-open` |
| Export | `ส่งออก Excel` | `bx-download` |
| Search/filter | `ค้นหา` | `bx-search` when needed |
| Reset filters | `ล้างตัวกรอง` | `bx-reset` when needed |
| Cancel document | `ยกเลิกเอกสาร` | `bx-x-circle` |
| Delete draft | `ลบร่าง` | `bx-trash` |
| Return to index | `กลับหน้ารายการ` | `bx-arrow-back` |

Use Thai action text unless a domain term such as Stock or GL is intentionally retained throughout the system. Confirmation buttons should repeat the actual action (`อนุมัติ`, `ลงบัญชี`, `ยกเลิกเอกสาร`, `ลบร่าง`) instead of generic `ยืนยัน` when the distinction matters.

### GL preview controls

- `ดู GL` is a read-only utility action. It uses `btn-app-soft`, `bx-book-open`, and the existing `data-journal-preview-url` / `data-journal-preview-urls` contract.
- Place it after `กลับหน้ารายการ` and before the next workflow or destructive action. It must never be the visually dominant primary action.
- For more than one journal, use `ดู GL ทั้งหมด`; use a qualified label such as `ดู GL รายได้`, `ดู GL ต้นทุน`, or `ดู GL รายการยกเลิก` only when the distinction is necessary.
- A GL preview remains a utility even for a cancellation/reversal journal: do not use a danger button merely because the underlying document was reversed.
- Icon-only GL preview controls in DataTables require Thai `title` and `aria-label`.

### Status badges

Use `badge` with existing `app-status-*` classes. The same status must have the same Thai label and semantic class on index, detail, related-document tables, export, and confirmations.

| Meaning | Shared class |
|---|---|
| Draft, inactive, neutral | `app-status-neutral` |
| Waiting, in progress, informational | `app-status-info` |
| Partial, warning, attention | `app-status-warning` |
| Approved, posted, completed, success | `app-status-success` |
| Failed, rejected, void, cancelled | `app-status-danger` |

Prefer one shared status map/helper per domain over repeated PHP and JavaScript maps. A domain may distinguish APPROVED from POSTED, but that distinction must remain consistent across all of its pages.

### Detail pages

After the page header and action group, use this order when applicable:

1. Short workflow guidance or next-step alert, only when needed.
2. Document metadata.
3. Main line items.
4. Totals and accounting/stock impact.
5. Related documents.
6. Audit/history.

Align quantities and money to the right and show units/currency where ambiguous. Use AJAX DataTables or bounded pagination for large child collections instead of rendering the entire collection into Blade or JavaScript. Tabs/anchors are appropriate only when they improve navigation on a genuinely long page; status and primary action must remain easy to find.

### Forms, feedback, accessibility, and responsive behavior

- Group fields by business meaning, not database shape.
- Mark required fields consistently and show validation beside the field.
- Use result-oriented submit text such as `บันทึกร่าง`.
- Disable a submitted AJAX control until completion and restore it on failure.
- Preserve entered values after validation errors.
- Confirm destructive/irreversible actions and request a reason when the audit contract requires it.
- Success feedback states what happened and where the user should go next.
- Error feedback uses a safe server message with a useful fallback.
- Decorative icons use `aria-hidden="true"`; icon-only actions require `title` and `aria-label`.
- Keep visible keyboard focus. Never communicate status only by color.
- Action groups wrap on small screens; wide tables scroll horizontally; filters stack in a logical order.
- Do not hide critical instructions only inside a tooltip.

### Known Issues-page gaps to avoid copying

The current Issues pages are useful structural examples but still need these corrections when they are next touched:

- Index currently exposes Approve/Post quick actions; keep lifecycle-changing actions on detail by default.
- Some buttons use `btn-dark` instead of the semantic shared primary class.
- Detail labels such as `ยกเลิก` and `ลบ Draft` should become `ยกเลิกเอกสาร` and `ลบร่าง`.
- VOID and other status mappings are duplicated and can fall back to inconsistent colors; centralize the domain map.
- Derived Yajra columns such as formatted date/status/related-document labels require explicit search mapping before they satisfy “search what users see”.
- Inline SweetAlert colors should be replaced by the existing shared styling mechanism.
- Verify whether Excel export on server-side tables exports the current page or all filtered rows, and label/implement it accordingly.

### Definition of done

A user-facing page is complete only when:

- purpose, status, and next action are obvious;
- filters work server-side and reset fully;
- DataTable uses Yajra AJAX and includes export, Search, pageLength, paging, safe rendering, and bounded data access;
- exact rendered text is searchable;
- index/detail actions follow the defined split, order, labels, icons, permissions, and confirmation behavior;
- DRAFT deletion and `ยกเลิกเอกสาร` wording follow the document contract;
- statuses use shared labels/classes;
- loading, empty, error, success, mobile, keyboard, and accessible-name states were checked;
- the smallest relevant tests and `git diff --check` pass after code changes.

<!-- graft:start -->
## Graft — repo context graph

This repo is indexed in `graft/`: small linked markdown nodes that explain each
system and carry exact file:line spans, kept in sync with the code through git.

For ANY task here — understanding how something works, finding where code lives,
or scoping a change — get context from the graph before grepping or opening
source files. Re-ask freely (it's cheap) and reuse literal identifiers you
already have (symbol, error string, file name) as the query. New to this repo?
Run `graft map` first — a token-budgeted orientation (dir clusters, hubs,
hotspots), no LLM, no key.

- Run `graft ask "<your question>" --source` → ranked nodes with the relevant
  code spans inlined (each hit's ≤8-line crux by default; `--full` for whole
  definitions when the crux isn't enough). Match the tool to the task shape:
  for understanding or editing, the top node IS the answer — cite its
  `covers:` file:line spans and edit straight from `--source`. For
  exhaustive tasks ("every occurrence / every caller of this pattern"), ranked
  results are top-N, not complete — run `graft grep "<literal>"` instead
  (exhaustive over indexed files, grouped by enclosing symbol), falling back
  to raw `grep -rn` only for unindexed files.
- `graft skeleton <file>` → every definition's signature + span, ~10× cheaper
  than reading the file; use it to skim an API surface.
- `graft callers <symbol>` gives precomputed, exact edges — who calls this.
  Add `--direction out` for what it calls, or `--depth N` to walk
  transitively for the full blast radius. For structural questions, skip
  ranking and use this directly.
- Or browse: `graft/INDEX.md` lists every node; follow the links.
- Monorepos and folders of multiple repos rank fairly across sub-projects —
  hits carry `[scope/]` labels naming which one they're from. Narrow with
  `graft ask "<task>" --in <scope>/` once you know where you're working.

If a returned span is truncated ("+N more lines"), open the file at that exact
range before finalizing. Only open source files when a node genuinely lacks a
needed detail, and then at the exact file:line the node points to — never
re-read whole files.

After big code changes, refresh the graph with `graft build` (deterministic,
no API key, $0).
<!-- graft:end -->
