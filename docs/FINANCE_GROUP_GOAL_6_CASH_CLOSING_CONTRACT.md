# Finance Group — Goal 6: Month-End Cash Closing and Difference Contract

**Status:** Architecture and business-rule decision package only.  
**Scope:** Month-end physical cash count, variance review, approval, and an
audited cash-difference adjustment path.  
**Non-goals:** No daily-count mandate, no automatic balance overwrite, no bank
reconciliation, no runtime/schema/data change, and no production/Staging work.

## 1. Decision

Bowen starts with a **monthly cash closing** process for physical cash Fund
Accounts. It creates an immutable comparison between the system balance at a
defined cut-off and the physically counted cash.

```text
System balance at cutoff  ─┐
                           ├─ Cash Closing variance ──> review / approval
Physical count             ┘                                  |
                                                              v
                                             optional separate Cash Difference
                                             Adjustment (new canonical source)
```

The closing record itself is evidence. It must **never** directly change:

- `bank_accounts.opening_balance`;
- an existing fee, Other Income, Expense, Bank Transfer, or Fund Handover;
- a historical ledger row; or
- an account balance merely because the counted amount is different.

The existing `BankAccountBalanceAdjustment` remains only for an audited
**opening-balance correction**. It is not a substitute for day-to-day/monthly
cash variance and must not be reused for cash closing.

## 2. Scope and account eligibility

| Account type | Included in Cash Closing? | Reason |
|---|---|---|
| `cash` | Yes | Has a physical quantity that a custodian can count. |
| `bank` | No | Reconciled under the later Bank Reconciliation phase. |
| `mobile_wallet` | No in V1 | Requires provider statement/reconciliation, not physical count. |
| inactive / deleted | No new close | Historical prior closes remain readable/auditable. |
| Group HQ / Shared physical cash (future) | Yes, through Group account authority | One physical cash account is counted once, never copied into multiple schools. |

Closing operates only on active, authorized, current-school cash accounts in
the tenant phase. A later Group close must additionally satisfy Group-account
scope from Goal 2; a local role name or browser-supplied `school_id` is not
sufficient.

## 3. Closing record and immutable snapshot

Proposed tenant entities (later, additive schema):

| Entity | Essential fields | Purpose |
|---|---|---|
| `cash_closings` | id/uuid, school_id, bank_account_id, closing_date, cutoff_at, system_balance_snapshot, counted_amount, difference_amount, currency, status, counted_by, submitted_at, reviewed_by, reviewed_at, notes | One closing attempt for one cash account and cut-off. |
| `cash_closing_denominations` | closing_id, denomination, quantity, subtotal | Optional count-sheet evidence; subtotal must equal counted amount. |
| `cash_closing_attachments` | closing_id, file metadata/checksum/type/uploaded_by | Count sheet/photo/approval document, subject to upload-security policy. |
| `cash_closing_events` | closing_id, event_type, actor_id, occurred_at, reason, old/new status metadata | Append-only audit timeline. |
| `cash_difference_adjustments` | closing_id unique, source reference, direction, amount, status, proposed/approved/posted actors/times/reason | Separate financial source only after an approved variance decision. |

Suggested unique constraints:

- one non-void closing per `school_id + bank_account_id + closing_date + cutoff_at`;
- one active cash-difference adjustment per closing;
- no duplicate denomination per closing;
- attachment checksum deduplicated within a closing;
- approved/posted transition is idempotent through a stable operation key.

### Snapshot rule

`system_balance_snapshot` is calculated from the canonical account-side
Ledger V1 sources **as of `cutoff_at`**, then stored with its calculation
version and source watermark. It cannot be recalculated in-place later merely
because a backdated source was entered after the count.

The closing UI may show a live current balance for reference, but approval uses
the immutable snapshot and explains any subsequent-posting difference.

## 4. State machine

