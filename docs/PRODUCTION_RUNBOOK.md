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
