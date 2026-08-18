# Finance Group — Goal 9: HQ Multi-School Consolidated Reporting Contract

**Status:** Architecture and reporting decision package only.  
**Scope:** Read-only Bowen Group Finance reporting across member School tenants,
using Goals 2–8 scope, Ledger, category, transfer, closing, reconciliation,
and currency contracts.  
**Non-goals:** No tenant merge, no cross-school write, no Group user/account
bootstrap, no change to current tenant dashboards, no schema/runtime/data
change, and no production/Staging work.

## 1. Decision

Create a separate **Group Finance Reporting** surface rather than extending a
normal school route with a broad `school_id` filter.

```text
Normal School Finance route
  session-selected tenant -> tenant roles/account scope -> one School result

Future Group Finance Report route
  central Group identity -> trusted Group membership -> explicit user scope
  -> read-only Ledger V1 projections from allowed School(s)/Group account(s)
  -> consolidated report + source drill-down
```

Student, class, fee, staff, and existing tenant operational data remain in
their separate databases. Consolidation is a read-only Finance projection,
never a move/merge of tenant data.

## 2. Current-state characterization

| Current report/component | Existing behavior | Group-report implication |
|---|---|---|
| `FinanceDashboardController` | Tenant-local income/expense/account data for the authenticated School | Keep as local dashboard; do not add cross-school loops. |
| `FinanceReportController` | Tenant-local actual receipts/expenses, category grouping and account scope | It is a source/reference for metrics but cannot be trusted as a Group controller without a new scope layer. |
| `FinanceTransactionRegisterService` | Read-only adapter over canonical tenant sources; selected account directional, all-account internal transfer neutral | Ledger V1 is the correct per-tenant movement source for consolidated reporting. |
| `FinanceAccountAccessService` | Current-school account boundary, especially Cashier assignment scope | Must continue within each tenant; Group scope never bypasses it. |
| Outstanding-fee value | Deliberately hidden from account-scoped Cashiers to avoid a misleading partial number | A Group outstanding KPI requires whole-school source access and a clearly documented definition. |
| Bank account report | Account-local calculated balance and ledger | Group report must show balance per physical/Group account and currency, not sum raw different-currency balances. |

## 3. Authorization and request boundary

Every Group report request requires all of:

```text
authenticated central Group Finance identity
AND active trusted Group -> School membership
AND explicit Group user capability
AND requested School(s) are a subset of the user's active scope
AND requested Group/HQ/shared account(s) are a subset of account scope
AND each tenant source query applies tenant-local source/account rules
```

Forbidden shortcuts:

- trusting a request database name or raw tenant connection;
- allowing `Head Finance` in one tenant to see all Group schools by role name;
- treating matching tenant-local user IDs as a global identity;
- using a Group aggregate as a way for a Cashier/Accountant to see an
  unassigned account or another School;
- serving a broad cached report without Group/user/scope/filter identity in its
  cache key.

### Visibility matrix

| Viewer | Group report visibility |
|---|---|
| Group Head Finance | Explicitly assigned Group and School/account scope; full read-only metrics within that scope. |
| HQ Accountant | Only assigned Group/SCHOOL/HQ/shared account scopes; no implicit all-campus student/personnel access. |
| School Head Finance | Own School tenant dashboard by default; Group view only with explicit Group scope. |
| School Accountant | Own assigned Fund Accounts only; no Group all-school report by default. |
| School Admin | Existing own-School oversight only; no Group report unless a separate read-only Group scope is granted. |
| Super Admin | Platform administration is not operational finance visibility; an explicit audited Group-report capability is required. |

## 4. Canonical report data source

The Group report reads Ledger V1 rows with stable canonical keys:

```text
tenant:<school-id>:<source-type>:<source-id>
group:<group-id>:<group-source-type>:<source-id>
```

It does not calculate money from UI totals, scrape tenant dashboards, or create
a duplicate mutable “consolidated transaction” table.

Required row dimensions:

