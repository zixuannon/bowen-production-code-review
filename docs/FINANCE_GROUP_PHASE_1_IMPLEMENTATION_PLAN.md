# Finance Group Phase 1 — Execution Plan and Business Approval Pack

**Status:** Implementation-ready planning package; runtime work is gated on the
business confirmation in section 5.  
**Scope:** The smallest reversible local delivery of Group Scope plus Ledger V1
read-only consolidation.  
**Environment:** Local synthetic multi-tenant QA only. Production and Staging
are out of scope.

## 1. Phase 1 outcome

Phase 1 proves, locally, that Bowen can consolidate authorized Finance data
across independent School tenants without merging students, classes, fees,
users, or Fund Account balances.

```text
Central Group control plane
  -> trusted Group / School / Group-user / scope mapping
  -> authenticated Group-report authorization
  -> read-only Ledger V1 queries of allowed tenant sources
  -> one Group operating and transaction register view
```

### Included

1. Additive central Group membership and user-scope foundation.
2. Ledger V1 tenant source adapter with characterization parity against the
   existing Finance Transactions register.
3. Read-only Group register/operating summary for two synthetic School tenants.
4. Server-side Group → School → account authorization and forged-request tests.
5. Source/audit drill-down limited to the authorized tenant scope.

### Explicitly excluded

- cross-school Fund Handover/BankTransfer writes;
- Group HQ/shared Fund Account balance postings;
- cash closing, bank reconciliation, FX conversion, refunds/reversals, and
  budget implementation;
- automatic role assignment, user provisioning, or account assignment;
- any production/staging migration, data change, deployment, or browser test.

## 2. Non-negotiable invariants

| Invariant | Evidence required before Phase 1 acceptance |
|---|---|
| School tenant isolation stays intact | Existing tenant Finance P0–P3 suite remains green; Group query cannot open an unrelated School. |
| No second mutable finance ledger | Group rows retain canonical `ledger_key`, `source_type`, `source_id`; no Group ledger write endpoint/table is used as money truth. |
| Internal transfers do not become income/expense | Direct transfer and confirmed handover render once neutral at all-account scope; operating totals are unchanged. |
| Cashier/Accountant scope remains enforced | Existing `bank_account_user` scope is reapplied for tenant source account views; no Group route expands it. |
| Group access is explicit | Similar tenant role name alone cannot grant Group visibility. |
| Report is read-only | No Fee, OtherIncome, Expense, BankTransfer, FundHandover, opening balance, or account pivot changes arise from report requests. |
| Partial failure fails visibly | A tenant query failure marks the Group result incomplete with the omitted School; it never silently reduces totals. |
| Existing user identity is not guessed | Group user is linked to exact central identity and, if needed, explicit tenant identity mapping. |

## 3. Delivery waves

### Wave 0 — business-confirmed local fixtures (gate)

Create only synthetic local configuration after the confirmation sheet is
approved:

- `BOWEN_GROUP_QA` Group;
- two named synthetic member schools plus one unrelated school;
- one Group Head Finance, one HQ Accountant, one School Accountant per member
  school, and one unrelated user;
- explicit school/report scopes, not inferred roles;
- deterministic tenant financial source fixtures covering income, expense,
  internal transfer, pending handover, and soft-deleted source.

**Exit evidence:** repeatable local fixture command and an inventory asserting
no unrelated local tenant has been changed.

### Wave 1 — Ledger V1 adapter parity (tenant-local, read-only)

1. Extract a stable row contract from `FinanceTransactionRegisterService`.
2. Add `ledger_key`, normalized source/status/category/currency/audit metadata
   in a read-only adapter.
3. Preserve current selected-account directional transfers and all-account
   neutral internal-transfer rendering.
4. Characterize every existing source mapping before refactor.

**No migration required** unless a future non-financial cache is separately
justified. This wave reads current canonical sources only.

**Exit evidence:** source-by-source parity tests; no additional database rows
after repeated register requests; existing Transactions page/regression green.

### Wave 2 — central Group Scope foundation (additive)

Add only the central entities required for read-only authorization:

```text
finance_groups
finance_group_schools
finance_group_users
finance_group_user_scopes
finance_group_user_tenant_identities
```

Do **not** add Group transfer, Group account balance, cash closing, bank
statement, budget, or FX write tables in Phase 1.

Required migration posture:

- migrations target central `mysql` only and identify their connection;
- no tenant migration and no broad migration command;
- local migration rehearsal uses fresh/disposable local central DB;
- unique constraints enforce active Group/SCHOOL/user/scope identity;
- a guarded local bootstrap supports preview/dry-run and explicit synthetic
  Group code only; it cannot accept a raw database name.

**Exit evidence:** schema assertions, duplicate/ambiguous/revoked scope tests,
and evidence that tenant tables/data are untouched.

### Wave 3 — Group authorization and read-only reporting route

1. Add a dedicated Group Finance route namespace/controller/service. It must
   not be added to normal tenant sidebar/controller logic.
