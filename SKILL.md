---
name: new-erp
description: Develop and review the Laravel ERP in this repository. Use for planning, implementing, testing, refactoring, or reviewing its Platform, purchasing, inventory, sales, production, finance, accounting, logistics, assets, migration, UI, or shared infrastructure.
license: Proprietary
---

# New ERP Development

Build an industry-neutral ERP from the proven behavior in `/Users/mrnutninlaong/GitRepository/minterp` without copying its legacy architecture, secrets, or production data.

## Required reading

1. Read [CHECKLIST.md](CHECKLIST.md) for current delivery status, then [PLANING.md](PLANING.md) for fixed decisions and reference routing.
2. Read only the planning reference required by the use case:
   - [product and architecture](docs/planning/01-product-architecture.md) for boundaries, structure, shared data, settings, or storage;
   - [accounting and inventory](docs/planning/02-accounting-inventory.md) for stock, costing, transfer, recost, GL, tax, period, or migration;
   - [modules and UI](docs/planning/03-modules-ui.md) for module scope, flows, Blade/jQuery/AJAX/DataTables, or shared UI;
   - [delivery](docs/planning/04-delivery.md) for roadmap, dependencies, review, Definition of Done, risks, and unresolved decisions.
3. Inspect the matching end-to-end flow in `minterp` and the current implementation. Trace status, stock, money, accounting, permissions, and reports before changing behavior.
4. Keep work inside one explicit use case. Record a shared-contract change before editing dependent modules.

Do not load every reference by default. Use `rg` to find the relevant heading, then read enough surrounding context to preserve its invariants.

## Non-negotiable product boundaries

- PHP 8.2, Laravel 12, MySQL, one modular monolith and one database.
- One installation/database contains one legal company. Do not add SaaS tenancy, multi-company, intercompany, `organization_id`, offline mode, microservices, generic workflow/plugin engines, or speculative abstractions.
- Use Bootstrap 5, Blade, jQuery-first page behavior, AJAX CRUD, Yajra DataTables where useful, shared SweetAlert2, and approved public widgets. Prefer existing Bootstrap components/utilities (`form-control`, grid, spacing, display, buttons, cards, tables, validation) before adding project CSS. Pin frontend libraries in `public/vendor` and include them once from the root layout; the approved icon exception is version-pinned Boxicons CDN, also included once at the root. Do not require npm/Vite or a frontend server. All pages reuse the root template/shared components and CSS; never add inline or per-Blade CSS.
- Purchasing means procurement; WMS owns warehouse operations and every stock write. Internal program codes remain `wms` for Purchasing and `inventory` for WMS to preserve existing routes and references.
- Accounting is the ERP kernel. Operational modules post through its idempotent contract and never write journal tables directly.
- One company-wide AVG or FIFO policy applies across every branch/warehouse. Preserve transfer cost lineage and recost dependencies.
- Business-varying policies/defaults/thresholds/formats that are not invariants belong in typed Global Settings. Secrets remain in environment/config.
- Persistent files use the private Platform storage contract; modules do not call GCS or construct bucket URLs directly.
- Automated tests are Unit Tests only. Maintain repeatable manual QA for database, route/policy, queue, UI, GCS, and end-to-end reconciliation.

Never simplify authorization, validation at trust boundaries, auditability, data-loss prevention, accounting balance, stock consistency, decimal precision, locking, idempotency, or immutable posted history.

## Implementation style

