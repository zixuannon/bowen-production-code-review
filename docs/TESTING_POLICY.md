# eSchool Testing Policy

## Pipeline V2 default sequence

`LOCAL → TARGETED TEST → LOCAL PLAYWRIGHT → REVIEW → PRODUCTION GATE`

1. Use local/test databases and deterministic synthetic data only.
2. Run a targeted baseline, implement, and rerun targeted PHPUnit tests.
3. Fix normal failures autonomously.
4. For UI work, run local Playwright against local Laravel and verify visible behavior plus relevant synthetic DB state.
5. Run broader regression only for shared/core changes, before a production release, or when confidence requires it.
6. Review the diff and run `git diff --check`.

Do not use staging or production as a normal test dependency. Do not repeatedly run the full suite after every tiny edit.

Use `npm run qa:local` for the guarded local PHPUnit + Playwright entry point. It accepts only localhost URLs and refuses known staging/production identities. `npm run qa:local:check` verifies the guard without starting tests.

For representative eSchool browser QA, first run `php artisan local:bowen-qa reset`. This command is fixed to the synthetic `BOWEN_QA` tenant/database, has no target override, and refuses non-local application, URL, and database identities. Local Playwright authenticates only to the resulting local `BOWEN_QA` account and saves its gitignored session state under `qa/playwright/.auth/`.

## Financial changes

Tests should cover:

- tenant isolation
- authorization
- missing/inactive/deleted Fund Account
- balance impact
- ledger impact
- report impact
- soft-deleted records
- duplicate/reference behavior
- transaction rollback
- old/new audit history
- reason/actor fields

## Browser acceptance

Browser QA should validate actual rendered behavior, not only code assumptions.

For production, default to non-mutating checks unless the user explicitly approves a specific write. Production browser access does not replace local Playwright acceptance.

## Migration changes

Before production:

- verify migration path/connection
- verify exact tenant list
- verify unrelated pending migrations
- verify partial-schema state
- rehearse or reason through rollback semantics
- verify backup and post-migration schema checks
