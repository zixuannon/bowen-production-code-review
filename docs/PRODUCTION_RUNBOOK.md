# eSchool Production Runbook

## General rule

Production is a Human Gate.

### Unidentified Deposit P0 — future gate, not deployment authorization

#### Explicit historical QA identity maintenance (separate approval)

Normal bank Payment/Other Income still require a real reference. A missing
reference must never be filled with a receipt number, random token, or another
transaction's reference. The bounded historical QA service supports only an
operator-confirmed simulated pre-QA-Run collection with a complete immutable
Pending/Payment/Allocation/Receipt/Ledger chain, trusted permanent QA registry,
explicit classification audits, no Run membership and exactly one money-in.

The maintenance procedure appends one `historical_qa_identity_reconciled`
document audit, preserving NULL references and every original financial row.
Its deterministic `historical_qa` namespace is distinct from bank evidence.
Only this independently revalidated audit permits the P0 migration's historical
inventory to accept that missing-reference source. There is no live HTTP or
ordinary bank-posting fallback, bulk QA conversion, or QA Run assignment.

Use `scripts/production/reconcile_historical_qa_identity.php` only after explicit
bounded operator approval, active Central Head Finance actor verification,
local targeted/full tests, exact database rehearsal and fresh encrypted R2E
backup with independent COS verification. An isolated reviewed maintenance
bundle may load the unchanged current release's dependencies as `www`; it must
not activate the P0 release or run migration. Supply the private exact scope,
baseline and reviewed evidence via STDIN, never in shell history or committed
Production fixtures. `--preflight` is read-only. `--execute` uses a short-lived
SERIALIZABLE connection, locks the evidence before snapshots, appends only the
audit, and compares financial/classification/Run hashes before commit. Exact
retry returns the existing audit; drift or changed content fails closed.
`--inventory` invokes only the SELECT-only candidate migration inventory and
returns counts/schema observations, not deployment approval. Separately review
Pending/Import conflicts and partial schema before declaring migration ready.

Historical QA reconciliation does not authorize the P0 migration/deployment.
Official missing references, additional unapproved historical exceptions, or
duplicate physical effects remain blockers. Never use normal Refund/Reversal
to undo zero-cash deposit attribution. Once a historical identity is recorded,
retain the audit; do not delete it to reopen identity reuse.

Reconciled local parent: `135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e`.
Only Central connection `mysql` has a new migration:
`database/migrations/2026_10_07_000001_close_unidentified_deposit_p0.php`.
No tenant migration, role expansion, fake School or financial-history repair is
part of this candidate. Never run broad `migrate` or `migrate:school` for it.

Before any separately approved rollout:

1. Verify exact candidate/remote SHA, ancestry, immutable content, fresh encrypted
   R2E backup and authoritative remote verification. Verify the Central schema,
   expected named unique index and unrelated migration state.
2. Inventory historical bank Payments, Deposits and Other Income, including
   voided/deleted history. Missing references/accounts, duplicate normalized
   account+currency+identity, inconsistent currency, or invalid origins block
   migration **before DDL**. Never invent references or repair history silently.
3. Require a controlled quiescent bank-posting window across HTTP/Queue/import
   writers throughout inventory, DDL, identity backfill and activation. MySQL/
   MariaDB DDL is not transactional; old writers must not race the identity
   backfill. Plan the exact approved execution/history recording and schema
   verification on Production MariaDB before running this Central path as
   `www` through the runtime-user wrapper. Local MySQL rehearsal is not proof
   that Production history passes this preflight.
4. Verify identity uniqueness/FKs, nullable Payment/allocation links, removed
   deposit+receivable unique index, migration history and unchanged historical
   Payment/Receipt/Receivable/Ledger/balance snapshots. Partial schema is a
   FAIL-CLOSED forward-fix situation, not permission to rerun blindly.
5. Complete immutable switch and runtime/ownership checks under the existing
   deployment contract. No old posting worker may continue after activation.
   Automatic schema rollback is forbidden once identities or new links exist;
   financial-history preservation takes priority. `down()` only permits unused
   schema. If a release must revert after use, block affected bank write paths
   and seek a reviewed forward fix; old code lacks the new identity fence.

