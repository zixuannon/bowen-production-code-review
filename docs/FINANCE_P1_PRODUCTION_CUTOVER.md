# Finance P1 Production Cutover Package

This package is preparation only. Production schema changes and application deployment require explicit approval.

## Active production target

- Server: `43.160.241.126`
- Project: `/www/wwwroot/43.160.241.126`
- Canonical SSH alias: `eschool-prod`

`183.240.79.48` is a legacy/rollback environment only and is explicitly out of scope for this Finance P1 cutover.

## Scope

The P1 application release contains only these P1 files (plus the six migrations listed below):

- `app/Console/Commands/FinanceP1AuditSafety.php`
- `app/Http/Controllers/BankAccountController.php`
- `app/Http/Controllers/ExpenseController.php`
- `app/Http/Controllers/FeesController.php`
- `app/Models/BankAccount.php`
- `app/Models/BankAccountBalanceAdjustment.php`
- `app/Models/CompulsoryFee.php`
- `app/Models/Expense.php`
- `app/Models/ExpenseChangeLog.php`
- `app/Models/OptionalFee.php`
- `app/Services/BootstrapTableService.php`
- `app/Services/FeesPaidImportService.php`
- `app/Services/FeesPaymentService.php`
- `resources/views/expense/index.blade.php`
- `resources/views/Income/pay-compulsory.blade.php`
- `resources/views/Income/pay-optional.blade.php`

Target only these tenant databases:

- `eschool_saas_1_demo`
- `eschool_saas_15_zixuan`
- `eschool_saas_17_bahan`
- `eschool_saas_19_timecitys`
- `eschool_saas_20_`
- `eschool_saas_21_`
- `eschool_saas_31_zixuanyang`
- `eschool_saas_32_`

Target only these school migrations, in one Laravel migration invocation per tenant:

1. `2026_08_10_000001_add_soft_deletes_to_expenses`
2. `2026_08_10_000002_add_updated_by_to_expenses`
3. `2026_08_10_000003_add_soft_delete_audit_to_fee_tables`
4. `2026_08_10_000004_add_audit_fields_to_bank_accounts`
5. `2026_08_10_000005_create_expense_change_logs_table`
6. `2026_08_10_000006_create_bank_account_balance_adjustments_table`

Do not use `php artisan migrate:school`, `php artisan migrate:school:rollback`, or a directory-level school migration command: unrelated migrations are pending.

## Preflight and backup

1. Put the six migration files and `app/Console/Commands/FinanceP1AuditSafety.php` on the production release candidate, without enabling the P1 controllers/models yet.
2. Verify the release candidate syntax:

   ```bash
   php -l app/Console/Commands/FinanceP1AuditSafety.php
   php artisan finance:p1-audit-safety
   ```

   The command uses Laravel's configured `school` connection, changing the database in application configuration for each approved tenant. It does not pass database credentials to a process argument or print them.
3. Back up each tenant before any schema change. When `mysqldump` is required, create a root-owned option file such as `/root/.my-p1-backup.cnf` with `chmod 600`; place `[client]`, `user`, `password`, `host`, and `port` in that file. Do not use `-pPASSWORD`.

   ```bash
   install -d -m 700 /root/backups/finance_p1_YYYYMMDD_HHMMSS
   mysqldump --defaults-extra-file=/root/.my-p1-backup.cnf --single-transaction --routines --events --databases eschool_saas_1_demo > /root/backups/finance_p1_YYYYMMDD_HHMMSS/eschool_saas_1_demo.sql
   sha256sum /root/backups/finance_p1_YYYYMMDD_HHMMSS/eschool_saas_1_demo.sql
   ```

   Repeat the backup and checksum for all eight exact databases. Record the artifact paths and checksums in the change record. Verify free disk space before starting.

## Schema-first cutover

1. Optionally enable maintenance mode to prevent financial writes during the cutover.
2. Run the preflight status command again immediately before migration. It must report `0/6 applied` and `schema: not complete` for every tenant.
3. After backups, apply the targeted migrations:

   ```bash
   php artisan finance:p1-audit-safety --execute
   ```

   The command calls Laravel `migrate` once per tenant with exactly six absolute migration-file paths. Laravel records the six migrations in one new batch per tenant. It aborts on partial state, connection failure, incomplete schema, or a batch that is not exactly one P1 batch.
4. Confirm the command reports `6/6 applied`, the captured batch number, and no schema verification failure for every tenant. Do not deploy P1 application code unless all eight complete.
5. Deploy the P1 application files only after schema verification, run `php artisan optimize:clear`, then restore service if maintenance mode was enabled.

## Browser smoke tests

Use a non-production-value test account and do not create or delete financial records unless separately approved.

- Open Expense Management and the fee-payment pages; confirm they load.
- Open the Fund Account edit form and confirm existing account data renders.
- Confirm an expense delete prompt and each fee-payment delete prompt require a reason; cancel each prompt.
- Confirm an opening-balance edit presents the adjustment-reason field when the balance/date changes; cancel without saving.
- Check the application log for schema or SQL errors.

## Rollback and forward-fix

Before any P1 audit write, rollback is available only with the exact batch number printed by the targeted runner:

```bash
php artisan finance:p1-audit-safety --rollback-batch=THE_CAPTURED_BATCH
```

The command refuses rollback unless that batch contains exactly the six P1 migrations and it finds no P1 audit writes. It uses the same six explicit paths, so it cannot roll back unrelated migrations.

After any P1 audit write, do not roll back the schema or drop audit data. Prefer a code rollback that remains compatible with the additive schema, or a data-preserving forward fix. Stop after the first tenant failure; do not continue to later tenants until the partial state is assessed.
