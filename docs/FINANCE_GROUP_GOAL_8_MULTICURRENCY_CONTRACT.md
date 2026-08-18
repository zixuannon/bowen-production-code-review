# Finance Group — Goal 8: Multi-Currency Finance Contract

**Status:** Architecture and business-rule decision package only.  
**Scope:** MMK, CNY, and USD Fund Accounts, transaction currency snapshots,
exchange-rate governance, foreign exchange, Ledger V1, and Group reporting.  
**Non-goals:** No exchange-rate data entry, no historical rewrite, no
cross-currency transfer implementation, no schema/runtime/data change, and no
production/Staging work.

## 1. Decision

Bowen Group uses **MMK as reporting currency** and supports MMK, CNY, and USD
as transaction/account currencies.

Every physical Fund Account has **exactly one native currency**. A balance is
meaningful only in that native currency.

```text
KBZ Main MMK       currency = MMK   balance = MMK only
HQ Cash CNY        currency = CNY   balance = CNY only
USD Bank           currency = USD   balance = USD only

Never: "Main Cash" balance mixing MMK + CNY + USD
```

The MMK equivalent is a reporting snapshot, not the physical account balance.
It must never be recalculated for an existing transaction using today’s rate.

## 2. Existing-state characterization

| Existing component | Current support | Limitation / decision |
|---|---|---|
| `BankAccount.currency` | Accepts `MMK`, `USD`, `CNY` | Correct account-level base; no complete FX/currency-conversion workflow. |
| `fees_paids` | Has `transaction_currency`, `original_amount`, `exchange_rate_snapshot`, `amount_mmk` | Payment snapshot is available, but it is not yet a full Group ledger/account-currency contract. |
| `expenses` | Has analogous original/rate/MMK columns | Same partial base; source validation/report parity requires a dedicated phase. |
| Fee definitions / class types | Carry fee currency/original/rate/MMK fields | Defines receivable pricing, distinct from actual collection/account currency. |
| `OtherIncome` | Current valid receipt/Fund Account/payment method/reference fields | Does not yet carry the complete currency snapshot fields. |
| `BankTransfer` / `FundHandover` | Current service rejects transfers where source/destination account currencies differ | Safe same-currency V1 behavior; no approved FX conversion source. |
| Finance Transactions / reports | Mostly use current source amounts/MMK fallbacks | Needs Ledger V1 normalized currency fields and explicit reporting/rate policy. |

Conclusion: the repository contains useful multi-currency fields for fees and
expenses, but **does not yet have a complete multi-currency accounting system**.
No historical row is to be silently “corrected” based on a current rate.

## 3. Canonical currency data contract

Every future money-changing Ledger V1 source must expose:

| Field | Rule |
|---|---|
| `account_currency` | Native currency of the affected Fund Account. Required for account-side movement. |
| `original_currency` | Currency in which the source amount was actually received/paid. For a direct account movement, normally equals account currency. |
| `original_amount` | Immutable amount in `original_currency`; precision follows currency policy. |
| `reporting_currency` | `MMK` for Bowen Group V1. |
| `exchange_rate_snapshot` | Approved rate from original currency to MMK at posting; MMK = `1`. |
| `reporting_amount_mmk` | Immutable rounded result of original amount × snapshot rate, with documented rounding. |
| `rate_source` | Approved internal table, bank settlement, or documented manual source. |
| `rate_effective_at` | Date/time that identifies the approved rate. |
| `rate_approved_by` | Required when the policy requires approval/manual override. |
| `currency_conversion_id` | Null for same-currency source; links an approved conversion event when money moves across account currencies. |

For a normal direct payment or expense, `original_currency` must equal the
Fund Account native currency. If cash is received in CNY but is deposited into
an MMK account after conversion, it is **not** one simple MMK receipt with a
client-provided rate. It needs a CNY receipt/cash account plus a separately
audited conversion to MMK, or an approved documented settlement workflow.

## 4. Exchange-rate governance

Proposed central Group-control-plane table, to be implemented later:

