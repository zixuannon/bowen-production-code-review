# Finance Group — Goal 10: Budget and Variance Contract

**Status:** Architecture and business-rule decision package only.  
**Scope:** Annual/monthly Group, School, and standard-category budget planning,
approval, actual-versus-budget reporting, variance, and forecast.  
**Non-goals:** No budget data creation, no payment blocking, no procurement or
commitment accounting, no source/ledger rewrite, no schema/runtime/data change,
and no production/Staging work.

## 1. Decision

Finance Budget is a **planning and control layer** over Ledger V1 actuals. It
is not a financial transaction, Fund Account balance, or substitute ledger.

```text
Approved Budget Version       Ledger V1 actual source rows
       |                                  |
       +---------- variance engine -------+
                         |
             budget / actual / variance / forecast report
```

The first release is **advisory**: it shows variance and escalations but does
not silently approve, reject, or block Expense, Other Income, BankTransfer,
FundHandover, refund, or adjustment transactions. Any future spending-control
or purchase-order/commitment feature requires a separate business decision and
new safety design.

## 2. Budget grain and currency

The canonical planning grain is:

```text
Group
-> Budget version
-> Fiscal period (year + month)
-> School or HQ/shared scope
-> Standard Finance category code
-> Budget class (OPERATING_INCOME or OPERATING_EXPENSE)
-> MMK reporting amount
```

Examples:

```text
Bowen Group | FY2027 Baseline | 2027-08 | Bahan | RENT | OPERATING_EXPENSE
Budget: MMK 3,000,000

Bowen Group | FY2027 Baseline | 2027-08 | Times City | CAMPS_PROGRAMS | OPERATING_INCOME
Budget: MMK 8,000,000
```

Budget is stored in MMK reporting currency in V1. Native-currency planning may
be added later only with an approved planning-rate policy; it must not be
confused with the immutable transaction-rate snapshot in Goal 8.

Internal transfers are excluded from operating budget/actual/variance. HQ
funding may be reported as a separate **cash funding plan**, but it must never
inflate income/expense budget totals.

## 3. Proposed central control-plane model

All proposed entities are additive, Group-scoped central tables. They do not
replace tenant sources or budgets in a School database.

| Entity | Essential fields | Rule |
|---|---|---|
| `finance_budget_versions` | id/uuid, group_id, code, name, fiscal_year, version_number, status, base_version_id nullable, planning_currency=`MMK`, submitted/approved/active timestamps/actors | One immutable approved/active version per Group/fiscal year/scenario policy. |
| `finance_budget_periods` | version_id, month start/end, status | Exact monthly periods; fiscal calendar is explicit. |
| `finance_budget_lines` | version_id, period_id, school_id nullable, group_account_scope nullable, category_code, budget_class, amount_mmk, notes, created/updated audit | Unique line per version/period/scope/category/class. |
| `finance_budget_change_requests` | version/line target, old/new amount, reason, requested/approved/rejected actor/time, effective version | Approved budget is not edited in place. |
| `finance_budget_events` | budget/version/line identity, event type, actor, time, old/new metadata, reason | Append-only governance/audit record. |
| `finance_budget_exports` | version/filter/scope, requester, generated time, checksum, row count | Audit of sensitive management export. |

Required integrity:

1. `group_id + fiscal_year + scenario + version_number` is unique.
2. One active baseline per Group/fiscal year unless a signed policy permits
   concurrent scenarios.
3. A line’s School belongs to the Group, and category code belongs to the
   approved taxonomy/type.
4. A line targets either a School, HQ/shared scope, or approved Group-wide
   allocation—not arbitrary combinations.
5. Duplicate import/HTTP requests use a stable idempotency key and cannot
   create multiple budget versions or lines.
6. An approved line/version is append-only; revision creates a new version or
   approved change record with a traceable before/after relationship.

## 4. Version workflow and ownership

```text
DRAFT -> SUBMITTED -> APPROVED -> ACTIVE
  |           |           |          |
  +-> VOID    +-> RETURNED|          +-> SUPERSEDED -> ARCHIVED
            (reason)      |
                         active reporting source
```