Business contract: uniqueness is physical Fund Account + currency + normalized
reference (case/whitespace normalized; punctuation preserved). Deposit without
a bank reference requires a stable alternative identity and explanatory reason,
not a random idempotency token. Manual and bank reference identities share the
same uniqueness namespace. Normal bank Payment/Other Income still require a
reference. An operator must not re-enter the same bank fact under another
invented reference; automated bank-statement identity matching remains P1.

Existing noncanonical allocation history is preserved, not silently converted.
New allocations create canonical documents and zero-cash income Ledger entries.
Ordinary Refund/Reversal for such Payments is denied pending a separately
approved deposit-aware correction lifecycle.

After code deployment, use a separately approved **Active Zixuan QA Run** with
a clearly QA-classified Group bank account bound to that QA context. Unknown
cash keeps `school_id=NULL`; QA Run membership does not assign School revenue.
Run a synthetic full E2E, Complete/Archive it, then operator UAT. Do not enable
Official money until that gate is approved. Never use a real student's Finance
records for automated mutation QA.

### Finance Layer 4 date and official go-live gate

Before an official School moves to `CENTRAL`, use
`docs/finance/OFFICIAL_FINANCE_GO_LIVE_CHECKLIST.md`.  Confirm every
Transaction Date is a real, non-future `YYYY-MM-DD` Yangon business date.
Front Desk collection time is the canonical Payment/Ledger date; Head Finance
confirmation and official-receipt issuance are audit timestamps and must not
silently move a transaction into another accounting period.  A QA/Test Fund
Account or allocation never satisfies official cutover readiness.  Do not
create a placeholder account, opening balance, allocation, payment, or Ledger
row to pass the gate.

### Central Chart of Accounts / Group Import V3 candidate

See `docs/CENTRAL_CHART_OF_ACCOUNTS_V3_MIGRATION_PLAN.md` before preparing a
release. `finance:migrate-central-chart-of-accounts` defaults to dry-run and
allows only its two exact Central migration paths after complete registry and
schema preflight. Never replace it with broad migrate. Existing tenant Fee
categories and Finance history are not automatically converted. A reviewed
legacy mapping manifest and separate Production approval are required; the
local candidate does not authorize execution or rewrite historical foreign keys.

## Canonical production connection

- Canonical SSH alias: `eschool-prod`
- Active production server: `43.160.241.126`
- Active project: `/www/wwwroot/43.160.241.126`

`183.240.79.48` is a legacy/rollback server and is forbidden unless separately authorized. Use the `eschool-prod` SSH alias rather than a raw production IP whenever possible.

Codex may prepare, inspect read-only state, package, and verify plans autonomously, but it must stop before production mutation. All implementation, synthetic-data testing, PHPUnit, and Playwright QA happen locally first.

## Before deployment

Confirm:

- exact release diff
- exact production file set
- exact migration files
- exact affected tenants
- unrelated pending migrations
- backup capability
- disk space
- rollback/forward-fix plan
- browser smoke-test plan

Confirm local targeted tests, relevant local Playwright QA, and diff review have passed before preparing this preflight.

## Atomic release runtime refresh

Application uploads and compiled Blade views must be shared across releases.
The release runner must invoke Composer through the guarded PHP 8.3 binary;
never rely on Composer's environment-selected PHP executable.
After an atomic release switch, preserve the existing shared `public/storage`
target and use a shared writable `VIEW_COMPILED_PATH`; never point compiled
views into a release directory that will later be removed.

Root owns only privileged deployment orchestration: release directories,
symlinks, and service configuration. Composer must run with `--no-scripts` as
root. Every command that boots Laravel and can write shared runtime state—such
as `package:discover`, exact-path migration runners, cache commands, and
prewarm checks—must run through
`scripts/production/run_artisan_as_runtime_user.sh` using the configured
runtime identity (`www` in Production). Do not run `php artisan ...` directly
as root from a release or from the active symlink.

