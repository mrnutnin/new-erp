# Feature Posting Configuration — Phase 5 QA

## Capitalization and Addition — 2 September 2026

Environment: local MySQL, branch `HQ`, actor `System Administrator`.

| Scenario | Evidence | Result |
| --- | --- | --- |
| Asset addition — category/document accounts | `AAHQ2609000001` → `GJ-202609-000004`: Dr 15100 / Cr 51000 12,900.00; metadata records `ASSET_CATEGORY` and `SOURCE_DOCUMENT` provenance. | Pass |
| Addition depreciation policy | Policy `#4` generated as draft and approved; effective 2026-10-01. | Pass |
| Capitalization — category/document accounts | `ACHQ2609000001` → `GJ-202609-000005`: Dr 15100 / Cr 51000 6,000.00; Asset subledger `FA-MOCK-HQ-002`. | Pass |
| Capitalization reversal | `ACHQ2609000001` → reversal `GJ-202609-000006`; amounts reverse the original journal and the test asset returns to Draft/book value 0.00. | Pass |
| Capitalization mapping fallback | Category `QA-CAP-FALLBACK` has no asset account and the line has no clearing account. `ACHQ2609000002` → `GJ-202609-000007`: Dr 15100 / Cr 51000 6,000.00. Metadata snapshots mapping IDs 42/43, version 1, source `MAPPING`. | Pass |

Automated evidence:

```text
php vendor/bin/phpunit tests/Unit/AccountMappingServiceTest.php tests/Unit/PostingConfigurationFoundationTest.php tests/Unit/AssetCapitalizationServiceTest.php tests/Unit/AssetAdditionUiContractTest.php --testdox
33 tests, 129 assertions — PASS
```

## Depreciation and Impairment — 2 September 2026

Environment: local MySQL, branch `TICR69 · คุณกฤษญา`, actor `System Administrator`.

| Scenario | Evidence | Result |
| --- | --- | --- |
| Book depreciation — category accounts | `DPTICR692610000001` → Posted: Dr 54000 / Cr 15110 1,019.17. Journal metadata records `DEPRECIATION_EXPENSE` and `ACCUMULATED_DEPRECIATION` from `ASSET_CATEGORY`. | Pass |
| Impairment — category accounts | `IMTICR692609000001` → `GJ-202609-000008`: Dr 54100 / Cr 15120 980.83, with Asset subledger on the accumulated-impairment line. Asset book value became 10,000.00. | Pass |
| Impairment reversal | `IMTICR692609000002` → `GJ-202609-000009`: original accounts reverse direction; Asset book value returns to 10,980.83 and accumulated impairment returns to 0.00. The reversal retains the original posting metadata. | Pass |

Automated evidence:

```text
php vendor/bin/phpunit tests/Unit/AssetImpairmentContractTest.php tests/Unit/PostingConfigurationFoundationTest.php --testdox
10 tests, 34 assertions — PASS

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=new_erp ERP_RUN_MYSQL_INTEGRATION=1 php -d memory_limit=512M vendor/bin/phpunit tests/Feature/AssetImpairmentPostingMySqlIntegrationTest.php --testdox
1 test, 13 assertions — PASS (rollback-only fixture; mapping fallback, retry, reversal)
```

The following section completes QA for `asset.disposal` and `asset.write_off`.

## Disposal and Write-off — 2 September 2026

Environment: local MySQL, branch `TICR69 · คุณกฤษญา`, actor `System Administrator`.

| Scenario | Evidence | Result |
| --- | --- | --- |
| Sale disposal — category accounts | `ADTICR692609000001` → `GJ-202609-000010`: Dr 15110 1,019.17; Dr 15130 12,000.00; Cr 43000 1,019.17; Cr 15100 12,000.00. Metadata has only accounts present in Journal lines. | Pass |
| Sale disposal reversal | `ADTICR692609000001-R` → `GJ-202609-000011`: reverses the original lines; asset returns to Active, book cost 12,000.00 and book value 10,980.83. | Pass |
| Write-off — category accounts | `ADTICR692609000002` → `GJ-202609-000012`: Dr 15110 1,019.17; Dr 54200 10,980.83; Cr 15100 12,000.00. Metadata contains `ASSET_COST`, `ACCUMULATED_DEPRECIATION`, `DISPOSAL_LOSS`, all present in Journal lines. | Pass |
| Write-off reversal | `ADTICR692609000002-R` → `GJ-202609-000013`: reverses original lines and returns the asset to Active with its original book values. | Pass |
| Re-dispose after reversal | A new write-off draft could be created after the sale reversal. The guard excludes original/reversal documents that are already reversed, but continues blocking truly pending documents. | Pass |

Automated evidence:

```text
php vendor/bin/phpunit tests/Unit/AssetDisposalContractTest.php --testdox
4 tests, 54 assertions — PASS

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=new_erp ERP_RUN_MYSQL_INTEGRATION=1 php -d memory_limit=512M vendor/bin/phpunit tests/Feature/AssetDisposalPostingMySqlIntegrationTest.php --testdox
1 test, 18 assertions — PASS (rollback-only fixture; mapping fallback, sale, write-off, retry, reversal)
```

## Transfer and Reconciliation — 2 September 2026

Environment: local MySQL, branch `HQ`, actor `System Administrator`.

| Scenario | Evidence | Result |
| --- | --- | --- |
| Branch transfer remains NO_GL | `AssetTransferNoGlMySqlIntegrationTest` creates, submits, approves, and posts a cross-branch transfer inside a rollback-only transaction. The register branch changes and history gets `TRANSFER_POSTED`; Journal and Asset value-event counts do not change. | Pass |
| Asset subledger vs GL reconciliation | HQ, September 2026: 15100 cost 31,800.00 = GL 31,800.00; 15110 accumulated depreciation 289.56 = GL 289.56; 15120 accumulated impairment 0.00 = GL 0.00; total variance 0.00. | Pass |
| Mapping fallback reconciliation | `ACHQ2609000002` has no line-level asset account and posts through Account Mapping. The report now uses the immutable `posting_metadata.accounts` snapshot before the legacy category fallback, preventing a false 6,000.00 difference. | Pass |

Automated evidence:

```text
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=new_erp ERP_RUN_MYSQL_INTEGRATION=1 php -d memory_limit=512M vendor/bin/phpunit tests/Feature/AssetTransferNoGlMySqlIntegrationTest.php tests/Unit/AssetReconciliationContractTest.php --testdox
2 tests, 12 assertions — PASS
```

Phase 5 QA is complete.
