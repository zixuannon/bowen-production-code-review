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

## Multi-tenant migrations

Tenant schema changes must be run against the school connection / correct tenant database.

When unrelated pending migrations exist, use targeted migration paths rather than a broad migration command.

Verify each tenant after migration.

### Zixuan Student Import V2.1 targeted runner

`student-import-v2:migrate` is verification-only by default and is limited to
the trusted Zixuan registry code `SCH202615`. The V2.1 tenant migration is
forward-only: it makes `users.email` and `users.last_name` nullable and adds
`students.notes`, without rewriting historical users or Finance records.
Before any approved execution, verify a tenant backup, the exact migration
history, nullable column state, and the `students.notes` column. Do not use a
broad tenant migrate command or run its `--execute` option without a Production
deployment/migration Human Gate.

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
`php artisan finance:migrate-p31-p32 --tenant=SCH202615 --execute`; all known
tenants require a separately approved `--execute` invocation. The runner
refuses partial, history/schema-inconsistent, or one-applied/one-absent states,
and verifies migration 000002 completely before it can run 000001. It restores
the tenant connection on success or failure. Do not add legacy fee-import or
P1/P2/P3 migrations to this runner. After any new-schema Finance activity,
prefer a forward fix rather than rollback.

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