2. Resolve central authenticated Group identity, Group membership, explicit
   School scope, and requested School set server-side.
3. Query only the allowed tenant source adapters through a controlled service;
   restore tenant connection/default state after each source read.
4. Build the first two views:
   - Group operating summary by School/source/class; and
   - Group Ledger V1 register with source identity and filters.
5. Show source watermarks and `INCOMPLETE` state for tenant query failure.

**Exit evidence:** 403/404 forged Scope/School/account tests, two-school
correct totals, unrelated-school absence, and no writes during page/API/export
requests.

### Wave 4 — local browser and security acceptance

Use only synthetic Group QA users:

1. Group Head Finance with two School scopes sees exactly those two schools.
2. HQ Accountant with one School scope sees that School only.
3. School Accountant/Cashier sees no Group aggregation and cannot forge a
   Group report request.
4. All-account internal transfer renders once neutral; selected tenant account
   still renders directionally.
5. Pending handover is absent; confirmed handover appears once through its
   canonical transfer.
6. Operating Income/Expense/Net excludes all internal transfer movement.
7. Download/export has identical scope to HTML/API view.

**Exit evidence:** targeted PHPUnit, relevant full Finance regression,
authenticated local Playwright, `git diff --check`, source/data count hashes,
and manual code/security review.

## 4. Proposed change inventory

This is an implementation map, not authorization to make all changes now.

| Area | Likely first implementation responsibility | Must remain unchanged in Phase 1 |
|---|---|---|
| Central models/migrations | New explicit Group membership/scope models with central connection | `schools` and tenant data shape; Spatie tenant roles. |
| Ledger adapter | Evolve/introduce read-only adapter alongside `app/Services/FinanceTransactionRegisterService.php` | Canonical Fee, OtherIncome, Expense, BankTransfer and FundHandover write paths. |
| Group authorization | New central scope service/policy | `FinanceAuthorizationService` and `FinanceAccountAccessService` tenant guarantees, except safe composition. |
| Group controller/routes/views | New separate read-only namespace/menu entry only for explicit Group users | Existing Finance dashboard/report routes and tenant sidebar decisions. |
| Fixtures/tests | Synthetic Group QA data and multi-tenant characterization suites | BOWEN_QA production-parity fixtures and real/production identities. |
| Operational docs | targeted central migration/runbook plus release gate | no production runner until a later explicit deployment phase. |

## 5. Business confirmation sheet — required before Wave 0

The following values must be supplied/approved. They are configuration facts,
not assumptions that code may infer.

### A. Group and School membership

| Required value | Business-confirmed value |
|---|---|
| Group legal/display name | `____________________________` |
| Group stable code | `____________________________` |
| Reporting currency | MMK / `_____________________` |
| Fiscal year start month | `____________________________` |
| Member School 1: official name + existing School Code | `____________________________` |
| Member School 2: official name + existing School Code | `____________________________` |
| Member School 3: official name + existing School Code | `____________________________` |
| Member School 4: official name + existing School Code | `____________________________` |
| Any School explicitly excluded | `____________________________` |

### B. Initial Group user/report scope

| Person / existing central identity | Role in Group report | Allowed School(s) | May export? |
|---|---|---|---|
| `________________` | Group Head Finance / HQ Accountant / School viewer | `________________` | Yes / No |
| `________________` | Group Head Finance / HQ Accountant / School viewer | `________________` | Yes / No |
| `________________` | Group Head Finance / HQ Accountant / School viewer | `________________` | Yes / No |

No person is assigned in production during Phase 1. This sheet only defines
future local synthetic and later production configuration.

### C. Initial account catalog (for later phases, not Phase 1 postings)

| Account name | Currency | Ownership: HQ / School / Shared | Home School | Custodian | Group report visibility |
|---|---|---|---|---|---|
| `________________` | MMK/CNY/USD | `________________` | `________________` | `________________` | `________________` |
| `________________` | MMK/CNY/USD | `________________` | `________________` | `________________` | `________________` |

### D. Reporting and control policy

| Decision | Recommended Phase 1 default | Confirmed value |
|---|---|---|
| Group operating reports | Income/Expense/Net only from Goal 3 sources | `________________` |
| Internal transfers | separate neutral funding metric, excluded from operating result | `________________` |
| Outstanding fees | hide until complete Group receivable definition/permission approved | `________________` |
| Cash closing | status/exception only, no adjustment posting | `________________` |
| Bank reconciliation | status/exception only, no statement adjustment posting | `________________` |
| Multi-currency | original amounts + immutable MMK snapshots; missing data visible | `________________` |
| Export retention/recipients | explicit Group-report capability only | `________________` |

## 6. Test and release gates

| Gate | Required proof | Production action? |
|---|---|---|
| Local schema | central additive migration on disposable DB; tenant schema/data hash unchanged | No |
| Scope security | explicit scope success; raw/forged school/account/tenant and revoked scope rejected | No |
| Ledger correctness | mapping/identity/parity, no duplicates, transfer neutrality, pending omission | No |
| Report correctness | two School totals/reconciliation, partial-read visibility, category/currency exception visibility | No |
| Browser acceptance | authenticated synthetic Group QA roles and export authorization | No |
| Finance regression | existing P0–P3.2/ UAT suites remain green | No |
| Code review | authorization, connection restoration, cache keys, no write paths | No |
| Production gate | separate approved central migration, backup, canary, post-deploy read-only smoke | **Yes; separate approval only** |

