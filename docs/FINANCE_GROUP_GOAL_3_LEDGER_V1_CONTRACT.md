# Finance Group — Goal 3: Standard Ledger V1 Contract

**Status:** Architecture decision package — no runtime implementation in this
Goal.  
**Scope:** A unified, read-only Finance ledger contract for current tenant
sources first, followed by safe Group consolidation.  
**Non-goals:** No new mutable ledger table, no source-data migration, no
cross-school transfer implementation, no production work, and no change to
existing P0–P3 accounting behavior.

## 1. Decision

Ledger V1 is a **deterministic read model over canonical source records**. It
does not become a second place to create, edit, delete, or calculate money.

The existing `FinanceTransactionRegisterService` is the correct tenant-local
prototype: it reads successful fee payments, `OtherIncome`, `Expense`, and
completed `BankTransfer` records and produces display rows. Ledger V1 makes
that contract explicit and stable enough for future Group reporting, bank
reconciliation, closing, exports, and audit.

```text
Canonical source record
        |
        | deterministic source adapter (no financial write)
        v
Ledger V1 row / export / report
```

**One financial event must have one canonical source and one ledger identity.**
The same event may have two account-side legs in a detailed account view, but
must not become two operating-income/expense events or two all-account rows.

## 2. Financial classification

| Ledger class | Canonical source | Balance effect | Operating income | Operating expense |
|---|---|---:|---:|---:|
| `STUDENT_FEE` | successful compulsory fee payment | Fund Account in | yes | no |
| `OPTIONAL_FEE` | successful optional fee payment | Fund Account in | yes | no |
| `OTHER_INCOME` | `other_incomes` row | Fund Account in | yes | no |
| `EXPENSE` | non-deleted `expenses` row | Fund Account out | no | yes |
| `INTERNAL_TRANSFER` | completed `bank_transfers` row | source out + destination in | no | no |
| `FUND_HANDOVER_PENDING` | none | none | no | no |
| `FUND_HANDOVER_CONFIRMED` | linked completed `bank_transfers` row | source out + destination in | no | no |

Consequences:

1. A pending, rejected, or cancelled handover emits **no movement row**.
2. A confirmed handover is represented **only** by its linked canonical
   `BankTransfer`; it never produces a second handover row.
3. An immediate Bank Transfer is internal, even when both accounts are visible
   to Head Finance. It is not Other Income, Expense, operating income, or
   operating expense.
4. A cancelled/deleted transfer is absent from current-balance and current
   ledger movement views. Its source audit remains available in the source
   record, not by silently reclassifying it as operating activity.

## 3. Canonical Ledger V1 row

The query/export contract uses the following fields. A future materialized
projection, if ever justified for reporting scale, must expose the same
semantics and retain `source_type` + `source_id`; it cannot replace the source
records as the authority for money.

