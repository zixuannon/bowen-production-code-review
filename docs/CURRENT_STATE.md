# eSchool Current State

Last updated: 2026-08-11

## Active production target

- Server: `43.160.241.126`
- Project: `/www/wwwroot/43.160.241.126`
- Canonical SSH alias: `eschool-prod`

`183.240.79.48` is a legacy/rollback environment only. It must not be selected, connected to, deployed to, or migrated for Finance P1.

## Current area

Finance V2

## Active development pipeline — V2

`LOCAL → TARGETED TEST → LOCAL PLAYWRIGHT → REVIEW → PRODUCTION GATE`

Local development is the only active implementation/test environment. Use local/test databases and deterministic synthetic data for migrations, PHPUnit, Playwright, debugging, and finance write-path acceptance. Production is read-only until an explicit production gate is approved.

`staging.school.mmbowen.com` is **PAUSED / NOT PART OF ACTIVE PIPELINE**. Do not authenticate to, test, debug, deploy to, delete, or otherwise modify staging without separate authorization.

## Completed and production-verified

- P0: Income and Expense require a valid Fund Account.
- P0: Cross-school, inactive, and deleted Fund Account protection.
- Existing Excel paid-fee import production regression passed after P0.

## Current phase

Finance P3.1 Permission, Money-In, and Expense Import hardening — **LOCAL CODE / PHPUNIT VERIFIED; BROWSER QA PENDING**. The completed role bootstrap remains infrastructure and was not extended. Runtime authorization now uses named finance permissions with strictly equivalent legacy compatibility only; account scope, tenant isolation, custody rules, forged-account rejection, and School Admin's handover read-only boundary remain server-side. Compulsory/optional payment and paid-fee import paths validate the payment method and an authorized active Fund Account before creating financial rows. The new Expense Excel workflow is preview-first (no `Expense` writes), whole-batch atomic at confirmation, and creates new records only; it never accepts an expense ID or upserts an existing expense. It rejects duplicate references/files/rows and repeated confirmation attempts, including references retained by soft-deleted expenses. Focused tests passed (39 tests / 134 assertions); the broader P1/P2/P3/P3.1 finance regression passed (128 tests / 468 assertions). Local browser QA is blocked without bypass: the configured local MySQL user cannot read central `system_settings` or create the guarded BOWEN_QA database, and this worktree has no Playwright dependency installed. Production and staging remain untouched.

Finance P3 Fund Handover + Receiver Confirmation — **LOCAL QA VERIFIED**. Production remains read-only; no production deployment or migration is prepared.

Finance P2-D Finance Staff Management — **LOCAL QA VERIFIED**: School Admin may assign/remove only Finance roles and manages Cashier account assignments; Head Finance may manage active current-school Cashier assignments but cannot change roles; Cashiers are denied staff-management access. Removing Cashier detaches assignments immediately. Targeted authorization/P1-P3 regression (28 tests / 118 assertions), authenticated local Playwright, and two BOWEN_QA reset/verify cycles pass. Production remains untouched.

Finance P3 School Admin Handover Oversight — **LOCAL QA VERIFIED**: School Admin may open the Fund Handover register, inspect all current-school handovers and their recorded confirmation/rejection/cancellation audit detail, but remains a non-participant. Participant-only recipient/account discovery is never evaluated for a School Admin session; forged create/confirm/reject/cancel requests are rejected server-side before any finance write. Head Finance/Cashier P3 flows and Cashier Fund Account isolation remain unchanged. Focused P1/P2/P3/P2-D regression (29 tests / 121 assertions) and authenticated local Playwright pass. No schema, migration, role, account, or production data change is included.

Tenant role-context lifecycle fix — **LOCAL QA VERIFIED**: the web group now establishes the selected tenant database immediately after session startup and clears any already resolved guard user before LanguageManager/WizardSettings can evaluate Spatie roles. This prevents an empty central-connection `roles` relation from surviving into the tenant request. Local characterization reproduces central-role absence followed by tenant School Admin/HR role visibility after context establishment, while confirming the central path remains mysql-only. Finance Staff, School Admin Handover oversight, Head Finance/Cashier handover flow, and Cashier account isolation pass after the lifecycle change. No schema or role-data change is required.