- Group, School ID/code/name, source tenant identity;
- posting date and report period;
- ledger key, source type/ID/status/reference;
- operating class/category code and category mapping state;
- Fund Account/Group Account identity, account type, native currency;
- original amount/currency, immutable MMK reporting amount/rate snapshot;
- account-side in/out vs neutral internal-transfer amount;
- operator, approval/audit state, and import/reconciliation/closing links when
  applicable.

No source whose status is pending, rejected, cancelled, soft-deleted, or
otherwise ineligible for current movement can silently enter an operating KPI.

## 5. Consolidation and elimination rules

### Operating result

```text
Group Operating Income  = sum eligible STUDENT_FEE + OPTIONAL_FEE + OTHER_INCOME
Group Operating Expense = sum eligible EXPENSE
Group Operating Net     = Income - Expense
```

All rows are summed in immutable MMK snapshot value for Group totals, with
native-currency breakdown retained.

### Internal transfers

| Event | School/account display | Group operating result | Group movement display |
|---|---|---|---|
| Tenant BankTransfer | Source Out / destination In | 0 income, 0 expense | One neutral internal transfer where all scoped accounts are included. |
| Confirmed tenant Handover | Same linked BankTransfer only | 0 income, 0 expense | One neutral canonical transfer. |
| Future HQ ↔ School Group transfer | Two account legs | 0 income, 0 expense | One neutral Group transfer. |
| Pending/rejected/cancelled handover/transfer | none | none | audit/exception only, no movement. |

The Group report must eliminate internal transfer from operating income,
expense, net, and category totals. It may display total internal funding volume
as a **separate non-operating custody metric**.

### Physical account duplication

An HQ/shared physical account appears once through its `group_fund_account`
identity. It is not added once for every linked School. Tenant-local School
accounts retain their own identity. This prevents duplicate balances in Group
liquidity and statement/reconciliation reports.

## 6. Required V1 report views

### A. Group operating summary

| Metric | Required presentation |
|---|---|
| Operating Income / Expense / Net | MMK consolidated total, period-over-period option later, source/class breakdown. |
| School comparison | One row per authorized School, with category and source drill-down. |
| Category comparison | Standard category codes plus `UNCATEGORIZED_LEGACY` / `pending_review` amounts/count. |
| Internal transfer volume | Separate, neutral metric; never added to income/expense. |
| Currency exposure | Native balance and activity by MMK/CNY/USD; MMK equivalent only from approved snapshots. |

### B. Group liquidity / Fund Account summary

Show per authorized physical account:

```text
Group/School owner | account | account type | native currency | native balance
MMK reporting valuation (if approved snapshot exists) | last close/reconciliation status
```

Never display a single raw “total balance” that adds 10,000 CNY + 1,000 USD +
50,000,000 MMK without explicit MMK valuation and a currency breakdown.

### C. Ledger register and exception view

Filters: date, Group, School, account, currency, category, class/source,
operator, reference, audit status, reconciliation state, cash closing state.

Drill-down always opens a read-only source/audit view inside the authorized
school/group scope. Exports retain source identities; report row numbers are
not identifiers.

### D. Control/completeness summary

Per School/account/period show:

- cash closing status and unresolved variance;
- bank reconciliation status, unmatched value/count, last statement date;
- uncategorized/legacy classification amount/count;
- currency snapshot missing/exception amount/count;
- pending Group/tenant handover count/amount (not operating movement);
- import duplicate/error status where applicable.

This separates data-quality/control risk from financial performance.

## 7. Metric definitions and exclusions

| Metric | Include | Exclude / caveat |
|---|---|---|
| Actual tuition income | successful, non-deleted canonical student fee movements | outstanding/due amounts; refunded/reversed treatment awaits Goal 10. |
| Other operating income | eligible Other Income by standard category | internal funding, deposits/loans, unapproved exceptions. |
| Operating expense | eligible non-deleted Expense by standard category | internal transfers; unapproved cash/bank adjustments. |
| Outstanding fees | Whole-school receivable definition only | Never aggregate partial Cashier/account-scoped numbers; Group display requires approved Group access and consistent tenant definitions. |
| Internal funding volume | completed canonical internal transfer only | income/expense/net/category totals. |
| Fund Account balance | source-derived account balance in native currency | mixed-currency unvalued aggregation; pending movement. |
| Net operating result | eligible operating income - expense | internal transfers; FX treatment per Goal 8 policy. |

