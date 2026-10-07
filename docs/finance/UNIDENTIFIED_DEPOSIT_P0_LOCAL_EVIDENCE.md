# Unidentified Deposit P0 — local verification evidence

Date: 2026-10-07. Baseline: `162375bd6038e4a8a458eaf8710c2f5a6fd819ee`.
Branch: `codex/unidentified-deposit-p0`. Local-only candidate; no push, Production
migration, deployment, real financial write or Official enablement performed.

## Verified cash/document contract

| Stage | Physical cash | School operating income | Canonical documents |
| --- | ---: | ---: | --- |
| Unknown bank receipt | +500,000 MMK | 0 | Deposit + original NULL-School Ledger |
| Tuition allocation | 0 | +400,000 MMK | Payment + Allocation + Receipt + attribution Ledger |
| Uniform allocation | 0 | +100,000 MMK | Payment + Allocation + Receipt + attribution Ledger |
| Final total | 500,000 MMK | 500,000 MMK | Two Payments/Receipts; three Ledger rows; one bank identity |

Deposit remaining: 500,000 → 100,000 → 0; status unidentified → partially_applied
→ applied. Both receivables end paid. Original cash Ledger row remains unchanged.
June 15 bank transaction date is retained on Payment/receipt/ledger business date;
actual October 7 recording and allocation timestamps remain distinct.

## Automated gates

- Focused final Finance/QA/Facade set: 203 tests / 1,207 assertions, zero failures,
  errors or skips. Includes shared bank identity, canonical settlement,
  partial/same-receivable allocations, strict retries/conflicts, pending-reserved
  amount, classification/account/currency/cutover/permission denial and QA Runs.
- Final full regression: 1,194 tests / 9,286 assertions, zero failures/errors,
  34 existing skips and zero new skips. Independent final read-only review found
  no blocking correctness/authorization/data-integrity issue; diff check passed.
  The 37-file tested implementation manifest remained unchanged after the run
  (manifest SHA-256 `c13f2a78a36570ab86e3d4752eb79758da526979b80fa999ff4e29b2ca411406`).
- Exact Central migration: 25 successful dedicated MySQL 9.6.0 checks. Empty
  apply/rollback/reapply, pre-DDL historical ambiguity refusal, preserved source
  records, three FKs, unique physical identity/origin/Payment linkage, multiple
  same-receivable allocations, used rollback refusal.
- Tests execute only against disposable SQLite or dedicated local MySQL port
  3324. Full-suite config forces local Central/tenant DB names and empty socket/
  URL. Existing PHP 8.5/PHPUnit deprecations remain; Production PHP is unchanged.
- Migration SHA-256: `0f40db2f7b23727616dd13d6a01aff51811a174dc2231c214a0d86e58a5b1f70`;
  migration bytes are unchanged since the successful rehearsal.

## Actual browser E2E

Final clean rerun: local `127.0.0.1:8095`, dedicated
`eschool_ud_browser_final` / `school_ud_browser_final`, synthetic Head Finance
and BOWEN_QA Student. Credentials and database configuration are not in this
candidate. Original user Production browser tab was not used.

- Desktop 1440px: login, create 500,000 MMK unknown-School deposit, inspect Group
  statement cash +500,000, open original Ledger/source, search Student Code,
  allocate Tuition 400,000, inspect canonical Payment and thermal Receipt.
- Original bank reference/date, student Code/GR, fee, amount and actual allocation
  timestamp verified on Receipt; no-second-bank-receipt wording present.
- Statement/Ledger/source navigation preserves authorized QA visibility; both
  original and allocation Recorded At show actual creation time, not bank date.
- Mobile 390×844: GR search, remaining Uniform receivable, allocate 100,000.
  Form stacks without clipping; document width equals viewport width (390px).
- Final Group statement: one +500,000 row and two zero-cash rows; physical
  balance stays 500,000. Local SQL independently confirms two Payments,
  Receipts, Payment Allocations and deposit allocations; three Ledger entries,
  one bank identity and School income total 500,000.
- Final browser error/warning console was empty; visited business pages rendered
  normally and final-window application log had no ERROR/CRITICAL/EMERGENCY.
  Earlier disposable setup failures and browser-discovered QA-link/date issues
  were corrected before this fresh rerun; they are not Production incidents.

Synthetic browser data is retained in the isolated local instance for inspection,
not installed as Production seed data. No claim of Production UAT is made.

## Safety review / rollout limitations

- Head Finance group authority required. No new Front Desk, School Admin,
  Principal, School Accountant or Super Admin bypass is granted.
- Shared bank identity covers normal Payment, Pending confirmation, Finance
  Import and bank Other Income; Bank Import automation remains deferred.
- Active Zixuan QA Run membership prevents historical/cross-Run reuse while
  preserving unknown financial School attribution. Official totals exclude QA.
- Allocation-specific ordinary Refund/Reversal fails closed in P0 rather than
  applying a cash-refund engine to zero-cash attribution.
- Historical missing/duplicate references can block future Production migration;
  no history repair is authorized or performed by this candidate.
- Production MariaDB preflight, verified backup, quiescent writer window, exact
  migration history/schema verification and immutable runtime verification are
  separate approval gates. Zixuan QA Run E2E + operator UAT must precede Official
  Finance enablement.