Before creating an immutable release, run the versioned runtime-link guard. It
must resolve (not merely identify as symlinks) `.env`, `storage`, and
`public/storage` to the exact `shared_*_target` paths in
`config/production-baseline.json`. Their resolved targets must be owned by the
configured runtime user/group and be readable/writable as required;
`bootstrap/cache` and every checksum-pinned runtime asset must also pass. Do
not copy `readlink` output from the active release into a candidate. A broken,
relative-to-the-wrong-release, incorrectly owned, or unexpected target blocks
deployment before release creation and atomic switch.

`scripts/production/verify_runtime_ownership.sh` is also required immediately
before activation and immediately after the switch. It is read-only and fails
closed if any root-owned entry exists under shared
`storage/framework/cache/data`, `storage/framework/views`, or
`storage/framework/sessions`, or if `www` cannot write those paths. It must
report offending paths; it must never silently repair them. A post-switch
failure restores the prior immutable symlink before application QA begins.

Run any necessary configuration, route, and view cache operations through the
runtime-user wrapper after the switch, then reload the
PHP-FPM master serving the active Nginx vhost. Determine that master/socket from
the vhost's included PHP configuration and PID file—do not assume a similarly
named virtual-host socket is the serving pool. Verify one fresh browser request
is rendered by the intended release before starting acceptance QA.

## Prohibited broad operations

Do not use:

- `git pull`
- `git reset --hard`
- `git clean -fd`
- `composer update`
- blind `php artisan migrate`
- broad tenant migration when unrelated migrations are pending
- destructive financial SQL

Production application boot now enforces this rule: direct `migrate*`,
`migrate:school`, and `migrate:school:rollback` invocations fail closed. Schema
changes may run only through a reviewed application runner whose command name,
tenant registry allowlist, and exact single-file migration paths are encoded in
`ProductionMigrationGuard`. Do not bypass that guard from an updater, restore
flow, Tinker, scheduler, or nested Artisan call.

## Zixuan incident restore and PITR gate

After the 2026-09-15 Zixuan restore incident, cleanup/cutover/go-live remain
paused. Never pipe a SQL archive directly to an active central or tenant DB,
even when its command line names a disposable target: `USE`, database-level
DDL, or qualified SQL can switch or write elsewhere. Do not repeat a full
tenant restore to investigate a data difference.

For a separately approved *disposable* restore, create a new, empty,
unregistered `eschool_incident_disposable_*` database, then run the read-only
`incident:restore-preflight /absolute/resolved/archive.sql.gz
--target=eschool_incident_disposable_*` from the reviewed release. A non-zero
exit blocks the import. It verifies the central School registry, target
existence/emptiness, and scans executable SQL (rather than quoted historical
row values) for `USE`, database DDL, binlog disabling, and explicit references
to protected databases. This preflight
does not import or authorize any SQL; raw client imports bypass it and are
prohibited until an enforced import wrapper/server permission policy is
separately approved and deployed. Compare disposable rows read-only, then
seek human approval for a minimal audited forward-fix. Never restore the
disposable result wholesale to an active tenant.

The deployed `d1061d2` scanner still rejects the latest real Zixuan archive:
the MariaDB sandbox `/*M!... */` closing delimiter crosses its chunk buffer.
The local boundary-state fix must pass a separate approved immutable release
and real-archive positive control before any routine SQL import. Laravel's
database identity currently has global `ALL PRIVILEGES`; do not treat the
read-only preflight as an enforced disposable-only importer. Any routine
restore/import tooling must use a dedicated database identity restricted to
the specific nonregistered disposable DB, require a documented human approval
reference, append a tamper-evident operation log, and reject active/registered
targets before opening the SQL stream. Root/direct SQL is break-glass only,
never a daily restore path; require separate human approval and audit.

Production MariaDB was observed with `log_bin=OFF` and `sync_binlog=0` on
2026-09-15. PITR cannot be claimed from the current logical backup. At a
separate `SERVER CONFIG CHANGE REQUIRED` gate, review off-host binlog
archival, disk growth and retention, configure a persistent log-bin path with
ROW format and crash-safe sync, restart the actual aaPanel MariaDB service in
a maintenance window, and verify `SHOW VARIABLES`/`SHOW BINARY LOGS`. Take a
new full backup *after* activation with its binlog coordinates, then rehearse
one point-in-time replay into a disposable DB. This is mandatory before the
first real Production school data. A cloud snapshot search may remain
UNVERIFIED without blocking exclusively QA/Test cleanup once the restore
guard and routine disposable-only importer are independently verified.

