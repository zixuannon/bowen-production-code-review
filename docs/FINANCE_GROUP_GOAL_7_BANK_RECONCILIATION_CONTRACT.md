# Finance Group — Goal 7: Monthly Bank Reconciliation Contract

**Status:** Architecture and business-rule decision package only.  
**Scope:** Monthly reconciliation of active bank Fund Accounts against bank
statement lines, including import evidence, matching, exceptions, adjustment
approval, and audit.  
**Non-goals:** No live bank connection, no real statement import, no automatic
financial write, no runtime/schema/data change, and no production/Staging work.

## 1. Decision

Bank reconciliation is an **evidence and matching process**, not a second
ledger and not a way to overwrite Fund Account balance.

```text
Bank statement file / API extract
        -> parsed statement lines (evidence, no money write)
        -> suggested / manual links to Ledger V1 source movements
        -> matched / exception review
        -> close reconciliation
        -> only approved explicit adjustment, when appropriate
```

The current Finance transaction register/Ledger V1 remains the source view of
school transactions. The bank statement remains external evidence. A match
links the two; it does not duplicate an income, expense, transfer, handover,
or account-side balance movement.

## 2. Scope and eligibility

| Fund Account type | V1 reconciliation eligibility | Reason |
|---|---|---|
| `bank` | Yes | Bank/provider statement is an independent external record. |
| `mobile_wallet` | Later provider reconciliation profile | Similar pattern, but provider format/settlement rules differ. |
| `cash` | No | Covered by Goal 6 physical cash closing. |
| inactive/deleted | No new period | Historical reconciliation remains readable/auditable. |
| Group HQ/shared bank account (future) | Yes, only through Group account authority | One physical account/reconciliation; never duplicated per School. |

Every reconciliation begins with a server-side resolved active bank account:

```text
tenant / Group scope
AND active account
AND bank account type
AND current account authorization
AND statement owner identity verified against configured account profile
```

A browser-supplied account ID, school ID, bank name, or database name cannot
select an unrelated account or tenant.

## 3. Reconciliation record model

Proposed later additive entities. Tenant-local records cover a school-owned
bank account; Group-control-plane equivalents cover HQ/shared accounts.

| Entity | Essential fields | Rule |
|---|---|---|
| `bank_reconciliations` | id/uuid, school/group account identity, period_from/to, currency, statement_opening_balance, statement_closing_balance, system_closing_snapshot, status, prepared/reviewed/closed actor+time | One reviewed period/account reconciliation. |
| `bank_statement_imports` | reconciliation_id, file name/hash, format/profile version, imported_by, imported_at, parse status, row counts, source checksum | One immutable evidence import; file hash unique per account/profile/statement period. |
| `bank_statement_lines` | import_id, stable line key, bank transaction id/reference, booking/value date, description, debit, credit, running balance, raw normalized fields/hash, status | External evidence; never a Finance source by itself. |
| `bank_reconciliation_matches` | statement_line_id, ledger_key, matched_amount, match_type, confidence, matched_by, matched_at, notes | Link table, supports legitimate one-to-many/many-to-one allocation with totals constrained. |
| `bank_reconciliation_events` | reconciliation_id, event type, actor, time, reason, old/new metadata | Append-only workflow/audit history. |
| `bank_reconciliation_adjustment_requests` | reconciliation_id, statement line(s), proposed adjustment class/category/account/amount, status, proposer/approver/poster audit | A request; it creates no money until separately approved and posted through a canonical source workflow. |

Required uniqueness/integrity:

1. A statement line has a stable key derived from bank transaction ID when
   available, otherwise normalized account/date/amount/reference/sequence plus
   the source file hash. It is never identified by spreadsheet row number.
2. A file hash cannot be imported twice into the same account/period/profile.
3. A statement line cannot be matched above its debit/credit amount.
4. A Ledger V1 source leg cannot be matched above its eligible account-side
   amount unless an explicitly approved split/settlement rule permits it.
5. Closed reconciliation and statement lines are immutable; correction is a
   new version/reopen workflow with actor/reason, not destructive edit.
6. One posted adjustment request resolves to exactly one canonical Finance
   source and stores that source identity/idempotency key.

## 4. Import workflow — evidence before action