| Field | Meaning / rule |
|---|---|
| `ledger_key` | Stable unique identity: `tenant:<school-id>:<source-type>:<source-id>`. Never use a display row number or an Excel row number. |
| `posting_date` | The source economic date (`date` or `transfer_date`), not merely `created_at`. |
| `group_id` | Null for tenant-only Ledger V1; resolved from trusted Group→School membership for a Group view. |
| `school_id`, `school_code`, `school_name` | Trusted school registry identity; no client-supplied tenant/database name. |
| `source_type`, `source_id` | Canonical source record type and primary key. Required for traceability and deduplication. |
| `source_status` | `completed`, `cancelled`, `deleted`, etc. Current-movement adapter includes only eligible completed/non-deleted sources. |
| `transaction_class` | One of the classes in section 2. |
| `reference_no` | Source reference if available; nullable only where the historical source genuinely has none. |
| `counterparty` | Student, payer, vendor, or `from → to` account label. It is descriptive, not an authorization key. |
| `description` | Source description/notes, with a normalized display fallback. |
| `finance_category_id`, `finance_category` | Present when source supports it. Categories are standardized in a later phase; absent must not be fabricated. |
| `fund_account_id`, `fund_account_name` | Account side for account-filtered output. For all-account internal transfer, also expose both `from_fund_account_*` and `to_fund_account_*`. |
| `payment_method` | Source payment method where applicable; null for internal transfer rather than falsely calling it Cash/Bank. |
| `currency` | Source transaction currency. V1 defaults legacy source rows to MMK only where existing migration/data explicitly supports that interpretation. |
| `original_amount` | Amount in `currency`; do not derive a foreign amount from an MMK amount. |
| `exchange_rate_snapshot` | Rate actually captured with the source, if available. Null means no approved snapshot exists. |
| `reporting_amount_mmk` | Snapshot MMK equivalent where an approved source value exists. Never recompute historical values from today’s rate. |
| `money_in`, `money_out` | Presentation side only. One selected account: directional. All-account internal transfer: both zero and `internal_transfer_amount` carries the amount. |
| `operating_income`, `operating_expense` | Explicit reporting measures. Internal transfers always both zero. |
| `internal_transfer_amount` | Completed transfer amount in an all-account/group neutral view; zero/null for operating activity. |
| `operator_user_id`, `operator_name` | Creator/recording actor if the source stores it; never infer from the current viewer. |
| `created_at`, `updated_at` | Source audit timestamps. |
| `audit_state` | A normalized display of original/completed, edited, soft-deleted, cancelled, or superseded status; preserve source audit links/reasons. |

## 4. View rules: one event, correct perspective

### 4.1 Fund Account view

For a completed transfer A → B of X:

| View | Money In | Money Out | Internal transfer amount |
|---|---:|---:|---:|
| Account A | 0 | X | 0 |
| Account B | X | 0 | 0 |
| All Accounts / Group | 0 | 0 | X |

The all-account row displays `Internal Transfer`, From, To, amount, and the
canonical source reference. It must be exactly one row even if the viewer is
authorized for both accounts.

### 4.2 Operating reports

```
Operating income  = STUDENT_FEE + OPTIONAL_FEE + OTHER_INCOME
Operating expense = EXPENSE
Operating net     = operating income - operating expense

Internal transfers = excluded from all three measures
```

Fund Account balance is separate:

```
opening balance
+ account-side completed income
+ account-side completed internal-in
- account-side completed expense
- account-side completed internal-out
```

This preserves the P0/P3 verified 50,000,000 handover rule: both account
balances move once after confirmation; school/group operating income, expense,
and net change by zero.

## 5. Source mapping and eligibility

| Source | Eligible ledger condition | Current audit/deletion rule | Ledger identity |
|---|---|---|---|
| `compulsory_fees` | current tenant, `status = Success`, active authorized Fund Account | soft deletion excludes current movement; delete actor/reason remain source audit | `tenant:<school>:compulsory_fee:<id>` |
| `optional_fees` | current tenant, `status = Success`, active authorized Fund Account | same principle | `tenant:<school>:optional_fee:<id>` |
| `other_incomes` | current tenant, valid Fund Account | no separate duplicate receipt row | `tenant:<school>:other_income:<id>` |
| `expenses` | current tenant, non-deleted valid Fund Account | edits require a reason; deletion has actor/reason; old record remains auditable | `tenant:<school>:expense:<id>` |
| `bank_transfers` | current tenant, `status = completed` | cancelled/deleted transfer has no current movement; confirmed handover transfer is immutable through direct cancellation | `tenant:<school>:bank_transfer:<id>` |
| `fund_handovers` | never independent movement | pending/rejected/cancelled are audit-only; confirmed resolves to linked transfer identity | no separate movement key |

The adapter must query and filter server-side by accessible Fund Account. A
Cashier/Accountant cannot obtain unassigned account movements through a forged
account filter, export, or Group query.

## 6. Immutability, corrections, and duplicates

1. **No ledger edit endpoint.** Fix the authorized source workflow, not a
   ledger row.
