# eSchool Finance V2 Roadmap

## Finance Layer 4 — Date Contract + Official Go-Live Foundation — local candidate

- [x] One strict Yangon `YYYY-MM-DD` non-future Transaction Date contract for
  Group Import V3, manual Income/Expense, opening adjustments, Refund and
  Reversal; legacy V3 `Date` header is accepted only for compatibility.
- [x] Front Desk collection date remains the Payment and Ledger business date;
  Head Finance confirmation and receipt issuance remain separate audit times.
- [x] Ledger/statement period ordering and display use canonical `entry_date`;
  current physical account balance is distinct from filtered period movement.
- [x] Official Fund Account onboarding/checklist and QA/Test readiness
  exclusion; no fake account, balance, allocation, or Finance transaction.
- [x] Local targeted and complete regression, plus Desktop/390px browser
  verification of the date controls, V3 preview, receipt, statement, and
  overview.
- [ ] Production deployment remains a separate Human Gate.

## Central Chart of Accounts + Group Finance Import V3 — local candidate

- [x] Five-type Group CoA, manual text code, explicit multi-School allocation
- [x] Fund holder metadata; unchanged physical-balance/access-only allocation rules
- [x] Bilingual V3 workbook, dependent dropdowns and currency-separated summaries
- [x] Server validation, zero-write preview and exactly-once confirm regression
- [x] Full regression, fresh disposable MySQL and authenticated Desktop/390 px QA
- [ ] Approved legacy mapping manifest and Production preflight/migration/deployment


## Bahan + Timecity Centralization setup — no cutover

- [x] Production read-only tenant, identity, Finance, Student, and migration inventory
- [x] Fresh verified backup and disposable Bahan/Timecity restore rehearsal
- [x] Exact audited `MMBOWEN02` / `MMBOWEN03` mapping migration and fail-closed runner
- [x] Canonical active-tenant registries, Gate A scope, Group Import V2.2, and regression coverage
- [x] Disposable fresh MySQL mapping rehearsal
- [ ] Production deployment and exact mapping migration require separate Human Gates
- [ ] Formal School staff identities, Fund Account/allocation, opening-balance audit, and receivable cutoff
- [ ] Centralization rehearsal and cutover remain stopped

## Operational UX & Identity — local candidate

- [x] Group Finance Import Template V2.2 validations and clean lookup data
- [x] Canonical School Code redesign with Zixuan `MMBOWEN01` and locked sequence
- [x] Runtime identity accepts only current, uppercase-normalized canonical codes; legacy codes are audit-only
- [x] Tenant-bound 60-minute password reset and separate 24-hour staff invitation lifecycle
- [x] Exact-path additive migration runner, focused tests, fresh MySQL, browser smoke, and full regression
- [ ] Production migration/deployment requires a separate Human Gate

## P0 — Money Integrity

- [x] Income must link to Fund Account
- [x] Expense must link to Fund Account
- [x] Cross-school Fund Account rejection
- [x] Inactive/deleted Fund Account rejection
- [x] Production verification

## P1 — Financial Audit Safety

- [x] Expense hard delete → SoftDelete + delete audit
- [x] Expense edit history
- [x] Fee payment delete history + reason
- [x] Historical reference number reservation
- [x] Opening-balance adjustment history
- [x] Local automated tests
- [x] Production migration — 8/8 tenants, targeted six-file cutover
- [x] Production deployment — backup `/root/backups/finance_p1_20260810_163626`
- [x] Production non-mutating browser smoke checks
- [~] Production verification — deployed/partially verified; remaining destructive-path acceptance moves to local synthetic browser QA

### Local acceptance before further Finance implementation

- Dedicated local/test tenant and synthetic finance data only.
- Local browser QA may create/edit/delete synthetic payments, expenses, opening balances, and transfers.
- Prove compulsory/optional payment deletion: reason required, SoftDelete/audit, FeesPaid/outstanding/Fund Account balance, and reserved reference number.
- Prove opening-balance adjustment: reason required, old/new audit values, actor, and resulting balance.

### Archived staging work — paused / not part of active pipeline