| Entity | Essential fields | Rule |
|---|---|---|
| `finance_exchange_rates` | group_id, base_currency=`MMK`, quote_currency, rate, effective_from/to, source_type, source_reference, status, entered_by, approved_by, timestamps | One approved, effective rate per Group/pair/time window; no client-provided raw rate authority. |
| `finance_exchange_rate_events` | rate_id, event type, actor, time, old/new data, reason | Append-only audit for draft/approve/supersede/retire. |

Rules:

1. Rates use a clear convention: `1 foreign unit × rate = MMK equivalent`.
2. MMK rate is fixed at `1` and is never edited.
3. A draft rate cannot be used for financial posting.
4. Published rate updates affect **new** postings only. Existing source
   snapshots remain immutable.
5. Backdated rate changes require explicit approval and cannot rewrite
   historical posted `reporting_amount_mmk`.
6. Manual override requires reason, source/reference, capability, and—above
   the agreed threshold—second approval.
7. A rate is selected server-side from trusted Group/rate configuration; a
   browser may display it but cannot authoritatively choose another Group rate.

## 5. Posting rules by source

| Source | Native account balance | Reporting amount | Rule |
|---|---|---|---|
| Student Fee / Optional Fee | Increase selected same-currency Fund Account | immutable MMK snapshot | Fee currency/receipt currency/account currency relationship must be explicit; no hidden conversion. |
| Other Income | Increase selected same-currency Fund Account | immutable MMK snapshot | Add full currency fields before enabling foreign receipt. |
| Expense | Decrease selected same-currency Fund Account | immutable MMK snapshot | Add server-side account-currency and rate validation; no arbitrary client rate. |
| Same-currency BankTransfer | Out/in the same native currency | neutral internal transfer, reporting amount snapshot | Current safe behavior remains. |
| Confirmed Handover | Same as linked canonical BankTransfer | neutral internal transfer | No separate handover currency row. |
| Cross-currency conversion | Decrease source native currency; increase destination native currency | uses approved FX conversion snapshots | New canonical conversion event only; never a normal direct BankTransfer. |
| Bank interest / fee | Increase/decrease account native currency | snapshot | Added via approved reconciliation adjustment source. |

## 6. Cross-currency conversion

V1 does **not** allow a normal BankTransfer/Handover from MMK account to CNY or
USD account. Current same-currency rejection remains correct.

A later explicit `FX_CONVERSION` canonical source must contain:

```text
source account + source original amount/currency
destination account + destination original amount/currency
bank/market rate snapshot
settlement fees (if any)
approved rate source and approver
two account legs
separate FX gain/loss or rounding line when accounting policy requires it
```

The conversion is internally neutral in **cash flow** but may create an
explicit, separately reported FX gain/loss in the reporting-currency financial
result. It must not be hidden inside Other Income, Expense, or a generic
internal transfer.

No conversion may confirm if accounts are inactive/deleted/out of scope, the
rate is unapproved/expired, the source balance is insufficient, or the two
legs do not reconcile under the chosen rate/rounding policy.

## 7. Ledger and reporting presentation

Ledger V1 shows each source in its original/native currency and the immutable
MMK report value:

```text
2026-08-18 | USD Bank | Other Income | USD 1,500.00
Rate: 1 USD = 3,500 MMK | Reporting MMK: 5,250,000.00
```

Required report rules:

1. Fund Account balance reports aggregate only within each account currency;
   they must never sum CNY + USD + MMK into a fake native balance.
2. Group operating income/expense/net can be consolidated in MMK from source
   snapshots only, with a currency breakdown alongside it.
3. Internal transfers remain excluded from operating income/expense regardless
   of currency. FX gain/loss, once approved, is disclosed separately according
   to the business accounting policy.
4. Reports display rate/missing-snapshot exceptions. A missing rate cannot
   quietly show `0 MMK` as a valid consolidated total.
5. Historical reports use the source posting snapshot; management may view a
   separate “today-rate valuation” only as a non-accounting analytics view.
6. Excel export uses `ledger_key`, source fields, original and reporting
   amounts, rate/snapshot/source metadata—never display row number as identity.

## 8. Rounding and precision

| Currency | Proposed storage/display policy |
|---|---|
| MMK | 2 decimal storage for compatibility; display/business rounding policy to be confirmed (commonly no displayed fraction). |
| CNY | 2 decimal places. |
| USD | 2 decimal places. |
| Exchange rate | At least 6 decimal places in future rate table; existing 4-decimal snapshot fields may be insufficient for final policy. |

