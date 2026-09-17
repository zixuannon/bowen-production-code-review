# Central Chart of Accounts and Group Import V3

## Local candidate boundary

Baseline: `dc9e38d53cd0ed13741c1e24ef93f96c2cd08639`, verified against the
active immutable Production release on 2026-09-17. This change is local only.
No Production migration, mapping, classification, cutover or financial write
is authorized by this implementation task.

This remains cash-flow accounting. Asset/Liability/Equity cash movements
affect `money_in`/`money_out`, not operating profit. Income and Expense keep
their existing respective incoming/outgoing direction. Refunds and reversals
remain their existing explicit workflows. No debit/credit, journals, trial
balance, implicit FX or school-level manual balance is introduced.

## Source of truth and compatibility

- `central_finance_categories` retains every existing primary key and every
  historical document reference. New definitions use `group_id`, a null
  owning `school_id`, one manually entered textual `category_code`, one of
  five types, a name and active status. Code uniqueness is group-wide, not
  per School or per type. `0101` remains distinct from `101`.
- `central_finance_category_school_allocations` grants explicit active School
  usage without copying definitions. Runtime still requires the actor's
  existing School/Group authorization. A CoA and Fund Account must belong to
  the same Group. Principal remains read-only; School Admin receives no
  Finance privilege. Definitions and allocation changes write before/after,
  actor and reason into append-only category audits.
- Existing school-owned Central categories remain legacy compatible until a
  reviewed mapping is approved. They are not automatically merged, renamed,
  recoded or assigned to additional Schools. V3 lookup excludes unmapped
  legacy definitions and generated `CATEGORY-*` identifiers. V2 historical
  upload contracts and historical details remain readable.
- Tenant Fee Setup is a separate legacy dependency:
  `fees_class_types.finance_category_id` references tenant `finance_categories`,
  **not** Central `central_finance_categories`. This candidate does not
  reinterpret those IDs or rewrite Student Receivable, Fee or Payment
  history. Any future consolidation of that separate legacy Fee taxonomy
  requires an explicit tenant-to-Central mapping; it is not an implicit ID
  match or an automatic migration in this release.
- Fund Account IDs, ownership, allocation authorization, account opening and
  Ledger history are unchanged. `owner_holder` is descriptive free text only,
  never a scope or owner-type input. Allocation amounts remain unused.
- Changes to a posted document's Account Type are rejected. Account Code and
  Type are immutable on Central definition edits. Reversal effects are read
  from the original Ledger, not recomputed from today's master data.

## Exact Central schema steps

Only connection `mysql` (Central) changes; no tenant schema migration:

1. `2026_09_18_000001_add_central_chart_of_accounts.php`: nullable legacy School
   ownership, nullable Group FK, group/code unique constraint, explicit
   allocations with restrictive FKs and unique pair, category audit table.
2. `2026_09_18_000002_add_owner_holder_to_central_finance_fund_accounts.php`:
   nullable descriptive holder field. No automatic data backfill.

Use the exact-path `finance:migrate-central-chart-of-accounts` preflight, not
generic migrate. It is read-only without `--execute`. Any mismatch between
registry and actual columns/indexes/FKs is a zero-write stop before either
migration. Before a separately approved deployment: fresh verified backup,
schema/registry inspection, disposable MySQL rehearsal, financial and master
checksums, immutable release preparation, then separately approved exact
schema execution. Both paths must be preflighted before the first write.

MySQL DDL is not transactionally rolled back. If a partial state appears,
stop and review a forward fix. Do not mark partial tables as migrated. Do not
run broad rollback or restore into active Central/tenant databases. A code
rollback must not resume legacy Category creation on the new Central chart
without a compatibility review.

## Reviewed legacy mapping manifest (not executed)

Before converting any existing definition, collect exact category ID/UUID,
old School/type/code/name/status, Finance Group membership, all document and
classification references, target manual code/type/name, intended School
allocations, approving actor and reason. Group code collision, ambiguous
membership, duplicate names with different meaning, inactive allocation or
unknown provenance stops the mapping. Names alone never prove equivalence.

For a one-to-one approved conversion, preserve the category ID, record old
School/code as audit provenance, validate exact Group/code and allocations,
and verify Payment/Receipt/Ledger/Receivable row hashes unchanged. Multiple
legacy categories that are judged equivalent must not be merged by rewriting
historical FKs: create one reviewed future-use Central definition, retain old
IDs for history and explicitly retire old choices. There is no automatic
Production mapping or invented business code in this candidate.

## Import and balance verification

V3 uses sixteen bilingual input columns. Campus selects School Code;
allocated Account Code selects Type/Name; allocated Fund Code selects Fund
Name. Server validation independently checks all identities, authorization,
allocation, active state, date, payment method and cash direction. Currency
is taken from the authorized Fund Account, not a client formula. Summaries
or manually edited balances are never imported.

The unique physical Fund sheet shows opening once, canonical Ledger snapshot
plus unconfirmed workbook activity, and a clearly labelled projected closing.
The projection is informative only: server Preview decides duplicate/error
eligibility. By Currency has no mixed-currency grand total; By School also
includes Currency and is activity, never an account balance. Exact text
formula matching prevents `0101` and `101` balances being combined.

Preview writes audit/preview containers only. Confirm revalidates all rows,
posts through existing canonical document services in one transaction, locks
the batch/account rows, and respects unique references/idempotency. Changed
amount, code, date, method, or voided-source replay fails closed. Completed
batch resubmission is rejected without new writes. Group cash-flow import
creates Expense/Other Income and Ledger sources, not Student Payments or
Receipts.

## Local acceptance evidence

- Full regression: 146 isolated test files, 859 tests, 6,854 assertions,
  no failures. Existing PHP/PHPUnit deprecations remain.
- Disposable MySQL: exact runner dry-run, two migrations, registry/constraint
  validation, repeat idempotency, partial-state rejection and unchanged
  history checksum (1 test / 20 assertions).
- Authenticated browser: 1440/1280/390 px CoA create/edit/multi-allocation,
  holder create/edit, V3 download; no overflow/console/failed HTTP response.
- Native Microsoft Excel: synthetic workbook opened, saved, closed and
  reopened without repair. Excel-saved file reload preserves `0401` and
  `00101` as strings, 1,500 validation rules, all nine sheets and no formula
  error cells. Separate MMK/USD projected balances remain 1,900 and 23.
- Independent final review: no remaining P0/P1 money-integrity or scope issue
  in the reviewed CoA/import/operating-document paths.