- [x] Same-host isolated staging, runtime, database-isolation, and synthetic-data design approved
- [x] FINANCE_QA-only guard, seed/reset command, deployment/runtime scripts, runbook, and Playwright scenario scaffold prepared locally
- [x] Same-host read-only preflight — no existing staging root/database/vhost/TLS; DNS absent
- [x] DNS: `staging.school.mmbowen.com` → `43.160.241.126`
- [x] Provision same-host staging root/database credentials/vhost/TLS and FINANCE_QA synthetic baseline
- [ ] No staging browser acceptance is planned in Pipeline V2. Preserve the environment and recorded changes for a separately authorized cleanup/reuse decision.

### Pipeline V2 local QA preparation

- [x] Local-first development/testing policy established
- [x] Local Playwright configuration and guarded `npm run qa:local` entry point prepared
- [x] BOWEN_QA deterministic local tenant, login fixture, representative configuration, and guarded reset/seed command prepared
- [x] Finance P1 — LOCAL ACCEPTANCE VERIFIED: deterministic BOWEN_QA Playwright payment-delete and opening-balance scenarios, tenant DB/ledger/balance/reference assertions, and targeted Finance regression passed. No production deployment occurred.

## P2-A — Roles + Fund Account Ownership Foundation

- [x] Reuse Spatie roles for Head Finance and Cashier
- [x] Tenant-local `bank_account_user` many-to-many ownership migration
- [x] Centralized current-school Fund Account scope and direct-account authorization service
- [x] School Admin and Head Finance all-current-school access
- [x] Head Finance assignment-management capability
- [x] Cashier explicit-assignment-only capability and opening-balance restriction
- [x] Deterministic BOWEN_QA Head Finance/Cashier users, three P2 accounts, and A/B assignments
- [x] Targeted PHPUnit and local Playwright authorization verification

## P2-B / P2-C — Apply Fund Account Scope to Finance Workflows

- [x] Fee payment Fund Account choices and server-side write-path validation
- [x] Excel paid-fee import preview/confirm account authorization, including recheck after preview
- [x] Expense Fund Account choices and server-side write-path validation
- [x] Bank Transfer visibility, dual-account cancellation guard, and direct-request authorization
- [x] Fund Account list/detail/ledger and Bank Account report filtering/direct-request authorization
- [x] General finance-report account scoping; school-wide outstanding hidden from account-scoped Cashiers
- [x] BOWEN_QA fixture, targeted PHPUnit, malicious/direct-request coverage, authenticated local Playwright, and broader finance regression

Do not start Branch Finance, transfer handover, daily closing, reconciliation, refund/void/reversal, or a production release as part of P2-B.

## P2-D — Finance Staff Management

- [x] School Admin role lifecycle for Head Finance and Cashier
- [x] Head Finance active current-school Cashier account assignment only
- [x] Cashier assignment removal on role removal; server-side forged/cross-school/inactive rejection
- [x] BOWEN_QA PHPUnit and authenticated local browser acceptance

## Student & Finance V2 Phase 3 — Roles + Permissions

- [x] Keep tenant School roles separate from Central Finance principals and scopes
- [x] Explicit Principal read-only and School Accountant operating Central grants
- [x] Server-side tenant role validation through trusted Staff UUID identity mapping
- [x] Scope revocation immediately blocks Central workspace access while preserving School role/history
- [x] Multi-role and School Admin / Super Admin boundary characterization
- [x] Central Finance, Student Import, authorization, localization, and scope regression

## P3 — Transfer Handover + Receiver Confirmation

 - [x] Extend internal transfers into custody-aware handover

- [x] Sender/receiver, source/destination, pending/confirmed/rejected/cancelled state, and audit trail
- [x] Pending records have no balance or ledger impact
- [x] Receiver confirmation atomically creates one canonical completed `BankTransfer`
- [x] Head Finance ↔ Cashier authorization, tenant/account scope, insufficient-balance recheck, direct-request protection, and immutable confirmation
- [x] School Admin current-school register/audit oversight without participant or action authority
- [x] Fund Handover menu and route access use dedicated School Admin / Head Finance / Cashier roles, not generic Expense permissions
- [x] Deterministic BOWEN_QA PHPUnit and local Playwright acceptance

