# Finance Group Goal 2 — Group / School / Finance Scope Decision

Status: proposed architecture decision, prepared locally on 2026-08-18.
No runtime code, schema, tenant data, production data, role assignment, or
financial movement is changed by this document.

## Decision summary

Adopt a **two-plane Group Finance architecture**.

```text
Central mysql: Group Finance Control Plane
  Bowen Group
    ├── Group membership: School A / School B / School C / School D
    ├── Group finance users and explicit school/account scopes
    ├── HQ and shared physical Fund Account identities
    ├── Group Ledger V1 / cross-school canonical transfers (later phases)
    └── Consolidated read models (later phases)

School tenant database: School Operational Plane
  Students, classes, fees, local staff, local roles,
  tenant-local Fund Accounts and existing Finance P0–P3 source records
```

No student, class, fee, attendance, school settings, or existing tenant
finance table is merged between schools. No user gains cross-school access by
having a familiar role name alone.

## Why a central control plane is required

The existing `BankTransfer` and `FundHandover` foreign keys point only to
accounts and users in one tenant database. A physical HQ account cannot safely
be copied into several tenant databases: doing so would create several balances
for one real bank/cash account.

The central control plane is therefore the authority for:

- Bowen Group membership;
- cross-school finance authorization;
- headquarters/shared physical account identity;
- later Group Ledger V1 and cross-school canonical transfer identity.

Tenant-local `BankAccount` remains the authority only for an account that is
physically and operationally owned by that one school.

## Proposed additive central entities

All tables below belong on the trusted central `mysql` connection. They are
additive and do not replace `schools`, tenant `users`, or Spatie tables.

| Entity | Essential fields | Purpose |
|---|---|---|
| `finance_groups` | id, code, name, status, reporting_currency | Defines Bowen Group |
| `finance_group_schools` | group_id, school_id, active_from, active_to, status | Trusted Group membership for existing School registry rows |
| `finance_group_users` | group_id, central_user_id, status | Identifies a real central user as a Group Finance participant |
| `finance_group_user_scopes` | group_user_id, school_id nullable, scope_type, capability, active dates | Explicit Group/HQ/School authority; no implicit cross-school role inheritance |
| `group_fund_accounts` | group_id, code, name, ownership_type, home_school_id nullable, currency, status | One identity for an HQ, School-owned, or shared physical account |
| `group_fund_account_school_links` | group_fund_account_id, school_id, link_type, active dates | Declares which school may use/view a Group account; does not duplicate its balance |
| `finance_group_user_tenant_identities` | group_user_id, school_id, tenant_user_id | Maps central identity to the actual tenant-local authenticated user where an operational action is permitted |

Required uniqueness constraints:

- `finance_groups.code` unique;
- one active `finance_group_schools` row per Group/School;
- one active tenant identity per Group user/School;
- no duplicate active Group account code within a Group;
- no duplicate active user scope for the same capability and target.

## Fund Account ownership model

| Ownership type | Example | Balance authority | Who may operate it |
|---|---|---|---|
| `HQ` | KBZ Main, HQ Cash | Group Finance ledger/account | Explicit HQ scopes only |
| `SCHOOL` | Bahan Cash | That School tenant account | That School's assigned Accountant and allowed Head Finance scopes |
| `SHARED` | One physical bank account used for several schools | One Group account only | Explicit linked School/HQ scopes only |

Important rules:

1. A physical account has one `group_fund_accounts` identity and one
   authoritative balance.
2. A school link permits use or reporting; it must never create a copied
   opening balance.
3. Existing tenant `bank_accounts` stay local until Ledger V1 defines the
   source-to-Group-account posting rule.
4. A Group account link is not a blanket right to view a school's students,
   fees, personnel, or other non-finance data.

## Authorization matrix

| Actor | Group reports | School operational finance | HQ/shared accounts | Cross-school transfer actions |
|---|---|---|---|---|
| Group Head Finance | All member Schools within assigned Group | Only through an explicit School scope and tenant identity | Explicit HQ/shared scopes | May initiate/approve only within configured Group scope |
| HQ Accountant | Only assigned Group/Schools | Only assigned School scopes | Explicit HQ/shared scopes | May initiate/confirm only where custody scope permits |
| School Accountant | Own assigned School only | Assigned active Fund Accounts only | No HQ/shared access unless explicitly granted | May receive/send only assigned School account and configured counterpart scope |
| School Admin | Own School oversight only | Existing tenant policy continues | No automatic Group access | Read-only only where existing policy permits |
| Super Admin | Platform administration | Not an ordinary finance participant by default | No implicit operational custody | Explicit audited exception only |

