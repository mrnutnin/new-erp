# Purchasing module boundary

Purchasing owns supplier master and the procure-to-pay documents: PR, PO,
Goods Receipt, Purchase Invoice/Credit Note and their PDF/approval flows.

This extraction is intentionally incremental. The canonical surface is
`/purchasing` with `purchasing.*` route names. The current handlers still
delegate to the proven WMS purchasing controllers and keep `/wms/...` routes
as compatibility links, so existing bookmarks and Blade forms remain valid.
The program selector maps the internal `wms` program code (displayed as
Purchasing) to `purchasing.index` (`/purchasing`), while the `inventory`
program code (displayed as WMS) maps to `wms.index` (`/wms`). Legacy database
rows that still contain `wms.purchasing.index` are normalized at redirect time.

Supplier, PR, PO and AP now have module-aware controller and route/view seams
in this namespace while reusing the existing validation and persistence
implementation. Their Purchasing pages use `Purchasing::` view aliases and
the controller-generated data/action links use canonical `purchasing.*`
routes. AP also exposes canonical Select2 options, three-way matching, variance
decisions and guarded inventory actions. Shared AP form/show templates resolve
document, option and reference links from the active module route prefix, so
Purchasing pages stay on `/purchasing` while WMS compatibility pages stay on
`/wms`. The WMS implementation remains the single source of truth during this
staged move; its controller/request/service classes are retained until each
contract is extracted.
GR now has a module-aware Purchasing adapter and canonical route/view prefix
while the existing WMS controller remains the single business-rule source of
truth. Purchasing PDF routes now use a module-local controller and
`Purchasing::pdf.purchase-document` view alias; WMS compatibility routes remain
unchanged and keep the existing `wms.*.print` permission contract during this
staged move. The renderer still reuses the shared platform PDF service and
WMS-owned document models, so this boundary does not duplicate accounting or
stock rules.
The Purchasing entry dashboard and workflow center are also module-local
(`EntryController`, `WorkflowController`, and `Purchasing::dashboard` /
`Purchasing::workflow.index`). The legacy WMS workflow remains available for
the inventory program. Those pages now resolve through a Purchasing-local
layout; the navigation partial remains shared intentionally until the final
menu split.

Next extraction waves move the implementation one bounded flow at a time
(Supplier → PR → PO → GR → AP), without renaming database tables or changing
posted-document contracts. The seven-stage boundary plan is now complete for
the safe surface (provider, adapters, canonical routes, PDF, AP endpoints,
dynamic form/show links, and boundary checks). Legacy WMS controllers, requests,
services, and shared templates are intentionally retained while `/wms/...`
compatibility routes and Purchasing adapters still reference them; remove each
file only after a reference audit proves that the corresponding legacy route,
view alias, and adapter no longer depend on it.
Supplier remains an explicit adapter at this point: its 340-line identity,
tax, audit, and financial-history implementation is still shared with WMS.
Purchasing now owns the request and view entry seams, plus the
`index/data/options/create/edit/store/update/destroy` controller methods. The
read methods and action seams are Purchasing-owned; all mutation methods still
delegate to the single shared transaction implementation. The Purchasing
request classes for Supplier, PR, PO and AP actions are now present as
inheritance seams, but the controller signatures continue to use the
compatible WMS request types until the parent contracts can be widened safely.
The views inherit shared templates, preventing duplicate Supplier identity
rules. These seams are intentionally not a license to delete the WMS request
classes: both `/wms` compatibility routes and the shared controllers still
resolve them today.

Canonical `/purchasing` routes use the `purchasing.*` permission namespace.
The `/wms` compatibility routes intentionally retain `wms.*`; the user
permission bridge keeps existing legacy role assignments working during the
module extraction.

## Legacy-file removal boundary

No WMS controller, request, service, or shared Blade file may be removed merely
because a Purchasing wrapper now exists. The `/wms` compatibility routes still
resolve these classes directly, and Purchasing views intentionally inherit
shared WMS templates until their markup and business rules are extracted.
Before deleting a legacy file, run a reference audit across both route trees,
module providers, view aliases, controllers, requests, and tests. Removal is
safe only when the corresponding WMS route, alias, and adapter have zero
references; otherwise keep the file and add the next bounded seam first.

Shared purchase templates must render route URLs from the injected
`$moduleRoutePrefix` (`purchasing` or `wms`). Any remaining hard-coded `wms.*`
route in a shared template is compatibility debt to remove before markup
extraction is considered complete.
