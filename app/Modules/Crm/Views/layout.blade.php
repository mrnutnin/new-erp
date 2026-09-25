@extends('layouts.app')

@section('sidebar')
    @include('Crm::partials.sidebar')
@endsection

@push('styles')
<link rel="manifest" href="{{ asset('crm-manifest.webmanifest') }}">
<meta name="theme-color" content="#0f766e">
<link rel="apple-touch-icon" href="{{ asset('images/mint-icon-192.png') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="MintERP CRM">
<style>
.crm-page .card { border-radius: 1rem; }
.crm-kpi-value { line-height: 1.15; overflow-wrap: anywhere; }
.crm-dashboard-summary .crm-kpi { color: inherit; transition: transform .15s ease, box-shadow .15s ease; }
.crm-dashboard-summary .crm-kpi:hover { transform: translateY(-2px); box-shadow: 0 .8rem 1.8rem rgba(31,41,55,.1) !important; }
.crm-kpi-icon { display: grid; place-items: center; width: 2.35rem; height: 2.35rem; margin-bottom: .75rem; border-radius: .75rem; font-size: 1.3rem; }
.crm-kpi-icon.is-blue { color: var(--crm-blue); background: rgba(var(--crm-blue-rgb),.1); }
.crm-kpi-icon.is-violet { color: var(--crm-violet); background: rgba(var(--crm-violet-rgb),.1); }
.crm-kpi-icon.is-teal { color: var(--crm-teal); background: rgba(var(--crm-teal-rgb),.1); }
.crm-kpi-icon.is-danger { color: var(--bs-danger); background: var(--bs-danger-bg-subtle); }
.crm-dashboard-stage { display: flex; justify-content: space-between; align-items: center; gap: .75rem; padding: .7rem 0; border-bottom: 1px solid var(--bs-border-color-translucent); color: inherit; text-decoration: none; }
.crm-dashboard-stage:last-child { border-bottom: 0; }.crm-dashboard-stage:hover strong { color: var(--crm-blue); }
.crm-dashboard-shortcuts { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: .75rem; }
.crm-dashboard-shortcuts a { display: grid; place-items: center; min-height: 6.5rem; padding: .75rem; border: 1px solid rgba(var(--crm-blue-rgb),.14); border-radius: .85rem; color: inherit; background: rgba(var(--crm-blue-rgb),.045); text-align: center; text-decoration: none; }
.crm-dashboard-shortcuts a:hover { border-color: var(--crm-blue); background: rgba(var(--crm-blue-rgb),.09); }.crm-dashboard-shortcuts i { color: var(--crm-blue); font-size: 1.65rem; }
.crm-install-card { background: radial-gradient(circle at 90% 10%,rgba(var(--crm-teal-rgb),.13),transparent 34%),#fff; }.crm-install-icon { display: grid; flex: 0 0 4rem; width: 4rem; height: 4rem; place-items: center; border-radius: 1.15rem; color: var(--crm-teal); background: rgba(var(--crm-teal-rgb),.11); font-size: 2rem; }
.crm-contact-link { color: inherit; text-decoration: none; }
.crm-contact-link:hover { color: var(--bs-primary); text-decoration: underline; }
.crm-filter-card { background: radial-gradient(circle at 92% 0%, rgba(var(--module-accent-rgb), .08), transparent 28%), #fff; }
.crm-opportunity-card { --crm-stage: var(--module-accent); --crm-stage-rgb: var(--module-accent-rgb); position: relative; overflow: hidden; border: 1px solid rgba(var(--crm-stage-rgb), .18) !important; background: radial-gradient(circle at 92% 8%, rgba(var(--crm-violet-rgb), .11), transparent 28%), radial-gradient(circle at 5% 100%, rgba(var(--crm-blue-rgb), .07), transparent 30%), linear-gradient(180deg, rgba(var(--crm-stage-rgb), .035), #fff 42%); transition: transform .15s ease, box-shadow .15s ease; }
.crm-stage-new { --crm-stage: var(--module-accent); --crm-stage-rgb: var(--module-accent-rgb); }
.crm-stage-contacted { --crm-stage: var(--bs-info); --crm-stage-rgb: var(--bs-info-rgb); }
.crm-stage-qualified { --crm-stage: var(--bs-success); --crm-stage-rgb: var(--bs-success-rgb); }
.crm-stage-proposal { --crm-stage: var(--bs-warning); --crm-stage-rgb: var(--bs-warning-rgb); }
.crm-stage-negotiation { --crm-stage: var(--bs-purple); --crm-stage-rgb: 111, 66, 193; }
.crm-stage-won { --crm-stage: var(--bs-success); --crm-stage-rgb: var(--bs-success-rgb); }
.crm-stage-lost { --crm-stage: var(--bs-danger); --crm-stage-rgb: var(--bs-danger-rgb); }
.crm-stage-new .app-status-neutral { color: var(--module-accent); background: rgba(var(--module-accent-rgb), .12); }
.crm-opportunity-card .min-w-0 { min-width: 0; }
.crm-card-title { font-size: 1.08rem; font-weight: 700; line-height: 1.35; }
.crm-card-customer { color: var(--module-accent) !important; font-size: .88rem; }
.crm-card-amount .crm-card-value { color: var(--crm-blue); }
.crm-card-chance .crm-card-probability { color: var(--crm-violet); }
.crm-card-commercial { display: flex; justify-content: space-between; align-items: end; gap: 1rem; }
.crm-card-label, .crm-card-meta small, .crm-card-next-action small { display: block; color: var(--bs-secondary-color); font-size: .76rem; font-weight: 500; }
.crm-card-value { font-size: 1.35rem; font-weight: 700; line-height: 1.2; }
.crm-card-value span { font-size: .78rem; font-weight: 500; color: var(--bs-secondary-color); }
.crm-card-probability { font-size: 1.25rem; font-weight: 700; line-height: 1.2; }
.crm-probability-progress { width: 100%; height: .4rem; overflow: hidden; border: 0; border-radius: 999px; accent-color: var(--crm-violet); }
.crm-probability-progress::-webkit-progress-bar { background: rgba(var(--crm-blue-rgb), .09); }
.crm-probability-progress::-webkit-progress-value { border-radius: 999px; background: linear-gradient(90deg, var(--crm-blue), var(--crm-violet)); }
.crm-probability-progress::-moz-progress-bar { border-radius: 999px; background: var(--crm-violet); }
.crm-card-meta { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: .65rem; }
.crm-card-meta > div { padding: .7rem; border: 1px solid transparent; border-radius: .75rem; }
.crm-card-meta .crm-meta-owner { border-color: rgba(var(--crm-amber-rgb), .16); background: rgba(var(--crm-amber-rgb), .08); }
.crm-card-meta .crm-meta-close { border-color: rgba(var(--crm-teal-rgb), .16); background: rgba(var(--crm-teal-rgb), .08); }
.crm-card-meta > div, .crm-card-next-action { display: flex; align-items: center; gap: .55rem; min-width: 0; }
.crm-card-meta i, .crm-card-next-action > i { flex: 0 0 auto; font-size: 1.15rem; }
.crm-meta-owner i { color: var(--crm-amber); }
.crm-meta-close i { color: var(--crm-teal); }
.crm-card-next-action > i { color: var(--crm-blue); }
.crm-card-meta span, .crm-card-next-action span { min-width: 0; }
.crm-card-meta strong, .crm-card-next-action strong { display: block; font-size: .88rem; line-height: 1.25; }
.crm-card-next-action { padding: .7rem .75rem; border: 1px solid rgba(var(--crm-blue-rgb), .16); border-radius: .75rem; background: linear-gradient(90deg, rgba(var(--crm-blue-rgb), .08), rgba(var(--crm-violet-rgb), .045)); }
.crm-card-next-action.is-overdue { border-color: var(--bs-danger-border-subtle); background: var(--bs-danger-bg-subtle); color: var(--bs-danger-text-emphasis); }
.crm-card-next-action.is-overdue small, .crm-card-next-action.is-overdue i { color: inherit; }
.crm-card-footer { padding-top: .8rem; border-top: 1px solid rgba(var(--crm-stage-rgb), .12); }
.crm-card-actions .btn:first-child { border-color: rgba(var(--crm-blue-rgb), .2); color: var(--crm-blue); background: rgba(var(--crm-blue-rgb), .09); }
.crm-card-actions .btn:first-child:hover { border-color: var(--crm-blue); color: #fff; background: var(--crm-blue); }
.crm-card-actions .btn:nth-child(2) { border-color: rgba(var(--crm-violet-rgb), .18); color: var(--crm-violet); background: rgba(var(--crm-violet-rgb), .07); }
.crm-work-filter { color: inherit; }
.crm-work-filter.is-active { border-color: rgba(var(--module-accent-rgb), .35) !important; box-shadow: 0 .7rem 1.8rem rgba(var(--module-accent-rgb), .14) !important; }
.crm-work-summary > div:nth-child(1) .crm-work-summary-icon { color: var(--crm-blue); }
.crm-work-summary > div:nth-child(2) .crm-work-summary-icon { color: var(--bs-danger); }
.crm-work-summary > div:nth-child(3) .crm-work-summary-icon { color: var(--crm-teal); }
.crm-work-summary > div:nth-child(4) .crm-work-summary-icon { color: var(--crm-amber); }
.crm-work-summary > div:nth-child(5) .crm-work-summary-icon { color: var(--bs-success); }
.crm-work-summary-icon { font-size: 1.45rem; }
.crm-work-card { position: relative; overflow: hidden; border: 1px solid rgba(var(--crm-blue-rgb), .14) !important; background: radial-gradient(circle at 92% 8%, rgba(var(--crm-violet-rgb), .1), transparent 28%), #fff; }
.crm-team-load { min-height: 68px; padding: .7rem .8rem; border: 1px solid rgba(var(--crm-blue-rgb), .12); border-radius: .75rem; color: inherit; background: rgba(var(--crm-blue-rgb), .045); }
.crm-team-load:hover, .crm-team-load:focus-visible { border-color: var(--crm-blue); background: rgba(var(--crm-blue-rgb), .09); }
.crm-team-load span { color: var(--bs-secondary-color); font-size: .78rem; }
.crm-work-owner { display: flex; align-items: center; gap: .55rem; padding: .55rem .7rem; border-radius: .7rem; color: var(--crm-orange); background: rgba(var(--crm-orange-rgb), .08); }
.crm-work-owner span, .crm-work-owner small, .crm-work-owner strong { display: block; }
.crm-work-owner small { color: var(--bs-secondary-color); font-size: .72rem; }
.crm-work-time { display: flex; align-items: center; gap: .65rem; padding: .8rem; border-radius: .8rem; color: var(--crm-blue); background: rgba(var(--crm-blue-rgb), .08); }
.crm-work-card.is-overdue .crm-work-time { color: var(--bs-danger); background: var(--bs-danger-bg-subtle); }
.crm-work-time i { font-size: 1.35rem; }
.crm-work-time small, .crm-work-time strong { display: block; }
.crm-work-time small { opacity: .78; }
.crm-work-meta { display: flex; flex-wrap: wrap; gap: .5rem; }
.crm-work-meta span { padding: .35rem .6rem; border-radius: 999px; color: var(--bs-secondary-color); background: var(--bs-light); font-size: .78rem; }
.crm-work-actions { display: flex; flex-wrap: wrap; gap: .5rem; padding-top: .8rem; border-top: 1px solid var(--bs-border-color-translucent); }
.crm-btn-call { color: var(--crm-teal); border-color: rgba(var(--crm-teal-rgb), .2); background: rgba(var(--crm-teal-rgb), .09); }
.crm-btn-note { color: var(--crm-violet); border-color: rgba(var(--crm-violet-rgb), .2); background: rgba(var(--crm-violet-rgb), .09); }
.crm-kanban-board { display: grid; grid-template-columns: repeat(7, minmax(18rem, 1fr)); gap: 1rem; overflow-x: auto; padding-bottom: 1rem; scroll-snap-type: x proximity; }
.crm-kanban-column { min-height: 24rem; padding: .85rem; border: 1px solid rgba(var(--crm-blue-rgb), .12); border-radius: 1rem; background: rgba(var(--crm-blue-rgb), .035); scroll-snap-align: start; transition: border-color .15s ease, background .15s ease; }
.crm-kanban-column.is-drag-over { border-color: var(--crm-blue); background: rgba(var(--crm-blue-rgb), .09); }
.crm-kanban-column > header { display: flex; justify-content: space-between; align-items: start; gap: .5rem; min-height: 3.2rem; }
.crm-kanban-column h2 { margin: 0; font-size: .95rem; }
.crm-kanban-column header small { color: var(--bs-secondary-color); font-size: .68rem; }
.crm-kanban-items { display: grid; gap: .7rem; }
.crm-kanban-card { padding: .85rem; border: 1px solid var(--bs-border-color-translucent); border-radius: .85rem; background: #fff; box-shadow: 0 .3rem 1rem rgba(34,45,72,.06); }
.crm-kanban-card[draggable="true"] { cursor: grab; }
.crm-kanban-card.is-dragging { opacity: .45; }
.crm-kanban-card .badge { max-width: 8rem; white-space: normal; }
.crm-kanban-value { display: flex; justify-content: space-between; gap: .5rem; color: var(--crm-blue); font-weight: 700; }
.crm-kanban-value span { color: var(--crm-violet); }
.crm-kanban-card .progress-bar { background: linear-gradient(90deg,var(--crm-blue),var(--crm-violet)); }
.crm-kanban-meta { display: grid; gap: .35rem; color: var(--bs-secondary-color); font-size: .76rem; }
.crm-kanban-meta span { display: flex; align-items: center; gap: .35rem; overflow-wrap: anywhere; }
.crm-kanban-empty { padding: 2rem .5rem; color: var(--bs-secondary-color); text-align: center; }
@media (hover: hover) { .crm-opportunity-card:hover { transform: translateY(-3px); box-shadow: 0 .8rem 1.8rem rgba(var(--crm-stage-rgb), .16) !important; } }

@media (max-width: 767.98px) {
    .crm-page { padding: 1rem .75rem 6rem !important; }
    .crm-page-header { margin-bottom: 1rem !important; }
    .crm-page-header h1 { font-size: 1.5rem; }
    .crm-page-header > .btn { width: 100%; }
    .crm-page .card-body { padding: 1rem !important; }
    .crm-page .form-control, .crm-page .form-select, .crm-page .select2-selection { min-height: 2.75rem; font-size: 16px; }
    .crm-page textarea.form-control { min-height: auto; }
    .crm-kpi .card-body { padding: .9rem !important; }
    .crm-kpi-value { font-size: 1.35rem !important; }
    .crm-dashboard-shortcuts { grid-template-columns: 1fr 1fr; }
    .crm-dashboard-shortcuts a { min-height: 5.5rem; }
    .crm-install-card .card-body { align-items: flex-start !important; }
    .crm-install-icon { flex-basis: 3rem; width: 3rem; height: 3rem; font-size: 1.5rem; }
    .crm-next-action { align-items: flex-start !important; flex-direction: column; gap: .25rem !important; }
    .crm-next-action-time { white-space: normal !important; }
    .crm-action-bar { display: grid !important; grid-template-columns: 1fr 1fr; width: 100%; }
    .crm-action-bar > *, .crm-action-bar form, .crm-action-bar .btn { width: 100%; }
    .crm-action-bar .btn, .crm-form-actions .btn, .crm-card-actions .btn { min-height: 2.75rem; }
    .crm-action-bar form { display: contents; }
    .crm-action-bar .btn-app-primary { grid-column: 1 / -1; }
    .crm-form-actions { position: sticky; bottom: .5rem; z-index: 20; margin: 1rem -.35rem -.35rem !important; padding: .75rem; border-radius: .85rem; background: rgba(255,255,255,.96); box-shadow: 0 -.35rem 1.25rem rgba(15,23,42,.12); }
    .crm-form-actions .btn { flex: 1 1 0; }
    .crm-activity-submit { width: 100%; }
    .crm-opportunity-card .card-body { padding: 1rem !important; }
    .crm-card-title { font-size: 1rem; }
    .crm-card-value { font-size: 1.2rem; }
    .crm-card-commercial { margin-block: .85rem !important; }
    .crm-card-meta { padding: .65rem; }
    .crm-card-footer > div:first-child:empty { display: none; }
    .crm-card-actions { display: grid !important; grid-template-columns: 1fr 1fr; width: 100%; }
    .crm-card-actions .btn { width: 100%; }
    .crm-card-actions .btn:only-child { grid-column: 1 / -1; }
    .crm-page .pagination { justify-content: center; flex-wrap: wrap; }
    .crm-work-actions { display: grid; grid-template-columns: repeat(2, 1fr); }
    .crm-work-actions .btn { min-height: 2.75rem; }
    .crm-work-actions .btn:last-child:nth-child(odd) { grid-column: 1 / -1; }
}

@media (min-width: 1200px) { .crm-activity-compose { position: sticky; top: 1rem; } }
.crm-activity-card { position: relative; display: flex; gap: .85rem; padding: 1rem 0; border-bottom: 1px solid var(--bs-border-color-translucent); }
.crm-activity-card:last-child { padding-bottom: 0; border-bottom: 0; }
.crm-activity-marker { display: grid; flex: 0 0 2.35rem; width: 2.35rem; height: 2.35rem; place-items: center; border-radius: .75rem; color: var(--crm-blue); background: rgba(var(--crm-blue-rgb), .1); }
.crm-activity-meeting .crm-activity-marker { color: var(--crm-violet); background: rgba(var(--crm-violet-rgb), .1); }
.crm-activity-task .crm-activity-marker { color: var(--crm-amber); background: rgba(var(--crm-amber-rgb), .11); }
.crm-activity-note .crm-activity-marker { color: var(--crm-teal); background: rgba(var(--crm-teal-rgb), .1); }
.crm-activity-card.is-overdue .crm-activity-marker { color: var(--bs-danger); background: var(--bs-danger-bg-subtle); }
.crm-activity-card.is-completed { opacity: .72; }
.crm-activity-content { flex: 1 1 auto; min-width: 0; }
.crm-activity-details { padding: .65rem .75rem; border-radius: .65rem; background: var(--bs-light); font-size: .88rem; }
.crm-activity-audit { color: var(--bs-secondary-color); font-size: .72rem; }
.crm-activity-card .crm-activity-action-info { border: 1px solid var(--app-badge-info-text); color: var(--app-badge-info-text); background: var(--app-badge-info-bg); }
.crm-activity-card .crm-activity-action-danger { border: 1px solid var(--app-badge-danger-text); color: var(--app-badge-danger-text); background: var(--app-badge-danger-bg); }
.crm-activity-card .crm-activity-action-success { border: 1px solid var(--app-badge-success-text); color: var(--app-badge-success-text); background: var(--app-badge-success-bg); }
.crm-activity-card [class*="crm-activity-action-"]:hover { filter: brightness(.96); }
#activity-pagination .pagination, #customer-pagination .pagination { justify-content: center; flex-wrap: wrap; }
.crm-customer-card { border-color: rgba(var(--crm-blue-rgb), .14) !important; background: radial-gradient(circle at 95% 0%, rgba(var(--crm-blue-rgb), .1), transparent 28%), #fff; }
.crm-customer-contact { display: grid; gap: .35rem; color: var(--bs-secondary-color); font-size: .84rem; }
.crm-customer-contact span { display: flex; align-items: center; gap: .45rem; min-width: 0; overflow-wrap: anywhere; }
.crm-customer-contact i { color: var(--crm-blue); }
.crm-customer-metric { height: 100%; padding: .7rem; border-radius: .75rem; color: var(--crm-violet); background: rgba(var(--crm-violet-rgb), .08); }
.crm-customer-metric.is-value { color: var(--crm-teal); background: rgba(var(--crm-teal-rgb), .08); }
.crm-customer-metric small, .crm-customer-metric strong { display: block; }
.crm-customer-metric small { color: var(--bs-secondary-color); }
.crm-customer-profile { display: grid; grid-template-columns: minmax(6rem, auto) 1fr; gap: .65rem 1rem; }
.crm-customer-profile dt { color: var(--bs-secondary-color); font-size: .8rem; font-weight: 500; }
.crm-customer-profile dd { margin: 0; font-weight: 600; }
.crm-360-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: .75rem 0; border-bottom: 1px solid var(--bs-border-color-translucent); color: inherit; text-decoration: none; }
.crm-360-row:last-child { border-bottom: 0; }
a.crm-360-row:hover { color: var(--module-accent); }
.crm-quick-activity-card { border-color: rgba(var(--crm-violet-rgb), .18) !important; background: radial-gradient(circle at 95% 0%, rgba(var(--crm-violet-rgb), .1), transparent 25%), #fff; }
.crm-quick-log { min-height: 40px; border: 1px solid transparent; }
.crm-quick-log.is-missed { color: var(--app-badge-danger-text); background: var(--app-badge-danger-bg); }
.crm-quick-log.is-callback { color: var(--app-badge-warning-text); background: var(--app-badge-warning-bg); }
.crm-quick-log.is-sent { color: var(--app-badge-info-text); background: var(--app-badge-info-bg); }
.crm-quick-log.is-contacted { color: var(--app-badge-success-text); background: var(--app-badge-success-bg); }
.crm-quick-log.is-selected { border-color: currentColor; box-shadow: 0 0 0 .15rem rgba(var(--crm-blue-rgb), .12); }
@media (max-width: 575.98px) { .crm-quick-log { min-height: 44px; } }
.crm-contact-form { background: rgba(var(--crm-blue-rgb), .04); }
.crm-contact-card { padding: 1rem; border: 1px solid rgba(var(--crm-blue-rgb), .14); border-radius: .85rem; background: radial-gradient(circle at 95% 0%, rgba(var(--crm-blue-rgb), .08), transparent 30%), #fff; }
.crm-financial-card { background: radial-gradient(circle at 92% 0%, rgba(var(--crm-teal-rgb), .11), transparent 28%), radial-gradient(circle at 8% 100%, rgba(var(--crm-blue-rgb), .07), transparent 30%), #fff; }
.crm-financial-metric { height: 100%; padding: .85rem; border-radius: .8rem; background: var(--bs-light); }
.crm-financial-metric small, .crm-financial-metric strong { display: block; }
.crm-financial-metric small { color: var(--bs-secondary-color); font-size: .76rem; }
.crm-financial-metric strong { margin-top: .2rem; font-size: 1.15rem; overflow-wrap: anywhere; }
.crm-financial-metric.is-sales { color: var(--crm-blue); background: rgba(var(--crm-blue-rgb), .08); }
.crm-financial-metric.is-return { color: var(--crm-violet); background: rgba(var(--crm-violet-rgb), .08); }
.crm-financial-metric.is-outstanding { color: var(--bs-danger); background: var(--bs-danger-bg-subtle); }
.crm-financial-metric.is-credit { color: var(--crm-teal); background: rgba(var(--crm-teal-rgb), .09); }
.crm-credit-progress { width: 100%; height: .5rem; border: 0; border-radius: 999px; accent-color: var(--crm-teal); }
.crm-credit-progress::-webkit-progress-bar { border-radius: 999px; background: var(--bs-light); }
.crm-credit-progress::-webkit-progress-value { border-radius: 999px; background: linear-gradient(90deg,var(--crm-teal),var(--crm-amber)); }
.crm-credit-progress::-moz-progress-bar { border-radius: 999px; background: var(--crm-teal); }
#timeline-pagination .pagination { justify-content: center; flex-wrap: wrap; }
.crm-timeline-event { position: relative; display: flex; gap: .85rem; padding: .9rem 0; border-bottom: 1px solid var(--bs-border-color-translucent); }
.crm-timeline-event:last-child { border-bottom: 0; }
.crm-timeline-icon { display: grid; place-items: center; flex: 0 0 2.2rem; width: 2.2rem; height: 2.2rem; border-radius: 50%; color: var(--crm-blue); background: rgba(var(--crm-blue-rgb), .1); }
.crm-timeline-event.is-stage .crm-timeline-icon { color: var(--crm-violet); background: rgba(var(--crm-violet-rgb), .1); }
.crm-timeline-event.is-document .crm-timeline-icon { color: var(--crm-teal); background: rgba(var(--crm-teal-rgb), .1); }
.crm-document-flow { display: grid; gap: .65rem; padding: .85rem 0; border-bottom: 1px solid var(--bs-border-color-translucent); }
.crm-document-flow:last-child { border-bottom: 0; }
.crm-document-step { position: relative; min-width: 0; padding: .75rem .85rem .75rem 2.35rem; border: 1px solid var(--bs-border-color-translucent); border-radius: .8rem; background: #fff; }
.crm-document-step::before { content: ''; position: absolute; left: .9rem; top: 1rem; width: .7rem; height: .7rem; border: 2px solid var(--crm-blue); border-radius: 50%; background: #fff; }
.crm-document-step.is-complete::before { background: var(--crm-blue); }
.crm-document-step.is-pending { color: var(--bs-secondary-color); background: var(--bs-light); }
.crm-document-step-label { color: var(--bs-secondary-color); font-size: .72rem; font-weight: 600; text-transform: uppercase; }
.crm-document-step strong, .crm-document-step small { display: block; }
.crm-document-step .badge { display: inline-block; margin-top: .4rem; }
@media (min-width: 992px) {
    .crm-document-flow { grid-template-columns: repeat(6, minmax(0, 1fr)); }
    .crm-document-step { padding: .8rem; }
    .crm-document-step::before { left: auto; right: -.47rem; top: 50%; z-index: 1; transform: translateY(-50%); }
    .crm-document-step:last-child::before { display: none; }
}
</style>
@endpush

@push('scripts')
<script>
$(function(){
    const buttons=$('[data-crm-install]');let installPrompt=null;
    const standalone=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;
    const isiOS=/iphone|ipad|ipod/i.test(navigator.userAgent);
    const show=()=>{if(!standalone)buttons.removeClass('d-none');};
    if('serviceWorker'in navigator)navigator.serviceWorker.register('/crm-push-sw.js').catch(()=>{});
    window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();installPrompt=event;show();});
    window.addEventListener('appinstalled',()=>{installPrompt=null;buttons.addClass('d-none');});
    if(isiOS||window.matchMedia('(max-width: 767.98px)').matches)show();
    buttons.on('click',async function(){
        if(installPrompt){installPrompt.prompt();await installPrompt.userChoice;installPrompt=null;return;}
        const instruction=isiOS?'แตะปุ่ม Share <i class="bx bx-share"></i> แล้วเลือก <strong>Add to Home Screen</strong>':'เปิดเมนู Browser แล้วเลือก <strong>ติดตั้งแอป</strong> หรือ <strong>เพิ่มลงในหน้าจอหลัก</strong>';
        Swal.fire({icon:'info',title:'ติดตั้ง MintERP CRM',html:instruction,confirmButtonText:'เข้าใจแล้ว'});
    });
});
</script>
@endpush