```text
Upload -> Parse/Validate -> Preview -> Confirm evidence import
       -> Suggest matches -> Review/resolve exceptions -> Close
```

### Preview (zero financial writes)

The preview can parse local uploaded content and show:

- chosen bank account and statement period;
- detected columns, currency, opening/closing balance, dates and row count;
- duplicate-file/duplicate-line warnings;
- invalid row list (bad date/amount/direction/currency/line identity);
- projected count of candidate matches; and
- whether the uploaded statement belongs to the configured account profile.

Preview must create **no** `Expense`, `OtherIncome`, payment, BankTransfer,
FundHandover, opening-balance adjustment, or cash-difference adjustment.

### Confirm evidence import

Confirmation stores only the verified imported statement and normalized lines
in a transaction. It does not create financial source records. Duplicate file,
duplicate line, malformed statement, wrong currency, wrong account, repeated
confirmation, or cross-school account attempt is rejected server-side.

No browser-only guard is sufficient; hash/line uniqueness and state transition
are enforced at service/database level.

## 5. Matching rules

### Candidate matching order

1. Exact bank transaction/reference ↔ Ledger source reference.
2. Exact account-side signed amount + currency + booking/value date window.
3. Exact amount + normalized counterparty/description/reference fragment.
4. Manual review queue; no automatic confirmation below the approved threshold.

Matching remains an assistive proposal until a user with the correct scope
accepts it. Auto-match policy must be explicit, deterministic, and auditable;
V1 can start with suggested-only matching.

### Match forms

| Form | Example | Constraint |
|---|---|---|
| one statement line ↔ one ledger source | Bank fee receipt/ref number aligns exactly | amounts/currency/direction match. |
| one statement line ↔ many ledger sources | Bank settlement groups several same-day fee payments | allocated sum equals statement amount. |
| many statement lines ↔ one ledger source | Bank posts partial settlements/fees separately | allocated sum cannot exceed source amount; fee is normally a separate exception. |
| no match | statement-only or ledger-only item | visible exception; no invented matching. |

Internal transfer handling is perspective-aware. A bank statement line for a
transfer into/out of the reconciled bank account may match that account-side
Ledger V1 leg. It remains `INTERNAL_TRANSFER`, with zero operating income and
expense in all reports.

Confirmed Fund Handover is matched only through its linked canonical
BankTransfer, never through both the handover and transfer.

## 6. Statuses and reconciliation completion

```text
DRAFT -> IMPORTED -> MATCHING -> REVIEW -> CLOSED
                         |           |
                         v           v
                   EXCEPTIONS   ADJUSTMENT_PENDING
                                      |
                                      v
                              ADJUSTMENT_POSTED -> REVIEW -> CLOSED

DRAFT / IMPORTED / MATCHING -> VOID (reason; evidence retained)
```

| Status | Financial effect |
|---|---|
| `DRAFT`, `IMPORTED`, `MATCHING`, `EXCEPTIONS`, `REVIEW` | no financial movement |
| `ADJUSTMENT_PENDING` | no financial movement |
| `ADJUSTMENT_POSTED` | only an explicitly approved new canonical source may have moved money |
| `CLOSED` | no new adjustment or match edit without controlled reopen/version |
| `VOID` | no financial movement; evidence/audit retained |

A reconciliation may close only if the configured policy accepts every line
as matched, explained exception, or approved pending follow-up. It may not
hide an unmatched amount by changing a Fund Account opening balance.

## 7. Difference and adjustment policy

The following are not all the same problem and must not be merged into one
generic “adjustment” button:

| Exception | Required treatment |
|---|---|
| Bank fee not yet recorded | Propose a new Expense with `BANK_CHARGES`, valid Fund Account, reference, approval, and normal Expense audit. |
| Bank interest not yet recorded | Propose new Other Income with `BANK_INTEREST`, valid Fund Account, reference, approval, and normal receipt audit. |
| Returned/failed customer payment | Future refund/void/reversal flow linked to original payment; never delete original paid-fee source. |
| Timing difference | Leave as documented outstanding/reconciling item; no financial source. |
| Amount/date/reference discrepancy | Manual investigation; do not auto-adjust. |
| Unknown bank debit/credit | Investigation/approval required; no automatic Financial source. |
| Internal transfer | Match canonical BankTransfer account-side leg; operating result stays zero. |