2. **No upsert by an import row number.** Import identifiers stay source-level;
   Expense Import already creates new records only and retains batch/file/
   reference barriers.
3. **No destructive rewrite of historical money.** Future refund, reversal,
   waiver, bad-debt, bank fee, and reconciliation adjustments are new,
   explicitly classified source events linked to the original record. They are
   not negative edits of an old ledger row.
4. **Deduplicate by canonical source identity**, not amount/date/reference
   alone. A confirmed handover link must resolve to exactly one BankTransfer.
5. **Projection idempotency:** a future materialized cache uses a unique
   `ledger_key` plus source version/audit timestamp. Rebuilds must not create
   economic events; source truth wins on disagreement.

## 7. Tenant and future Group boundary

Tenant Ledger V1 runs in the selected school connection and preserves every
existing role, permission, account-pivot, soft-delete, and custody boundary.

A later Group query:

1. authenticates a Group Finance user in the central control plane;
2. resolves only trusted Group→School membership;
3. applies explicit user→school/account scope before opening any tenant
   source; and
4. tags each row with the originating school and group context.

It must not use a raw database name, trust an `school_id` supplied by the
browser, assume same tenant user IDs identify the same person, or grant
all-school rows merely because a local user has a similar role name.

Cross-school HQ↔branch handover is deliberately **not** implemented by making
two tenant-local `BankTransfer` rows. Ledger V1 first provides the canonical
identity contract; the later cross-school transfer design must then create one
group-level canonical transfer with two account legs and exactly one neutral
Group ledger movement.

## 8. Rollout sequence

1. **Ledger V1 tenant adapter:** refactor/rename only after characterization
   tests prove its output is equivalent to today’s transaction register.
2. **Stable export/query contract:** add filters for date, school, account,
   category, operator, class, reference, and source identity without changing
   source write paths.
3. **Accounting parity tests:** every canonical source, visibility scope,
   all-account transfer neutrality, handover canonicality, cancellation, and
   soft deletion.
4. **Group read-only consolidation:** only after Goal 2 control-plane scope
   migrations and Group membership are approved; start with no financial
   writes.
5. **New source types:** refunds/reversals/waivers/bad debt, cash closing,
   bank reconciliation, foreign exchange, and budget are individually added
   with their own source contract and audit rules.

No historical backfill should be treated as a financial write. If a cached
projection is later needed, it must be repeatable, source-linked, and verified
against source totals before becoming operational.

## 9. Required acceptance tests before runtime implementation

- one successful compulsory/optional fee payment maps once to operating income;
- one Other Income maps once to operating income;
- one Expense maps once to operating expense, while an invalid/soft-deleted
  source never becomes a current movement;
- direct transfer is source-account Out, destination-account In, and one
  neutral all-account row;
- pending/rejected/cancelled handover has no movement; confirmed handover maps
  exactly once through its BankTransfer;
- operating totals exclude every internal transfer;
- account balance calculation reconciles to opening balance plus canonical
  account-side movements;
- reference/import retries do not duplicate a source or ledger identity;
- Cashier scope and forged-account rejection hold for all queries/exports;
- Group read model cannot see a school outside the explicit Group scope;
- currency snapshot is displayed exactly as recorded and is never recomputed.

## 10. Decisions still required before implementation

1. Which existing historical date/status anomalies should be visible as an
   audit-only exception rather than silently omitted?
2. Which reporting currency is mandatory for Bowen Group (MMK is proposed),
   and who approves each historical exchange-rate snapshot?
3. Must each receipt/payment have a system-generated immutable finance
   document number, or is the source reference number sufficient during V1?
4. What retention/export permissions apply to Group users and auditors?
5. Which attachment types are mandatory for future adjustment/reversal and
   high-value ledger events?

## 11. Result of Goal 3

The current Finance Transactions register remains the runtime implementation
until a later approved Ledger V1 development phase. This document defines its
safe evolution: a standardized, source-linked, non-duplicating read model that
supports Group consolidation without reopening the stable tenant architecture.