Balances move only on designated receiver confirmation.

## P3.1 — Permission, Money-In, and Expense Import Hardening

- [x] Finance permission migration uses role defaults plus named functional permissions; legacy compatibility is limited to semantically identical paid-fee/expense permissions.
- [x] Cashier account scope, tenant isolation, forged-account rejection, custody rules, and School Admin handover read-only oversight remain server-side invariants.
- [x] Compulsory/optional payments and paid-fee import validate both payment method and authorized active Fund Account before any financial write.
- [x] Paid-fee template keeps its business-facing headers and excludes database identifiers.
- [x] Expense Excel import supports template, upload, validation, preview, confirm, and batch audit; preview has no `Expense` writes and confirmation is whole-batch atomic create-only.
- [x] Expense import protects against duplicate files, duplicate rows/references, repeated confirmation, cross-school or unauthorized accounts, and overwriting existing expenses.
- [x] Final authenticated BOWEN_QA Playwright acceptance passed: permission grant/revoke, Cashier scope, Fund Handover create/confirm/reject/cancel application-modal behavior with no native dialogs, paid-fee protection, and Expense Import preview/confirm/duplicate/invalid/unauthorized paths.
- [x] Browser acceptance exposed and corrected the missing audited Expense delete-button helper; broader P1–P3.2 finance regression passes (143 tests / 516 assertions).

## P3.2 — Unified Finance Transactions

- [x] Finance → Transactions provides a unified query/register over existing source records, not a duplicate transaction or ledger source of truth.
- [x] Sources: compulsory payment, optional payment, non-fee `OtherIncome`, Expense, and completed BankTransfer. Pending handovers are not money movement; confirmed handovers appear only through their canonical BankTransfer.
- [x] Register filters date, transaction type, Fund Account, reference, and keyword. All source queries apply current-school and accessible-Fund-Account scope; forged account filters are rejected.
- [x] Receive Money is a tenant-local, non-fee `OtherIncome` source. It requires a valid payment method and active authorized Fund Account, preserves reference reservation (including soft-deleted history), and is transactional.
- [x] Account balances, account ledger, account report, Finance Dashboard, and Finance Report include Other Income. Internal BankTransfers remain excluded from operating income/expense summaries.
- [x] Transaction page links to existing Expense and Expense Excel Import workflows rather than duplicating their writes/audit rules.
- [x] Final authenticated BOWEN_QA Playwright acceptance passed 17/17 guarded scenarios: role-scoped register access/filtering, forged account rejection, Receive Money exactly once through `OtherIncome`, account balance/ledger/register/report integration, pending/confirmed handover representation, and reuse of the Expense/Import workflows.
- [x] Final broader P1–P3.2 Finance regression passes (143 tests / 516 assertions).
- [x] Finance UAT P0 local hardening: all-account Transactions render a completed internal transfer once as neutral `Internal Transfer`; account-filtered views remain directional. Direct BankTransfers now require active, non-deleted, authorized distinct accounts and use exception-safe transactions. Focused coverage verifies handover canonicality, no pending movement, cancellation balance reversal, and no income/expense side rows. Broader local Finance regression: 143 tests / 546 assertions. Guarded BOWEN_QA browser rerun remains pending local MariaDB credentials only.
- [x] Guarded release runner prepared locally for only `expense_import_batches` and `other_incomes`; Phase 9 confirms both schemas are absent on all current production tenants. Legacy fee-import divergence is explicitly excluded.

## P2

## Central Finance Fresh Start — cutover safety foundation

- [x] Per-School `legacy` / `ready` / `central` state, stored centrally in an
  additive reversible schema.
- [x] Server-enforced Central-write and tenant-legacy-write gates with no raw
  database selection and no automatic Fund Account/opening-balance creation.
- [x] Zixuan/Timecity independent-state, rollback-before-first-transaction,
  route/service guard, and Central workspace read-only characterization.
