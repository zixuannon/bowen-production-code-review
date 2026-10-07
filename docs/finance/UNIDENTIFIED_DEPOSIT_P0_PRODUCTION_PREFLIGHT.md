# Unidentified Deposit P0 — read-only reconciliation preflight

Date: 2026-10-07. Result: **BLOCKED**.

## Baseline and local state

- Production SHA: `135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e`.
- Active release: `eschool-rc-135341bf2f9e-consolidated`.
- Accepted prior candidate: `abcd66e0d262daa5a3fd9b5a1daba537cd1c20d5`.
- Local reconciliation branch: `codex/unidentified-deposit-p0-reconciled`.
- Changes reapplied without commit; only CURRENT_STATE documentation conflicted.
- Final reconciled candidate: NONE. No push, Production migration or deployment.

## Inspection method

SSH invoked PHP 8.3 as runtime user www, requiring Composer autoload only, not
bootstrapping Laravel. PDO used a READ ONLY consistent snapshot; SELECT-only
schema/aggregate inspection ended with rollback. Connection secrets, raw bank
references, job payloads and financial row identifiers were not emitted.
Production MariaDB: 10.11.10. Database-reported snapshot time: 13:11:26.

## Exact counts

| Check | Count |
| --- | ---: |
| All Payments | 4 |
| Bank-scoped Payments | 3 |
| Bank Payment NULL reference | 1 |
| Bank Payment blank non-NULL reference | 0 |
| Invalid nonblank reference | 0 |
| Duplicate nonblank account/currency/normalized reference groups | 0 |
| Records in those duplicate groups | 0 |
| Duplicate source/origin groups | 0 |
| Invalid origins, nonpositive amounts, missing accounts, currency mismatch in migration sources | 0 |
| Pending Collections | 3 |
| Confirmed Pending Collections linked to existing Payment | 3 |
| Bank-scoped Pending Collections | 2 |
| Bank Pending NULL/blank reference | 1 |
| Linked Pending/Payment identity-field mismatches | 1 |
| Unlinked Pending overlapping an existing posted identity | 0 |
| Import batches / row reservations / group batches / group preview rows | 0 / 0 / 0 / 0 |
| Other Income (including soft-deleted) | 0 |
| Unidentified Deposits | 0 |
| Deposit Allocations | 0 |
| P0 migration history entries | 0 |
| New P0 columns present | 0 |
| New bank transaction identity table present | 0 |

Expected `cfuda_deposit_receivable_unique` index remains. No partial P0 schema
detected. Duplicate checks use the exact candidate normalization: UTF-8/control
validation, collapsed whitespace, trimmed uppercase, maximum 100 characters;
uniqueness is Fund Account + currency + normalized reference hash. Missing
references cannot be treated as verified unique physical transactions.

The one Pending/Payment mismatch compares reference, intended/actual account
and currency. This aggregate does not identify which field differs or establish
that the mismatch is a duplicate. All Pending rows are already confirmed; do
not double-count them as separate physical receipts.

## Required stop

Classification: **HISTORICAL DATA RECONCILIATION REQUIRED**.

`historicalIdentities()` in the exact migration
`2026_10_07_000001_close_unidentified_deposit_p0.php` rejects the bank Payment
with NULL reference before first DDL. Missing references must not be invented,
and an existing idempotency key is not proof of physical bank identity.

Next requires separately authorized, narrowly scoped evidence reconciliation
against original bank/receipt evidence and a reviewed audited repair plan.
Nothing was repaired here. If evidence cannot establish a physical identity,
stop for a business decision instead of weakening uniqueness.

Post-reconciliation tests, migration rehearsal and browser smoke were not run
after this blocking result. Prior 1,194 tests / 9,286 assertions and completed
local browser E2E belong to the old accepted SHA only. No final candidate was
frozen. Production financial data and configuration remain unchanged.

**Operator limitation retained:** ordinary Refund/Reversal for deposit-linked
allocation Payments fails closed. Zero-cash attribution cannot use the normal
cash-refund engine; a separate correction contract is outside P0 scope.