## 7. Definition of Phase 1 complete

Phase 1 is complete only when current local evidence proves all of the
following:

- approved business confirmation sheet is reflected in synthetic configuration;
- Group membership and scope are additive/central and cannot cross tenant
  boundaries;
- Ledger V1 remains a read-only canonical-source projection;
- two authorized Schools aggregate correctly and an unrelated School cannot be
  observed through any route/API/export;
- all internal-transfer and handover accounting semantics remain correct;
- no money-changing source, Fund Account balance, role, or pivot has been
  modified by Group reporting;
- targeted tests, broad Finance regression, browser acceptance, diff review,
  and security review pass; and
- no Production/Staging action has occurred.

## 8. Next action after approval

After section 5 is confirmed, create a dedicated local implementation Goal:

```text
Finance Group Phase 1-A — central Group Scope schema + read-only bootstrap
```

That goal will own only Wave 0–2, run local migrations/tests, and stop before
any Group reporting UI. Phase 1-B will then implement the Ledger/reporting
read path using the verified scope foundation.

## 9. Technical implementation seam audit

This audit records the currently verified code boundaries so that Phase 1-A
can make the smallest additive change after the business sheet is approved.
It does not authorize runtime implementation before that approval.

### Central control plane

- `App\\Models\\School` is the trusted central registry for a School code,
  numeric ID, and tenant database name. Group membership must reference this
  registry; browser input must never select a database name or a connection.
- Every new Group model and migration must explicitly use the central `mysql`
  connection (or an equally explicit central repository). It must not inherit
  the request's default `school` connection.
- `staff_support_schools` is an existing tenant-local staff-support relation,
  not a Group Finance authority source. It must not be repurposed for Group
  membership, reporting, or custody.
- User IDs may be copied between the central and tenant representations during
  provisioning, but that is not a durable cross-tenant identity guarantee.
  Phase 1 must store an explicit central identity to tenant identity mapping
  and reject an absent or ambiguous mapping.

### Tenant connection lifecycle

- `InitializeTenantDatabase` establishes the session-selected tenant before
  web middleware can resolve Spatie roles. Ordinary Finance routes remain
  bound to exactly that one selected tenant.
- The existing tenant-aware password reset flow demonstrates the required
  scoped pattern for exceptional controlled switching: save the previous
  default connection and school database, switch only after trusted central
  resolution, then purge and restore both in `finally`.
- A future Group read service may use that same save/switch/read/restore
  discipline per approved School. It must never leave a tenant connection or
  loaded tenant identity active after a Group request, and a failed School
  read must be reported as partial coverage rather than silently using a
  previous School connection.

### Finance read-model composition

- `FinanceTransactionRegisterService` is already a read-only source adapter
  over successful fees, `OtherIncome`, non-deleted `Expense`, and completed
  `BankTransfer`. It is the parity reference for Ledger V1, not a mutable
  ledger table or a write-path replacement.
- Its all-account completed transfer row is neutral (`money_in = money_out =
  0`), while a selected-account view is directional. Confirmed Fund Handovers
  are represented only through their canonical completed transfer; pending
  handovers are absent. Group Ledger V1 must preserve these exact semantics.
- `FinanceAccountAccessService` and `FinanceAuthorizationService` remain the
  tenant safety authority. Group scope grants only the right to request an
  approved School projection; it must compose with, never substitute for,
  tenant-local permission, active-account, account-pivot, and custody checks.

### First implementation ownership

| Future component | Owner boundary | Explicit non-goal |
|---|---|---|
| Central Group migrations/models | `mysql`, trusted `schools` foreign keys, explicit identities/scopes | no tenant-table change, role assignment, or data import |
| Scope/bootstrap service | preview-first, central registry allowlist, idempotent configuration | no inference from role name or `staff_support_schools` |
| Ledger parity adapter | source-only reads with stable `tenant:<school>:<type>:<id>` keys | no balance recomputation, source write, or duplicate canonical row |
| Group report controller/export | separate route namespace and server-side scope enforcement | no reuse of a tenant sidebar guard as Group authorization |

### Implementation tests that must exist before UI work

1. Central Group records always write to `mysql`, even while a tenant is the
   default request connection.
2. A Group member resolves only through the central `schools` registry; a
   forged School code, ID, or database name is rejected.
3. Every temporary tenant connection is restored after success and exception.
4. An explicit central-to-tenant identity mapping is required and an
   ambiguous/missing mapping is denied.
5. Tenant Ledger V1 parity preserves current source identities, pending
   handover omission, and internal-transfer neutrality.
6. Existing P0–P3.2/UAT Finance tests remain green without any change to
   money-writing services.