## Public upload execution boundary

Every production vhost must include
`config/nginx/eschool-upload-security.conf` inside its server block. Before a
separately approved server-config change, confirm the include uses the tracked
`location ^~ /storage/` and `location ^~ /uploads/` blocks, run `nginx -t`, and
then reload Nginx. Verify a normal uploaded document remains readable and an
uploaded `.php` probe is never dispatched to PHP-FPM. This repository change
does not itself update or reload the production vhost.

## Multi-tenant migrations

### P1-A optional Fee due date

`fees:due-date-schema` is the only approved Production runner for the exact
tenant migration
`2026_10_05_000001_make_fee_due_date_nullable.php`. It is read-only by default;
`--execute` discovers active Schools from the Central registry and applies only
that exact migration through Laravel's real-path migration guard. Run it from
the prepared immutable release through the `www` runtime wrapper, after a fresh
encrypted recovery set is independently verified. Preflight must report only
`eligible` or `complete`; partial or inconsistent state blocks execution.

The migration changes only `fees.due_date` nullability. It does not rewrite
Fee, Fee Assignment, Receivable, Payment, Receipt, Ledger, or QA Run data. Verify
dated values remain unchanged and the exact migration history plus nullable
column state report `complete` for every selected tenant. Once undated Fees are
created, prefer a forward fix rather than restoring `NOT NULL`.

### QA Staff classification actor attribution

`finance:migrate-classification-actors` is Central-only and read-only by
default. It requires complete existing data-isolation schema/history and
accepts only `eligible` or `complete`; partial state fails closed. Its
`--execute` form permits only
`2026_10_05_000001_add_tenant_actor_to_finance_data_classifications.php`, from
an immutable Production release through the `www` runtime wrapper and after
a fresh remotely verified encrypted backup. No tenant schema is migrated.

The extension preserves Central actor foreign keys, makes their columns
nullable for true tenant actors, and adds nullable actor scope/School/tenant
identity plus audit action. It does not backfill historical audit rows. Once
new actor attribution is used, rollback refuses to discard it; retain the
schema and forward-fix. Before use, an unused extension can roll back and
reapply, as rehearsed locally.

Staff creation writes tenant identity and qualified Central classification
metadata on the same tenant PDO transaction. Database co-location, actual
schema identity, canonical School binding, InnoDB and actor trust are checked.
Never satisfy a tenant actor foreign key by substituting a Central numeric ID.
Existing missing Staff classifications require a separately bounded audited
reconciliation; do not silently reclassify Staff during ordinary edits.

Tenant schema changes must be run against the school connection / correct tenant database.

When unrelated pending migrations exist, use targeted migration paths rather than a broad migration command.

Verify each tenant after migration.

### Zixuan Student Import V2.1 targeted runner

`student-import-v2:migrate` is verification-only by default and is limited to
the trusted Zixuan registry code `MMBOWEN01`. The V2.1 tenant migration is
forward-only: it makes `users.email` and `users.last_name` nullable and adds
`students.notes`, without rewriting historical users or Finance records.
Before any approved execution, verify a tenant backup, the exact migration
history, nullable column state, and the `students.notes` column. Do not use a
broad tenant migrate command or run its `--execute` option without a Production
deployment/migration Human Gate.

### Round 5 identity and Bank schema targeted runner

`schema:round5-integrity` is verification-only by default. It binds the seven
approved active School codes to exact tenant database names and performs a
read-only pass across every selected tenant before applying anything. The only
allowlisted paths are the tenant Student Import identity-table migration and
`2026_09_12_000001_harden_legacy_student_import_and_bank_transfer_integrity`.

The preflight must report zero duplicate Student Codes/student/user identities,
zero duplicate active non-null transfer references, zero orphan/cross-School
student/user/actor/account references, and zero invalid transfer account,
amount, School, or status rows. Any partial migration history, required-column
or index mismatch, or partially present Round 5 constraint is a forward-fix
stop. Never auto-delete or merge a conflicting Production row. `--execute`
requires a separate Production migration Human Gate, backup, reviewed data
remediation where applicable, and a fresh read-only rerun immediately before
execution.