Rounding occurs once at an explicitly documented stage. The source amount,
rate, unrounded calculation (where retained), rounded reporting amount, and
rounding difference must remain auditable. A rounding difference is never
silently absorbed by Fund Account balance.

## 9. Security and tenant/Group boundary

- Existing Fund Account authorization remains server-side; a Cashier/Accountant
  can use only assigned active accounts of their selected tenant.
- Currency does not bypass account scope: knowing a USD account ID does not
  grant its use or visibility.
- Group rate selection requires trusted Group membership plus explicit
  capability; local role names do not create cross-school rate/account access.
- A forged currency/rate/original amount is revalidated against selected
  account, approved rate, source amount, and transaction type before posting.
- A rate update or FX conversion cannot change previously posted Fee, Expense,
  Other Income, BankTransfer, or Handover values.
- No background job may use request-global tenant context for a queued rate or
  posting action; it must resolve the trusted Group and target account afresh.

## 10. Compatibility and rollout

1. **Inventory first:** report completeness of existing currency/snapshot
   fields per source. Classify older data as `MMK_ASSUMED_LEGACY` only where
   existing migration/data explicitly supports that statement; otherwise mark
   `CURRENCY_UNVERIFIED_LEGACY`.
2. **Do not backfill by today’s rate.** Preserve existing values and expose
   missing/uncertain data in reports.
3. **Normalize new sources:** first add/enforce full snapshots for Other Income
   and unified Ledger adapter; then validate Fees/Expense parity and source
   server-side calculations.
4. **Rate governance:** build central approved rate configuration and audit
   before enabling foreign-currency entry/override.
5. **Same-currency transfers stay unchanged.** Add `FX_CONVERSION` only after
   Ledger, rate governance, fee/expense/interest policy, and reconciliation
   requirements are accepted.
6. **Group consolidation last:** read-only MMK report with original-currency
   breakdown; no multi-school financial write in the initial release.

## 11. Required local acceptance before implementation

- MMK/CNY/USD account each accepts only a matching-currency direct movement;
- a forged account/currency/rate is rejected server-side;
- rate chosen by approved Group rule is snapshot on a new source and cannot be
  changed by later rate edits;
- same-currency transfer and confirmed Handover remain internal with zero
  operating income/expense;
- cross-currency direct BankTransfer/Handover remains rejected;
- approved FX conversion creates exactly two currency account legs plus any
  explicit approved FX line once, and retry is idempotent;
- Fees, Other Income, Expense, bank interest/fee, import, and Ledger rows
  retain original amount/currency/rate/MMK reporting value correctly;
- foreign-currency account balances do not mix in a native-currency total;
- Group consolidated MMK report reconciles from snapshots and exposes missing
  snapshot exceptions;
- Cashier account scope, tenant isolation, soft-delete/inactive rejection,
  reference/import protection, and P0–P3 Finance regressions remain green.

## 12. Business sign-off required

1. Confirm MMK is the official Group reporting currency and the display
   rounding policy.
2. Approve the exchange-rate source hierarchy (bank settlement, published
   internal daily rate, manual exception) and rate publication owner.
3. Confirm rate approval thresholds, override rights, and backdating policy.
4. Confirm whether foreign payment must enter a same-currency account before
   conversion or whether documented bank settlement may be modeled directly.
5. Confirm FX gain/loss classification and whether it affects operating KPI or
   is shown as a separate finance-result line.
6. Confirm supported currencies beyond MMK/CNY/USD, if any.
7. Confirm historical-data policy for missing/uncertain currency snapshots.

## Goal 8 acceptance result

- [x] One-native-currency-per-Fund-Account rule selected.
- [x] MMK reporting snapshot, immutable rate governance, and source contract
      defined.
- [x] Existing partial fees/expense multi-currency support characterized
      without claiming full completion.
- [x] Same-currency transfer boundary retained; explicit FX conversion model
      defined for later phase.
- [x] Ledger/report, rounding, security, compatibility, and Group boundaries
      defined.
- [x] Business sign-off and local acceptance plan defined.
- [x] No runtime code, schema, data, production, or Staging change made.