Refunds, discounts, waivers, bad debt, FX gain/loss, bank fee/interest, and
cash difference treatment must use the source-classification rules introduced
by their later approved phases. Until then, Group reports surface them as
explicit exceptions rather than silently folding them into a misleading KPI.

## 8. Data freshness, partial failure, and audit

Group reporting may involve several tenant reads. It must state the exact
`generated_at` time, each School source watermark/status, and the applied
scope/filter set.

- A tenant query failure produces a **partial/incomplete** report with the
  excluded School identified; it must not silently return a lower total as
  complete.
- Cached report data is keyed by Group, user/scope version, School set,
  account set, filters, Ledger/source version, and currency/rate policy.
- Scope revocation invalidates cached visibility immediately or fails closed.
- Export/download logs actor, scope, filters, generated time, record count,
  and file checksum. Exports contain no extra School data.
- Report generation is read-only and restores any tenant connection context
  after each source query; no shared request identity/cache relation can leak
  role data across tenants.

## 9. Rollout sequence

1. Approve Goal 2 Group/SCHOOL/account/user scope configuration.
2. Implement and characterize Ledger V1 tenant adapter parity (Goal 3).
3. Establish category mapping/completeness visibility (Goal 5) and currency
   snapshot policy (Goal 8).
4. Deliver a **read-only two-synthetic-school** Group operating summary and
   source register first, with no Group account/transfer writes.
5. Add Group account liquidity, cash closing, and bank reconciliation control
   statuses when those modules are locally verified.
6. Add HQ↔School canonical transfers only after Goal 4 state/recovery logic
   is implemented and verified; then show them once as neutral movement.
7. Require a separate production security/migration/release gate.

## 10. Required local acceptance before runtime implementation

- Group user with School A scope sees A only; School B and unrelated tenant are
  absent from UI, route, export, and forged request response;
- Group user with A+B scope gets correct sum of eligible Ledger V1 rows;
- school local users retain tenant-only visibility, and Cashier/Accountant
  cannot obtain Group totals or unassigned account movements;
- direct BankTransfer and confirmed Handover appear once neutral in all-account
  Group view and do not affect operating income/expense/net;
- future Group transfer appears once with two account legs and no duplicate
  tenant movement;
- category totals reconcile to source/Ledger totals, including explicit
  uncategorized/legacy rows;
- native-currency account balances never mix; MMK total reconciles to immutable
  snapshots and shows missing-rate exceptions;
- outstanding KPI is hidden unless whole-school source authorization exists;
- pending handover, unapproved adjustment, and soft-deleted/cancelled source
  are excluded from current totals;
- partial tenant read returns visibly incomplete result, never silent total;
- export has the same authorization/filter result as UI and logs audit data;
- existing P0–P3 finance reports/dashboard/account scopes and broad Finance
  regression remain green.

## 11. Business sign-off required

1. Confirm initial Bowen Group member schools and Group-report user scopes.
2. Approve the exact operating KPI definitions and monthly reporting calendar.
3. Confirm whether HQ/shared account liquidity is visible to each School or
   HQ-only.
4. Confirm outstanding-fee definition and whether Group may display it before
   refund/waiver/bad-debt rules are implemented.
5. Confirm which users may export/download Group reports and retention policy.
6. Confirm desired first dashboard layout and whether comparison targets are
   monthly, quarterly, academic year, or custom date range.
7. Approve treatment/display of FX, cash differences, bank fees, and
   unclassified historical data in management reports.

## Goal 9 acceptance result

- [x] Separate, read-only Group Finance reporting surface selected.
- [x] Explicit Group/School/account authorization and tenant-source boundary
      defined.
- [x] Ledger V1 source, internal-transfer elimination, and physical-account
      deduplication rules defined.
- [x] Operating, liquidity, ledger, and control/completeness report contract
      defined.
- [x] Currency, partial-failure, caching, export audit, rollout, and test
      requirements defined.
- [x] No runtime code, schema, data, production, or Staging change made.
