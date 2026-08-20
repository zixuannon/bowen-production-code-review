# Finance Group Operating Context — Scheme B

## Purpose

Finance Group lets a central Finance user work across explicitly configured
Schools without merging tenant data and without requiring a second School
login. It has two distinct modes:

| Mode | Scope | Behaviour |
| --- | --- | --- |
| All Schools | Explicit Group reporting scope | Read-only Group Dashboard, Ledger, reports, and CSV export. |
| One School | Explicit School operating scope | Reuses that School's existing Finance routes and business services. |

All Schools never becomes a cross-tenant financial write surface. A financial
write is always one School's existing canonical write path.

## Identity model

1. The authenticated identity is always the **central User** (`centralActor`).
   Scheme B never calls `Auth::login`, `loginUsingId`, or impersonates a
   tenant User.
2. A configured `FinanceGroupUserTenantIdentity` is an authorization mapping,
   not a login identity. It proves which tenant-local role and Fund Account
   scope may be consulted for a selected School.
3. The session stores only opaque central identifiers:
   `central_actor_id`, `group_id`, `school_id`, and `tenant_user_id`. It never
   stores a tenant database name.
4. The selected School database is resolved only from the trusted central
   `schools` registry. Request values, URLs, sessions, and CLI input can never
   choose a database name.

## Entering and leaving a School

`enterSchool` succeeds only when all of the following remain true:

- the logged-in User is central (`school_id = null`);
- the Group and Group membership are active;
- the School is an active Group member;
- the central User has explicit `operate_finance` Group or School scope;
- the configured tenant identity is active and still belongs to that School;
- the identity can be resolved using the trusted tenant connection.

Entering from an existing tenant-login session is denied. `exitSchool` removes
only the Finance Operating Context and restores no tenant authentication,
because central authentication was never replaced.

Every request that consumes a context revalidates the central actor, active
membership, operating scope, and mapped tenant identity. A stale or forged
context is denied rather than silently selecting another School.

## Authorization boundaries

- **Super Admin** configures Groups, memberships, scopes, and tenant identity
  mappings only. It receives no report or operating right implicitly.
- **Head Finance** receives cross-School access only through explicit Group
  scopes. Its broad School access is still narrowed by the mapped tenant
  identity and the existing Finance rules.
- **HQ Accountant** and **School Accountant** receive only their explicit
  School scope; existing `bank_account_user` / Fund Account checks continue to
  decide which accounts they may use.
- A later integration must call the existing Finance authorization, custody,
  active-account, deleted-account, tenant, and School checks. Group scope is
  an additional boundary, never a bypass.

## Connection and audit rules

Checkpoint 1 validates the mapped tenant identity through a short-lived,
trusted connection and restores the previous default connection immediately.
It exposes no generic tenant callback and opens no cross-School write route.

Before any cross-School write path is enabled, its audit record must preserve:

- central actor;
- Group;
- selected School;
- mapped tenant identity;
- action, timestamp, and existing Finance reason/audit data.

No Finance Group context may create a second ledger, duplicate a transaction,
or change the canonical sources for FeesPaid, OtherIncome, Expense,
BankTransfer, or FundHandover.

## Checkpoint sequence

1. **Checkpoint 1 — foundation:** context lifecycle, trusted mapping
   resolution, forged input rejection, and Zixuan/Timecity tests. No UI and no
   cross-School writes.
2. **Checkpoint 2 — route adapter:** clearly labelled enter/exit UX and a
   guarded read-only adapter to existing single-School Finance sources. It
   exposes Bank Accounts, Transactions, and Finance Reports through Ledger V1
   and Fund Account balance calculations, not a second write path.
3. **Checkpoint 3 — income and expense writes:** selected-School Expense,
   Other Income/Receive Money, and Student Fee continue through their
   canonical tenant services. Central-actor audit provenance is committed in
   the same transaction without creating a second ledger.
4. **Checkpoint 4 — internal movements:** selected-School immediate Bank
   Transfer and Fund Handover creation delegate to the canonical tenant
   services. A pending handover remains neutral; the designated tenant
   receiver, not the central actor, confirms it and creates exactly one
   linked canonical BankTransfer. Internal movements remain outside
   operating income, expense, and net result.
5. **Checkpoint 5 — HQ ↔ School Funding:** Funding uses the existing central
   `FinanceGroupTransfer` canonical source only from a selected, trusted
   Operating School. A Central Head Finance remains the authenticated actor;
   the mapped tenant Head Finance identity is used solely for existing School
   and Fund Account checks. A request is pending with zero balance/Ledger
   effect. Confirmation projects one Internal Transfer between the selected
   School Fund Account and an authorized HQ account, never operating income,
   expense, or net result. All Schools remains read-only.

## Formal role boundary for Checkpoint 5

- Only a **central Head Finance** with explicit active Group scopes may enter
  the Group switcher, an Operating School, or HQ ↔ School Funding.
- A **School Accountant** remains in the ordinary single-School Finance
  workflow. They receive no Group switcher and cannot select a peer School.
- An **HQ Accountant** has no cross-School operating capability in this
  checkpoint. Any future HQ-account-only workflow must be explicitly designed
  and must not inherit Group operating access.