```text
DRAFT -> COUNTED -> SUBMITTED -> REVIEWED_NO_VARIANCE -> CLOSED
                           |                    \
                           |                     -> VARIANCE_APPROVED
                           |                              |
                           |                              v
                           |                    ADJUSTMENT_POSTED -> CLOSED
                           |
                           +-> RETURNED (reason) -> DRAFT

DRAFT / COUNTED / SUBMITTED -> VOID (reason, no financial posting)
```

| Status | Balance / Ledger effect | Who may act |
|---|---|---|
| `DRAFT` | none | assigned cash custodian / authorized Accountant |
| `COUNTED` | none | counter verifies total before submission |
| `SUBMITTED` | none | count locked pending reviewer decision |
| `REVIEWED_NO_VARIANCE` | none | reviewer confirms difference is zero |
| `VARIANCE_APPROVED` | none yet | designated approver accepts documented adjustment decision |
| `ADJUSTMENT_POSTED` | one separate canonical adjustment only | service/system after approval, idempotently |
| `CLOSED` | closing is immutable | no direct change; correction requires a new process |
| `RETURNED` / `VOID` | none | reviewer/admin with mandatory reason |

No state allows editing a confirmed count or financial source. If a count
sheet is wrong before posting, return it with a reason and create a new
version/attempt rather than silently replacing evidence.

## 5. Difference calculation

```text
difference_amount = counted_amount - system_balance_snapshot

difference = 0       -> no-variance review / close
difference > 0       -> physical cash surplus
difference < 0       -> physical cash shortage
```

The closing retains the signed difference exactly, with both raw counted amount
and system snapshot. It must not round away a real variance; tolerance uses
the account currency’s approved minor-unit policy.

For an MMK cash account the starting proposed tolerance is **0 MMK**: every
difference requires explanation. A non-zero tolerance requires written finance
approval and must still be recorded/displayed, never discarded.

## 6. Variance workflow and independent adjustment

### Required evidence for a non-zero variance

- reason code and explanatory note;
- counter/denomination sheet when used;
- count attachment/photo according to approved privacy/security policy;
- cash custodian identity and count time;
- independent reviewer identity and review time; and
- approval reference for any financial adjustment.

Suggested reason codes: counting error, missing receipt, pending recorded
transaction, suspected theft/loss, counterfeit/damaged cash, timing cut-off,
unknown. `unknown` cannot be directly posted without a higher approval.

### Adjustment rule

After variance approval, the system may create **one new canonical
`CASH_DIFFERENCE_ADJUSTMENT` source** linked one-to-one to the closing. It
posts an account-side movement exactly once and is visible in Ledger V1 with:

```text
source type / source id
closing id
reason code + free-text reason
counted_by / reviewed_by / approved_by / posted_by
system snapshot / physical count / signed difference
attachment references
```

It must not alter opening balance or modify any original source. Repeated HTTP
submit/retry must resolve to the same adjustment, never a second movement.

**Business accounting classification is intentionally a Human Decision:**

- Cash surplus may be recognized as a separately disclosed income/other gain;
- Cash shortage may be recognized as a separately disclosed expense/loss; or
- both may be excluded from operating KPIs and reported as an exceptional
  finance-result line until policy chooses otherwise.

Until this is signed off, V1 should allow closing/review but **must not post a
financial adjustment automatically**. This prevents an unexplained physical
count from changing reported profit/loss.

## 7. Authorization and segregation of duties

| Action | Assigned Accountant / custodian | Head Finance | School Admin | Group Head Finance (future) |
|---|---|---|---|---|
| View assigned closing | yes | all scoped school accounts | oversight only if granted | explicit Group scope |
| Create/count a closing | only assigned cash account | scoped cash account | no by default | explicit HQ/shared scope |
| Submit count | count creator | may submit only if policy allows | no | scoped HQ/shared account |
| Review zero variance | no self-review | yes | read-only | explicit group review capability |
| Approve variance adjustment | no | only when distinct from counter/reviewer per policy | no | explicit Group approval capability |
| Post approved adjustment | service under idempotent approval | no manual duplicate endpoint | no | service under approved process |
| Void/return before posting | creator/reviewer under state rule | yes with reason | no | explicit scope |