The transfer reference unique key uses a generated active-reference column, so
cancelled/soft-deleted audit history can retain an earlier reference while two
non-deleted transfers for one School can never share it. Do not rewrite or
delete historical transfers to satisfy this constraint.

### Operational UX & Identity targeted runner

`operational-identity:migrate` is the only candidate runner for the School Code
finalization and staff-invitation token schemas. It is read-only by default,
uses the fixed seven-School Production registry, and permits only these exact
additive migrations:

- central `2026_09_11_000001_finalize_school_code_identity`
- tenant `2026_09_11_000001_create_staff_invitation_tokens_table`

The central migration verifies the legacy value belongs to the approved Zixuan
tenant, changes that one School row to canonical `MMBOWEN01`, stores
`SCH202615` only in immutable audit history, and initializes the locked
`MMBOWEN02...` sequence. It is not a bulk string replacement and the old value
is never a runtime alias. Registry and format validation happen before the
first DDL. The runner refuses registry mismatch, missing migration history, and
partial table/column/index/FK states. Apply this exact central identity migration
before any downstream runner whose fixed registry now names `MMBOWEN01`.
`--execute` is a Production schema-migration Human Gate and must follow backup
plus a captured zero-write preflight; never substitute broad `migrate` or
`migrate:school`. The canonical identity migration is forward-only; preserve its
audit history and use an audited forward fix rather than rollback.

### Bahan + Timecity canonical School Code targeted runner

`centralization:migrate-school-codes` is the only candidate runner for the
Bahan and Timecity mapping. Its default invocation is a zero-write preflight:

```sh
php artisan centralization:migrate-school-codes
```

It accepts no tenant/database input and can apply only central migration
`2026_09_14_000002_canonicalize_bahan_timecity_school_codes`. The exact audited
mapping is Bahan `SCH202616 -> MMBOWEN02` bound to
`eschool_saas_17_bahan`, and Timecity `SCH202619 -> MMBOWEN03` bound to
`eschool_saas_19_timecitys`. The old codes become history only and are not
runtime/login aliases.

Before `--execute`, require a fresh verified backup, complete identity-schema
and migration-history checks, exact School/database ownership, no canonical or
history conflict, and an eligible sequence state. Any mismatch must return a
non-zero exit with zero write. Because candidate runtime registries already use
the canonical codes, execute this exact migration from the prepared immutable
candidate release immediately before the atomic switch, then verify both rows,
both audit records, sequence `next_number >= 4`, and old-code login rejection.
Never substitute broad `migrate`, `migrate:school`, rollback, or manual SQL
replacement. Production execution and deployment are separate Human Gates.

### Central Finance canonical Fund Account authorization

Normal Central/Group Fund Account use is authorized only by the intersection
of an active account, active School allocation, active Central School scope,
active Finance Group membership/scope, and (for School staff) an active
Central-to-tenant staff identity. Every runtime check must receive the exact
transaction School ID. `central_finance_fund_account_users` is retained for
legacy compatibility/audit only: it must not grant access, satisfy Cutover
readiness, or be populated as a normal accountant-by-account matrix.

An unallocated Group Account may be visible only to an explicitly authorized
Head Finance user in the account-management control plane so that its first
allocation can be configured. It must remain unavailable to payments,
imports, transfers, handovers, operating documents, and Ledger writes until
the canonical predicate passes.

### Central Fund Account V2 targeted migration and conversion

`finance:migrate-fund-account-v2` is the only approved runner for
`2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits`.
The default invocation is read-only and must report exactly `eligible` or
`complete`:

```sh
php artisan finance:migrate-fund-account-v2
```

After a fresh verified Central backup and a separate Production migration
approval, run the exact `--execute` form only from the prepared immutable
release. The runner must reject migration-history/schema mismatch and partial
state with a non-zero exit and zero schema write. Never substitute generic
`migrate`, broad rollback, or manual SQL.