| Status | May edit lines? | Used for official variance? |
|---|---|---|
| `DRAFT` | Yes, authorized preparers | No |
| `SUBMITTED` | No except controlled return | No |
| `APPROVED` | No direct edits | Can be activated by approved schedule |
| `ACTIVE` | No direct edits | Yes |
| `SUPERSEDED` / `ARCHIVED` | No | Historical comparison only |
| `VOID` | No | No |

Suggested duties:

| Action | School Accountant | School Head Finance | Group Head Finance / Finance Director |
|---|---|---|---|
| Prepare scoped School draft | optional if granted | yes if granted | yes |
| Submit draft | yes if granted | yes | yes |
| Review/approve Group official version | no self-approval | review only by policy | required, separate from preparer for material budget |
| Activate/supersede | no | no by default | explicit capability |
| View approved actual/variance | own scope only | own School + explicit Group scope | Group-wide explicit scope |

School Admin retains existing school oversight only; it does not receive Group
budget authority merely because it can view local Finance data. An Accountant
cannot gain a Group budget line or another School through a forged scope ID.

## 5. Actual mapping from Ledger V1

Budget actuals are generated read-only from eligible Ledger V1 sources within
the selected fiscal period and authorized scope.

| Budget class | Include from Ledger V1 | Exclude |
|---|---|---|
| `OPERATING_INCOME` | eligible Student Fee, Optional Fee, Other Income mapped to standard income category | internal transfer, pending/rejected/cancelled events, deposits/loans, unapproved adjustment, unknown classification. |
| `OPERATING_EXPENSE` | eligible non-deleted Expense mapped to standard expense category | internal transfer, pending/unapproved bank/cash adjustment, unrelated payroll-only non-finance source until classified. |
| `CASH_FUNDING_PLAN` (optional separate view) | completed canonical internal Group transfer | operating income, operating expense, net, standard operating category totals. |

Actual must use source posting date and immutable MMK snapshot from Goal 8.
It must not use current exchange rates or an account balance. Each variance
drill-down exposes `ledger_key`, source type/id, school, category mapping state,
and source audit status.

`UNCATEGORIZED_LEGACY`, `pending_review`, missing-currency snapshot, partial
tenant read, and unapproved-adjustment amounts appear as explicit completeness
exceptions. They cannot be silently allocated into a budget category.

## 6. Variance formulas and interpretation

For each line/period:

```text
Income variance amount = Actual income - Budget income
Income variance %      = (Actual - Budget) / Budget, when Budget != 0

Expense variance amount = Budget expense - Actual expense
Expense variance %      = (Budget - Actual) / Budget, when Budget != 0
```

Positive income variance and positive expense variance are **favourable**;
negative values are **unfavourable**. The UI must show both actual/budget and
plain-language favourable/unfavourable label—not just a signed number.

For a zero budget:

- actual `0` → `on plan` with variance percentage `N/A`;
- non-zero actual → `unbudgeted` exception, not divide-by-zero/infinite percent.

For a zero actual and non-zero budget:

- income → revenue shortfall / pending collection context;
- expense → underspend; it is not automatically a saving until commitments are
  tracked in a later phase.

## 7. Forecast and reforecast

V1 may show a transparent **run-rate forecast**, but it is not an accounting
fact and must be labelled as an estimate:

```text
forecast = actual to date + approved remaining-month plan
```

No forecast may alter an active budget or actual Ledger value. A formal
reforecast is a new approved budget version/scenario linked to the baseline;
it retains baseline-versus-reforecast comparison and decision reasons.

## 8. Reports and alerts

### Required V1 views

- annual Group / School operating budget summary;
- monthly Budget vs Actual vs Variance by School and standard category;
- income and expense drill-down to Ledger source rows;
- unbudgeted activity and uncategorized/partial-data exception list;
- separate internal funding plan/actual view (zero operating impact);
- version comparison: baseline, approved revision, actual, forecast;
- export with version, scope, filters, generated timestamp, and checksum.

### Alert policy boundary

