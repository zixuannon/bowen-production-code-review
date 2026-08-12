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

## P3 — Transfer Handover + Receiver Confirmation

 - [x] Extend internal transfers into custody-aware handover

- [x] Sender/receiver, source/destination, pending/confirmed/rejected/cancelled state, and audit trail
- [x] Pending records have no balance or ledger impact
- [x] Receiver confirmation atomically creates one canonical completed `BankTransfer`
- [x] Head Finance ↔ Cashier authorization, tenant/account scope, insufficient-balance recheck, direct-request protection, and immutable confirmation
- [x] Deterministic BOWEN_QA PHPUnit and local Playwright acceptance

Balances move only on designated receiver confirmation.

## P2

- Daily Cash Closing
- Bank Reconciliation
- Refund / Void / Reversal
- Reporting / Audit workbench