Existing Group-account conversion is a separate data Human Gate. First run
`php artisan finance:convert-fund-account-v2` without `--execute`; the
preflight targets only `B-0001` and `M-0001`, requires every historical Ledger
School to have an active allocation, and records the account IDs, opening
balances, allocation set, and Ledger checksum. Only after explicit approval
may `--execute --actor-id=<central-user-id> --reason='<audited reason>'` run from
the immutable release. It changes ownership metadata only, preserves IDs and
all Ledger/opening data, verifies the checksum in-transaction, and is
idempotent. Never convert an unreviewed code or infer missing allocations.

### Central Fund Account V2.1 legacy allocation amount cleanup

School allocation rows grant access only. The retained
`opening_allocation_amount` column is rollback compatibility, not a financial
source of truth. Application services and UI must not read or write it.

After a fresh verified Central and tenant backup, run the exact preflight from
the prepared immutable release:

```sh
php artisan finance:cleanup-fund-account-v2-1-allocations
```

Production execution is a financial-data Human Gate and requires an explicit
Head Finance actor and reason:

```sh
php artisan finance:cleanup-fund-account-v2-1-allocations \
  --actor-id=<central-user-id> \
  --reason='<reviewed audit reason>' \
  --execute
```

The command may only zero the compatibility column. It must preserve and
verify the allocation-access checksum, Fund Account opening checksum, and
canonical Ledger checksum in the same transaction, append a Fund Account
audit, and be idempotent. Do not substitute manual SQL, generic migration, or
schema removal. Remove the column only in a future release after rollback
compatibility has been retired through a separate schema Human Gate.

### Finance P2/P3 targeted runner

`finance:p2-p3-migration-safety` is the only approved runner for the P2/P3
release migrations. It has a fixed allowlist of the eight verified tenant
databases and a fixed migration-path allowlist containing only
`2026_08_11_000001_create_bank_account_user_table` and
`2026_08_12_000001_create_fund_handovers_table`.

Its default is verification-only. It refuses unknown/duplicate tenants and
partial migration state. `--execute` is still a production Human Gate. Capture
the isolated two-migration batch number per tenant; after pivot assignment or
handover activity, use a forward fix rather than schema rollback.

### Finance P3.1/P3.2 targeted runner

`finance:migrate-p31-p32` is the only candidate runner for the additive
Expense Import and Other Income schema release. Its fixed allowlist is exactly:

- `2026_08_12_000002_create_expense_import_batches_table`
- `2026_08_13_000001_create_other_incomes_table`

It accepts only trusted central-registry school codes, never raw database
names. Default invocation is read-only verification:

```sh
php artisan finance:migrate-p31-p32
```

After a new Human Gate, a canary would use
`php artisan finance:migrate-p31-p32 --tenant=MMBOWEN01 --execute`; all known
tenants require a separately approved `--execute` invocation. The runner
refuses partial, history/schema-inconsistent, or one-applied/one-absent states,
and verifies migration 000002 completely before it can run 000001. It restores
the tenant connection on success or failure. Do not add legacy fee-import or
P1/P2/P3 migrations to this runner. After any new-schema Finance activity,
prefer a forward fix rather than rollback.

### Round 4 financial currency history targeted runner

`finance:migrate-currency-history` is the only approved runner for
`2026_09_11_000001_add_financial_currency_history_integrity`. Its default mode
is read-only verification. It accepts only the seven fixed active-School codes,
excludes Demo and raw database names, rejects any partial schema/history state,
and restores the tenant connection after success or failure.

Before any separately approved Production migration, run the read-only command
from an immutable release and confirm every tenant is `eligible` or `complete`:

```sh
php artisan finance:migrate-currency-history
```

After backup and a Production migration Human Gate, execute Zixuan canary alone
with `--tenant=MMBOWEN01 --execute`, verify migration history, columns, foreign
keys, application health, and zero unexpected financial writes, then execute
exactly the remaining allowlisted School codes. Never use generic `migrate`,
`migrate:school`, restore/updater migration paths, or a raw tenant database
name. Once payment FX snapshots or receivable history uses the new columns,
prefer a forward fix and do not drop the history table.

## Safe cutover principle

When new application code requires new columns/tables, prefer:

1. maintenance mode if needed
2. backup
3. add/run compatible schema changes
4. verify schema
5. deploy new application code
6. lint/cache clear
7. restore service
8. browser smoke test

Old code should be able to tolerate the additive schema during the short cutover.

## Rollback

Before any new-schema production writes, schema rollback may be possible if actual migration batches support it.

After production writes use new audit columns/tables, prefer forward-fix/code rollback without dropping audit data unless data-preserving rollback is proven safe.

## Secrets

Never print, paste, log, or put database passwords into shell process arguments.

Prefer application connections or secure temporary client option files with restrictive permissions when command-line clients are unavoidable.

## Archived staging state — no active action

`staging.school.mmbowen.com` is paused and is not part of the active Pipeline V2. Do not delete, deploy to, authenticate to, or modify it without separate authorization.

Historical staging-only changes retained for a future cleanup/reuse decision include its isolated project/database/runtime, dedicated staging access/error logs, a BT-WAF per-site policy with overseas blocking disabled for the staging hostname only, and synthetic FINANCE_QA fixtures. None of these changes alter the production vhost, production databases, or global WAF policy.

## Zixuan QA Finance Run schema and operations

The QA Run candidate adds Central-only tables through the exact-path
`finance:qa-runs-migrate` command. In Production, run it from the staged
immutable candidate release before switching the active symlink. Use the
root-owned `scripts/production/run_guarded_qa_run_migration.sh` entry point;
do not invoke the Artisan command directly in Production. With `--execute`,
the wrapper asks for an explicit `YES` before release verification, runtime
checks, database connection, or schema preflight. A rejected answer exits
without Git commands or database access.

After confirmation, the deployment identity runs
`verify_qa_run_release_identity.sh` and verifies real Git state: staged HEAD,
remote ref, ancestry from the exact active baseline and previous QA Run
candidate, marker, manifest, candidate SHA, and baseline contract. It requires
HEAD = remote SHA = marker SHA = manifest SHA. No per-release or wildcard
`safe.directory` entry is used. Git repository operations belong to the
deployment actor; the `www` runtime actor never runs Git.

The Git diff check continues to cover tracked application and release source.
It excludes only `.env`, `bootstrap/cache/**`, `public/storage`, and
`storage/**`, which the release builder intentionally links or regenerates.
The exact shared-link targets and runtime ownership/read/write requirements
for those paths are checked independently by the runtime-link and runtime
release guards before migration or activation.

The wrapper then verifies application readability, Laravel/PHP bootstrap,
active/staged symlink state, shared runtime links, and writable runtime
directories as `www`. Artisan checks the root-owned immutable marker and
manifest, exact baseline, and a matching deployment SHA attestation from a
root `runuser` parent before connecting to Central. It verifies the fixed
Central database and that the active immutable release is exactly
`b87bac3a2bc6eaf32cada9cdbfa19c565d5e61b2`, the candidate marker/manifest,
and the exact
`7d6e73c12f6de23c24e5dd62312df53fcef8d497` release contract. The global
migration guard permits only the pinned SHA-256 of
`2026_10_05_000001_create_central_finance_qa_runs.php` (currently
`74e22c730e31468ef4047188c4e20b054f92ca2e1a63929ad00c37e0bdbbdc79`) through
this runner.
After the Central target is validated, the runner verifies the exact migration
file identity/path, the pinned allowlist/hash guard, and only then the schema
state. It rechecks the active/candidate marker and manifest immediately before
execution. After all checks pass, it invokes Laravel's nested
exact-path `migrate` command with `--force`; nested Artisan cannot complete its
own interactive prompt. Laravel's global migration guard repeats the
allowlist/hash check at execution. Run the post-migration read-only preflight
and repeat execution while the approved baseline is still active; both must
report `complete` before atomically switching to the prepared candidate. A
denied confirmation must not connect to the migration database or inspect
migration state. Production execution still requires a separately approved
exact candidate and a fresh remotely verified encrypted backup. No tenant
migration is part of this schema. QA Run archive changes lifecycle only and
must preserve all Student and Finance history. Never reset or delete Payments,
Receipts, Ledger, or prior Run records to prepare another test.
