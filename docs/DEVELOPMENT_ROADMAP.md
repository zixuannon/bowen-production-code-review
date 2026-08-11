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

## P1 — Finance Roles

Pending business design:

- Head Finance
- Cashier
- Branch Finance
- Auditor

Do not implement until role visibility/action rules are approved.

## P1 — Fund Account Ownership

Preferred direction to validate later:

- user ↔ Fund Account many-to-many assignment
- account-level visibility/actions
- role + account ownership compose together

## P1 — Transfer Handover

Extend internal transfers into custody-aware handover:

- sender
- receiver
- source/destination account
- pending/accepted/rejected state
- receiver confirmation
- audit trail

Business decision required for when balances legally/operationally move.

## P2

- Daily Cash Closing
- Bank Reconciliation
- Refund / Void / Reversal
- Reporting / Audit workbench
