# Central Finance Fresh Start Cutover Plan

Central Finance will become the sole writer for new financial activity on an
approved per-School cutover date. School tenants remain the source of truth
for Student, Teacher, class, and academic data. Existing tenant Finance data
is retained as legacy history only: it is not imported and is never used to
derive Central opening balances.

## Per-School cutover contract

The additive `central_finance_school_cutovers` central table is the trusted,
server-enforced source of this state. It is not inferred from a route, URL,
browser session, role, or database name.

| State | Tenant Finance | Central Finance |
| --- | --- | --- |
| `legacy` | Existing writer | Read-only |
| `ready` | Existing writer while configuration is reviewed | Read-only |
| `central` | Read-only legacy history | Sole writer for new Finance records |

A School must move `legacy → ready → central`. A `central → legacy` rollback
is permitted only when that School has no Central payment, expense, other
income, internal transfer, handover, HQ funding, or Standard Ledger entry.
After the first real Central financial transaction, rollback is forbidden;
preserve the append-only Central audit/Ledger and use an approved forward fix.

The cutover guard protects tenant payment, expense, other income, Fund Account,
Bank Transfer, Fund Handover, and Finance Staff/account-assignment mutations at
the HTTP route and canonical service layers. It protects Central payment,
operating-document, reimbursement, transfer, handover, and funding services
before a financial document or Ledger write. No request can supply a tenant
database name; tenant guards derive the School solely from the authenticated
tenant user and validate it against the central School registry.

## Fresh Start procedure

1. Finance signs the School cutover date, role/scope list, Fund Account
   catalogue, currency, and opening-balance confirmation.
2. Central Super Admin configures the existing Group membership plus explicit
   Central School scope. This configuration never gives Super Admin Finance
   operating authority; Head Finance and School Accountant need their own
   Group, School, and Fund Account scopes.
3. Head Finance creates only the approved Central School Fund Accounts and
   records a signed opening baseline. Each initial baseline and each later
   adjustment stores old/new value, effective date, reason, and central actor.
   Neither writes Money In, Operating Income, Operating Expense, or a Standard
   Ledger entry.
4. Set the School to `ready`; Central workspace/reporting remains read-only,
   and tenant Finance remains the only writer.
5. At the approved cutover time, set the School to `central`. The server
   refuses this transition until active Group membership, an active audited
   Central School Fund Account, and assigned Head Finance Group/School/Fund
   Account operating authority are all present. Then verify
   Central write authorization and tenant legacy write rejection with no
   production test transaction.
6. Monitor Central Ledger, account balances, audit, and scope fingerprints.
   Each later School repeats the same independent checklist.

## Explicit exclusions

- No legacy Finance import, historical reconciliation, or opening-balance
  derivation from tenant test data.
- No tenant database consolidation or Central-to-tenant academic reverse sync.
- No automatic Fund Account, opening-balance, role, or scope creation.
- No dual write between tenant Finance and Central Finance.
