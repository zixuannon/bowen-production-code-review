# Finance Group Goal 1 — Current-State and Data-Boundary Baseline

Status: completed locally on 2026-08-18. This is a read-only architecture
baseline; it changes no runtime behavior, schema, tenant data, or production
configuration.

## Purpose

Bowen operates as one education group with multiple school tenants. Student,
class, fee, and school operations data must remain isolated in their current
tenant databases. Finance headquarters needs controlled visibility and custody
across the member schools without merging those tenants.

This document records what exists now before Group / School Finance Scope work
begins.

## Confirmed current architecture

```text
Central mysql registry
  School(id, code, database_name, status, ...)
        |
        +-- tenant database A: users, students, fees, finance records
        +-- tenant database B: users, students, fees, finance records
        +-- tenant database C: users, students, fees, finance records
        +-- tenant database D: users, students, fees, finance records
```

- Login stores the selected tenant database in the session.
- `InitializeTenantDatabase` establishes that connection before web middleware
  resolves tenant roles.
- `SwitchDatabase` and `SchoolDataService` reconnect only to the selected
  tenant database for normal school requests.
- The central `schools` registry is trusted tenant metadata; it is not a
  Group Finance model.
- No Group, parent-school, campus, or group-level finance-account model exists
  in the runtime source.

The known production registry contains seven active tenants plus the inactive
Demo tenant. This Goal does not inspect, alter, or reclassify any production
school, database, user, or financial record.

## Current finance source of truth

Each listed record is tenant-local and also carries `school_id`.

| Source | Fund Account relation | Current financial meaning |
|---|---|---|
| `CompulsoryFee` | `bank_account_id` | Operating income after successful payment |
| `OptionalFee` | `bank_account_id` | Operating income after successful payment |
| `OtherIncome` | `bank_account_id` | Non-fee operating income |
| `Expense` | `bank_account_id` | Operating expense |
| `BankTransfer` | `from_account_id`, `to_account_id` | Internal transfer only |
| `FundHandover` | source/destination accounts | Pending custody record; confirmed record links to one `BankTransfer` |

`FinanceTransactionRegisterService` is the current read-only unified adapter
over those sources. It is not an independent mutable ledger table. A confirmed
handover appears exactly once through its canonical completed `BankTransfer`;
pending handovers do not produce a money movement.

## Existing safety boundaries to preserve

1. A normal request uses one selected tenant database only.
2. All finance writes validate the actor's current `school_id`.
3. Fund Accounts must be current-school, active, and not soft-deleted.
4. A Cashier (user-facing: Accountant) is limited by `bank_account_user`.
5. School Admin and Head Finance have all-account access only within the
   current school tenant.
6. Internal transfers change account balances but never operating income or
   operating expense.
7. Confirmed handovers have exactly one canonical transfer; rejected and
   cancelled handovers have no completed movement.
8. Soft deletion, reference reservation, opening-balance audit, and expense
   audit behavior must remain intact.

## Existing roles and their limits

| Current role | Current scope | Group-finance implication |
|---|---|---|
| School Admin | Current-school Finance oversight/management according to named permissions | Must remain local read-only/oversight unless separately granted Group scope |
| Head Finance | All current-school accounts; finance staff and handover authority | Must not automatically become cross-school merely by role name |
| Cashier / Accountant | Explicit current-school assigned Fund Accounts only | Must remain School + Fund Account scoped |
| Super Admin | Platform-level behavior | Must not become an ordinary headquarters finance role |

`staff_support_schools` exists for cross-school staff support. It can inform
identity/user-experience design, but it is not safe to reuse as Finance Scope:
it has no finance permission meaning, Fund Account custody, transaction
authority, audit policy, or Group boundary.

## Why the current transfer model cannot be used unchanged across schools

The current `BankTransfer` has one `school_id` and both account foreign keys
point to `bank_accounts` in the same tenant database. `FundHandover` has the
same constraint and its receiver/sender foreign keys point to that tenant's
`users` table.

Therefore a headquarters account in one tenant and a school account in another
tenant cannot form a valid current `BankTransfer` or `FundHandover`. Copying a
headquarters account into each tenant would create duplicate balances and is
not acceptable.

The future design must introduce a Group-level canonical transfer/account
identity for cross-school movements while preserving the proven existing
state-machine semantics:

```text
Pending -> Confirmed / Rejected / Cancelled
Confirmed -> exactly one canonical internal transfer
Internal transfer -> affects account balances; operating income/expense = 0
```

## Goal 1 gap inventory

| Needed capability | Exists today | Safe next-step direction |
|---|---|---|
| Bowen Group membership | No | Central Group and Group-School membership model |
| Cross-school finance scope | No | Explicit Finance User ↔ Group/School grants, not broad role inheritance |
| HQ/shared Fund Account | No | One Group-level account identity; never duplicate a balance per tenant |
| Cross-school handover | No | Generalize the canonical transfer boundary after Ledger V1 design |
| Group ledger contract | Partial | Formalize current transaction adapter as Ledger V1 contract |
| Cross-school consolidated report | No | Build only after Group scope and Ledger V1 are stable |
| School operational isolation | Yes | Preserve without tenant merges |

## Required decisions before implementation Goal 2

1. Which existing School registry rows belong to Bowen Group?
2. Which accounts are headquarters-owned, school-owned, or shared?
3. Does a headquarters Accountant require direct entry rights in a school, or
   only Group-level custody workflows?
4. Does each school retain a local School Admin read-only finance role?
5. What unique identifier should remain stable across tenant-local source
   records when exported to Group Ledger V1?
6. What is the reporting/functional currency, and which historical data can be
   reported without an approved exchange-rate snapshot?

## Goal 1 acceptance result

- [x] Current tenant boundary mapped.
- [x] Current finance source records and account relationships mapped.
- [x] Current finance role/account boundary mapped.
- [x] Existing cross-school support mechanism reviewed and ruled out as a
      finance authorization shortcut.
- [x] Cross-school transfer incompatibility identified before schema work.
- [x] No code, schema, local finance fixture, staging, or production data was
      modified.

## Next daily goal

`Daily Goal 2 — Group / School / Finance Scope architecture decision`:
produce the proposed central Group entities, membership relationships,
authorization matrix, HQ/shared-account ownership model, and migration
compatibility plan. No production changes.