Any adjustment posting must:

```text
be approved under an explicit capability/threshold
AND validate active authorized Fund Account
AND use category/purpose and reference controls
AND use source-level transaction/idempotency protection
AND link back to reconciliation + statement line
AND create exactly one canonical Ledger V1 source
```

It cannot update an existing source amount/date/Fund Account, fabricate an
opening balance adjustment, or directly manipulate the calculated balance.

## 8. Authorization and segregation

| Action | Accountant with account scope | Head Finance | School Admin | Group Head Finance (future) |
|---|---|---|---|---|
| View own account reconciliation | yes | all scoped school accounts | oversight only if granted | explicit Group scope |
| Upload/preview statement | yes, assigned account only | yes in scope | no by default | explicit HQ/shared scope |
| Confirm evidence import / accept match | policy-controlled, not self-approve adjustment | yes in scope | no | explicit scope |
| Review/close | no self-review by default | yes | read-only | explicit review capability |
| Approve adjustment | no | only distinct approver and threshold | no | explicit Group capability |
| Post approved adjustment | canonical service only | no manual duplicate path | no | canonical service only |

Existing FinanceAccountAccessService account scope, tenant isolation, inactive
and soft-delete rejection, Finance permissions, and Group Scope conditions all
apply. No reconciliation link grants access to a Fund Account or school.

## 9. Statement formats, privacy, and retention

V1 begins with a versioned bank statement import profile per bank/account
format. A profile identifies expected headers, date/amount direction rules,
currency, bank transaction-id/reference columns, and checksum/parser version.

It does not execute formulas/macros or download remote files. Uploaded files
must use existing safe upload validation, be virus-scanned if that service is
available, and be access-controlled as sensitive financial evidence.

Legacy Excel mapping belongs after a stable Standard Ledger and statement
profile contract. It must be preview-only first, user-confirmed, versioned,
and unable to map raw Excel values to arbitrary tenant/account IDs.

## 10. Required local acceptance before runtime implementation

- active bank account can preview a valid synthetic statement with zero
  financial writes;
- cash/mobile/inactive/deleted/cross-school/unassigned account is rejected;
- duplicate file and duplicate statement line are rejected idempotently;
- malformed/foreign-currency/wrong-account statement is rejected;
- statement import confirmation stores evidence lines only;
- exact match, one-to-many settlement, and manual unmatched exception reconcile
  without double allocation;
- canonical direct BankTransfer and confirmed FundHandover match once only;
- pending handover has no matchable movement;
- matched internal transfer has zero operating Income/Expense impact;
- bank fee and interest adjustment require approval and create exactly one
  proper canonical Expense/OtherIncome source when posted;
- returned payment cannot delete a prior fee payment;
- repeated adjustment confirmation creates no duplicate financial row;
- Accountant cannot see/submit another account or forged account match;
- School Admin remains read-only; all reviewer/approver separation passes;
- closed period/reopen audit and Ledger/report totals reconcile;
- P0–P3 finance regression, upload-security tests, and future Group scope
  isolation remain green.

## 11. Business sign-off required

1. Confirm monthly statement cut-off date, allowed date window, and whether
   value date or booking date controls reconciliation.
2. Confirm each bank/mobile provider statement format and retention source.
3. Approve auto-match conditions or choose suggested-only V1.
4. Set adjustment approval thresholds and reviewer/approver separation.
5. Confirm bank fee/interest category and operating-report policy.
6. Confirm treatment/approval path for returned payment, chargeback, unknown
   debit, and unknown credit.
7. Confirm whether a closed reconciliation creates a hard period lock or only
   an exception report in V1.
8. Confirm who may view/download sensitive bank statement evidence.

## Goal 7 acceptance result

- [x] Bank reconciliation defined as statement evidence plus Ledger V1 match,
      not a duplicate ledger or direct balance edit.
- [x] Preview/import/match/exception/close workflow and idempotency rules
      defined.
- [x] Internal transfer and Fund Handover canonicality preserved.
- [x] Adjustment boundary uses new approved canonical sources only.
- [x] Account/tenant/Group authorization, privacy, audit, and segregation
      requirements defined.
- [x] Business sign-off and local acceptance plan defined.
- [x] No runtime code, schema, data, production, or Staging change made.