- Laravel 12 conventions win for technical structure; `minterp` supplies familiar business terms, document/status flows, module grouping, Blade, and jQuery/AJAX behavior. Never copy a legacy workaround when Laravel provides the standard path.
- Follow `app/Modules/<Module>` as domain grouping and create only directories needed by the use case; this is not a custom framework. Keep shared migrations/config/resources/bootstrap/tests in their standard Laravel locations.
- Keep controllers as HTTP orchestration, validation in Form Requests, access in Policies/Gates, ordinary CRUD in Eloquent, and real multi-record/cross-module transactions in focused services. Use Eloquent by default; business/master records referenced by history use `SoftDeletes` unless the domain requires immutable ledger/reversal semantics. Do not apply soft deletes blindly to pivots, journals, stock movements, audit logs, or immutable posted records.
- Use Laravel resource actions/naming, PSR-4, conventional model/table/foreign-key names, service providers, config, jobs, queues, scheduler, commands, migrations, and seeders. Document any legacy-name compatibility mapping.
- Reuse an existing local convention before adding a helper, component, layer, or dependency. Share behavior only when reuse is real or a Platform invariant needs one enforcement point.
- Use synchronous services and database transactions for stock, payment, and journal correctness. Queue only retryable side effects or persisted background calculations with visible status and idempotent retry.
- Use migrations, foreign keys, unique constraints, query-path indexes, decimal business values, server-side state transitions, UTC system timestamps, explicit business dates, and reversal instead of editing posted records.
- Keep critical stock/accounting behavior in an explicit service/transaction; never hide it in observers, accessors, Blade, JavaScript, or opaque event chains. Do not create generic base controllers/repositories/services.
- Comment complex **why**, invariant, lock order, accounting/costing sequence, edge case, or external limitation so a junior developer can change it safely. Do not narrate syntax or leave commented-out code.
- AJAX actions must disable/guard the trigger until completion and restore it on every response path. This never replaces server idempotency/current-state checks.
- Match the team's `minterp` CRUD feedback style: AJAX save controllers return at least `status` and `msg` (plus `redirect` when needed). Each page registers its form with shared `window.erpAjaxForm({ form, url, method, reload, redirect, alert })`; defaults do not reload or redirect. CRUD save shows SweetAlert2, then performs only the page option explicitly enabled. Create pages normally use `redirect: true` to prevent duplicate creation; update pages normally keep `reload: false`. A string `reload` targets a DataTable and `true` reloads the browser page. Login/context selection use `alert: false, redirect: true` because they are navigation. Validation remains beside fields and may also show one concise SweetAlert result. Never use native `alert`, `confirm`, or `prompt`.
- Keep page-specific jQuery/DataTable/filter/action code at the bottom of the same Blade in `@push('scripts')`, matching the team's familiar `minterp` workflow so one file is easy to maintain. Shared libraries and behavior stay in `public/js`; extract page code only when it is genuinely reused or the Blade becomes impractically large. Use one `$(function () { ... })`, delegated `.on()` handlers, and local variables; do not copy legacy inline `onclick`, duplicate ready blocks, globals, debug logs, or destroy/reinitialize loops.
- Keep official Select2/Flatpickr/DataTables/SweetAlert2 styles unchanged. Every DataTable includes Excel export, pagination, page length, and search; database-backed growing lists use server-side processing. Use DataTables Buttons `excelHtml5` as the default export action; on server-side tables it exports the rows currently loaded in the browser. Add a separate authorized backend full-dataset export only when the owner explicitly requires it.
- Every DataTable value shown to users must be human-readable, never a raw database/API representation. Format business dates with the company date-format setting, convert datetimes to the company timezone before display, use clear localized labels for statuses/booleans, render structured values instead of raw JSON/HTML entities, and show a consistent placeholder such as `-` for empty values. Keep raw ISO/numeric values only as separate internal sort/filter data when needed.
- Form sizing is a shared UI contract: every form control must have a readable minimum width for its data type (especially account/select, description, amount, tax and date fields). A dense form table may use horizontal scrolling at narrow widths; it must not shrink controls until values or labels are unreadable. Prefer shared CSS utility classes and responsive wrappers over per-page inline sizing.
- Export-specific automated tests, browser tests, and manual QA are outside MVP scope. Verify shared assets and ordinary page compilation only; do not delay module delivery for testing downloaded export files.
- Follow the familiar `minterp` list-page flow: a DataTable controller `index()` only returns its Blade view and never queries, compacts, or paginates the table-row dataset. Small option/filter datasets may be passed when the page genuinely needs them. The Blade's script section initializes DataTables and calls a separate AJAX `data()` route backed by Yajra; the Blade must not render table rows or Laravel pagination for that dataset.
- When a DataTable row has delete capability, render a permission-gated jQuery action button and register it per page with shared `window.erpAjaxDelete({ button, url, method, reload, redirect, confirm })`. Defaults use `DELETE` and do not reload/redirect; a DataTable selector reloads only that table. The helper must SweetAlert-confirm, disable the trigger while waiting, then show the controller's `status`/`msg`. The destroy route has its own delete permission, transaction, domain guard, and audit. Master/history data uses Eloquent SoftDelete unless deletion is forbidden by domain history; never infer a hard delete.
- Use one icon family only: Boxicons `2.1.4`. Icons normally accompany visible text, are decorative with `aria-hidden="true"`, and use Bootstrap spacing classes; do not mix Boxicons, Material Icons, and Font Awesome or create custom icon CSS.

## Verification and handoff

Run relevant Unit Tests, `vendor/bin/pint --test`, fresh-migration smoke check, local-asset HTTP smoke check, and applicable manual QA when available. The application must work with `php artisan serve` alone. Report concrete blockers for checks that cannot run.

A handoff states the use case, changed files/schema/routes/permissions/settings, cross-module/accounting contract, checks and results, and unresolved or deferred scope. Do not mark a page complete while required stock, finance, accounting, authorization, audit, reconciliation, or reversal behavior is missing.

Update `CHECKLIST.md` through the Master/Integration Agent whenever work changes state; parallel agents report status in handoff and do not edit the checklist concurrently.