V1 alert is informational: category reaches 80%/100% of budget or has
unbudgeted actual. It sends/records no automated financial action. Threshold,
recipient, escalation, and notification channel require business approval.

## 9. Security, audit, and performance

- Every budget/report request uses trusted Group membership and explicit
  Group→School→account/report scope; no raw database name or broad tenant role
  check.
- Budget editor/export permissions are separate from Expense/Payment/Transfer
  permissions. Budget access does not grant financial-write access.
- Approved version/change/export audit records capture actor, time, reason,
  old/new value, scope/filter and output checksum where applicable.
- Cached report keys include Group, version, user-scope version, School/account
  set, category mapping version, period, currency policy and Ledger source
  watermark. Scope revocation fails closed/invalidates visibility.
- Multi-tenant partial-read failure produces an `INCOMPLETE` report naming the
  omitted School; no silently understated total.
- Budget import (if later allowed) is preview-first, template-controlled,
  atomic confirmation, row-level validation, idempotent, and never accepts
  arbitrary School IDs/category codes beyond authorized scope.

## 10. Rollout sequence

1. Approve fiscal calendar, Group membership, reporting currency, taxonomy and
   Ledger V1 actual mapping from Goals 2, 3, 5, 8, and 9.
2. Deliver Group read-only actual/category completeness report first.
3. Add draft/approval/versioned monthly budget lines for two local synthetic
   Schools; no spending controls.
4. Add Budget vs Actual, unbudgeted exceptions, and export audit.
5. Add controlled budget revisions/reforecast after baseline workflow passes.
6. Consider purchase commitment/expense approval enforcement only as a
   separately approved project with financial safety review.
7. Require a separate production migration/deployment gate.

## 11. Required local acceptance before runtime implementation

- Group budget version and lines exist only in authorized Group/SCHOOL scope;
- preparer cannot self-approve material version/change when policy forbids it;
- approved active budget cannot be edited in place; revision has audit trail;
- duplicate import/submit/retry does not duplicate lines/versions;
- actual income/expense reconcile exactly to filtered eligible Ledger V1 rows;
- internal transfers and pending handovers affect neither operating actual nor
  variance; optional funding-plan view stays separate;
- category mapping/missing-currency/partial-read exception is visible and not
  silently allocated;
- Income/Expense favourable/unfavourable formulas and zero-budget edge cases
  are correct;
- School A user cannot view/edit/export School B budget/actual via UI or forged
  request; Cashier scope remains restricted;
- export matches on-screen authorized scope/filter and logs audit;
- budget operation never creates/mutates Fund Account, Fee, Other Income,
  Expense, BankTransfer, FundHandover, or Ledger source record;
- existing P0–P3 Finance and Group read-only regression remain green.

## 12. Business sign-off required

1. Confirm fiscal year start/end and whether the academic year differs from
   finance fiscal year.
2. Approve the initial budget owner/preparer/reviewer/approver matrix and
   self-approval threshold.
3. Approve standard income/expense categories and whether budget is School,
   HQ/shared account, or Group-wide allocation at each category.
4. Confirm baseline/reforecast scenarios and revision deadline/calendar.
5. Set unbudgeted/80%/100% alert thresholds and recipients.
6. Confirm whether budget is advisory only in V1 (recommended) or when a
   future explicit spend-control phase should be considered.
7. Approve treatment of outstanding fees, refunds, cash differences, bank
   adjustments, FX, and unclassified legacy data in management variance.

## Goal 10 acceptance result

- [x] Budget selected as versioned planning/read-only control over Ledger V1,
      not a financial source or automatic payment block.
- [x] Group/SCHOOL/month/category/class budget grain and MMK reporting currency
      defined.
- [x] Approval/freeze/revision/audit workflow and segregation boundaries
      defined.
- [x] Actual mapping, internal-transfer exclusion, variance/forecast formulas,
      exception presentation, and reports defined.
- [x] Security, multi-tenant, export, rollout, and acceptance requirements
      defined.
- [x] No runtime code, schema, data, production, or Staging change made.
