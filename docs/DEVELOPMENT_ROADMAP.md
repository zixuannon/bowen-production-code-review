# eSchool Finance V2 Roadmap

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
- [ ] Approved real Zixuan role/scope, Fund Account, signed opening-balance
  configuration, and pilot deployment.

- Daily Cash Closing
- Bank Reconciliation
- Refund / Void / Reversal
- Reporting / Audit workbench