Hard rules:

1. A user cannot approve their own non-zero count adjustment.
2. Cashier/Accountant scope stays based on `bank_account_user` for tenant
   cash accounts; a forged account ID is rejected server-side.
3. School Admin oversight does not create count/review/approval authority.
4. Role/permission checks never bypass account, tenant, Group, or custody
   checks.
5. Attachments follow existing upload validation and access rules; a closing
   attachment never grants access to a Fund Account.

## 8. Timing, backdating, and late transactions

The business day/month close needs an approved timezone and cutoff, proposed
as the school’s configured local timezone and `23:59:59` on the closing date.

- Transactions posted **before** cutoff are in the system snapshot.
- Transactions created after cutoff with an economic date before cutoff are
  late/backdated exceptions, not silently injected into an already approved
  closing snapshot.
- The closing report shows late-posting exceptions for reviewer follow-up.
- A closed period does not necessarily block all entries in V1; an eventual
  period-lock policy belongs to a separate approved phase.

## 9. Ledger/report behavior

| Event | Account balance | Operating income/expense | Ledger presentation |
|---|---|---|---|
| Count with zero variance | unchanged | unchanged | audit-only closing record, no money movement |
| Count with unapproved variance | unchanged | unchanged | audit-only exception, no financial movement |
| Approved, posted surplus | account-side in once | policy-controlled, separately disclosed | `CASH_DIFFERENCE_ADJUSTMENT` |
| Approved, posted shortage | account-side out once | policy-controlled, separately disclosed | `CASH_DIFFERENCE_ADJUSTMENT` |
| Return/void | unchanged | unchanged | audit event only |

The Finance Dashboard/Group report must show closing status and unresolved
variance separately from normal operating income/expense. A cash count never
makes a Bank Transfer or Fund Handover appear twice.

## 10. Local acceptance before implementation

- cash accounts appear; bank/mobile-wallet accounts do not;
- a closing snapshot equals Ledger V1 account balance at exact cutoff;
- a late/backdated source is flagged, not silently rewriting the snapshot;
- zero-variance count closes with no financial row/balance change;
- positive/negative variance remains non-financial until approval;
- count/review/approval separation and self-approval rejection pass;
- account-scoped Accountant cannot count another account or forge its ID;
- School Admin remains oversight-only;
- a rejected/returned/void count creates no adjustment;
- approved adjustment creates exactly one canonical source and one account leg
  despite repeat confirmation requests;
- opening balance and `BankAccountBalanceAdjustment` remain untouched;
- original fee/Other Income/Expense/transfer records remain unchanged;
- attachment validation/access, soft-delete/audit, tenant isolation, and P0–P3
  Finance regression remain green;
- future Group physical cash account is counted once, not once per linked
  School.

## 11. Business sign-off required

1. Confirm monthly closing date/time and whether each campus has a distinct
   close time.
2. Confirm whether 0 MMK tolerance is accepted or set per currency/account.
3. Confirm count-sheet and photo/attachment requirements and retention.
4. Confirm reviewer/approver separation and amount thresholds.
5. Decide surplus/shortage accounting classification and whether it affects
   operating KPI, finance-result-only, or a dedicated exceptional category.
6. Confirm action for suspected loss/theft: incident workflow, investigation,
   and whether financial posting waits for conclusion.
7. Confirm whether an approved monthly close later becomes a hard period lock.

## Goal 6 acceptance result

- [x] Monthly physical cash-account closing selected; bank/mobile reconciliation
      deferred to later phase.
- [x] Immutable system snapshot/count/variance evidence model defined.
- [x] Closing cannot alter opening balance or existing financial sources.
- [x] Separate, approved, idempotent cash-difference adjustment rule defined.
- [x] Authorization, segregation, timing, Ledger/report, and tenant/Group
      boundaries defined.
- [x] Required business decisions and local acceptance plan defined.
- [x] No runtime code, schema, data, production, or Staging change made.