Every Group authorization requires all applicable checks:

```text
trusted Group membership
AND explicit Group/User scope
AND active School scope where applicable
AND tenant-local authenticated identity for a tenant operation
AND existing Finance permission/custody/account checks
```

No controller/sidebar check may use `Head Finance` alone as evidence of
cross-school authority.

## Request and connection boundary

Normal school routes remain single-tenant:

```text
session-selected school database -> normal school request -> one tenant only
```

Future Group Finance routes must be separate from normal school routes:

```text
authenticated central identity
-> trusted Group scope resolution
-> controlled read projection or explicit target-school service
-> tenant-local authorization recheck before an approved write
```

The Group portal must not accept arbitrary database names, raw tenant IDs, or
client-selected school connections. Membership is resolved only through
`finance_group_schools` joined to the central `schools` registry.

## Cross-school money movement design boundary

Existing P3 semantics are retained, but not its single-tenant database shape.

```text
Cross-school Handover Request
  -> Pending (no balance / Ledger impact)
  -> receiver confirmation
  -> exactly one Group canonical internal transfer
  -> sender Money Out; receiver Money In
  -> operating income = 0; operating expense = 0
```

Phase 3 may reuse the current service state machine and validation principles,
but it must not write two independent tenant `BankTransfer` rows as the
financial source of truth. Ledger V1 must first define the stable Group source
identity and idempotency key.

## Compatibility and migration strategy

1. **Central additive schema only.** Introduce Group configuration tables
   before changing tenant finance behavior.
2. **Explicit bootstrap.** Add Bowen Group members and scopes through a
   guarded, preview-first command/UI; never infer or grant scopes from role
   names, database names, or `staff_support_schools`.
3. **Read-only first.** Group scope can initially power configuration review
   and no-money-movement reporting. Existing P0–P3 tenant operations remain
   unchanged.
4. **Ledger V1 next.** Add a source adapter/projection with stable
   `group_id`, `school_id`, `source_type`, `source_id`, and source database
   identity. Do not create duplicate mutable accounting facts.
5. **Cross-school handover after Ledger V1.** Deploy only after idempotency,
   failure recovery, and reconciliation behavior are proven in synthetic
   multi-tenant QA.
6. **No broad migration commands.** Production migrations must be additive,
   tenant/central-targeted, backup-verified, canary-first, and independently
   reversible where no new activity exists.

## Required test plan for implementation Goal 3+

- One Group with two synthetic member School tenants;
- unrelated School tenant denied Group access;
- Head Finance with only School A scope denied School B;
- School Accountant denied HQ and other-school accounts;
- revoked scope takes effect immediately;
- forged school/account/database target rejected server-side;
- same physical Group account cannot acquire duplicated balances;
- tenant P0–P3 regression remains green;
- cross-school pending movement has no Ledger/balance effect;
- confirmed cross-school movement is represented exactly once;
- read-only Group reports never alter tenant connection state for subsequent
  requests.

## Business sign-off required before runtime implementation

1. Confirm the exact existing School registry rows that belong to Bowen Group.
2. Confirm the initial account catalog and ownership type: HQ, School, or
   Shared.
3. Confirm whether HQ Accountant actions require dual approval for any amount.
4. Confirm School Admin's Group visibility remains disabled by default.
5. Confirm Group reporting currency and future multi-currency policy.
6. Confirm whether an HQ/shared account may receive school fee collections
   directly, and the required allocation/audit rule when it does.

## Goal 2 acceptance result

- [x] Two-plane architecture selected.
- [x] Existing tenant boundaries preserved.
- [x] Group/Security entities and uniqueness requirements specified.
- [x] HQ, School, and Shared Fund Account ownership defined.
- [x] Authorization matrix prohibits role-only cross-school escalation.
- [x] Cross-school transfer boundary defined without duplicating balances.
- [x] Additive, preview-first migration compatibility strategy defined.
- [x] No runtime code, schema, local financial fixture, staging, or production
      data modified.

## Next daily goal

`Daily Goal 3 — Finance Standard Ledger V1 contract`: define the canonical
source mapping, standard fields, idempotency key, balance presentation,
reporting behavior, and Standard Excel Template V1 boundary.
