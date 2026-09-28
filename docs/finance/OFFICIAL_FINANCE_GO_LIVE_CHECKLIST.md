# Official Central Finance go-live checklist

Use this checklist once for each official School before moving it from `READY`
to `CENTRAL`. It is an operational record, not a way to create test data.

## Preconditions

- Confirm the registered canonical School Code and Finance Group membership.
- Confirm the School is active, has a named School Accountant identity, and
  has active Finance scope. Do not assign a role as part of this checklist.
- Confirm at least one Central/Group-owned, **Official** active Fund Account
  has an explicit active allocation to the School. An allocation grants
  access only; it never creates a School balance.
- Confirm the physical account owner, holder, masked identifier, custodian,
  currency, account type, and bank name (where applicable) have been reviewed
  by Head Finance.
- Record one account-level opening balance, its Yangon effective date, source
  reference, and signed reason. Opening balance is audited and is never a
  Ledger income or expense.
- Verify each School allocation and its reason. A shared account is one
  physical account and one balance; School activity remains isolated by
  `school_id` on canonical Ledger rows.
- Confirm no test master data, Fund Account, or transaction is being used as
  an Official configuration subject.

## Date and document checks

- Transaction Date is a real non-future `YYYY-MM-DD` Yangon business date.
- Front Desk collection date remains the canonical Payment and Ledger date;
  Head Finance confirmation time is an audit event only.
- Payment effective date and receipt-issued timestamp are both visible on the
  official receipt. Reprinting is read-only.
- Reconciliation/statement periods filter canonical `entry_date`, not the
  document creation or confirmation timestamp.

## Approval record

Record the following outside the application or in the approved audit reason:

| Field | Required value |
| --- | --- |
| School and canonical code |  |
| Finance Group |  |
| Fund Account code and currency |  |
| Account owner / holder / custodian |  |
| Opening balance effective date and source reference |  |
| Active allocations reviewed |  |
| School Accountant identity and scope verified |  |
| Reconciliation owner and first close date |  |
| Head Finance approval name, time, and reason |  |

## Cutover

Only after every prerequisite is independently verified, use the audited
Central Finance cutover workflow to move `LEGACY → READY → CENTRAL`. Do not
create placeholder Fund Accounts, opening balances, payments, or Ledger rows
to make readiness pass.