Fund Handover role-gate follow-up — **LOCAL QA VERIFIED**: the menu container and handover routes now use the dedicated `School Admin` / `Head Finance` / `Cashier` custody roles rather than unrelated generic Expense permissions. The Expense Management feature entitlement remains required. School Admin stays register-only and forged actions remain 403; Head Finance/Cashier regain participant access without broadening Cashier Fund Account scope. Targeted P2/P3 regression (22 tests / 83 assertions) and authenticated BOWEN_QA Playwright (P2 3/3, P3 1/1) pass. A runtime-only hotfix is prepared; production is untouched.

P2/P3 release prerequisite is locally verified: `finance:p2-p3-migration-safety`
has the fixed eight-tenant and two-file allowlists, verification-only default,
partial-state refusal, isolated-batch verification, canary selection, and
data-aware rollback refusal. It is not deployed or executed on production.

P3 local evidence:

- Tenant migration: `2026_08_12_000001_create_fund_handovers_table.php` adds tenant-local pending/confirmed/rejected/cancelled handover audit records and their underlying `bank_transfer_id` link.
- A pending handover has no balance or ledger effect. Only the designated receiver can confirm it; confirmation is transactional and creates exactly one normal completed `BankTransfer`, reusing the shared transfer balance calculation.
- Head Finance ↔ Cashier is supported; Cashier ↔ Cashier, cross-school, inactive/unassigned accounts, insufficient source balance, unauthorized confirmation, and repeated confirmation are rejected server-side.
- Confirmed handovers are immutable: their completed transfer cannot be cancelled through the immediate-transfer endpoint. Rejection and cancellation retain actor, timestamp, and mandatory reason without a transfer.
- BOWEN_QA Playwright passed the actual Head Finance request → Cashier confirmation flow, pending/no-ledger state, balance and ledger movement, direct-request rejection, exactly-once confirmation, and immutability.
- Targeted Finance regression: 25 tests / 113 assertions passed; local P2/P3 browser regressions passed. PHP 8.5 vendor deprecation notices remain non-functional.

P2-A local evidence:

- Tenant migration: `2026_08_11_000001_create_bank_account_user_table.php` creates the `bank_account_user` many-to-many pivot with unique pair and cascading tenant-local foreign keys.
- Roles: `Head Finance` and `Cashier` reuse Spatie. Fixture Cashiers receive only `expense-list`; Head Finance receives the minimal current finance permissions. Neither inherits the complete School Admin permission set.
- Centralized `FinanceAccountAccessService`: current-school query scope, direct-account authorization, all-account access for School Admin/Head Finance, and elevated account/assignment/opening-balance capability checks.
- BOWEN_QA: `QA_HEAD_FINANCE`, `QA_CASHIER_A`, `QA_CASHIER_B`; Fund Accounts `QA_P2_CASH_A`, `QA_P2_CASH_B`, `QA_P2_BANK`; A/B are assigned only to their respective cash accounts. Head Finance access is role-based, not pivot-based.
- Targeted PHPUnit: 3 tests / 21 assertions pass (the current PHP runtime reports known vendor deprecation notices only).
- Local Playwright: 4/4 pass — Cashier A isolation, Head Finance all-account visibility, direct unassigned-account rejection, and Cashier edit/opening-balance rejection (HTTP 403).

P2-B/P2-C local evidence:

- The centralized scope now protects compulsory/optional payment selectors and writes, Excel paid-fee preview and confirmation, Expense selectors and writes, Bank Transfers, Fund Account reports, account detail/ledger, and the finance report.
- A Cashier sees and may use only assigned active current-school Fund Accounts. Direct unassigned report and transfer requests are rejected before a financial write; transfer cancellation requires access to both accounts.
- General finance-report totals are account-scoped. The school-wide outstanding figure is deliberately hidden from account-scoped Cashiers rather than shown as a misleading partial total.
- Excel confirmation rechecks uploader account authorization after preview; revoking an assignment invalidates the confirmation and rolls back without fees/payment writes.
- BOWEN_QA local Playwright: 7/7 pass, covering Head Finance all-account access, Cashier A/B inverse isolation, payment/expense/transfer/report selectors, direct unauthorized account/report/transfer requests, and Cashier opening-balance restriction.
- Broader local Finance regression: 103 tests / 354 assertions pass. The PHP 8.5 PDO SSL constant deprecation warnings are pre-existing and non-functional.

Finance P1 Financial Audit Safety remains **LOCAL ACCEPTANCE VERIFIED**. Production remains deployed (with its historical non-mutating browser coverage noted below); all destructive-path acceptance evidence was completed only in deterministic BOWEN_QA local synthetic data.

Implemented locally:

- Expense delete: SoftDelete + deleted_by + delete_reason.
- Expense edit: change history with old/new values, reason, changed_by.
- Fee payment delete: SoftDelete + deleted_by + delete_reason; historical reference_no remains reserved.
- Opening balance edit: adjustment history with old/new balance/date and reason.

Current automated evidence:

- 104 tests
- 344 assertions
- 0 failures

## P1 production deployment evidence

Completed on `eschool-prod`:

- Full checksum-verified tenant backups: `/root/backups/finance_p1_20260810_163626`.
- The six targeted P1 migrations completed on all eight tenants, with schema verification and one P1-only batch per tenant.
- Canary: `eschool_saas_1_demo` (batch 5); remaining batches: Zixuan 8, Bahan 7, Timecitys 7, `20_` 7, `21_` 7, Zixuanyang 7, `32_` 2.
- The final application release contains 15 P1 files: 13 initial files plus `FeesPaidImportService.php` and `FeesPaymentService.php`, which reserve references held by soft-deleted payments.
- PHP syntax and Laravel autoload checks passed, caches were cleared, and production was restored online.
- Browser checks passed without a financial write: public/dashboard access, expense list, expense delete-reason dialog and empty-reason rejection, required expense edit reason, paid-fee list, optional-fee list, Bank Account list/report, and expense report.
- No production financial record was created, edited, deleted, refunded, or transferred for testing.

## Production preflight evidence

Exact tenant databases observed:

- `eschool_saas_1_demo`
- `eschool_saas_15_zixuan`
- `eschool_saas_17_bahan`
- `eschool_saas_19_timecitys`
- `eschool_saas_20_`
- `eschool_saas_21_`
- `eschool_saas_31_zixuanyang`
- `eschool_saas_32_`

All eight showed:

- required base finance tables: 5/5
- P1 schema present before migration: none

Important unrelated pending school migrations exist on several tenants.

Therefore:

- DO NOT use broad `php artisan migrate:school` for the P1 cutover.
- P1 production migration must target only the six P1 migration files.

## Local acceptance requirements

The following are deliberately **not** marked PASS in production and are not production blockers:

1. Compulsory and optional payment delete-reason dialogs, including empty-reason rejection and post-delete accounting/audit verification.
2. Opening-balance reason validation and `BankAccountBalanceAdjustment` audit verification.

They require a dedicated local/test tenant with synthetic data and browser permission to perform controlled finance writes. No further production financial testing is authorized for these paths.

### BOWEN local QA environment — ready

The generic local demo was not representative because the local central database had no installed school, only default SaaS settings, and only one enabled feature. Bowen’s visible structure is driven by a combination of current application code, central branding/subscription features, and tenant-local school settings, roles, permissions, academic-year data, and finance bootstrap.

- Local-only tenant: `BOWEN_QA` in fixed database `eschool_local_bowen_qa`.
- Reset/seed: `php artisan local:bowen-qa reset`. The command has no selectable database/tenant argument and refuses non-local APP_ENV, non-local APP_URL, staging, production, and production-style database names.
- Fixture: synthetic QA admin/teacher/guardian/student, `Bowen QA 2026`, class/section, compulsory and optional fee structures, two QA Fund Accounts, fixed payment `BOWEN_QA_P1_PAYMENT_001`, and fixed expense `BOWEN_QA_P1_EXPENSE_001`.
- Representative configuration: Bowen-style school/system name, non-sensitive public branding images copied with checksum verification, active Bowen-equivalent subscription feature names, and School Admin role/permission bootstrap. No production users, students, financial records, uploads other than the two public branding images, sessions, or credentials were copied.
- Repeatability: two reset/seed cycles produced the same stable fixture fingerprint (`f0309de62232949dff2649309f1233ea67bd3a88a14b36ca59702fc566a17fbe`).
- Local browser target: guarded `http://127.0.0.1:8000`, authenticating only to `BOWEN_QA`; the auth state remains gitignored. Representative browser checks pass for dashboard, Fund Accounts, Expenses, compulsory/optional paid-fee pages, and the Excel paid-fee import UI.

Focused production read-only code parity confirms the Finance P0/P1, paid-fee import, and relevant finance/sidebar views used by local QA match the deployed production files. Two unrelated production-only Xiaobailong AI route/sidebar additions are not present in local; they are recorded for separate source-of-truth reconciliation and are outside Finance P1.

