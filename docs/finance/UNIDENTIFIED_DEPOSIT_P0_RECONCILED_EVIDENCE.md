# Unidentified Deposit P0 — reconciled candidate evidence

Date: 2026-10-07. Exact parent: `135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e`.
Prior accepted implementation: `abcd66e0d262daa5a3fd9b5a1daba537cd1c20d5`.
This report contains aggregate verification, not Production fixture data or secrets.

## Local engineering

- Full regression: 1,232 tests / 9,562 assertions, zero failures/errors.
  Exact 34 existing skips unchanged; zero new skips. Existing PHP 8.5/PHPUnit
  deprecations remain, not Production runtime changes.
- Historical QA + bank identity: 46 tests / 272 assertions, all pass.
- Broader Finance/Pending/QA target: 194 tests / 1,162 assertions, all pass.
- Exact maintenance CLI on fresh disposable MySQL: read-only preflight,
  execute, identical retry, SELECT-only inventory pass. Retry reuses its audit.
- Exact historical migration rehearsal: eight checks pass, including original
  NULL reference, unchanged financial rows, one original money-in, read-only
  identity verification before/after additive schema, used rollback refusal.
- Generic migration rehearsal: 25 checks pass, including pre-DDL ambiguity
  rejection, empty rollback/reapply, unique/FK enforcement and used-data refusal.
- Migration SHA-256:
  `8cb9cc668a4ad17ad4c7543564beba3b21f9d6d6ad583ce4acb3f18e1ea4c432`.
- Independent review found no code blocker to local freeze. Accepted live P0
  paths remain intact, with the newer P1-A optional due-date display preserved.

## Focused browser smoke

The current reconciled worktree served localhost:8095 against the retained
isolated synthetic databases on localhost:3324; no Production session was used.
1440px/390px deposit list, QA visibility, Student Code and GR search, allocation
form/eligibility controls, thermal receipts and Group statement pass. Viewport
width equals document width at both sizes; final console warning/error list empty.
No allocation was submitted during this read/render smoke.

Original accepted full E2E cash invariant remains: 500,000 physical receipt,
400,000 + 100,000 allocations, no extra physical movement, two canonical Payments
and Receipts. A separate synthetic open receivable/deposit enabled form smoke
without altering that completed chain. Fixtures stay local, not Production seeds.
The temporary server/tab is stopped after verification.

The initial local login URL reused the correct existing Head Finance session,
but its redirect to the legacy dashboard hit incomplete synthetic School admin
metadata. P0 pages subsequently rendered normally. This out-of-scope fixture
limitation is recorded, not counted as a Production failure or silently repaired.

## Authorized maintenance and read-only Production data preflight

- Exact active release remained at the parent SHA throughout.
- Fresh R2E encrypted Central + eight trusted tenants, shared storage/config and
  manifest: all 12 local checksums and independent remote COS HEAD sizes pass.
- Explicitly bounded operator-approved historical QA reconciliation appended
  one dedicated maintenance audit under the separately authorized real actor.
  The identity namespace is `historical_qa`, not asserted bank evidence.
- All ten financial/classification/Run table snapshots match before/after.
  Original bank reference remains NULL; no new Payment, Receipt, Ledger,
  balance movement or QA Run membership was created.
- Exact SELECT-only migration inventory returns two `bank_reference` identities
  and one `historical_qa` identity, exclusively the approved historical source.
- Duplicate identity/origin groups: zero. Unresolved Pending, Import and Other
  Income conflicts: zero. Existing Deposit/allocation records: zero. New P0
  columns/table/migration history: absent; old allocation index still present.
- Raw missing-reference count remains one by design. It is not missing bank
  evidence for an Official transaction and is not rewritten. One raw linked
  cash Pending/account difference is expected cash-confirmation destination
  selection, not an independent bank receipt.
- Data/schema preflight classification: **CLEAN**. Production P0 migration and
  application deployment: **NOT PERFORMED**. Login 200, runtime root-owned cache/
  views/sessions entries zero, R2E timers active and service last results success.

## Remaining deployment gate

Data-ready does not mean executable under the existing Production pipeline.
The P0 exact-path Production runner and `ProductionMigrationGuard` allowlist
entry are not yet implemented/approved. Define and review migration-history
recording, complete schema verification and a controlled quiescent bank-writer
window before any migration. Never bypass the guard or invoke broad migrate.
The final local candidate may be frozen; direct Production deployment remains
blocked until this operational gate and explicit exact-candidate approval.

Ordinary Refund/Reversal on deposit-attribution Payments remains intentionally
denied; a separate zero-cash correction lifecycle is outside this scope.
