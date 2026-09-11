# eSchool Production Runbook

## General rule

Production is a Human Gate.

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

Before creating an immutable release, run the versioned runtime-link guard. It
must resolve (not merely identify as symlinks) `.env`, `storage`, and
`public/storage` to the exact `shared_*_target` paths in
`config/production-baseline.json`. Their resolved targets must be owned by the
configured runtime user/group and be readable/writable as required;
`bootstrap/cache` and every checksum-pinned runtime asset must also pass. Do
not copy `readlink` output from the active release into a candidate. A broken,
relative-to-the-wrong-release, incorrectly owned, or unexpected target blocks
deployment before release creation and atomic switch.

Clear configuration, route, and view caches after the switch, then reload the
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

## Public upload execution boundary

Every production vhost must include
`config/nginx/eschool-upload-security.conf` inside its server block. Before a
separately approved server-config change, confirm the include uses the tracked
`location ^~ /storage/` and `location ^~ /uploads/` blocks, run `nginx -t`, and
then reload Nginx. Verify a normal uploaded document remains readable and an
uploaded `.php` probe is never dispatched to PHP-FPM. This repository change
does not itself update or reload the production vhost.

## Multi-tenant migrations

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
