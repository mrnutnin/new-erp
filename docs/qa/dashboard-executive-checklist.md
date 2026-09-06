# Executive Dashboard QA Checklist

## Implementation status

- [x] Dashboard module/provider registered
- [x] `GET /dashboard` route created
- [x] AJAX data endpoint created
- [x] Executive Dashboard permission added to RBAC seed
- [x] Branch scope enforced from user permissions
- [x] Global filter shell: Date Range, Company, Branch, Business Unit
- [x] Current-month default filter
- [x] KPI strip visible before the main charts
- [x] Business trend chart and branch performance chart
- [x] Dashboard charts migrated to ApexCharts with graceful CDN failure state
- [x] Attention Center and Decision Center
- [x] Loading, error and empty states in UI
- [x] Short-lived server-side cache
- [x] Executive Dashboard link added to module sidebars
- [x] Dashboard added to Program Selector and attached to existing users by migration
- [x] Logistics hidden from Program Selector for the MVP scope
- [x] Executive Dashboard navigation centralized; hidden from module sidebars
- [x] Gross Profit KPI connected to posted POS sales and final COGS allocations
- [x] AR/AP KPI connected to Finance open-item remaining balances
- [x] Period comparison values and percentage change shown for period-based KPIs
- [x] Dashboard filters persist in the URL without a full page reload
- [x] MySQL integration tests added for response contract and branch scope enforcement
- [x] Dashboard UI contract test added

## Pending QA / next phase

- [x] Run RBAC seeder and verify admin visibility in local target environment
- [x] UAT with multiple users and branch scopes
- [x] UAT Date Range, Company, Branch and Business Unit filtering
- [x] Verify all KPI values against source reports
- [ ] Add Business Unit master and real filtering when the organization contract is available
- [x] Attention/Decision drill-down links point to valid source routes and preserve selected branch
- [x] Chart fallback implemented when ApexCharts CDN is unavailable
- [ ] Test desktop/tablet/mobile layouts
- [ ] Measure uncached and cached response time with production-like data
- [x] Execute MySQL integration tests in local target database: 2 tests / 26 assertions
- [x] Local performance baseline: uncached ~45 ms / 25 queries; cached ~3 ms / 6 queries
- [x] Record business UAT evidence with multiple users and branch scopes
