# Phase 4 — School Finance Unification Discovery

**Baseline:** Production and GitHub `17a8c62`
**Scope:** Read-only inventory and mapping. No application code, schema, data, or deployment changes were made.

## Executive summary

Central Finance is the canonical financial system for a School after Central Finance cutover. Zixuan's tenant School Finance routes and read models still exist, but its legacy financial write routes are protected by `tenantFinanceWritable`.

The main Phase 4 problem is therefore duplicated navigation and potentially inconsistent read models, not an approved dual-write path. The safe approach is to migrate daily UX to Central Finance in small phases while retaining tenant School Fee Setup and historical read access.

## Page inventory

| Area | Central Finance | Zixuan School Finance | Assessment |
| --- | --- | --- | --- |
| Finance Overview | `/central-finance` → `CentralFinanceWorkspaceController::dashboard`; Central Ledger and canonical operating documents; Central principal, Group and School scope | `/finance-dashboard` → `FinanceDashboardController`; tenant `fees_paid`, `expenses`, `other_incomes`, `bank_accounts`; tenant role | Duplicate aggregation. Reuse Central dashboard; legacy summary can diverge after cutover. |
| Student Finance | `/central-finance/student-collection` → `CentralFinanceStudentCollectionController`; `CentralFinanceReceivable`, Payment, Receipt, Ledger | `/fees/pay/*`, `/fees/paid` → `FeesController`; tenant `FeesPaid`, `CompulsoryFee`, `OptionalFee` | Duplicate collection logic. Central is canonical. Zixuan legacy writes are guarded. |
| Receivables | `/central-finance/receivables`; Central receivable snapshots and adjustment service | `/outstanding-fees` → `OutstandingFeesController`; tenant fee/payment-derived calculation | Similar purpose, different source semantics. Replace daily Zixuan queue with Central; retain old data only for history/reconciliation. |
| Other Income | `/central-finance/other-income` → `CentralFinanceOperatingDocumentService::createOtherIncome()` → Central Ledger | No equivalent independent canonical School workspace; tenant `other_incomes` feeds old reports/account views | Reuse Central Other Income. Do not wire the tenant form directly to Central without principal/scope resolution. |
| Expense | `/central-finance/expenses` → `CentralFinanceOperatingDocumentService::createExpense()` → Central Ledger | `/expense` → `ExpenseController` / `ExpenseCreationService` → tenant `expenses`, `bank_accounts` | Duplicate and legacy. Replace daily Zixuan entry with Central Expense; preserve historical tenant expense access. |
| Fund Accounts | `/central-finance/fund-accounts`, `/central-finance/account-statements`; Central Fund Account, allocation and Ledger balance services | `/bank-accounts` → `BankAccountController`; tenant `bank_accounts`, tenant transaction adapters | Duplicate but different data models. Central is allocation/scope-aware. Never auto-merge accounts. |
| Receipts | `/central-finance/payments/{payment}/receipt`; Central Payment / Receipt view model | `/fees/paid/receipt-pdf/{id}` → `FeesController`; tenant `fees_paid` | Duplicate rendering, distinct data. Central is canonical for new receipts; legacy receipt is historical only. |
| Fee Setup | Central reads/synchronizes source through `StudentFeeAssignmentService` and the receivable publisher | `/fees`, `/fees-type`; `FeesController`, `FeesTypeController`; tenant `fees`, `fees_types`, `fees_class_types` | Not duplicate. Keep School Fee Setup as the tenant academic/master-data authority. |
| Reports | `/central-finance/reports`, `/central-finance/ledger`, fund account reports and exports; scoped Central Ledger | `/finance-report`, `/bank-account-report`, `/finance-dashboard`; tenant Payments/Expenses/Accounts aggregation | Financial-reporting duplication. Use Central reports for cutover Schools; do not combine legacy and Central totals. |

## Authorization and data boundaries

### Central Finance

Routes sit behind `centralFinance` and `auth`. Runtime access requires:

- a Central Finance principal;
- active Group access;
- explicit School scope;
- Fund Account scope for relevant operations; and
- Central cutover permission for financial writes.

Core services include:

- `CentralFinanceWorkspaceService`
- `CentralFinancePaymentService`
- `CentralFinanceOperatingDocumentService`
- `CentralFinanceLedgerService`
- `CentralFinanceFundAccountScopeService`
- `CentralFinanceFundAccountSchoolAvailabilityService`

### School Finance

School Finance routes use the tenant session, tenant `Auth::user()`, tenant roles and feature checks. Its money-changing routes use `tenantFinanceWritable`.

For Zixuan, Central Finance cutover means the tenant financial writers are server-side blocked. Existing legacy GET pages remain a risk of user confusion because their models do not become canonical merely because the write guard blocks POST/PUT/DELETE actions.

## Keep

- School Fee Setup, including Fee, FeesType, FeesClassType, Class and Academic Year configuration.
- Academic/student master data owned by the tenant.
- Central canonical Payments, Receipts, Receivables, Expenses, Other Income, Fund Accounts, Ledger and Audit history.
- Explicit Central principal, Group, School and Fund Account scope model.
- The Zixuan tenant financial write guard.

## Reuse

- Central Student Collection for daily School Accountant collection.
- Central Receivables for overdue and outstanding management.
- Central Expense, Other Income, Fund Accounts, Account Statements, Standard Ledger and reporting workspaces.
- Central workspace components: header, School context, currency summaries, filters, tables and mobile presentation.
- Canonical Central services rather than creating a second School-to-Central posting path.

## Replace

- Zixuan daily legacy Finance Dashboard, Outstanding Fees, Expense, Bank Accounts, Bank Account Report and Finance Report navigation.
- Zixuan daily student payment and optional payment entry navigation with Central Student Collection.

## Deprecate

- Tenant `FeesPaid`, Expense and Bank Account as targets for *new* Zixuan financial writes.
- Tenant receipt, transaction log and finance report pages as the current financial source of truth.
- Legacy tenant payment/expense imports for new writes in a Central-cutover School.

## Security fixes / requirements

- Central sidebar or deep links must resolve a Central principal and explicit scope; never infer Finance authority from a School role.
- Legacy School Finance pages must clearly identify historical/read-only behavior after cutover.
- School Admin must not receive Central Finance authority merely because a navigation item exists.
- Central School selection must remain server-side validated; never trust a query string or hidden School id.
- Tenant edit/delete semantics cannot be mapped to immutable Central Payment, Receipt or Ledger records.
- Legacy and Central reports must be visibly source-labelled and never summed together.

## Recommended implementation sequence

### Phase 4A — Navigation and read unification

For Zixuan only, change daily Finance navigation to the appropriate Central Finance read or operating workspace. Keep School Fee Setup in the tenant app. Add historical/read-only notices to retained legacy finance pages. No schema migration and no financial write change.

### Phase 4B — Student Finance cutover UX

Make Central Student Collection, Receivables and Receipts the daily workflow. Keep tenant payment pages as controlled historical references. Characterize all role and direct URL boundaries before implementation.

### Phase 4C — Operations, funds and reports

Replace daily Expense, Other Income, Fund Account, Account Statement and report entry points with Central workspaces. Keep tenant history separate; do not copy, recalculate or merge historical finance data.

### Phase 4D — Legacy retirement gate

Audit each Central-cutover School's legacy routes, imports, exports and sidebar entries. Only after proving no new tenant financial write can occur should deprecation/archive policy be proposed.

## Migration assessment

No migration is required for Phase 4A. Later historic-data bridging, if requested, requires a separately approved data-attribution and audit design. It must not migrate, merge, rewrite or re-calculate Finance history by default.