Finance P1 local acceptance completed in BOWEN_QA: the real delete-reason dialog rejects empty input then soft-deletes `BOWEN_QA_P1_PAYMENT_001`; audit actor/reason, normal-versus-history visibility, FeesPaid/outstanding, Fund Account balance, ledger exclusion, and manual/Excel reference reservation all pass. The Bank Account edit form now conditionally renders/submits `adjustment_reason`, preserves controller validation, records exactly one opening-balance adjustment, and does not create one for an unrelated edit. A cascade-risk fix retains zeroed FeesPaid aggregates so a soft-deleted payment cannot be removed by a foreign-key cascade.

## Archived staging provisioning — paused

Historical same-host staging work is retained for future reference only. It is not an active requirement or deployment target under Pipeline V2. Production application/data remain protected and were not changed.

- Same host: `eschool-prod` (`43.160.241.126`); separate staging root `staging.school.mmbowen.com` at `/www/wwwroot/staging.school.mmbowen.com`.
- Isolation contract: APP_ENV/APP_KEY/.env/storage/session/cache/queue are independent; central `eschool_staging`; FINANCE_QA tenant `eschool_staging_finance_qa`; staging-only least-privilege credentials; staging must never point to `sql_43_160_241_126` or `eschool_saas_*`.
- Prepared tooling: `StagingFinanceQaGuard`, `staging:finance-qa` (verify/seed/reset), guarded runtime/deploy scripts, a synthetic FINANCE_QA baseline, a staging runbook, and Playwright P1 acceptance scenarios.
- The reset command requires exact staging environment, URL, central database, school, and tenant database checks, a confirmation flag, and creates a targeted local snapshot before resetting only `QA_P1_%` fixtures.
- Payment-delete and opening-balance browser acceptance remain deliberately unverified until the isolated environment and synthetic credentials exist. No production financial mutation was used for testing.
- Same-host preflight (2026-08-11): `eschool-prod` is `VM-0-4-ubuntu`; production root exists; 77 GB is free. Nginx 1.24, PHP 8.3 FPM (`/tmp/php-cgi-83.sock`) / PHP 8.3 CLI (`/usr/bin/php83`), MariaDB 10.11, and Redis are available. The production CLI default remains PHP 8.1; Node/npm are not installed.
- DNS now resolves `staging.school.mmbowen.com` to the active host. The isolated staging root, independent `.env`/APP_KEY/storage/session/cache, `eschool_staging` central DB, and `eschool_staging_finance_qa` tenant DB are provisioned. The application DB account has privileges limited to those two staging DBs; the temporary MariaDB staging-admin credential file was deleted after application credential verification.
- `FINANCE_QA` is installed through the real per-school migration mechanism. Its synthetic tenant includes QA users, student/class/session, compulsory and optional fees, four test Fund Accounts, a transfer, and an expense. `staging:finance-qa verify` passes.
- `https://staging.school.mmbowen.com` is live with its own Let’s Encrypt certificate. HTTP redirects to HTTPS; the staging Nginx vhost contains no production proxy routes. PHP/FPM writable-path and tenant-local `Teacher`-role bootstrap defects were corrected in the staging-only setup.
- Runtime isolation verified: central DB `eschool_staging`, school DB `eschool_staging_finance_qa`, mail `log`, queue `sync`, cache/session `file`. Production project and production databases were not modified.
- Staging-only visual guard deployed: login and authenticated layouts render `STAGING — FINANCE_QA · TEST DATA ONLY` only when `app()->environment('staging')`. The focused view test confirms it renders for staging and is absent for production; staging login rendering was verified over HTTPS. Staging `APP_NAME` is `STAGING_FINANCE_QA` so the login title cannot be mistaken for production.

## Next task

Finance P4 Daily Cash Closing, if approved. Do not start Bank Reconciliation, refund/void/reversal, or any production work. Any production release requires a fresh, explicit production deployment/migration gate.

## Backlog

- Repair the isolated `TwoStageLeaveServiceIsEnabledTest` bootstrap (`Target class [config] does not exist`) and the generic `ExampleTest` HTTP host fixture (`HTTP_HOST` is absent). These caused 16 unrelated failures in the full local suite and are outside Finance P1 scope.

## Roadmap after P1 production verification

1. Head Finance / Cashier / Branch Finance role design
2. Fund Account user ownership and account-level permissions
3. Transfer → Handover + Receiver confirmation
4. Daily Cash Closing
5. Bank Reconciliation
6. Refund / Void / Reversal
7. Reports and Audit

Do not implement finance roles until P1 is production-verified and business rules are confirmed.