- [x] Local configuration UI/services for explicit Central School scope,
  Head-Finance-only audited Fund Account opening balance, and a fail-closed
  `ready → central` gate.
- [x] Fresh Start Receivable cutoff: each School must record an explicit,
  approved effective datetime before readiness. Only Fee Assignments created
  on/after that boundary sync to Central; pre-cutoff history is excluded from
  reconciliation unless a future approved carry-forward process selects it.
- [x] Feature Gap P0 Central read/configuration workbench: student ledger,
  payment/receipt history, Fund Account detail report, Standard Ledger filters
  and pagination, Finance Staff scope visibility, and Central category UI.
- [ ] Approved real Zixuan role/scope, Fund Account, signed opening-balance
  configuration, and pilot deployment.

- Daily Cash Closing
- Bank Reconciliation
- Refund / Void / Reversal
- Reporting / Audit workbench

## Zixuan QA Run system — local regression recovered

- [x] Reconciled the completed implementation onto exact current Production
  SHA `7d6e73c12f6de23c24e5dd62312df53fcef8d497`; Staff Invitation, canonical
  identity, actor audit, QA Staff inheritance, and User #77 recovery are kept.
  `UserService` and `StaffInvitationService` have no QA Run changes.
- [x] Additive Central Run/membership schema, lifecycle, immutable membership,
  mixed-run guards, permanent QA classification, official-total exclusion,
  and Head Finance/Super Admin UI entry.
- [x] Targeted reconciliation suite (130 tests / 842 assertions), full PHPUnit
  regression (1,106 tests / 8,683 assertions / 34 existing skips), and
  disposable-clone Central migration apply/rollback/reapply rehearsal.
- [x] Disposable Run #1 browser workflow reached Payment, Receipt, Ledger,
  Complete, and Archive; archived history remains visible.
- [x] Run #2 recovered a partial Student Import V2 provision, created a fresh
  Student Fee Assignment and Receivables, submitted and confirmed a Pending
  Collection, and reached Payment, Receipt, and Ledger. Complete and Archive
  preserved history; Run #1 and Run #2 memberships do not overlap.
- [x] Late profile synchronization now adopts only existing Receivables for a
  Run-reserved Student; a feature test covers the recovery path and the local
  Run #2 fixture was reconciled without moving any record from another Run.
- [x] Official-filtered counts and money totals are unchanged across Run #2
  archive. New collection writes receive 403 after Complete and after Archive.
- [x] Reconciled-tree browser verification passed at 1440 × 900 and 390 × 844
  with no 403/404/500 responses, console errors, or mobile overflow. Temporary
  local registry and image fixtures were removed before candidate freeze.
  Desktop/mobile Run detail passed; Front Desk lifecycle access receives 403.
- [ ] Production release gate remains separate. No Production change, migration,
  financial write, or deployment was performed.
- [x] Guarded exact-path Production migration capability was added under the
  separate security-boundary approval. The runner pins the Central migration
  SHA-256, exact Production baseline, target database, and active release
  manifest. It uses Laravel's normal confirmation and does not pass `--force`.
- [x] Paired exact-Production-baseline and candidate full regression after
  fail-closed disposable fixture recovery: baseline 1,098 tests / 8,648
  assertions; candidate 1,109 tests / 8,702 assertions; zero failures/errors,
  34 existing skips, and no new skips. No baseline-only or candidate-only test
  failures.
- [x] Targeted QA Run/migration/ownership checks (27 tests / 105 assertions),
  guarded migration path rehearsal, and archived Run history desktop/mobile
  browser smoke at 390 × 844 with no horizontal overflow.
- [ ] Production release remains incomplete: approved SHA
  `b87bac3a2bc6eaf32cada9cdbfa19c565d5e61b2` is the active release symlink,
  but the guarded Central migration remains `eligible`. The pushed successor
  `74d7a3608e23ceebe4a624562a55a562e7d53ff4` is not deployed because its
  confirmation/check order did not match the approved sequence. A further
  successor is being prepared; it needs separate exact-SHA approval before
  push, deployment, or migration. No Finance history was rewritten and no QA
  Run was created.
