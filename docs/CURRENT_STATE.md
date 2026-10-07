# eSchool Current State

## Latest — P0 COS HEAD deployment blocker corrected locally (2026-10-07)

- Approved candidate `d5f86b86cb3d4106f47e7041b36a1411eefabe8e` was pushed
  exactly to `origin/codex/unidentified-deposit-p0-reconciled`. Fresh recovery
  `eschool-prod-20261007T070528Z-135341bf2f9e` covers Central + eight tenants,
  shared storage, recovery configuration and encrypted manifest (12 artifacts).
  An immutable d5f86b86 release was prepared but **never activated**.
- Its gate stopped before the Finance write window, migration or runtime
  lifecycle: COSCLI `stat --disable-log` exits zero but suppresses all HEAD
  metadata. The collector correctly refused empty evidence, but misdiagnosed it
  as a size mismatch. Independent stat returned matching sizes for all objects;
  every encrypted local checksum passed. No backup corruption was found.
- Local-only correction preserves captured stat output, isolated credentials /
  temporary logs, clean environment and exact object target. Empty, duplicate,
  malformed, zero or mismatching length and nonzero exit remain fail-closed.
  No application/PHP/schema/migration/business behavior changes.
- **24 Python tests PASS**, including a real local child-process reproduction of
  exit-zero/empty output. The corrected `verify_backup` function was separately
  streamed for read-only verification (not installed) against the existing COS
  recovery set: **12 objects / 9 databases PASS**. No upload, policy change,
  Finance write-window action or migration was performed. Independent review
  found no blockers. Final PHP regression: **1,291 tests / 10,404 assertions**,
  zero failures/errors; the exact 34 baseline skips are unchanged. Existing
  PHP/PHPUnit deprecations remain. Migration bytes are unchanged from rehearsal.
- Production remains `135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e`. Historical
  Payment/Receipt/Ledger/Fund Account fingerprints and two failed jobs were
  unchanged at the stopped rollout. The prior exact-SHA approval does not cover
  this new local correction: freeze a new candidate and request approval again.
- Official Finance enablement and Zixuan QA Run UAT remain **NO**. Browser 419
  is a separate, unresolved login/session issue, not the COS failure; no password
  reset or global session/cache clear was attempted.

## Latest — P0 stale-runtime cleanup and final gate validation (2026-10-07)

- Under explicit bounded lifecycle approval, rediscovered the old September 7
  e904db9 Artisan service and independent sandbox FPM. Confirmed Production DB
  identity, no active HTTP/FastCGI clients, no sandbox workers, zero database
  transactions, zero queued jobs, and no log activity since September 7.
- At **06:44:05–06:44:09 UTC**, stopped only HTTP child 2799088 with SIGINT;
  Artisan parent 2799084 exited. Sandbox master 2797859 exited with SIGQUIT.
  No SIGTERM/SIGKILL, formal-service restart, server config change, database
  write, migration or deployment. The old port and sandbox socket disappeared.
- All **51 Central Finance table** counts/content fingerprints remained equal;
  queue stayed empty and both historical failed jobs stayed unchanged. Current
  Production remains `135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e`.
- Read-only P0 process/launcher inventory now passes: zero extra eSchool PHP
  runtimes, normal FPM master 2507293 and 11 children accounted for, Queue
  2058057 and WebSocket 2058061 unchanged. Nginx/MySQL/Redis and R2E remain
  healthy; three /login GETs returned 200, no new Laravel log entries or HTTP
  500 during the observation window. No historical failed job was replayed.
- Independent final review identified a **local** OPEN-gate compatibility
  issue: non-dumpable Linux FPM children deny www access to /proc exe/cwd.
  Consumer now uses existing fresh root-attested FPM paths, retains live birth
  identity and validates child-to-master linkage; CLI workers still require
  direct paths/command checks. Actual read-only www /proc validation passed for
  all 11 Production children. No server permissions/configuration were changed.
- Exact migration bytes unchanged. Focused release-gate checks pass **7 tests /
  47 assertions**; Python **19 tests PASS**; isolated real PHP 8.3 FPM switch /
  reload rehearsal passes again. Exact-runner disposable MySQL rehearsal passes
  all **37 checks**, including mid-DDL and failed-activation closure.
- Final full regression: **1,291 tests / 10,404 assertions**, zero failures/errors;
  exact 34 prior skips unchanged. Initial default 128MB CLI run exhausted memory;
  successful rerun used a process-only 1GB limit (peak 301MB), no config change.
  Existing PHP/PHPUnit deprecations remain reported. Independent review has no
  remaining blockers. Approved-scope local candidate freeze is ready; no push.
- **ENGINEERING PASS YES; read-only runtime readiness PASS; READY FOR PRODUCTION
  DEPLOYMENT APPROVAL YES. Production migration/deployment remain NOT RUN.**
  A future rollout still requires explicit full-SHA approval, fresh encrypted
  backup/COS verification and repeat live gates. See cleanup evidence/runbook;
  this task does not authorize write-window installation or Production DDL.

## Earlier — P0 migration gate implemented locally; Production runtime blocker (2026-10-07)

- Follow-up: old processes remain ad-hoc root-session services, with a loopback
  built-in server on 18081 and independent sandbox FPM socket; no matching
  launcher/proxy references were found in inspected config trees. No currently
  connected clients observed, but stopping them still requires explicit approval.
  Normal PHP 8.3 master is separate and healthy despite SysV `MainPID=0`.
  Corrected only the local collector's exact PID-file identity validation;
  operational tests now **19 PASS**, isolated PHP 8.3 FPM rehearsal passes again.
  Prior 1,290-test PHP regression remains applicable to unchanged PHP/migration
  code. Production SHA remains exact; no server lifecycle/config action occurred.

- Read-only recheck: active symlink, marker and Git remain exact
  `135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e`, release
  `eschool-rc-135341bf2f9e-consolidated`. Parent P0 candidate remains
  `0798e13b3ba94fac16229ccc057d5031ce698ac1`. Historical Payment #37 audit is
  still present once; original NULL reference, 50,000 payment, 100,000 receivable
  paid and 200,000 account balance match accepted evidence. No P0 schema/history
  exists on Production. No Production data/configuration/process was changed.
- Local dedicated `finance:unidentified-deposit-p0-migrate` runner pins the
  exact Central migration and unchanged SHA-256 `8cb9cc668a4ad17ad4c7543564beba3b21f9d6d6ad583ce4acb3f18e1ea4c432`.
  Tenant migrations NONE; strict guard/schema/history/identity states and a
  durable pre/post financial preservation receipt are required.
- Native temporary write window: exact 59-table / 177-trigger InnoDB fence,
  current locking reads, metadata-lock draining and advisory serialization.
  One atomic durable latch opens writers only after exact candidate/runtime,
  complete schema, original preservation receipt and empty queue verify.
  Partial DDL, failed activation and stale/missing receipts retain closure.
  Dormant triggers remain after OPEN; no sequential-drop partial reopening.
- Root-owned deployment evidence independently validates immutable release,
  remote SHA, fresh encrypted recovery set + COS HEAD, trusted active tenant
  coverage, actual worker commands/backend, extra PHP processes and real FPM
  response. Existing deployment/scheduler locks stay held by a live root
  issuer through the www-only Artisan action. No global cache clearing or
  ownership repair is used.
- Final full regression: **1,290 tests / 10,395 assertions, zero failures/errors**.
  Exact 34 existing skips are unchanged; zero new skips. Final targeted classes
  within that run: **186 tests / 1,495 assertions**. Native write-fence tests:
  **15 / 712**; migration runner tests: **37 / 83**; guard/release tests: **23 / 81**.
  Python operational contracts: **15 PASS**, including producer-to-PHP evidence.
  Existing PHP 8.5 / PHPUnit deprecation warnings remain, not hidden.
- Exact-runner disposable MySQL rehearsal: **37 checks PASS**, including
  historical identity, real mid-DDL constraint collision, closed failure state,
  preserved original rows, durable receipt failures, retry no-op and atomic OPEN.
  Only Linux/root deployment evidence is simulated there. Separate real local
  PHP **8.3.32 FPM** rehearsal passes old-runtime rejection, activation failure,
  atomic switch, graceful reload and fresh candidate response. Only its own
  disposable process is stopped; no Production FPM action occurred.
- **Deployment/final-freeze HOLD:** read-only investigation found old eSchool
  built-in PHP processes 2799084/2799088 and sandbox FPM 2797859 referencing the
  e904db9 release; the old environment's DB host/name/connection match current
  Production. No process was stopped. These cannot be presumed isolated tests.
  The new gate intentionally denies them. Separate lifecycle approval and a
  fresh identity check are required; historical PIDs are not future kill targets.
- Therefore ENGINEERING PASS YES, local gate/rehearsal PASS, actual Production
  migration readiness NO, READY FOR DEPLOYMENT APPROVAL NO. No new final commit
  was frozen because the requested all-gates condition is unmet. Changes remain
  reviewable in the existing isolated P0 worktree; no push/deploy/Official enable.
- Runbook: [exact migration and write window](UNIDENTIFIED_DEPOSIT_P0_MIGRATION_GATE.md).
  Evidence/follow-up: [old runtime blocker](UNIDENTIFIED_DEPOSIT_P0_RUNTIME_BLOCKER.md).
  Backlog: root Laravel scheduler still uses generic PHP resolving to 8.1; this
  was recorded, not repaired outside this task's approved scope.

## Latest — Historical QA identity reconciled; P0 data preflight CLEAN (2026-10-07)

- Exact Production parent remains `135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e`.
  Accepted P0 is reconciled locally on this parent, retaining P1-A, QA Runs,
  permanent QA School isolation and guarded release infrastructure.
- Operator explicitly confirmed the bounded historical collection was simulated
  QA and authorized a real Central Head Finance audit actor. After fresh encrypted
  Central + eight-tenant R2E recovery and 12 independent COS HEAD/checksum checks,
  the reviewed isolated maintenance bundle appended one historical identity audit.
  No financial row, original NULL reference, classification or Run membership was
  changed; all ten before/after financial/classification/Run hashes matched.
- The exception requires trusted QA registry, audited classification, pre-Run
  source evidence, no Run membership, one proven money-in and explicit bounded
  approval. It is not bank evidence or a fallback for normal bank posting.
  Official missing-reference transactions remain denied.
- Post-maintenance exact SELECT-only migration inventory recognizes two bank
  reference identities and one historical QA identity. No duplicate identity,
  unresolved Pending/Import/Other Income conflict or partial P0 schema remains.
  The raw cash Pending account difference is expected: destination was selected
  at cash confirmation, not a second physical bank receipt.
- Full regression: **1,232 tests / 9,562 assertions, zero failures/errors**;
  the exact 34 existing skips are unchanged. Historical + bank identity target:
  **46 tests / 272 assertions**. Disposable MySQL exact maintenance preflight,
  execute, idempotent retry, read-only verification and migration checks pass;
  eight historical checks and 25 generic migration checks pass.
- Reconciled-tree browser smoke passes at 1440px and 390px: deposit list,
  Student Code/GR search, allocation controls, canonical receipts and Group
  statement. Original synthetic 500,000 cash with 400,000 + 100,000 zero-cash
  allocations remains intact. No browser settlement was submitted. Existing
  full accepted P0 E2E evidence is retained; no executable live-posting behavior
  changed in the historical identity seam.
- Production migration, P0 activation and push remain prohibited until separate
  exact-candidate approval. Data-preflight CLEAN does not authorize a deployment
  or bypass the migration guard. Final rollout must still define the exact
  guarded runner/history recording and quiescent bank-writer window.
- Deposit-allocation ordinary Refund/Reversal remains intentionally denied;
  zero-cash attribution correction is not part of this scope.
- Local fixture limitation/backlog: direct legacy dashboard rendering assumes
  every synthetic School has an administrator; the retained test fixture has a
  NULL administrator and raises a null relation error there. P0 Finance pages
  pass with the correct synthetic Head Finance session. No unrelated dashboard
  code or Production data was changed to mask this fixture issue.

## Earlier — Unidentified Deposit P0 reconciliation BLOCKED (2026-10-07)

- Actual Production Git HEAD and active symlink were read-only verified as
  `135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e`, release
  `eschool-rc-135341bf2f9e-consolidated`. No Production changes were made.
- Accepted prior candidate `abcd66e0d262daa5a3fd9b5a1daba537cd1c20d5`
  remains frozen. Its changes are reapplied without commit on isolated branch
  `codex/unidentified-deposit-p0-reconciled` at the exact current Production
  base. The sole conflict was this historical status document; both histories
  are retained. P1-A code/migration/guard changes remain in the base.
- Production preflight classification: **HISTORICAL DATA RECONCILIATION
  REQUIRED**. Four Payments exist, three bank-scoped; one bank Payment has a
  NULL reference. There are zero duplicate nonblank physical identities or
  duplicate origins. The exact P0 migration refuses this missing reference
  before any DDL. No reference was invented or financial history repaired.
- Three Pending Collections are confirmed and linked to existing Payments;
  two are bank-scoped and one has a missing reference. One linked Pending/
  Payment identity comparison differs; its exact business explanation has not
  been established. These linked records are not independent money-in facts.
- Deposits, Deposit Allocations, Other Income, all inspected Import tables:
  zero records. No new P0 columns/table/history are present; the expected old
  allocation unique index remains. No partially applied P0 schema detected.
- Per the explicit stop gate, no post-reconciliation test suite, migration
  rehearsal, browser smoke, final commit, push, or deployment was performed.
  Prior local engineering/E2E evidence is historical, not a PASS for this
  uncommitted reconciled tree. See the read-only preflight evidence document.
- Allocation-linked ordinary Refund/Reversal must continue to fail closed;
  no zero-cash attribution correction engine is approved in this task.

## Latest — P1-A reconciled after Zixuan QA Run Production PASS (2026-10-07)

- Read-only Production release marker and Git HEAD both match
  `162375bd6038e4a8a458eaf8710c2f5a6fd819ee`. This is the QA Run release and
  the exact parent of the P1-A reconciliation branch. The frozen accepted
  P1-A source remains `6d58da9b4a257fe0bcbef8c2e7075bef177bf353`; the frozen
  source branch was left untouched.
- Reconciliation is isolated on `codex/p1a-post-qa-run`. The accepted P1-A
  commit range reapplied without conflicts. QA Run app, central migration,
  release scripts and migration guards remain from the exact Production base.
  Shared QA fixture edits were already present at that base. Only the release
  contract's accepted baseline is advanced to the exact current Production
  SHA; the QA Run migration's prior one-time deployment identity remains
  historical and unchanged. No QA Run migration is being rerun.
- Conflict audit found no application or migration-path conflict. P1-A and QA
  Run Chinese locale additions both remain, and all QA Run runtime/release
  files are byte-identical to the exact Production baseline.
- Merged targeted regression passes **99 tests / 363 assertions**. Full
  regression passes **1,125 tests / 8,886 assertions, zero failures, zero
  errors, 34 existing skips**, with one existing PHP 8.5 PDO deprecation and
  no new skips.
- P1-A exact tenant migration rehearsal on the disposable localhost fixture
  passed eligible → applied → complete. BOWEN_QA read-only due-date preflight
  reports complete; QA Run read-only migration preflight reports
  `central=complete`. No Production migration was run.
- Focused BOWEN_QA desktop browser smoke passes **1/1**: dated save/reload and
  clear/reload both verified. It created no Payment; fixture and temporary
  permission were removed, and the canonical School mapping remained intact.
- The first deployment attempt stopped before migration or activation: the
  P1-A command and exact migration were missing from
  `ProductionMigrationGuard::RUNNER_PATHS`. The active Production release
  remains at the exact baseline above; no schema or Finance business data was
  changed.
- A fresh encrypted R2E recovery set,
  `eschool-prod-20261007T043049Z-162375bd6038`, completed before the attempt.
  All 12 encrypted artifact checksums and independent COS HEAD checks passed.
  The original accepted candidate remains staged but inactive.
- The local correction adds only `fees:due-date-schema` →
  `2026_10_05_000001_make_fee_due_date_nullable.php` to the exact-path
  production allowlist, with positive and negative guard coverage. The
  targeted suite passes **116 tests / 406 assertions**; the full suite passes
  **1,126 tests / 8,890 assertions, zero failures/errors, 34 existing skips**.
  Production-mode local BOWEN_QA rehearsal passes eligible → applied →
  complete against the disposable localhost fixture.
- A new candidate SHA has been frozen locally for review. It has not been pushed,
  staged, migrated, or deployed. The previous inactive staged candidate is
  not approved for activation. No P1-B work has started.

## Unidentified Deposit P0 — prior local closure candidate (2026-10-07)

- Exact audited Production parent: `162375bd6038e4a8a458eaf8710c2f5a6fd819ee`,
  release `eschool-rc-162375bd6038-zixuan-qa-run-active`. Work is isolated on
  `codex/unidentified-deposit-p0`; Production has not been modified.
- Unknown-School bank receipt now retains the original cash fact. Allocation
  reuses the canonical Payment writer, producing Payment Allocation, Receipt,
  and append-only School income attribution with zero additional physical
  cash movement. Partial and repeated allocations to one receivable are
  supported; exact retries return the original result, changed content fails.
- A shared physical bank identity fence covers Deposit, normal Payment,
  Pending confirmation, Finance Import and bank Other Income. Missing bank
  reference requires an explicit stable manual identity and reason for Deposit;
  bank Payment/Income without a reference fail closed. This does not implement
  automated Bank Import matching or claim that arbitrary operator-entered
  references can identify the same bank transaction without bank evidence.
- Pending reservations, account availability, Central cutover, currencies,
  Group/School authority, and QA/Official isolation remain server enforced.
  Permanent Zixuan QA deposits require an Active QA Run and cannot be allocated
  across Runs; original cash still has no School revenue attribution.
- Group statements retain NULL-School bank facts. Deposit allocation income
  appears separately with zero cash. Bank date remains the original date;
  Recorded/Allocated timestamps are actual system times. Canonical receipt
  identifies the original bank receipt rather than implying a second receipt.
- Final disposable browser rerun completed at 1440px and 390px: create 500,000,
  allocate 400,000 Tuition then 100,000 Uniform, inspect Payment/Receipt/Ledger
  and Group statement. Physical balance stays 500,000; final graph has two
  Payments, two Receipts, two Payment Allocations, three Ledger entries and one
  physical bank identity. QA links and actual Recorded At display were fixed
  and reverified. No Production browser mutation or real School fixture used.
- Focused final regression: 203 tests / 1,207 assertions, zero errors/failures/
  skips. Final full regression: 1,194 tests / 9,286 assertions, zero errors or
  failures, 34 existing skips and zero new skips. All 37 implementation/test
  file hashes stayed unchanged through the final run; independent safety review
  found no blocking issue. Only documentation was updated after these gates.
  Exact Central migration rehearsal on dedicated local MySQL 9.6.0 passed 25
  checks (apply/unused rollback/reapply, pre-DDL ambiguity rejection, financial
  history preservation, FKs/uniqueness, used rollback refusal). This is not a
  Production MariaDB migration execution or approval.
- Deposit-linked ordinary Refund/Reversal is explicitly denied until a
  deposit-aware correction contract is separately approved. Advanced matching,
  correction UI, aging, attachments and investigation workflow remain P1/P2.
- Next gate: approve the frozen full SHA separately, verified backup and exact
  Central migration preflight, immutable deployment, then Zixuan QA Run E2E,
  Complete/Archive and operator UAT. Official Finance enablement is NOT approved.
  See `docs/finance/UNIDENTIFIED_DEPOSIT_P0_LOCAL_EVIDENCE.md` and the runbook.

The entries below are historical and do not supersede this task's verified parent.

## Zixuan QA Run — immutable-release trust-boundary repair (historical attempt, 2026-10-06)

- Active Production remains `b87bac3a2bc6eaf32cada9cdbfa19c565d5e61b2`.
  Candidate `e9d0be2efe82bd0882377799d17ea9fb5d6efbfe` is pushed and staged,
  but not activated. Its guarded migration has not executed; schema remains
  eligible. No QA Run #001 or Finance business record was created.
- Fresh encrypted recovery set `eschool-prod-20261006T102819Z-b87bac3a2bc6`
  covers Central and eight trusted tenants. R2E completed successfully; all 12
  encrypted artifact checksums and post-upload COS HEAD checks passed.
- Root cause: immutable release and Git metadata are deployment-owned
  `root:root`, while Artisan runs as `www`. `MigrateCentralFinanceQaRuns`
  incorrectly invoked Git from the runtime identity. Production Git is
  2.34.1; `www`'s `git -c safe.directory=<release> ... rev-parse HEAD` still
  fails with dubious ownership. The deployment identity can read the exact
  same staged HEAD successfully.
- A bounded successor moves Git HEAD, remote-ref, ancestry, marker, and
  manifest verification to a root deployment wrapper. Production confirmation
  comes first; rejected confirmation exits before Git or database work. The
  wrapper verifies runtime readability and PHP bootstrap as `www`; the Artisan
  command now checks root-owned marker/manifest plus an attestation from a root
  `runuser` parent and does not invoke Git. No `safe.directory`, wildcard,
  permission weakening, or `/etc/gitconfig` change is part of the fix.
- The two-release synthetic trust-boundary rehearsal passes, including wrong
  SHA/ref/manifest/ancestry, tracked tampering, symlink, unreadable runtime
  files, and confirmation cancellation. Targeted regression passes (36 tests /
  260 assertions); full local PHPUnit passes (1,116 tests / 8,839 assertions,
  34 existing skips). Exact Central migration apply/rollback/reapply and
  partial-schema fail-closed rehearsal pass on the dedicated disposable MySQL
  fixture at port 3317. Existing Run #1/#2 desktop/mobile browser E2E remains
  applicable because this repair changes only migration/deployment trust
  boundaries; it changes no Finance or UI workflow.
- The first successor's staged preflight exposed expected generated runtime
  paths (`.env`, `bootstrap/cache/**`, `public/storage`, and `storage/**`) in
  the Git worktree diff even though their independent runtime-link checks had
  passed. The deployment verifier now excludes only those exact paths and
  keeps all other tracked source covered. Both synthetic releases test those
  generated paths plus rejection of tampered tracked application source; the
  corrected verifier also passes read-only against the preserved staged
  release. The corrective successor is being finalized before migration.
- Production remains on b87; no migration, activation, service reload, Finance
  write, role change, or QA Run creation has occurred in this attempt. Local
  final release verification is next.

## Zixuan QA Run — full regression fixture recovery complete

- Candidate branch `codex/zixuan-qa-run-final-candidate` is based on exact
  Production SHA `7d6e73c12f6de23c24e5dd62312df53fcef8d497`; the Staff
  Invitation/canonical School identity changes remain preserved.
  `UserService`, `StaffInvitationService`, application role definitions, and
  permission assignments were not changed.
- Paired full regression used the same normalized schema and fixture snapshot
  on the exact Production baseline and candidate. Initial comparison had 28
  shared failures: 2 identical and 26 same-test/different fixture-dependent
  text; no baseline-only or candidate-only failure. Causes were invalid Student
  fixtures missing required Guardian IDs, stale/default academic-year IDs,
  hard-coded cross-school year IDs, and obsolete denied-request redirect
  expectations. Test fixtures were corrected without weakening assertions.
- Exact Production baseline: **1,098 tests / 8,648 assertions, 0 failures,
  0 errors, 34 existing skips**. Candidate: **1,109 tests / 8,702 assertions,
  0 failures, 0 errors, 34 existing skips**. No skips were added.
- Focused QA Run, guarded migration, and runtime ownership suites: **27 tests /
  105 assertions PASS**. Exact-path migration runner allowed only the pinned
  Central migration; complete schema was a no-op. Wrong baseline, wrong
  tenant, unrelated migration, and partial state were rejected by tests.
- Disposable local regression fixture preparation is fail-closed to MySQL
  `127.0.0.1:3317`, datadir `/private/tmp/qa-run-regression-mysql`, Central
  `eschool_testing`, and tenant `school_testing`. It adds only missing local
  compatibility columns and normalizes the test academic year; it is not a
  migration and cannot target Production.
- Previously verified full Run #1 and Run #2 Finance E2E evidence remains valid:
  distinct fresh records through Payment, Receipt, Ledger, Complete, and Archive;
  no record reuse; archived Run #1 history unchanged; Official totals unchanged.
  This recovery changed test fixtures only. A local browser smoke additionally
  confirmed QA ONLY and archived Run #1/#2 details are readable and read-only
  at desktop and 390 × 844 mobile viewport, without horizontal overflow.
- Production migration, data, users, roles, permissions, and deployment were not
  changed. The candidate is frozen locally only; any Production gate is separate.

Last updated: 2026-10-06

## Zixuan permanent QA Finance Runs — reconciled local candidate

- Reconciled directly onto current Production SHA
  `7d6e73c12f6de23c24e5dd62312df53fcef8d497`. Overlap was limited to
  `CentralFinanceDataIsolationService`, `zh-cn.json`, and shared status/runbook
  documentation; no textual conflict remained. Production canonical School
  identity, Staff Invitation, tenant actor attribution/audit, QA Staff
  classification inheritance, and User #77 recovery implementation are kept.
  QA Run adds no changes to `UserService` or `StaffInvitationService`.
- `MMBOWEN01` resolves through the trusted Central School Registry mapping and
  remains permanently `qa_test`; records cannot be promoted into Official
  totals. Historical Zixuan QA data remains readable and is not backfilled into
  a Run. Membership is immutable; mixed-run Finance composition is rejected.
- Run lifecycle remains Preparing → Active → Completed → Archived. Completion
  freezes new business writes; archive retains Students, Finance history, and
  audits. Run #1 and Run #2 completed the existing Finance E2E with distinct
  records; Run #1 history remains unchanged and Run #2 records were not reused.
- Targeted reconciliation suite: **130 tests / 842 assertions, zero failures
  or errors**. Full PHPUnit on this tree: **1,106 tests / 8,683 assertions,
  zero failures or errors, 34 existing skips**. PHP 8.5 PDO and PHPUnit
  deprecations remain.
- Central-only migration rehearsal passed on a fresh disposable clone. The
  exact additive migration applied, rolled back, and reapplied; every
  pre-existing Central table row count matched its source, and the tenant
  migration ledger checksum was unchanged. No tenant migration or historical
  Finance rewrite occurred.
- Browser acceptance passed at 1440 × 900 and 390 × 844. QA ONLY, Run #1/#2
  history and details, archived read-only state, and Official data scope were
  verified with no 403/404/500 responses, console errors, or mobile overflow.
  The local-only synthetic registry/image test overrides were removed before
  freezing the candidate.
- Production code, data, migrations, and deployment were not changed.

Last updated: 2026-10-06

## P1-A optional Fee due date — accepted source candidate, previously passed gates

- This frozen source was originally based on
  `7d6e73c12f6de23c24e5dd62312df53fcef8d497`. It is now being reconciled onto
  `162375bd6038e4a8a458eaf8710c2f5a6fd819ee` after QA Run reached Production
  PASS; see the current entry above for this reconciliation's gates.
- Before fixture repair, the identical normalized snapshot produced 1,098
  baseline tests / 8,556 assertions / 9 errors / 20 failures and 1,104
  candidate tests / 8,580 assertions / 9 errors / 20 failures. Failure identity
  comparison was exact: 9 errors and 20 failures were present on both revisions;
  there were no baseline-only or candidate-only failures. This accounts for
  the 28 reported fee/import errors and failures; the clean paired run also
  surfaced the same fixed-ID collision in `InitializeTenantDatabaseTest` on
  both revisions.
- Root cause was invalid legacy test setup, not P1-A behavior. The rebuilt
  tenant schema requires a Guardian FK but import helpers omitted it while
  using `INSERT IGNORE`, so their Student rows were silently absent. The test
  tenant's year ID 1 also held a different default year than the import fixture
  expected. Cross-school payments reused hard-coded year IDs, and tenant-login
  setup reused a user ID already present in the synthetic tenant. Two denied
  HTTP tests expected redirects while the current route returns 403.
- Test-only repairs now create a valid Guardian identity, seed the exact default
  test year in a guarded localhost-only fixture preparer, give cross-school
  tests their own SessionYear, select that year's actual ID, and allocate a
  tenant-login ID free in both databases. Permission tests assert the current
  403 denial. No business validation was removed, no assertion was weakened,
  and no skips were added.
- Three new direct coverage tests verify same-Fee NULL → date → NULL
  persistence and UI-format accessor behavior, no reminder/notification or
  epoch fallback for a NULL overall due date, and Fee Setup preview/confirm
  snapshots for both undated and dated Fees remaining unchanged after later
  Fee-master edits. No product code changed and no product defect was found.
- New direct coverage plus the affected Fee/Reminder/Assignment set passes:
  38 tests / 206 assertions. Complete candidate regression passes **1,107
  tests / 8,695 assertions**, zero failures, zero errors, 34 existing skips,
  and one PHP 8.5 PDO deprecation. No new skips were introduced. The first
  post-coverage run used a stale test snapshot whose year ID 1 was `Bowen QA
  2026`; after restoring the guarded fixture, the clean full run passed and
  retained the expected `2025-2026` year row.
- Focused authenticated local desktop browser E2E passes (1/1): edit a NULL
  Fee, select 31-12-2026 through the date picker, save, reload and verify the
  exact date; clear, save, reload and verify blank/SQL NULL. No Payment was
  created. No executable application files changed, so mobile E2E was not
  required.
- Current read-only `fees:due-date-schema --tenant=BOWEN_QA` preflight reports
  `complete`. The exact-path migration rehearsal previously passed and the
  migration/runner sources are unchanged by this test-only repair. No schema
  changed in this regression pass.
- The final coverage additions and status update are frozen as a local-only
  candidate. Production deployment remains unperformed.

## QA Staff classification and tenant actor audit — 2026-10-05 LOCAL GATES PASS

- MariaDB metadata follow-up: runner accepts the exact unquoted `NULL` default token only when the live connection identifies MariaDB. Quoted/lowercase/padded/other defaults remain rejected. Migration bytes and application/browser behavior are unchanged. Targeted metadata + disposable MySQL rehearsal: 21 tests/64 assertions PASS; independent review PASS; complete isolated regression: **1098 tests/8648 assertions, zero failures, 34 existing skips**. Prior QA/Official browser E2E evidence remains applicable because only CLI schema verification changed. Production remains `b420e2192c124760759784fb2bece654aed3b857`; ownership guard PASS. Next gate must read the already-applied migration as complete without executing it again.

- **Production gate HOLD after migration (latest):** frozen/pushed candidate `9788ad1148a5b213b309f1a54c0cd0c8d65ea4bf` on `origin/codex/qa-staff-classification-audit`; remote exact match. Fresh R2E set `eschool-prod-20261005T105002Z-b420e2192c12` covers Central + 8 tenants, encryption/COS HEAD verification PASS. Prepared immutable release `eschool-rc-9788ad1148a5-qa-staff-classification` passed content/manifest/runtime-link/ownership guards. Active symlink was NOT switched and remains `eschool-rc-b420e2192c12-staff-invitation`.
- Exact Central migration preflight returned eligible; execution applied migration `2026_10_05_000001_add_tenant_actor_to_finance_data_classifications` (history id77, batch50), but its post-check rejected MariaDB metadata. Read-only diagnosis: MariaDB10.11.10 reports SQL NULL column defaults as string `"NULL"`; runner's strict PHP-null comparison misclassifies otherwise expected columns. All expected nullable fields/unsigned types/foreign keys exist; new actor attribution rows remain0. No schema rollback, rerun migration or code workaround performed. Local MySQL rehearsal did not cover this MariaDB metadata representation.
- 58 protected Central Finance/School/role + tenant User/Staff/role table snapshots and legacy historical audit digests exactly unchanged after migration; User77 untouched. Existing data-isolation runner, ownership guard and post-failure health checked read-only. No staff reconciliation, no new audit rows for targets, no invitation/token action, no FPM/Queue/WebSocket restart. Production schema DID change; Production Finance/user/business records did NOT change.
- Required follow-up: minimal runner metadata normalization with MariaDB regression/rehearsal, new immutable candidate, then verify existing migration as complete WITHOUT reapplying. Continue deployment/reconciliation/resend only after explicit stop condition is resolved and all gates pass. Do not report Production PASS. This post-deployment note is uncommitted documentation only; pushed candidate bytes remain unchanged.

- Latest resume: added the missing Chinese rejection message. Translation contract 8 tests/2089 assertions PASS. Complete isolated regression **1085 tests / 8635 assertions / zero failures / 34 existing skips**; existing PHP8.5 PDO deprecation remains. No new skips.
- Exact migration MySQL rehearsal rerun: **8 tests / 51 assertions PASS**, including preserved history, Central/tenant actor support, rollback refusal after attribution is used, unused rollback and reapply. Migration bytes unchanged.
- Real localhost browser E2E passed separately for QA and Official synthetic School modes. Each created Principal and School Accountant, verified visibility after reload and duplicate-email rejection with no browser console/page/404/500 errors. Post-browser DB assertions verified one User/Staff/role/invitation per target, exact tenant actor attribution for QA and no QA metadata for Official. Array mail only; both sets cleaned with metadata cleanup + guarded local reset + verify-clean PASS.
- Independent rereview passed. No application behavior edits since full suite; only this status update and the already-tested translation. Candidate remains based on Production `b420e2192c124760759784fb2bece654aed3b857`, freshly read-only reverified. Production Staff browser session available; User #77 not yet reconciled. Freeze/push/backup/exact migration/immutable release and bounded reconciliation/resend are explicitly authorized by the current task, but no Production PASS is claimed until those gates finish.
- Engineering/local E2E gates are PASS. Production deployment, User #77 recovery and single invitation remain pending. The below entries preserve earlier gate failures and repair history, not the latest status.

### Earlier gate history

- Resume outcome (2026-10-05): missing local runtime directories repaired; localhost `/login` now 200. Read-only Production SHA reverified unchanged at `b420e2192c124760759784fb2bece654aed3b857`. Import classification now handles existing User/new Staff profiles with behavioral batch-rollback coverage; Central provisioning rejects non-QA canonical Zixuan and mismatched School identities before writes. Central reclassification replaces stale tenant attribution without rewriting historical audit. Independent rereview found no remaining blocker in these changes.
- Resume targeted evidence: first subset 99 tests/684 assertions; classification/reconciliation 29/316; imports plus contracts/classification 46/447; Central provisioning 21/113; Central actor compatibility + isolation 9/55. These runs overlap and must not be added together. Migration source is unchanged from the passed rehearsal.
- Full regression on forced localhost:3317 config: **1085 tests / 8604 assertions / 1 failure / 34 existing skips**. Exact new failure: `CentralFinanceLocalizationContractTest::test_every_ascii_central_finance_translation_source_has_a_zh_cn_value` requires zh-cn for `The selected School Staff identity does not belong to this School.` introduced by the new binding guard. Per explicit new-full-regression-failure stop gate, stop here; translation has NOT been patched and browser E2E has NOT been retried. Next bounded repair is add the localized message, rerun full regression, then QA/Official browser E2E. No implementation skip was introduced.
- Safety: initial suite launch was blocked pending proof of DB isolation. Temporary config `/private/tmp/qa-staff-phpunit-isolated.xml` forces localhost port3317, test DB names, empty socket/URL; instance port/datadir verified. Only legacy hardcoded3306 opt-in rehearsal remained unenabled, matching existing suite skip behavior. No shared3306 DB write occurred. Local fixture metadata cleanup/verify-clean passed; temporary public/storage link restored; app and dedicated MySQL stopped. No commit/push/Production backup/migration/deployment/reconciliation/invitation this resume.

- Isolated branch `codex/qa-staff-classification-audit` starts from read-only verified Production SHA `b420e2192c124760759784fb2bece654aed3b857`, release `eschool-rc-b420e2192c12-staff-invitation`. Local implementation is uncommitted and incomplete; no push or Production action occurred.
- Draft work adds tenant-aware classification actor metadata and an exact Central migration runner, transactional QA inheritance for supported Staff creation paths, and targeted tests/browser fixtures. Historical audit rows are not backfilled. Initial School Admin provisioning has no Staff profile at this baseline; generic Staff UI must not gain School Admin role assignment merely to satisfy a test.
- Read-only canonical MMBOWEN01 audit found five Staff-linked users: #23/Staff #3 active and unclassified; #57/Staff #24 active qa_test; #73/Staff #25 active qa_test; #76/Staff #26 inactive/soft-deleted and unclassified; #77/Staff #27 active Principal and unclassified. Exact prospective missing set is #23/#76/#77, not executed. User #77 identity, role, password and School remain unchanged.
- Recorded targeted runs passed: classification 27 tests/294 assertions; tenant creation contracts 23/109; Central identity integration 26/132; migration/guard subset 21/89 plus isolated MySQL migration 8/51. These are separate intermediate runs, not a combined final-candidate/full-regression claim. Final integration review and full regression remain outstanding.
- Migration rehearsal passed on disposable MySQL with historical compatibility, rollback/reapply and preserved Central foreign keys. Production migration/backup/preflight have NOT run.
- Browser E2E failed at globalSetup GET `/login` with HTTP 500 before Staff creation. Local worktree runtime directories `storage/framework/views` and `storage/framework/sessions` were missing; Laravel reported `InvalidArgumentException: Please provide a valid cache path.` at `Illuminate/View/Compilers/Compiler.php:67`. This is an isolated test-runtime setup failure, not evidence of a Production failure. Per the explicit browser-failure stop gate, no further implementation or deployment was attempted.
- Browser-created Staff count was zero. Disposable classification metadata cleanup and `verify-clean` passed. The localhost:8327 app server and dedicated localhost:3317 MySQL instance were stopped; local diagnostic files remain retained. No real mail transport was used.
- Resume requires correcting isolated local runtime setup and completing targeted/full/browser gates, including the Official path. Browser harness uses `QA_STAFF_MODE=qa|official`. Do not freeze/push/reconcile/resend until all required gates pass. Current status: ENGINEERING PASS NO; E2E PASS NO; PRODUCTION PASS NO (not executed); USER #77 RECOVERY PASS NO.

## Staff invitation canonical School identity — local hotfix candidate

- Base lineage: `5f28eb3b828be98c6a4d6c1f644b31c731ff620b`.
  The accepted Zixuan diagnosis found invitation generation failed before
  SMTP/Queue: `UserService::replaceStaffPlaceholders()` read a tenant-local
  School replica/actor relation, then `StaffInvitationService::createUrl()`
  re-resolved that derived code and conflicted with the saved School ID.
- Invitation identity now starts with persisted `users.school_id`, resolves
  the active/installed Central School registry by ID, and validates the
  trusted request School, configured/live tenant connection, and the actual
  tenant user ID/School/email before creating any token. Missing/inactive or
  mismatched identities fail closed. No School-code allowlist is introduced.
- Caller audit: Staff/Teacher/DriverHelper registration and synchronous
  Staff/Teacher imports use the normal trusted request path through
  `UserService`; Finance Staff onboarding uses the same guard through `send()`.
  School provisioning and the existing School Admin update/resend caller use
  an explicit canonical-ID scope which restores connections on success/error.
  These are not new resend routes or new permissions. No generic Principal
  resend endpoint is added; eventual recovery must target the existing user
  through a separately approved invocation after deployment.
- Tenant-local invitation tokens remain one-time, 24-hour, and separate from
  ordinary password reset tokens; a newly issued invitation invalidates the
  previous one. Test transport verifies recipient/template/canonical URL and
  exactly one generated message for Principal, Front Desk, School Accountant,
  legacy Cashier, and School Admin. Existing password/role behavior is unchanged.
- Targeted invitation/canonical/password/controller regression: 49 tests /
  309 assertions, zero failures. Independent read-only review found no blockers.
- Full local regression: 1,016 tests / 8,047 assertions, zero failures,
  34 existing skips. PHP 8.5 PDO and PHPUnit configuration deprecations remain
  pre-existing. The full suite needs more than the CLI default 128 MB memory;
  its successful run used `php -d memory_limit=1G vendor/bin/phpunit`.
- Real disposable browser E2E: School Admin creates one Principal through
  `/staff` with a deliberately stale tenant School code; creation returns
  success without an invitation warning. A duplicate email submit is rejected.
  Verification finds exactly one active tenant user, Staff profile, role and
  invitation token; zero browser console/page/404/500 errors. Mail is array-only,
  not SMTP. `local:bowen-qa reset` then removed the test account/token and
  restored the tenant replica. Reproducible guarded harness:
  `qa/playwright/local/staff-invitation-fixture.php` and `staff-invitation.spec.cjs`.
- No migration, Finance, role, School Code/GR redesign, or Production change.
  Production User #77 is untouched and no Production invitation was resent.
  Freeze the tested local candidate, then STOP for explicit exact-SHA deployment
  approval. Production delivery/recovery is not yet verified.

Last updated: 2026-10-05

## Student Import V2 confirmation isolation — local candidate

- Production incident diagnosis: `POST /students/import-v2/confirm` reached
  `StudentImportV2Service::confirm()`, which incorrectly created and
  immediately confirmed an empty `StudentFeeAssignment`. The Fee Setup guard
  correctly rejected that empty draft with `A non-empty draft assignment is
  required before confirmation.` The outer tenant transaction therefore rolled
  the admission import back atomically.
- Read-only Production reconciliation for Zixuan / `MMBOWEN01` references
  `000001`–`000010` found zero matching import identities, Students, tenant
  users, or Fee Assignments; the incident is classified as **ATOMIC ROLLBACK**,
  not a partial import. No Production retry has been performed.
- The local fix removes only the accidental Fee Setup invocation. Confirm now
  creates the Student admission identity, code, Guardian/profile, placement,
  and import identity inside its existing transaction. Fee Setup, Promotions,
  Receivables, Collections, Payments, Receipts, Ledger, and Fund Accounts stay
  separate workflows. The non-empty draft guard remains unchanged in
  `StudentFeeAssignmentService::confirm()`.
- The browser now always leaves the confirming state after a failed HTTP
  response. A network/uncertain response shows a reconciliation-and-repreview
  warning instead of inviting a blind retry; consumed tokens remain rejected.
- Local evidence: Student Import V2 contract suite passes (13 tests / 111
  assertions); full PHPUnit passes (993 tests / 7,878 assertions, 34 skips);
  disposable BOWEN_QA browser E2E passes at 1440px and 390px. It previewed and
  confirmed ten NEW rows with text references `000001`–`000010`, verified ten
  unique Student Codes and Student List visibility, then rejected a duplicate
  confirmation. It recorded zero automatic Fee Assignments. The disposable
  fixture was reset afterward.
- No migration is required. This candidate is local-only and must receive
  explicit Production deployment approval before it is pushed or released.

Last updated: 2026-10-05

## Promotion duplicate-code validation — local hotfix candidate

- Production incident on `2026-10-02`: Head Finance submitted a valid
  student-specific Promotion with a code that already existed in the same
  Finance Group. The database uniqueness constraint correctly rolled the
  transaction back, but the controller rendered the expected duplicate-code
  rejection as an HTTP 500.
- The local hotfix preserves the database constraint and adds a service-level
  duplicate check plus a narrow race-condition conversion. The controller now
  binds the resulting message to the `code` field, so no second Promotion,
  allocation, audit, Receivable, Payment, Receipt, Ledger, or Fund Account
  record is created.
- Local verification: the focused duplicate-code/controller contract passes;
  full `CentralFinanceReceivablePaymentTest` passes (44 tests / 280
  assertions); `CentralFinanceOptionalFeeCollectionContractTest` passes (7
  tests / 78 assertions). The PHP 8.5 PDO deprecation is pre-existing.
- No migration is required. The hotfix is local-only and awaits a separate
  Production deployment approval.

## Student-specific Discount — unified local candidate

- General Promotions remain Head Finance Group-control-plane records.
  Student-specific Discounts are now immutable Front Desk requests made only
  from the server-resolved Student Fee Setup line, then approved or rejected
  without amendment by Head Finance. Approval materializes the existing
  Promotion engine's exact Student/Fee definition; it does not introduce a
  second Discount ledger or manual adjustment path.
- The browser cannot choose a Group, School, Student, or Fee allocation. It
  supplies only type, value, reason, and business date. A pending or approved
  request locks the corresponding draft line; confirmation is blocked while
  pending and reuses the existing immutable Promotion Application,
  Receivable, Pending Collection, Payment, Receipt, Ledger, and Fund Account
  workflow after approval. Front Desk cannot render Promotion-definition
  management (403).
- The candidate adds one Central additive request table and one tenant
  draft-reference extension. Historical Promotions and Promotion Applications
  remain unchanged. The exact-path `finance:migrate-collection-v2` runner
  fails closed unless both extensions are recorded and present for the Central
  database and every trusted tenant.
- Verification: migration up/down rehearsal is additive and reversible;
  focused Finance/migration/localization tests pass (63 tests / 2,459
  assertions); full local PHPUnit regression passes (992 tests / 7,871
  assertions, 34 intentional skips). The PHP 8.5 PDO deprecation and PHPUnit
  configuration deprecation are pre-existing.
- Disposable BOWEN_QA browser E2E passes: Front Desk is denied Promotion
  management; it submits an exact Discount request; Head Finance approves it;
  the approved fee setup creates two Receivables and one Pending Collection;
  one confirmation produces exactly one Payment, Receipt, Ledger entry, one
  Promotion Application, and two allocations. The fixture verifier confirmed
  the exactly-once graph, then cleanup removed the disposable records.
- Production has not changed and 苏婷婷 has not been touched. The final
  candidate is local-only and awaits explicit Production deployment approval.

Last updated: 2026-10-02

## Student-specific Promotion scope — local finance candidate

- Head Finance may define an active Promotion for one Central student profile
  and exactly that profile's School. Existing generic Promotions remain
  School-scoped. Front Desk may only select an already-approved, eligible
  definition during Student Fee Setup.
- Server-side eligibility excludes a scoped Promotion for every other Student,
  retains QA/Test versus Official classification isolation, and reuses the
  immutable Promotion Application and receivable-adjustment audit chain. It
  adds no manual-discount field and changes no Payment, Receipt, Ledger, or
  Fund Account behavior.
- The candidate adds one additive Central migration
  `2026_10_02_000001_add_student_scope_to_central_finance_promotions` and
  extends only the exact `finance:migrate-collection-v2` preflight. It is
  local-only pending a separate Production migration/deployment approval.
- Local verification: the focused Central receivable/optional-fee suite passes
  46 tests and 330 assertions; Blade templates compile successfully. The PHP
  8.5 PDO SSL-constant deprecation is pre-existing and unrelated.

## Student Fee Setup promotion validation timing — local hotfix candidate

- The Central Student Finance `+ Add Item` modal submits a `No Promotion`
  value for every rendered optional Fee Item, including unselected rows. The
  optional-fee adapter previously checked draft membership before treating an
  empty promotion value as a no-op, so selecting one Fee Item could wrongly
  fail because another unselected row submitted an empty value.
- The local hotfix treats only `null`/empty promotion values as no-ops before
  the selected-item guard. The modal also disables Promotion/quantity inputs
  until its row is selected and clears a Promotion when the row is removed.
  A non-empty Promotion still must target a selected Fee Item and then passes
  the existing active/date/school/classification/Fee applicability checks. No
  schema, pricing, quantity, receivable, payment, receipt, ledger, or Fund
  Account behavior changes.
- Focused contract regression passes: blank values on selected and unselected
  rows are safe; an applicable selected Promotion is retained; a non-empty
  Promotion against an unselected Fee Item remains denied. Production has not
  been changed.

## Fee Item Quantity Configuration — strict compatibility candidate; regression verified

- Fee Item Management UI cleanup is now part of the same local candidate: Create/Edit rows are compact cards; the delete action is attached to each card; the quantity switch uses localized single-locale wording; and MMK rows visually collapse redundant exchange-rate and converted-MMK fields. This is presentation-only and does not change pricing, quantity semantics, validation, promotions, receivables, or any persisted Finance record.

- Active Production release: `cca1c124f7d7b7628766ae208e161b608db0c7e3`.
  Local candidate `f60d11de693672e9875b8162fbb4e2f41e500c72` exposes an explicit `Allow Multiple Quantity / 允许多数量`
  checkbox on Fee Item create and edit. `optional` and `quantity_enabled` are
  independent values; existing Fee Items remain false/default-off and no
  historical assignment, Receivable, Payment, Receipt, Ledger, Fund Account,
  or Production Fee Item was changed.
- The existing tenant schema migration already supplies
  `fees_class_types.quantity_enabled`; no new migration is introduced. The
  new model/controller path persists the boolean in both create and edit
  flows. A configurable server-side maximum of `100` is enforced in request
  validation and again before any immutable snapshot, including against
  tampered fixed-quantity requests.
- The form serializer submits one scalar `quantity_enabled=0` or
  `quantity_enabled=1`. Only the exact legacy jQuery-repeater payload
  `['0', '1']` is normalized to enabled; every other array and invalid scalar
  remains subject to Laravel's boolean validator and is rejected. The first
  deployment attempt of the broader candidate `1b1dd9d` was immediately
  rolled back because it accepted other duplicate boolean arrays; no migration
  or Finance write occurred.
- Student Fee Setup renders editable positive-integer quantity only for
  quantity-enabled Fee Items, otherwise renders fixed quantity one. It
  presents unit price × quantity line totals and a saved-draft preview with
  Fee Item, unit price, quantity, gross, selected Promotion, discount, and
  net. The preview delegates eligibility and exact DECIMAL calculation to the
  existing Central Promotion service and creates no Promotion Application or
  money record.
- Immutable snapshots remain the source for unit price, quantity, and line
  total. Existing Collection V2 and receipt/allocation regression verifies
  `50,000 × 2 = 100,000` as one Receivable and preserves itemized allocation
  through the receipt without duplicate Fund Account effect. No permission was
  added: Fee Item management remains subject to the existing `fees-create`
  contract; all other Front Desk and Finance boundaries are unchanged.
- Local verification: PHP lint, Blade compilation, whitespace check, and the
  targeted quantity/optional/receivable/payment regression pass: 47 tests,
  342 assertions. The full local PHPUnit suite passes through the authorized
  disposable test databases: 980 tests, 7,724 assertions (one project
  deprecation, one PHPUnit deprecation, and 34 skipped tests). PHP 8.5 emits
  the pre-existing `PDO::MYSQL_ATTR_SSL_CA` deprecation warning.
- Browser QA passes at both 1440px and 390px using a dedicated synthetic local
  Front Desk fixture. It verifies Fee Item management, edit rendering, a
  fixed-one quantity item, and a quantity-enabled item without submitting any
  Fee Setup form. The temporary quantity-enabled test state was reset after
  the check; the fixture now returns to default-off quantity configuration.
- Production remains on the verified pre-switch release; Timecity student
  `000001` / 苏婷婷 remains unmodified and reserved for the operator's manual
  E2E. The strict candidate requires a new explicit Production deployment
  approval.

Last updated: 2026-09-30

## Laravel shared-runtime ownership contract — local operations candidate

- Production is running `2fbf49c150bb8f949ad04f4ce6ff5fc8819cc6de`. The
  release is healthy after a bounded repair of the two already-identified
  root-owned runtime artifacts; `cache/data`, `views`, and `sessions` each
  currently have zero root-owned entries.
- The local follow-up candidate prevents the release builder from
  using root Composer hooks to bootstrap Laravel: Composer uses `--no-scripts`,
  then package discovery and any explicit runtime-writing Artisan operation
  run as `www`. Before discovery it removes only the two release-local,
  regenerated Composer provider caches (`bootstrap/cache/packages.php` and
  `services.php`), preventing a tracked stale provider map from referring to
  a dev-only package omitted by `--no-dev`. A new read-only ownership guard blocks a release before and
  after switching if shared cache data, compiled views, or sessions contain a
  root-owned entry or are not writable by `www`; a post-switch failure restores
  the prior immutable symlink rather than repairing files silently.
- Evidence confirms that production deployment Composer hooks and direct root
  Artisan invocations can boot Laravel under root; the exact historical writer
  of the repaired files is not attributable from extant filesystem/log evidence.
  R2E tenant discovery also boots Laravel as root and requires a separately
  reviewed server-configuration follow-up before this contract is complete.
- Local verification: shell syntax checks; `RuntimeLinkGuardTest` 5 passed / 33
  assertions; runtime-user package discovery completed. No Production change
  is included in this candidate.

Last updated: 2026-09-30

## Front Desk — School Finance Workspace Consolidation — deployed baseline; role-contract corrective candidate

- Deployed baseline: `7b1b164aa5bcb88e3f456555f6cb2ac5849cbc91`.
  The current local corrective candidate is based on that release; it has not
  been deployed.
- A School in canonical `CENTRAL` cutover now derives both daily Front Desk
  navigation and legacy-write retirement from the same authoritative cutover
  state. The obsolete code-based navigation rollout list is removed, resolving
  the Timecity drift without adding a second allowlist.
- A Central Front Desk sees only `收费设置 / Student Fees` (Fee Item Management
  and Fee Types) plus the Central `School Finance` collection workspace:
  Student Collection, My Pending Collections, Collection Receipts, and Cash
  Handover only when an active allocated Cash Fund Account is usable. Student
  Fee Setup remains the existing student-profile fee-assignment flow; no
  second setup or collection path was introduced.
- The generic legacy Finance navigation is suppressed for Central-cutover
  Front Desk identities. Existing historical data and authorized read paths
  remain intact; existing legacy settlement POST routes retain the trusted
  Central-cutover server-side denial and cannot bypass Pending Collection →
  Head Finance confirmation → canonical Payment/Receipt/Ledger/Fund Account.
- Canonical Front Desk onboarding grants only an auditable tenant role
  contract: read-only own-school student lookup plus fee setup. It excludes
  student create/edit/delete, legacy direct payment/receipt,
  Finance staff administration, Fund Account administration, opening balance,
  transfer, Head Finance confirmation, refund/reversal, promotion definitions,
  and other legacy settlement authority. Central collection submit remains a
  separate explicit scope. Existing non-contract grants fail closed for manual
  review rather than being silently rewritten.
- Verification: targeted Front Desk/cutover/collection/onboarding regression
  passes (90 tests / 599 assertions); full PHPUnit passes (973 tests / 7,650
  assertions, 34 pre-existing skips; PHP 8.5/PHPUnit deprecation notices
  remain). Local Playwright Front Desk acceptance passes at 1440px and 390px
  without a collection submission, 404/500, or console error. The browser
  fixture was disposable local BOWEN_QA data only.
- The deployed baseline removed Timecity Front Desk user #107's obsolete
  `校区收款专员` role under audit #355, leaving the nine fee-setup permissions.
  The resulting normal Student Profile entry was blocked because the original
  contract omitted the required read-only `student-list` permission. The
  current corrective candidate adds that exact permission to the shared
  contract without reintroducing any legacy settlement or student-write
  authority. It requires a separately approved code release and a scoped,
  audited alignment of the Timecity canonical Front Desk role; Production has
  not received this corrective candidate.
- Deployment must not invoke the
  global onboarding role provisioner without an explicit, separately audited
  tenant-role reconciliation plan, since its `--execute` mode changes role
  permission definitions.

Last updated: 2026-09-29

## P0 — Finance Collection V2 Complete — integrated local candidate

- Baseline: verified local Finance Layer 3 `64148b6a25ba841c49625d1a78757dacb8893de1`. Layer 3 is not being released independently; this is one integrated local-only candidate and Production has not been changed.
- Student Fee Setup is the only Front Desk Promotion application point. Front Desk can select an existing active, applicable Promotion with the fee item, but cannot define, edit, waive, correct, void, or enter a manual discount. Head Finance retains Promotion definition/lifecycle authority, and the existing Layer 3 promotion snapshot and audit service is reused.
- Receivables preserve immutable unit-price, quantity, gross, promotion, net, paid, and outstanding snapshots. A Front Desk declaration can allocate one parent pending collection to several same-student, same-currency receivables. Head Finance confirmation creates exactly one canonical Payment, Receipt, Ledger effect and Fund Account balance movement for that parent; allocation rows never duplicate physical money.
- Reused school-reference validation now rejects a duplicate or reserved Payment reference when Front Desk submits the collection, before it can surface as a later Head Finance confirmation error. Existing historical pending records remain protected and return a validation failure rather than a 500.
- Group-owned Unidentified Deposits record physical Group-held money without a School operating-income attribution. Later matching is idempotent and settles the chosen School receivable with allocation/audit evidence but no second Payment, Receipt, Ledger, or Fund Account effect. Deposit and allocation models are append-only.
- The exact-path `finance:migrate-collection-v2` runner enumerates only active canonical School registry entries and fail-closes on an unsafe/duplicate registry or partial migration state. Its status predicate is type-aware: a numeric status column uses only `status = 1`, preventing MySQL from coercing the text literal `active` to zero and accidentally including an inactive School. The local browser schema exposed a real MySQL 64-character default-index-name failure in the P0 migration; all new indexes now use explicit short names. The exact P0 central migration was rerun successfully against the disposable local browser database after that fix. The pre-existing local Layer 3 database had tables without its migration record, so the runner correctly refused to treat that state as a valid all-tenant rehearsal; no Production database was inspected or changed.
- Regression: targeted Collection V2 + promotion + localization passes, 49 tests / 2,315 assertions; full PHPUnit passes, 968 tests / 7,608 assertions, zero failures/errors, 34 pre-existing skips (one existing PHP deprecation and one PHPUnit XML deprecation). `artisan test` still inherits the repository 128 MB subprocess limit; direct PHPUnit at 1 GB completed with a 241 MB peak. Blade cache and `git diff --check` pass.
- Browser QA passes locally without financial form submission: Layer 3 promotion/receivable regression and P0 Unidentified Deposits form at 1440px and 390px; all exercised requests were 200 with no console/page/404/500 errors. The P0 browser check uses the additive local migration only.
- Supplemental receipt-quantity candidate (not pushed or deployed) preserves immutable allocation `unit_price_snapshot` and `quantity_snapshot` in the canonical receipt view model and renders quantity, unit price, and line total on the 80mm-compatible receipt document. Its focused integration test proves fixed tuition quantity `1`, `50,000 MMK × 2 = 100,000 MMK`, immutable payment-allocation/receipt snapshots after a mutable source projection changes, and rendered receipt values. Targeted Finance and migration-runner tests pass (43 tests / 285 assertions) and the P0 1440px/390px browser smoke passes. The aggregate suite is not a release gate in this sandbox: it stops at 509 tests because the pre-existing `school_testing` MySQL connection is blocked by the local permission policy (27 errors) and its unrelated example route returns 500; no business-code workaround was applied.
- Production remains a Human Gate: run immutable-release ancestry/diff guard, verified backup, exact runner preflight and execution, schema/data verification, graceful runtime activation where required, and read-only authenticated QA. No commit, push, deploy, or Production data change has occurred.

Last updated: 2026-09-29

## Student Fee Setup quantity + approved Promotion completion — local candidate

- The authenticated Timecity Front Desk QA found that the direct tenant-side
  `Student Fee Setup` page rendered only fee checkboxes.  The quantity
  snapshot service already existed, but the Controller and Blade form omitted
  its request field; the approved-Promotion selector existed only on the
  Central optional-item modal.
- The local candidate adds quantity controls only for optional Fee Items whose
  `quantity_enabled` flag is true; all compulsory and fixed-quantity items
  remain fixed at one.  The browser total is calculated from server-owned unit
  prices and quantities, and server validation remains authoritative.
- Tenant-side Fee Setup now renders only active, date-valid, School/Fee-item
  applicable Central Promotions.  It resolves the Front Desk identity solely
  from the trusted tenant session, validates selection again on POST, stores
  only the selected Central identifier in the immutable tenant snapshot, then
  invokes the existing Central `applyFromFeeSetup` service after receivable
  projection.  It does not introduce a manual discount path, promotion
  definition authority, waiver, correction, or void capability.
- An additive, tenant-only migration adds nullable
  `student_fee_assignment_items.selected_promotion_id`; the immutable Central
  Promotion Application remains the audit and monetary snapshot of record.
  `finance:migrate-collection-v2` now recognizes the prior Collection V2
  schema plus this additive step and fails closed for partial/unexpected
  states.
- Confirm retries are idempotent per immutable assignment-item UUID.  If a
  selected Promotion is awaiting Central projection, the page exposes an
  explicit retry control rather than silently treating the selection as
  applied.
- Local verification: PHP lint, Blade cache, migration rollback/reapply
  coverage, direct Fee Setup contract tests, and Central receivable/payment
  regression pass (45 tests / 308 assertions across the targeted commands).
  The local BOWEN_QA browser reset/verify guard correctly stopped because this
  worktree lacks permission to connect to its local MySQL service; no
  non-local fallback was used.  No Production system or data was contacted.

Last updated: 2026-09-30

## Finance Layer 3 — Receivable lifecycle — local candidate ready for Production gate

- Baseline: `9ce2339a03d0191926d6969d63cb5b10b4a56ca3`; Production has not
  been changed.  The candidate replaces the ambiguous generic receivable
  adjustment command with separate Head-Finance-only Promotion, Correction,
  Waiver, and unpaid-only Void actions.
- Every action is append-only, has an idempotency key, mandatory effective
  date/reason where required, and stores before/after net values.  Exact
  DECIMAL(20,4) arithmetic is used for new lifecycle writes.  No action
  creates or edits a Payment, Receipt, Ledger entry, or Fund Account balance.
- Promotion applications are immutable snapshots and one application is
  allowed per receivable.  Source sync preserves lifecycle-final receivables
  and blocks automatic source rewrites once a promotion snapshot exists.
- Targeted Central receivable/payment, workspace, and localization regression
  passes: 77 tests / 2,404 assertions. This includes additive migration → boot →
  rollback → reapply, percentage/fixed promotions, snapshot immutability,
  paid-floor and pending-collection guards, QA inheritance, and Layer 2
  refund/reversal regression.  The small follow-up closes the Receivables
  status-filter gap: `voided` is now an accepted and rendered status, so its
  append-only history remains discoverable from the normal UI. Blade
  compilation and diff whitespace checks pass.
- Complete local PHPUnit passes with 963 tests / 7,470 assertions, zero
  failures or errors, and 34 pre-existing skips. PHP 8.5 PDO and PHPUnit XML
  deprecation notices remain.  The suite was run against only the disposable
  `eschool_testing` and `school_testing` schemas.
- Local BOWEN QA browser gates pass: the shared Central Finance read screens
  at 1440px, 1280px, and 390px; and the Layer 3 Promotion Definitions plus
  Receivables lifecycle UI at 1440px and 390px.  All responses were 200 with
  no console, page, 404, or 500 errors.  The browser schema exercise applied
  only the already-tested additive Layer 3 migration to the local BOWEN QA
  database; Production was not contacted or changed.
- Production deployment remains a separate Human Gate.  It requires the
  approved immutable-release, backup, exact-path migration-preflight, and
  read-only Production QA process.

Last updated: 2026-09-29

## Zixuan QA School workspace consistency — local candidate

- Zixuan (`MMBOWEN01`) remains the explicitly classified permanent QA/Test
  School. The local candidate makes its already trusted selected-School
  context consistently readable across Central Finance workspace, account,
  student-collection, pending-collection, handover, and batch-history read
  surfaces. This prevents a QA record that is visible in one Zixuan page from
  becoming a false 404 on its detail or adjacent workspace page.
- The rule is narrowly scoped: only an actor already authorized for the
  selected QA School receives its own QA/Test records. QA School Fund Account
  lists still use exact QA classification, so an Official account cannot be
  selected merely because it is allocated to Zixuan. Archived data remains
  excluded from School workflow selectors.
- All Schools / Official views remain Official-only unless an authorized
  Head Finance or Super Admin deliberately requests QA history. School staff
  cannot use `include_qa_test` to expand into an All Schools QA view, and
  existing Central + Group school-scope authorization remains unchanged.
- Local targeted verification passed: workspace QA context and isolation
  regression (3 tests / 19 assertions), QA payment/collection regression
  (6 tests / 24 assertions), handover (7 tests / 72 assertions), Group
  Import confirm (24 tests / 160 assertions), Group Import preview (5 tests /
  94 assertions), School Finance facade (4 tests / 17 assertions), and
  operating-school context (4 tests / 16 assertions). The combined run hit
  the documented 128 MB PHP memory ceiling; no runtime limit or business code
  was changed to bypass it. Production is unchanged pending Human approval.

Last updated: 2026-09-29

## Finance Layer 4 — Date Contract + Official Finance Go-Live Foundation — local candidate

- Baseline: `1ce52fca9de5ea683a1a774a38e2edb8ab4de0d2`; branch
  `codex/finance-layer4-date-go-live-foundation`.  This local candidate has
  not changed Production code, data, configuration, cache, or services.
- Central Finance now uses one strict, non-future Yangon `YYYY-MM-DD`
  Transaction Date contract for V3 Group Import, manual Income/Expense,
  opening-balance effective dates, and Refund/Reversal effective dates.
  Legacy V3 `日期 / Date` headers remain accepted as compatibility input.
- A Front Desk collection keeps its original `collected_at` as the canonical
  Payment and Ledger business date even when Head Finance confirms it in a
  later month or year.  Confirmation and official-receipt issuance remain
  distinct audit timestamps, both visible on the official reprint.
- Ledger/statement ordering and period filters use `entry_date` followed by
  the recorded timestamp and immutable ID.  The UI distinguishes selected
  period movement from the current account-level physical balance; no new
  reporting, chart, month, year, or semester feature was introduced.
- The Group Import V3 preview table now shows the normalized `Transaction
  Date` alongside each row, so the business date validated during preview is
  visible before confirmation. This is presentation-only; preview remains
  zero-write and the posting path is unchanged.
- Group-owned Fund Account onboarding records holder/custodian metadata and
  audited account-level opening balance dates.  The new
  `docs/finance/OFFICIAL_FINANCE_GO_LIVE_CHECKLIST.md` documents the exact
  official account, allocation, identity, reconciliation and approval
  prerequisites. QA/Test Fund Accounts are excluded from official cutover
  readiness and cannot make a School appear ready.
- Local verification passes: date/import/collection/Fund Account/cutover
  targeted regression 122 tests / 828 assertions; localization and thermal
  receipt contract 12 tests / 1,926 assertions; complete PHPUnit suite 954
  tests / 7,368 assertions, 0 failures/errors, 34 pre-existing skips. PHP
  8.5 PDO and PHPUnit XML deprecation notices remain. Production rollout is
  a separate Human Gate and must use the approved immutable-release, backup,
  migration-preflight and read-only QA process. This candidate has no schema
  migration and does not create a Fund Account or other financial record.
- Local browser gate: desktop and 390px verified Import V3, Income, Expense,
  overview, refund/reversal, 80mm receipt/reprint, and account-statement date
  display with disposable local records only. Production remained read-only.

Last updated: 2026-09-28

## Finance Phase 2A — Refund / Payment Reversal — local candidate

- Read-only release inspection confirmed the active immutable Production
  release is `6b583ee7a9df803b0a711346420af9527e35c2c0`.  This candidate is a
  descendant of that exact release; nothing in this phase has been deployed or
  written to Production.
- Refunds remain immutable, partial-or-full correction documents bound to the
  original active Fund Account and currency.  They now require a Head Finance
  actor, a stable submitted form token, method, effective date, reference
  (when supplied), and an audit reason.  The Payment row lock plus the unique
  idempotency key makes a retry or double-submit return the same correction,
  never a second Ledger or balance effect.
- Payment Reversal V1 is a separate immutable document and route.  It is
  Head-Finance-only, full-payment-only, retains the original Payment/Receipt,
  and appends one money-out / negative-operating-income Ledger effect on the
  original Fund Account.  A prior Refund blocks a Reversal; a Reversal blocks
  every later Refund; a second Reversal is rejected.
- Payment Detail is now the only correction-action surface.  The history page
  is read-only and shows original, refunded, reversal, remaining-refundable,
  date, actor, reason and document links.  Dedicated Refund/Reversal
  confirmation text replaces the ambiguous generic confirmation wording.
- Trusted Zixuan `qa_test` classification is inherited to Refund/Reversal
  documents and their Ledger effects; official totals remain filtered.  No
  QA toggle or extra authority is exposed to School staff.
- Targeted Finance regression passed: 70 tests / 435 assertions; dedicated
  payment lifecycle coverage passed: 26 tests / 155 assertions; localization
  contract passed: 8 tests / 1,885 assertions; Blade templates cached and
  cleared successfully; `git diff --check` passed.  The full local suite now
  passes: 949 tests / 7,330 assertions / 0 failures / 0 errors / 34 skips.
  The previous `school_testing` failure was a stale local MySQL service plus
  sandbox-local-network restriction, not an application or Production grant.
  `artisan test` drops the parent memory override when it starts its bare-PHP
  subprocess; direct locked PHPUnit at 1024 MB executed the complete suite
  with a 231 MB peak.  The obsolete Student Finance source contract was
  aligned with the deployed Pending Collection lifecycle without weakening
  its authorization or direct-posting retirement guarantees.
- This candidate includes one additive **central-only** migration for refund
  fields and payment reversals.  Its exact-path `finance:migrate-payment-corrections`
  runner verifies the Central schema and migration registry before it writes,
  applies only that one migration, verifies the resulting constraints, and is
  idempotent on a repeat run. Production rollout remains a Human Gate and
  requires migration preflight, verified backup, immutable release, and
  authenticated read-only QA before any Refund/Reversal is submitted.

Last updated: 2026-09-28

## Kindergarten Gate A canonical-code closure — local candidate

- Baseline: immutable Production release
  `76402dc7c7f2d6ec57e69540a752b0b2c1062ee2`.
- The trusted Gate A runtime allowlist used by Student Profile sync,
  Receivable sync, and read-only legacy-cutover reconciliation now accepts
  `MMBOWEN04`, not the retired Kindergarten code `SCH202620`.
- Historical migration mappings keep the legacy code where they are needed to
  identify already-completed historical work; they are deliberately outside
  this runtime input change.  No School, tenant, Finance record, role, scope,
  schema, or Production configuration is changed.
- Characterization coverage proves `MMBOWEN04` resolves through the fixed
  runtime scope and `SCH202620` is rejected as a runtime command input.
  Production rollout remains a separate Human Gate.

Last updated: 2026-09-28

## Central Finance receipt branding fallback — local candidate

- Baseline: immutable Production release `3df57abe49cdd6a75cdf223a707d34b678214c2d`. This presentation-only candidate keeps a School's uploaded logo when it is available and safely falls back to the Bowen School master Logo when a Bowen School has no logo or its persisted logo cannot load.
- The canonical Bowen set is `MMBOWEN01–04`; external Schools retain the existing eSchool fallback. The receipt never uses the generic SaaS placeholder for a Bowen School, including where a legacy `schools.logo` value remains but the referenced shared-storage file is gone.
- No Finance record, School record, storage object, permission, configuration, or database schema is changed. Targeted branding/receipt checks and Blade compilation pass: 12 tests / 36 assertions (only pre-existing PHPUnit/PHP 8.5 deprecation notices).
- Production rollout is a separate Human Gate and requires an immutable release plus read-only receipt browser/thermal-print QA.

Last updated: 2026-09-28

## Confirmed Finance Receipt 80mm presentation — local candidate

- Baseline: immutable Production release `c40af34116ae5de2db45148f09bd7ca62d340134`. This candidate changes only the presentation and print stylesheet of the already-authorized canonical receipt; it does not change Payment, Receipt, Ledger, Fund Account, authorization, or Finance workflow data.
- The confirmed Central Finance receipt now uses the same 80mm, vertical thermal-paper contract as the Front Desk Collection Receipt. It carries the official receipt number, `Finance Confirmed` status, receipt date/time in Asia/Yangon, student, receivable, this payment, cumulative paid, outstanding, method, reference, collector, Fund Account, refund/reversal history, and compact audit timeline.
- Reprint remains a `window.print()` presentation action only. It cannot create a new Payment, Receipt, Ledger effect, Fund Account effect, or change the official balance.
- Targeted receipt, lifecycle, workspace, and Blade compilation checks pass: 59 tests / 386 assertions. Production rollout is a separate Human Gate and requires only read-only browser/thermal-print QA against an existing receipt.

Last updated: 2026-09-28

## Collection receipt access + direct collection retirement — local candidate

- Baseline: immutable Production release `30374c387a2e1cc0837c4363c1c7ccfe10a311f1`.  This local implementation does not alter Production data, configuration, services, or the existing Zixuan QA collection records.
- The canonical receipt route now resolves the owning School from the immutable Payment record, applies the established school-scope authorization first, then derives QA visibility from the trusted School classification.  This permits an authorized Zixuan QA user to view/print a receipt backed by a Zixuan QA Fund Account without making that account visible to Official schools or across schools.
- The old Head Finance direct-collection endpoint is retired: normal UI now points Front Desk to pending-collection submission and Head Finance to pending review; direct GET redirects to that review queue and direct POST returns HTTP 410 before creating a Payment, Receipt, Ledger entry, or Fund Account effect.  Bank Transfer and Cash lifecycle control remains in the pending/handover → Head Finance confirmation flow.
- No schema migration is required. Targeted lifecycle, workspace, Fund Account V2, transfer, and Blade compilation regression passes: 81 tests / 517 assertions; only existing PHPUnit XML and PHP 8.5 PDO deprecation notices remain.
- Production rollout remains a Human Gate: require a fresh verified backup, immutable release integrity check, and authenticated read-only QA.  Do not submit or reconfirm the existing Zixuan QA fixture during deployment QA.

Last updated: 2026-09-28

## Front Desk → Head Finance collection lifecycle clarity — local candidate

- Baseline: `7edca8210d79b531c1810037e5f86faf47ec4ebd`; no Production data, deployment, cache, or service state was changed.
- A submitted or held Front Desk collection now reserves the remaining receivable amount under the same locked Central Finance transaction.  An exact retry is idempotent; a second declaration that would exceed `amount_due - canonical amount_paid - submitted/held reservations` is rejected before any canonical Payment, Receipt, Ledger, or Fund Account effect.
- Student collection views now distinguish **Confirmed paid**, **Pending finance confirmation**, **Official outstanding**, and **Available to collect**.  Pending is deliberately not treated as paid or as a Finance-report total.
- Head Finance review locks a Bank Transfer to the Front Desk-declared eligible Bank Fund Account.  The reviewer supplies only the mandatory confirmation reason, or holds/rejects if the evidence/account is invalid; Cash remains confirmation-through-handover only.  Collection lists/receipts show collected, submitted, and (after confirmation) Finance-confirmed timestamps in Asia/Yangon.
- Targeted lifecycle, account V2, workspace, transfer, and Blade compilation regression passes: 77 tests / 510 assertions.  The only output warnings are existing PHPUnit XML and PHP 8.5 PDO deprecations.  Production rollout is a separate Human Gate and must use a fresh verified backup plus authenticated read-only QA before any confirmation is submitted.

Last updated: 2026-09-28

## QA staff onboarding visibility + multi-role selection — Production deployed

- Exact immutable Production release: `35652d8e2ae7d996f72aa4bfaed8043bae310094`, a descendant of the preceding active release `2c61a70ca0c474199e58c644bd9173ae7438a42c`.
- The School Admin Finance Staff Onboarding view now uses the same classification-aware Staff query as the Staff list.  A QA/Test School sees only its own `qa_test` Staff; official Schools retain their official-only operating view.  The POST action reuses the identical eligible-Staff predicate, so a direct request cannot select a hidden Staff row.
- Staff create/edit role controls are explicit multi-select checkboxes (`role_ids[]`); edit hydration selects every existing role instead of collapsing the Staff member to one role.  The onboarding flow continues to retain roles and does not grant Central Finance scope by itself.
- Fresh Central + eight-tenant backup: `/root/backups/eschool_pitr_full_20260928T035801Z_a14747`; all nine compressed database dumps passed gzip and SHA-256 verification before release creation.  No migration, Finance write, role/scope grant, or business-data mutation was part of deployment.
- Release guard, runtime-link guard, active symlink, marker and manifest all resolve to the exact commit.  PHP-FPM 8.3 received one graceful reload after configuration, route and compiled-view cache refresh.  Homepage/login returned HTTP 200 and the released frontend asset hash matched the immutable release.

Last updated: 2026-09-28

## QA Front Desk sidebar visibility — local candidate

- A read-only Production authorization trace confirms Zixuan Front Desk `zixuany228+frontdesk@gmail.com` has an active tenant role, active Central-to-tenant identity, active Bowen Group membership, and Zixuan `can_view=1` / `can_submit_collections=1` scope.
- The remaining defect is presentation-only: the sidebar obtained `accessibleSchools()` without QA visibility, then treated a properly scoped QA School Staff identity as absent.  The local candidate passes `includeQaTest` only for that already trusted, tenant-bound staff session.  It exposes the narrow School Finance collection navigation, but does not expose Official data, other Schools, account administration, reporting, or Head Finance operations.
- Targeted sidebar, onboarding, and School Staff identity tests pass: 17 tests / 150 assertions.  Production deployment remains a separate Human Gate.

Last updated: 2026-09-28

## Tenant-only staff identity compatibility — local candidate

- Baseline: active Production commit
  `f17e6703f2d25b12942a359f1a33cc3ecfb43e08`. Isolated branch
  `codex/tenant-only-staff-identity`; no Production data, deployment, cache,
  service, or configuration was changed.
- Canonical School Code login now creates a server-session assertion bound to
  the registered School, exact tenant database, tenant-local user, tenant
  `school_id`, and the regenerated Laravel session identifier. Raw session
  database values remain routing state and cannot by themselves select a
  tenant.
- Every later tenant request re-resolves the central School registry and the
  tenant-local active user before setting the tenant context. A Central
  duplicate user with the same numeric ID is neither required nor used for
  tenant-local School Admin, Principal, Front Desk, or School Accountant
  identities. Central/Super Admin and Head Finance flows remain central.
- Missing, tampered, inactive, deleted, cross-School, or stale tenant
  assertions fail closed. Legacy pre-assertion sessions are only cleared at
  public entry routes so the user can log in again; protected routes remain
  denied. Logout clears the assertion explicitly.
- Staff creation now keeps its atomic identity transaction separate from
  optional invitation delivery: after a successful commit, a failed email is
  logged and returned as a successful warning instead of `error_occur`.
  No Central shadow user is created for tenant-local roles. The controller
  never attempts a second commit from its catch boundary; it rolls back only
  an open core transaction.
- Disposable local-MySQL coverage verifies tenant login/context restoration,
  tenant-local staff roles, invitation token binding, and a forced invitation
  template failure. Full local regression: 887 passed / 7,091 assertions;
  34 existing skips and PHP 8.5/PHPUnit deprecations only.

Last updated: 2026-09-24

## Tenant-safe global view composer — local candidate

- Baseline: Production commit `b327f4d5a59ae51febcea72c4f575c488d3c0ef9`.
  Isolated branch `codex/tenant-safe-global-view-composer`; no Production data,
  deployment, cache, service, or configuration was changed.
- Global Blade composers now consume only the request-scoped trusted tenant
  context. A retained `db_connection_name=school` session marker is no longer
  treated as proof that a tenant database has been configured.
- 403/404 error rendering uses the same trusted-context contract rather than
  resolving the session guard against an unconfigured tenant connection. The
  expected denial/not-found response remains intact and does not become
  `SQLSTATE[3D000] No database selected`.
- Disposable local-MySQL coverage exercises retained tenant sessions, trusted
  scope setup/cleanup, and actual 403/404 error-page rendering with zero
  queries against an unconfigured `school` connection. Full local regression:
  913 tests / 7,045 assertions; only pre-existing PHP 8.5/PHPUnit deprecations
  and 34 explicit skips remain.

Last updated: 2026-09-24

## Step 4B — Legacy Finance Retirement + Explicit School Scope Hardening — local candidate

- Baseline: Production commit `b299b23aa17db2b243b2875456849253716345c7`.
  Isolated branch `codex/step4b-legacy-finance-retirement`; no Production
  data, schema, configuration, service, cache, deployment, or release changed.
- Legacy `BankAccount`, `BankTransfer`, legacy Fund Handover, and the legacy
  Group Finance operating-write adapter now fail explicitly with HTTP 410.
  Their models/tables and trusted-school historical reads remain only for
  compatibility. Normal sidebar and Group Finance operating navigation no
  longer advertises retired write paths.
- Active Student, Compulsory Fee, and approved legacy Expense reads use a
  trusted explicit school scope rather than an optional model owner scope.
  Canonical Central Fund Account V2, Central transfers/handovers, Group
  Finance Import V3, and Front Desk/Head Finance workflows remain separate.
- Disposable-MySQL targeted regression: 72 tests / 474 assertions. Full
  local regression: 904 tests / 6,999 assertions with a temporary CLI-only
  1 GB test limit (the aggregate suite otherwise retains the repository's
  128 MB test-wrapper limit). Only the existing PHP 8.5 PDO and PHPUnit
  schema deprecations remain; 34 explicit skips are unchanged.

Last updated: 2026-09-22

## School Creation / Tenant Provisioning Safety — local candidate

- Baseline: active Production commit `500e062d970c406ad2c96ece174f751d3556538b`.
  The isolated local branch `codex/school-provisioning-safety-step2` changes
  only initial School Admin tenant provisioning; no Production data, schema,
  deployment, cache, service, or configuration was changed.
- The provisioning path now uses the trusted central `admin_id` as the stable
  tenant identity key and an idempotent insert/re-read. It no longer attempts
  a malformed nested payload followed by an unconditional second user insert.
  A retry reuses only an exactly matching target-School identity.
- A missing School Admin role assignment is recovered idempotently. Conflicting
  tenant IDs, mismatched email bindings, soft-deleted identities, or a central
  user from another School fail closed without an overwrite or role grant.
- New disposable local-MySQL regression covers first create, repeat/retry convergence,
  partial-failure recovery, matching-user role recovery, identity conflicts,
  Super Admin separation, canonical School Code preservation, and tenant
  connection restoration. No finance behavior changed.
- Full local regression passes in bounded Unit and Feature batches at the
  required CLI memory limit: 891 tests / 7,054 assertions, with only the
  pre-existing PHP 8.5 PDO deprecation and PHPUnit XML-schema warnings.

Last updated: 2026-09-21

## Tenant + API Context Security — local candidate

- Baseline: active Production commit `64c9ef951660478d0965628398c05348333d0022` in the isolated branch
  `codex/tenant-api-context-security`. No Production data, deployment, cache,
  service, or configuration changed.
- API tenant context now resolves the canonical School, validates the
  tenant-local Sanctum token, School identity, explicit API-family ability and
  tenant role before a request handler can run in that tenant context. Every
  success, denial and exception restores the prior default connection and
  configured tenant database.
- Web requests accept a tenant context only when the session database maps to
  exactly one central School and the authenticated tenant actor belongs to that
  School. Central administrators are never converted into tenant actors by a
  session value. Middleware no longer re-applies a raw session database switch.
- `SetupSchoolDatabase` and tenant password-broker operations now use the same
  scoped connection guard, restoring worker/request defaults on success and
  failure without altering provisioning or password-token business rules.
- Security-focused regression: 34 tests / 197 assertions. Full local PHPUnit
  regression was run in six bounded batches across all 149 test files:
  883 tests / 7,028 assertions passing with a CLI-only 512 MB memory limit
  (the Laravel test wrapper otherwise spawns a 128 MB subprocess).

Last updated: 2026-09-21

## Zixuan QA Student Import + Front Desk Collection — local candidate

- Baseline: Production release `a4aec6bc…`; isolated branch
  `codex/zixuan-student-import-frontdesk-e2e`. No Production data, migration,
  deployment, or Bahan/Timecity/Kindergarten record was touched.
- An authorized Head Finance/Super Admin can deliberately select Zixuan
  `MMBOWEN01`, which is visibly marked QA/Test. The School selector change
  does not include its data in official totals, reports, accounts or default
  lookups; Include QA/Test remains the deliberate authorized history view.
- Student Import V2 now downloads a new-school workbook with `No.`, student,
  class, Weekday/Weekend, parent/contact, optional demographic data,
  enrollment date/status and remarks. Student Code is not an upload field:
  it is generated server-side from the locked per-School six-digit sequence.
  Preview is cache-only and labels NEW/DUPLICATE/CONFLICT/ERROR; deterministic
  import-reference matching skips exact replays and blocks conflicting rows.
  Confirm creates only NEW rows, and produces receivables only through the
  existing compulsory-fee assignment flow—never a Payment, Receipt or Ledger.
- The exact, read-only-by-default Zixuan schema runner now includes an
  additive `student_import_identities` enrollment-metadata migration. It
  validates registry/migration state before every write and remains confined
  to canonical `MMBOWEN01` when explicitly executed.
- Front Desk collection is now a non-canonical declaration: Bank Transfer
  requires an active, allocated, same-currency Bank account; Cash has no
  account until a submitted Cash handover. Head Finance revalidates all
  account/allocation/currency conditions before the only canonical posting
  path. Cash cannot be directly confirmed. Payment/Receipt/Ledger writes
  remain exactly-once and Head Finance-only.
- A printable 80 mm Collection Receipt is immediately available after a
  Front Desk declaration. It is explicitly non-official until confirmation,
  then links the canonical receipt. Pending review now displays School,
  Student Code, receivable, currency, method/intended account, collector,
  time, reference and remarks.
- Targeted regression passes 74 tests / 2,260 assertions; full regression
  passes when test files run in isolated processes against disposable local
  databases (the aggregate process retains its known 128 MB memory cap).
  `view:cache`, route validation and `git diff --check` pass. Production
  deployment, the exact Zixuan migration, and authenticated browser QA remain
  separate human-gated work.

Last updated: 2026-09-18

## Central Finance Fund Management bugfix — local candidate

- Baseline: Production commit `8dfc265ac88ca31257f7db0f03b4eaf3d4754043`.
  This candidate keeps Fund Account V2 accounting and authorization intact.
- The Fund Accounts directory now renders one physical account per horizontal
  desktop/tablet row, with account-level physical balance, allocated-school
  summary and Detail/Statement/Manage actions. The existing responsive card
  presentation remains available at narrow mobile widths.
- Group Finance Import V3 now carries its canonical Description into the
  immutable Ledger `memo` at confirmation. Reference and Description remain
  independent; historical entries with no memo are deliberately unchanged.
- Fund Handover and Bank Transfer client selection no longer rejects
  Group-owned accounts solely because their owner type is `hq`. Server-side
  authorization remains the canonical active-account + active allocation +
  school-scope predicate, with source exclusion and same-currency checks.
- Targeted and expanded Central Finance regression: 78 passing tests / 665
  assertions (two existing PHPUnit deprecation warnings). No migration or
  financial-data change is included.

Last updated: 2026-09-18

## Central Chart of Accounts + Group Finance Import V3 — LOCAL PASS

- Baseline: `dc9e38d53cd0ed13741c1e24ef93f96c2cd08639`, verified against the active
  Production release before implementation. Local branch:
  `codex/central-chart-of-accounts-import-v3`. No Production mutation/deploy.
- Central accounts support five cash-flow classifications, manual text codes,
  Group uniqueness, explicit audited School allocations and actor/group/School
  isolation. Codes/types are immutable after creation; existing document IDs,
  tenant Fee category references and historical Ledger effects are preserved.
  Legacy-to-Central consolidation requires an explicit reviewed mapping; it is
  not inferred by matching names or cross-database IDs.
- Fund Account V2 access-only allocations and one physical balance remain
  unchanged. Editable audited `owner_holder` is descriptive text, never an
  authorization source. Asset/Liability/Equity cash movements do not become
  operating income/expense. Voids reverse immutable original Ledger effects.
- V3 exports bilingual 16-column Import plus unique account/fund definitions,
  allocation sheets, currency-separated totals, and School activity. The
  summary combines the canonical snapshot with unposted workbook movements
  and labels closing balances as projected. No workbook balance is imported.
- Server-side allocation/code/type/name/date/amount/currency validation,
  zero-finance-write preview, account/batch locks, replay identity and
  exactly-once confirmation are covered. Formula-only empty rows are ignored.
- Final full regression: 146 files, 859 tests, 6,854 assertions, zero failures
  (existing deprecations; opt-in MySQL test separately exercised). Disposable
  MySQL rehearsal: 1 test / 20 assertions, two exact migrations, repeat-run
  idempotency, partial-schema fail-closed and unchanged history hash.
  Authenticated local browser QA passes 1440/1280/390 px CoA/holder/import
  flows with no page overflow, console error or failed HTTP response.
- Native Microsoft Excel open/save/close/reopen passes on the synthetic V3
  workbook. Reloading Excel's saved file preserves text `0401`/`00101`, all
  1,500 input validations, nine sheets and no formula errors; the separate
  synthetic MMK/USD projected balances remain 1,900 / 23. Final independent
  money-integrity/scope review found no remaining P0/P1 blocker.
- Deployment/mapping gates and compatibility boundary are documented in
  `docs/CENTRAL_CHART_OF_ACCOUNTS_V3_MIGRATION_PLAN.md`. Production migration,
  historical mapping, push and deployment remain unperformed.

Last updated: 2026-09-17

## Central Finance account authorization unification — local candidate

- Production `607ddddc12638ecaa9b72282a913da31b7a874f9` is the local
  baseline. Branch `codex/central-finance-authorization-unification` removes
  the legacy `central_finance_fund_account_users` row from normal Fund Account
  runtime and Cutover authorization.
- The canonical read/write predicate is now one active Fund Account, one
  active School allocation, one active staff finance identity (for School
  staff), one active Central School scope, and one active Finance Group scope.
  Every payment, import, operating document, transfer, handover, refund, and
  Ledger path supplies the explicit transaction School ID. A direct legacy
  account-user row cannot grant School Admin or cross-School access.
- Cutover readiness accepts an allocated Central account plus an active School
  Accountant finance identity with operate scope. Finance Group onboarding
  displays already-authorized accountants as `Finance Access Active` with a
  Manage/Revoke link; only inactive/ungranted staff appear in the Grant form.
  Legacy account-user records remain read-only compatibility/audit data and
  are not created for new normal Group accounts.
- The Head Finance control plane can still manage a newly created Group
  Account before its first School allocation, while every transaction use
  remains fail-closed until an allocation exists. No schema, migration,
  Ledger, or historical Finance data change is included.
- Targeted and expanded Central Finance regression passes 196 tests / 2,997
  assertions. Full regression passes all 142 test files in isolated processes:
  823 tests / 6,595 assertions, with only the repository's existing
  PHP/PHPUnit deprecation notices. Authenticated local Playwright passes the
  Finance Group management workspace, authorization-state rendering, and
  390 px overflow check.

Last updated: 2026-09-17

## Central Fund Account V2.1 allocation cleanup — local candidate

- Production `93aef0a78dc0c23f7f122e16894d08bbc96ae5e3` is the baseline.
  Branch `codex/central-fund-account-v2-1-cleanup` removes monetary meaning
  from School allocation rows: an allocation now grants account access only.
- The School allocation form no longer accepts an amount. Controller,
  administration service, Eloquent models, and balance service no longer
  accept, persist, expose, or read `opening_allocation_amount`. The physical
  balance remains the one account opening balance plus canonical Ledger
  effects; School activity remains a `school_id + fund_account_id` Ledger
  slice.
- The compatibility column is intentionally retained as non-null/default-zero
  so the previous immutable release remains rollback-compatible. The exact,
  default-dry-run command
  `finance:cleanup-fund-account-v2-1-allocations` clears legacy values to zero,
  writes an append-only account audit, and fails closed unless allocation
  access, account-opening, and Ledger checksums remain unchanged. Execution is
  restricted to a vetted immutable Production release and requires an
  explicitly authorized Head Finance actor plus audit reason.
- Targeted Fund Account, controller, and UI contract regression passes 37
  tests / 293 assertions. Unit regression passes 202 tests / 3,502
  assertions; every Feature test file passes in isolated processes (the
  repository's aggregate PHPUnit process exceeds its known 128 MB cumulative
  limit). Authenticated local Playwright passes Central account creation,
  access-only allocation to two Schools, removal of the legacy amount input,
  and 390 px overflow/console checks.

Last updated: 2026-09-17

## Central Fund Account V2 — Production deployed

- Production release `93aef0a78dc0c23f7f122e16894d08bbc96ae5e3`
  moves Fund Account
  ownership to the Central/Group control plane: Head Finance can create an
  `owner_type=hq`, `school_id=NULL` account without selecting a School, and
  every School read/write path requires an explicit active allocation.
- Physical balance is now one account-wide value (audited opening balance plus
  every canonical Ledger effect exactly once). School pages separately show
  `This School Activity`; Ledger/detail queries remain strictly `school_id`
  scoped for Principal and School Accountant, while Head Finance retains the
  authorized group-wide view. Principal access remains read-only.
- Cutover readiness now accepts an active Central/Group account with an active
  allocation to the School. It no longer requires a School-owned account.
  Transfers, handovers, payments, operating documents, imports, account
  selectors, and Group Import V2.2 all fail closed when allocation is absent.
- The forward conversion is limited to audited account codes `B-0001` and
  `M-0001`. It locks rows, requires all existing Ledger School IDs to have
  active allocations, preserves account IDs/opening balances/Ledger rows,
  records before/after plus Ledger checksum, and is idempotent. No conversion
  and the reviewed Production conversion preserved all Ledger history.
- The exact central migration runner is
  `finance:migrate-fund-account-v2`; the conversion preflight/runner is
  `finance:convert-fund-account-v2`. Both default to read-only, and Production
  execution is restricted to an immutable release path.
- Targeted Finance/security regression passes 95 tests / 572 assertions;
  localization passes 8 tests / 1,731 assertions; operating-document and
  receivable regression passes 22 tests / 127 assertions. Full PHPUnit passes
  819 tests / 6,570 assertions with four expected skips and existing
  PHP/PHPUnit deprecation notices only. The exact migration was rehearsed on a
  disposable MySQL database and verified idempotent. Authenticated Playwright
  passes Central account creation, two-School allocation, account detail, and
  390 px overflow/console checks in an isolated local database.

Last updated: 2026-09-17

## Head Finance Bank Transfer / Fund Handover safety — local candidate

- Production baseline `fb1cec85699549b9e37b8e57fa6b14e8a5933350`
  rendered Fund Account choices in the All Schools read model, posted a direct
  Bank Transfer without an audit reason or application confirmation, allowed a
  Fund Handover sender to select themselves as receiver, and exposed pending
  action controls more broadly than the sender/receiver lifecycle permits.
- Branch `codex/head-finance-transfer-handover-fix` keeps All Schools read-only
  and hides every write-account choice until an authorized Central School is
  selected. Direct Bank Transfer now requires an audit reason, records it in
  the immutable document audit, and uses the shared confirmation modal with
  the selected accounts and amount before the immediate paired Ledger write.
- Fund Handover now excludes the current actor from eligible receivers and
  rejects sender-equals-receiver server-side before any write. Only the
  designated receiver sees Confirm/Reject and only the sender sees Cancel;
  service authorization remains authoritative. All authorized-school history
  now shows School, account direction, participants, amount, currency, date,
  status, and safe empty states without granting cross-School operation.
- No schema or migration change is included. Targeted Finance/localization
  regression passes 68 tests / 2,134 assertions. Full regression passes 811
  tests / 6,499 assertions
  with four expected opt-in skips and existing PHP 8.5/PHPUnit deprecation
  notices only. Local authenticated Playwright passes the All Schools Transfer
  and Handover pages at 1440 px and 390 px with no console/page error or
  page-level overflow.

Last updated: 2026-09-16

## Global Finance Staff onboarding role provisioning — local candidate

- Production baseline `d79d07ad94e216543d60ea34913df00cf10b399e`
  exposes the School Admin onboarding UI globally, but only Zixuan currently
  has the permission-free Front Desk definition and only Timecity has the
  permission-free School Accountant definition. The other canonical role
  definitions are absent across the seven active installed tenants. The
  inactive Demo registry row is not an operational tenant and its tenant
  database has no matching School row.
- Branch `codex/global-finance-onboarding-provisioning` adds a dynamic,
  registry-driven two-pass command for all active installed tenants. It
  validates every School/database mapping and every existing canonical role
  before the first write, reuses permission-free same-name roles without
  changing their metadata, and creates only missing Principal, School
  Accountant, and Front Desk definitions. Repeated execution is idempotent.
- New-School setup now calls the same provisioner after tenant schema and
  standard permissions exist. Provisioning never assigns a user or
  permission, never changes existing custom roles, and never grants Central
  Finance scope; that remains the explicit audited Super Admin Finance Group
  step. Structured logs record School, tenant database, source, per-role
  created/reused state, and the no-scope guarantee.
- Targeted onboarding, tenant-isolation, Central identity, and fail-closed
  tests pass 30 tests / 150 assertions. Full regression passes 808 tests /
  6,433 assertions with four expected opt-in skips and existing PHP 8.5 /
  PHPUnit deprecation notices only.

Last updated: 2026-09-16

## Finance Staff onboarding checkbox visibility — local candidate

- Production `4b857adc9440d774b66eb80b0039fd32bc550f06` renders the three
  onboarding role inputs as transparent theme checkboxes, but the generated
  visual helper is not adjacent to the input. The helper selector therefore
  never draws a checkbox even though clicking the role copy changes the hidden
  value.
- Branch `codex/finance-onboarding-checkbox-fix` places the theme helper
  immediately after each role input and gives every input an explicit label
  association. School Admins can visibly select one role or any intentional
  combination; no role, permission, scope, Finance service, schema, or
  Production data behavior changes.
- Focused onboarding and Central identity/scope regression passes 20 tests /
  109 assertions. The full suite passes in memory-bounded processes: 787 tests
  / 6,395 assertions with four expected opt-in skips and existing PHP 8.5
  deprecation notices only.

Last updated: 2026-09-16

## Timecity Finance Staff onboarding — local candidate

- Production `354b6ca9f6f3369d869d59ca6ceb83c1fc42747f` has no formal
  School-facing onboarding UI for the canonical `School Accountant`,
  `Front Desk / Admissions & Collection`, and `Principal` tenant roles.
  Timecity therefore currently exposes only its older tenant roles; May Myat
  Mon has the tenant role `财务部门` and a stable Staff UUID, but no
  Central Staff identity link or School scope. The Finance Group rejection is
  the intended fail-closed result because the required canonical tenant role
  is absent.
- Branch `codex/timecity-finance-staff-onboarding` adds a School Admin-only,
  own-School onboarding page at `Staff Management -> Staff -> Finance Staff
  Onboarding`. It assigns one or more fixed identity roles without replacing
  existing roles, creates missing permission-free canonical roles on demand,
  and writes an append-only tenant audit with reason and before/after roles.
- The existing Super Admin Finance Group step remains separate. Its selectors
  now show only Staff with the matching tenant role, show link state, require
  an audit reason, and audit Central identity/scope before and after. Principal
  remains read-only, Front Desk receives only explicit Pending Collection
  submission scope, and Student tuition collection continues to require Head
  Finance; assigning School Accountant never grants tuition collection.
- Staff create/edit remains multi-role, and the edit modal now preserves every
  assigned role rather than selecting only the first. No route boundary,
  Finance calculation, schema, migration, or Production data was changed.
- Targeted onboarding/security regression passes 24 tests / 97 assertions;
  localization/UI contracts pass 18 tests / 2,798 assertions. Authenticated
  local browser acceptance passes at desktop and 390 px with no page-level
  overflow or target-page console error, and non-School-Admin direct access is
  denied. Full regression passes 797 tests / 6,392 assertions with four
  expected opt-in skips.

Last updated: 2026-09-16

## School staff create duplicate-submit feedback — local candidate

- Production `373ba3d0b6f3f3703e656f6cb01b0821896e1c7e` accepts Bahan's
  School-owned custom Finance role. The reported Bahan attempt did create one
  active tenant User/Staff row with that role, but the browser displayed an
  error because the School layout and the shared JavaScript asset both bound a
  submit handler to `#create-form`. One click could therefore send the same
  create request twice; the first request committed and the replay failed the
  unique-email validation.
- Branch `codex/staff-single-submit-fix` removes only the redundant School
  layout handler and retains the canonical shared handler used for validation,
  success/error feedback, form reset, and table refresh. No route, permission,
  Staff business rule, subscription rule, schema, or Production data is
  changed by the candidate.
- A static regression contract verifies that School create forms have one
  canonical submit binding. Local Playwright intercepts the Staff endpoint and
  proves one valid click produces exactly one POST. Targeted Staff/role/upload
  regression passes 38 tests (107 assertions; two existing PHP 8.5
  deprecations), and full regression passes 788 tests / 6,353 assertions with
  four expected skips and two existing deprecation-classified tests.

Last updated: 2026-09-16

## Timecity School Admin staff-role assignment — local candidate

- Production `e8987e505e2b3bb30e8e389952e1e8b5f2d792fd` rejects Timecity
  School Admin staff create/update requests with `Invalid staff role assignment`
  even when the selected custom role belongs to Timecity. The tenant form does
  not submit a target `school_id`, while the shared role guard incorrectly
  treated that empty request list as the actor's allowed School scope.
- The local fix derives the allowed School from the authenticated School Admin
  identity for tenant requests. Central administrators continue to require the
  explicit target School selection. Cross-School roles, Teacher, and other
  non-assignable system roles remain rejected with 403.
- Focused Staff/image, Central identity, Finance staff-boundary, and go-live
  isolation regression passes 53 tests / 177 assertions. Full regression passes
  793 tests / 6,349 assertions with four expected skips and existing PHP
  8.5/PHPUnit deprecation notices only. No Production data or code was changed.

## Bowen school header branding — local candidate, not deployed

- Current Production source is `115186ea7aec7c849340490249f78b88294c3929`.
  Zixuan `MMBOWEN01`, Bahan `MMBOWEN02`, and Timecity `MMBOWEN03` retain
  central `schools.logo` references, but the three referenced image files are
  absent from persistent shared public storage. All three tenant horizontal
  logo settings are empty; Zixuan's vertical setting also references a missing
  shared file. The pre-go-live reset protected school/settings rows by count;
  there is no evidence establishing when logo files disappeared.
- This independent local branch uses any existing school custom logo first,
  then the packaged Bowen School master image for the three trusted canonical
  codes and for global Bowen administrators. External schools keep their own
  images or the eSchool default. Both HTML and browser error fallback use the
  same brand image, and square Bowen artwork retains its proportions.
- No Production school data, persistent shared storage, Finance behavior, or
  cutover status was changed. Production browser acceptance and deployment
  remain subject to a separate explicit approval.

## Zixuan data-integrity incident — cleanup/cutover/go-live paused

- On 2026-09-15 the Zixuan tenant was inadvertently restored to a 03:31 UTC
  dump at approximately 03:54 UTC. No MariaDB binlog was enabled. Read-only
  reconciliation found no additional *provable* lost row beyond the known QA
  lifecycle states, but cannot rule out unlogged transient writes or a cloud
  account-side snapshot not yet accessible for inspection.
- A fresh, verified 121-table InnoDB Zixuan backup exists at Production
  `/root/backups/zixuan_incident_closure_20260915T042628Z`; it is a recovery
  point, not proof of pre-incident completeness.
- This isolated local branch adds a zero-write restore preflight that rejects
  active/nonempty targets and database-switching SQL. Commit `188712d5` is
  deployed to Production as an immutable descendant of active source
  `965226200`; it does not control raw privileged client imports. Deployed QA
  rejects the old `USE` archive, active and missing targets with non-zero exit,
  and accepts a harmless archive against an empty unregistered disposable DB.
  The real current Zixuan backup is incorrectly rejected because historical
  quoted values include a protected database/table name and permission names
  containing `-use`. A local-only lexical fix masks quoted SQL values before
  directive/reference checks; it is not pushed or deployed. Targeted and full
  local regression pass. A temporary upload to test the local scanner against
  the real Production archive was rejected by Production safety review, so
  real-archive acceptance for the follow-up remains pending explicit approval.
- Follow-up `d1061d2` was deployed from `188712d5` without a migration on
  2026-09-15. It still false-positively rejects the latest real backup and
  the safe disposable control because a closing `*/` split by the scanner's
  chunk buffer leaves it inside a block comment. A local-only incremental
  comment-state fix and boundary exploit/regression tests pass the complete
  784-test suite; Production real-backup acceptance and a separate release
  approval remain pending. Active/missing database targets still fail closed.
- A second fresh nine-database logical backup exists at Production
  `/root/backups/final_quality_gate_followup_20260915T052828Z_7e4432`;
  all nine SHA-256 and gzip checks pass. The old/new SQL row tuple checksums
  of 16 Central Finance and Zixuan Finance/Student/Staff tables match 16/16
  after normalizing MariaDB dump line layout. No restored/imported SQL ran.
- The Laravel database user has global `ALL PRIVILEGES` and `GRANT OPTION`;
  the preflight cannot technically stop a privileged raw client import.
  Routine disposable-only restore therefore needs an independently restricted
  importer/database identity and approval/audit gate. No exact scheduled
  direct-restore script was found; root break-glass remains privileged and
  must not become a routine workflow.
- A fresh verified nine-application-database Production backup was captured
  before release at `/root/backups/zixuan-protection-20260915-Gfew2ngV`.
  All nine SHA-256/gzip checks pass, Zixuan has 121 CREATE TABLE entries, and
  the 16 Central canonical Finance + Zixuan Finance/Student/Staff full-row
  hashes match before release, after release, and after guard QA.
- Tencent CVM `ins-ayvwj3ms` in `ap-singapore` uses the 120 GB `vda` disk;
  account-side independent snapshots remain UNVERIFIED without CAM access.
  MariaDB `log_bin=OFF` and `sync_binlog=0` remain unchanged; the user has
  explicitly paused binlog configuration/restart pending a maintenance window.
- Cloud snapshots remain UNVERIFIED but do not by themselves block cleanup
  of exclusively QA/Test data. Do not resume cleanup until the real-backup
  guard false positive and privileged routine-import bypass are closed.
  Binlog/PITR is mandatory before first real Production data; no MariaDB
  restart or binlog change is approved in this incident-minimum round.

Last updated: 2026-09-15

## Active production target

- Server: `43.160.241.126`
- Project: `/www/wwwroot/43.160.241.126`
- Canonical SSH alias: `eschool-prod`

`183.240.79.48` is a legacy/rollback environment only. It must not be selected, connected to, deployed to, or migrated for Finance P1.

## Current area

Finance V2

## Go-live QA/Test data isolation — local candidate / no Production data change

- Branch `codex/go-live-data-isolation-complete` starts from the clean local
  isolation candidate whose parent baseline is Production source
  `4c88e46981619e57abf9cc56955ebd10dcdc5f3e`. It adds canonical
  `Production / QA-Test / Archived` metadata without renaming, rewriting, or
  deleting any master, workflow, or financial record.
- Central Finance dashboards, reports, totals, Fund Accounts, Categories,
  student/receivable views, pending collections, collection handovers, import
  batches, and Group Import V2.2 lookups exclude QA/Test and archived records
  by default. A QA/Test School classification is inherited by its dependent
  read models, while shared Fund Accounts retain their explicit Group and
  School-allocation boundaries.
- Only central Super Admin or Head Finance identities may request the audited
  `Include QA/Test` history view. School-scoped Accountant/May and Front Desk
  identities retain their existing tenant/role boundary and receive clean
  empty/default views rather than broader access.
- Classification updates require existing Central School/Group scope, an
  audit reason, and append an immutable before/after audit record. QA/Test and
  archived records are read-only in Production workflows; direct write URLs
  fail closed. Existing Payment, Receipt, Ledger, posted operating documents,
  transfers, and confirmed handovers remain append-only and queryable through
  the authorized history/detail surface.
- Tenant-backed Student, Staff/identity, Fee, Fee Type, and Fee Item records
  use centrally audited `tenant:<school_id>` classification scopes. This keeps
  tenant-local numeric IDs collision-safe, verifies the active tenant database
  against the trusted School registry, filters School lists/Staff provisioning
  and Fee Assignment selectors, and makes QA identities ineligible for Central
  operations while leaving every source row intact. Central student projections
  inherit their tenant Student classification.
- The additive central metadata schema is available only through the exact-path
  `finance:migrate-data-isolation` runner. Partial or mismatched schema fails
  closed. Disposable fresh MySQL migration rehearsal passes, and no Production
  migration or Production data mutation has occurred.
- Focused isolation, role/scope, tenant-ID collision, registry mismatch,
  Student/Staff/Fee dropdown, no-write, Group Import/Excel, audit, and
  migration-runner regressions pass. Full regression passes 777 tests / 6,291
  assertions with four expected opt-in skips and existing PHP 8.5/PHPUnit
  deprecation notices only.

## Central Finance cutover control UI — local candidate / no cutover

- The initial Production deployment used
  `bba6c8e7491b4c55df8b309ab151e34c414e49f9`. During authenticated release
  QA, the unrelated profile page exposed a legacy date double-formatting 500
  for a Central user. The immediate descendant follow-up formats the canonical
  raw DOB value once and includes a regression contract before final browser
  QA.

- Branch `codex/central-cutover-ui` starts exactly from active Production
  source `056454940f3a359cfea234747491b45f0309f336`. It adds one dedicated
  Central control-plane page for selecting an authorized Finance Group School,
  viewing `Legacy / Ready / Central`, and configuring the receivable cutoff in
  the fixed `Asia/Yangon` business timezone. No migration or Production write
  is part of this candidate.
- Both cutoff saves and status transitions require a non-empty audit reason,
  an explicit confirmation checkbox, the selected canonical School Code, and
  a second browser confirmation. Cutoff and transition changes run under the
  existing row lock and append before/after records to the immutable Central
  Finance audit log. The page does not create Staff, Fund Accounts,
  Categories, Fees, Payments, Receipts, or Ledger entries.
- A Central Super Admin may control only a School that is an active member of
  an active Finance Group. Head Finance continues to require explicit Central
  School operate scope plus matching active Group operate scope. School staff,
  tenant identities, and cross-School direct URLs fail with 403.
- Cutoff edits stop after Ready/Central or after any real Central financial
  activity exists. A Central-to-Legacy transition remains blocked once any
  Central Payment, Expense, Other Income, Transfer, Handover, Funding, or
  Ledger history exists; permitted rehearsal transitions require a reason and
  remain audited.
- Focused authorization, validation, timezone, no-financial-write, audit, and
  rollback tests pass. Authenticated local Playwright passes at 1280 and 390 px
  with no page-level overflow, console error, or unauthorized School-staff
  access. Full regression passes 762 tests / 6,199 assertions with three
  expected opt-in skips and existing PHP 8.5/PHPUnit deprecations only.
- No cutover, schema migration, Production deployment, or Production data
  change has been performed.

## Bahan + Timecity Centralization setup — local candidate / no cutover

- Branch `codex/timecity-bahan-centralization-setup` starts exactly from
  Production `607903a618228abd57887c779aa7438e68fd6476`. It prepares the
  audited Bahan `SCH202616 -> MMBOWEN02` and Timecity
  `SCH202619 -> MMBOWEN03` identity transition without enabling cutover or
  creating a Payment, Receipt, Ledger entry, opening balance, allocation, or
  staff account.
- The forward-only central migration validates exact School/database
  ownership, unique canonical codes, complete history/sequence schema, and
  conflict-free audit history before locking and updating the two School rows.
  Legacy codes are retained only in `school_code_history`; canonical runtime
  lookup does not accept them. The read-only-by-default
  `centralization:migrate-school-codes` runner can execute only that exact
  migration and fails closed on registry, schema, history, or migration-state
  mismatch.
- Every fixed active-tenant registry and Gate A allowlist now expects
  `MMBOWEN02` and `MMBOWEN03`, so the exact mapping migration must run from the
  candidate release before its atomic application switch. Broad migration and
  rollback remain prohibited.
- Read-only Production inventory found both tenant databases active and fully
  migrated, both Schools in the Finance group, Head Finance central scopes for
  both, and tenant Student Code sequences ready at `000001`. It also found no
  formal Principal, School Accountant, Front Desk identity links; no
  School-owned formal Fund Account; no signed opening-balance audit; no active
  Accountant assignment; and no approved receivable cutoff. Those are explicit
  readiness blockers and must be completed through audited self-service flows
  before rehearsal/cutover.
- Bahan has no students or fee setup. Timecity has two test students and
  existing fee definitions but no stable import identities; neither School has
  Central Payment, Receipt, Ledger, Receivable, Pending Collection, or Handover
  rows. Timecity students `202601901` and `202601902` remain cleanup-eligible,
  but were not deleted or archived.
- Student Import V2 uses an explicit canonical allowlist for Zixuan, Bahan,
  and Timecity (`MMBOWEN01/02/03`). Tenant database identity and authenticated
  `school_id` are still cross-checked server-side; other Schools remain denied.
- Fresh Production backup
  `/root/backups/centralization_setup_20260914T050644Z` passed 8/8 SHA-256
  checks. Disposable restores of Bahan and Timecity matched all 121 tables,
  1,215 columns, 567 indexes, and every base-table row count, then were dropped.
  All Production migration runners passed read-only schema/history preflight;
  no Production migration or data write occurred.
- Local mapping, no-write, canonical-only, Central Finance scope, Group Import
  V2.2, Student Import, invitation, role, and migration-runner regressions pass.
  The exact migration also passes a disposable fresh MySQL rehearsal. Final
  Production preflight also verified that the runner's exact central path is
  registered with `ProductionMigrationGuard`; an initially missing guard entry
  failed closed with zero write and was fixed before deployment. Final full
  regression passes 757 tests / 6,101 assertions with three expected
  opt-in skips and existing PHP 8.5/PHPUnit deprecations only. Production
  deployment and execution of the exact central migration remain separate
  Human Gates; cutover remains stopped.

## Student Code self-service redesign — rebased local candidate

- Branch `codex/student-code-rebase-current-production` starts exactly from
  current Production source `8a03762e481f680bbebd3f96d46eb30586516c59`
  and semantically carries forward only the Student Code redesign from
  `ccf4158decd448eeedf1b75d376f319399dd20e7`.
- New Students receive an immutable six-digit `000001` sequence scoped to
  `school_id`. Allocation runs inside the Student transaction under a
  sequence-row lock; the existing `school_id + student_code` unique key is the
  final concurrency gate. Soft-deleted and inactive Students keep their
  identity reservation, so numbers are never reused.
- Student create and accepted online applications allocate server-side codes;
  create/edit UI no longer submits an editable Student Code. Student Import V2
  now accepts a School-scoped text Import Reference, generates Student Code at
  confirm, and uses a unique `school_id + import_reference` key for replay and
  concurrent exactly-once protection. Older V2 headings remain read-compatible
  as import references, not as canonical codes.
- The legacy CSV self-service path follows the same rule: its current template
  uses `import_reference`, old `student_code` headings are compatibility aliases
  only, and the canonical code is generated under the same locked sequence.
- Search, Dify API context, Central profile projection, fee/student displays,
  and Central payment import resolve the canonical Student Code. The Central
  payment workbook lookup no longer compares Student Code to `admission_no`.
- The additive tenant migration creates `student_code_sequences`, adds the
  nullable import idempotency reference, seeds above existing six-digit codes,
  and fails closed on partial schema. Production use is restricted to the
  exact-path, registry-validated `student-code:migrate` runner. No Production
  migration has been executed.
- Read-only Production verification for Timecity admissions `202601901` and
  `202601902` found zero tenant fee/payment references and zero Central
  receivable, Payment, Receipt, Ledger, Pending Collection, or Handover
  references. Only their non-financial Central student profile projections
  exist; they remain cleanup-eligible for the later rehearsal, and are not
  deleted or archived by this branch.
- Rebase verification passes 39 focused tests plus two PHP 8.5
  deprecation-classified passes (258 assertions), including Student Code,
  Student Import V2, legacy import, Central receivable/payment, Central Finance
  UX, and release-asset contracts. A disposable fresh MySQL rehearsal passes
  the additive DDL, real row-lock concurrency, and concurrent import
  exactly-once cases (1 test / 5 assertions); its database is dropped during
  teardown.
- Authenticated local Playwright passes at 1280 and 390 px: Student Code is
  server-generated/read-only, the form submits no `student_code`, leading-zero
  guidance remains visible, and there is no page-level overflow or console
  error. Full regression passes 745 tests / 6,052 assertions, plus two
  deprecation-classified passes and two expected opt-in skips. The inherited
  Central Finance student-view, Fund Account management, Ledger, reporting,
  audit snapshot, import-batch, sidebar, and brand-asset regressions remain
  green.

## Central Finance UX bugfix batch — local PASS

- Branch `codex/central-finance-ux-batch` starts exactly from the accepted UI
  Polish P3 Production source `34aa909e47129541a5797b04ba292399a5764463`.
  Head Finance can open an authorized School student-collection detail by
  direct URL without mutating the selected operating context; an unauthorized
  School remains denied, and all collection writes still require the explicit
  current-School context and existing capability checks. If a historical
  Central profile's tenant Student row is no longer available, only the
  optional-fee panel degrades to empty; the canonical read-only detail remains
  visible instead of returning 404.
- The Fund Account list no longer renders the readiness card or oversized
  action dropdown. It links to a scoped Head-Finance-only management page that
  reuses the existing configuration, allocation, adjustment, and audit flows.
  Backend cutover/readiness services, health checks, and guards remain intact.
- Standard Ledger uses the Bootstrap paginator, removing the unbounded
  Tailwind navigation SVGs. Group reports add date/School/currency filters,
  School comparison, daily trends, category analysis, and canonical-ledger
  drill-downs while keeping every aggregation separated by currency.
- Audit snapshots render a field-level Before/After table with the original
  JSON retained as secondary technical detail. Import batches expose explicit
  validation-failed, pending-confirmation, completed, and cancelled states,
  plus read-only error details/CSV and a correction link that creates a new
  batch; preview/error inspection writes no Finance records and preserves the
  original batch audit trail.
- Sidebar state is derived from the current route: only the current leaf is
  active, while parent rows express expanded/ancestor state. Local browser
  acceptance passes at 1440, 1280, and 390 px with no page-level overflow,
  console/page error, raw key, giant paginator icon, or HTTP 404/500 on the
  audited paths. Same-origin absolute `/storage` logo and favicon settings are
  now existence-checked before rendering, so stale settings use the approved
  static brand fallback instead of creating browser 404s; external CDN assets
  remain supported. Full direct PHPUnit regression passes 739 tests / 6,018
  assertions, plus two deprecation-classified tests and one expected opt-in
  skip, with existing PHP 8.5/PHPUnit deprecation notices only.
- The deployed Central Finance UX release required no migration or Production
  data write and makes no
  Finance posting/calculation, permission-boundary, or Handover-rule change.

## UI Polish P1 — local PASS

- Branch `codex/ui-polish-p1` starts exactly from active Production source
  `03278a4fc37e01d42c85e82c1e6a3482277978fa` and contains only the verified
  School-list `User -> roles` eager-load/query-detector safeguard plus the
  approved P1 presentation changes.
- Shared header/sidebar behavior now has one named menu search, consistent
  desktop/mobile controls, reliable logo fallbacks, readable disabled and
  read-only states, keyboard focus treatment, and responsive layout guards.
  Common 400/403/404/503 and empty states use accessible, non-leaking shared
  presentation components. Long School, Fund Account, Transfer, Handover, and
  Student Import forms retain a visible primary-action region.
- English and Simplified Chinese catalogs cover the visible dashboard,
  sidebar, School, and Student raw keys. Dynamic table/empty output rejects
  null, literal `undefined`, and missing translated labels. Finance and Student
  tables keep page width stable while preserving scoped table scrolling and
  named icon actions.
- Dashboard charts validate both target DOM and input data, show an empty state
  for zero-value donut data, and contain asynchronous render failures. The
  School edit dialog explains why canonical School Code is locked.
- Authenticated browser acceptance covers Super Admin, Head Finance, School
  Accountant, and School Admin at 1440, 1280, and 390 px across Dashboard,
  Schools, Finance Groups, Central Finance, Group Import, Fund Accounts,
  Ledger, Statements, Payments, Students, Student Import, and the generic 403.
  Checked pages have no page-level overflow, raw key, literal `undefined`,
  unnamed interactive control, or console error. Principal and Front Desk
  acceptance remains intentionally deferred until formal QA identities exist.
- Full direct PHPUnit regression passes 725 tests / 5,611 assertions with one
  expected opt-in skip; PHP 8.5 reports only existing dependency deprecations.
  No route, permission, schema, migration, amount-calculation, Handover rule,
  Production data, deployment, Phase 5.5B, or homepage change occurred.

## UI audit prerequisite — School list User role N+1 local PASS

- Branch `codex/ui-audit-querydetector-nplus1` starts exactly from active
  Production source `03278a4fc37e01d42c85e82c1e6a3482277978fa`.
- The Super Admin `/schools` Bootstrap table calls `SchoolController::show()`.
  Serializing each School appended `User::role`, whose fallback queried
  `roles()` once per admin because the nested relation was not loaded. The
  list query now eager-loads `user.roles` and hides the relation payload before
  serialization, preserving the existing scalar `user.role` response shape.
- A 12-School regression proves one roles query and at most three total list
  queries for School, User, and Role hydration; query count remains constant as
  the page grows. The authenticated local browser renders 10 of 12 matching
  synthetic rows with no QueryDetector dialog or `User -> roles` detector log.
- QueryDetector remains available and logs findings, but no longer injects
  browser alerts. Debugbar is opt-in outside Production and is forced off in
  Production even if `DEBUGBAR_ENABLED` is accidentally true.
- Targeted School/role/config regression passes 11 tests / 83 assertions.
  Full direct PHPUnit regression passes 720 tests / 4,694 assertions with one
  expected opt-in skip; PHP 8.5 reports only existing dependency deprecations.
- No migration, schema change, Production data write, deployment, Finance
  business-rule change, Phase 5.5B change, or homepage/assets change occurred.

## Final Quality Gate Group Import template blocker fix — local PASS

- Branch `codex/final-quality-gate-template-fix` starts exactly from the
  accepted GitHub main/Production/audit SHA
  `26022c5cb7e8d0f94ba481ee7475c9d95f6cf886`.
- Formal Group Finance Template V2.2 lookup filtering now rejects compact
  test-prefix names such as `testzixuan` and explicit preview/dummy/sample
  markers. The retained Production master records are neither changed nor
  deleted; they are only excluded from the distributed workbook.
- Numeric-looking School, Fund Account, and Category codes are written as
  explicit Excel strings in both human-readable lookup sheets and hidden
  validation ranges. Leading zeroes and text identity survive XLSX
  save/reopen, including the School-scoped Fund Account dropdown ranges.
- Group Import targeted regression passes (18 tests, 181 assertions). Full
  direct PHPUnit regression passes (716 tests, 4,681 assertions; one expected
  opt-in skip) with the Quality Gate's process-only 1024 MB allowance.
- No migration/schema change, financial write, Production data change,
  deployment, Finance posting-rule change, Phase 5.5B change, or
  homepage/assets change occurred. Production deployment and re-running the
  authenticated XLSX gate remain a separate Human Gate.

## Migration runner Production release + final quality gate

- Approved commit `ca217f62d3f1800b089adbb4dd69e162629c0040` and browser follow-up
  `26022c5cb7e8d0f94ba481ee7475c9d95f6cf886` are deployed as the immutable
  active release after verified ancestry, fresh nine-database backups, release
  guards, and read-only migration/schema preflight. GitHub `main`, Production,
  and the audit checkout are aligned to `26022c5`.
- P31/P32, Round 5, and Finance P2/P3 runners reject unknown, missing, and
  wrongly mapped targets immediately with exit code 1; valid allowlisted
  targets pass read-only validation. No Production migration or Finance write
  was executed.
- Authenticated Production browser QA covered School Admin, Head Finance, and
  Super Admin. School Admin Finance/direct URLs return 403, Head Finance is
  restricted to Central Finance, Super Admin authenticates without a School
  Code, and the School management form exposes canonical `MMBOWEN##` codes.
- Browser QA found three pre-existing compatibility defects fixed and deployed
  in the follow-up release: the removed `database_backups` table caused its obsolete
  list endpoint to return 500, the unused resource `schools.create` route called
  a nonexistent controller action, and the V2.2 workbook link retained a V2.1
  label. A missing dashboard chart target also now exits without an ApexCharts
  console error.
- The deployed follow-up passed 716 tests / 4,661 assertions with one expected
  opt-in skip, plus the disposable MySQL rehearsal (1 test / 20 assertions).

## Final Quality Gate migration runner fail-closed fix — local PASS

- Branch `codex/migration-runner-fail-closed` starts exactly from accepted
  Production/audit baseline `177e62b31bf75dbc89f1a31f63aba68e5385e5c7`.
- `finance:migrate-p31-p32`, `finance:p2-p3-migration-safety`, and
  `schema:round5-integrity` now return explicit boolean rejection from target
  validators. Registry, connection, School/database identity, base-schema, and
  unexpected validation failures terminate with exit code 1 and a specific
  mismatch/fail-closed log instead of coercing `Command::FAILURE` to `true`.
- P3.1/P3.2 and P2/P3 execute/rollback paths complete a read-only validation
  pass across the entire selected allowlist before the first schema write.
  Later-target failure therefore leaves every earlier tenant unchanged and the
  runner never continues to another School after an error.
- Runtime regression covers reordered valid registries, registry mismatch,
  missing tenants, wrong School/database mappings, later-target validation,
  no-write guarantees, non-zero exits, and valid allowlisted verification.
  Focused migration-runner regression passes (51 tests, 243 assertions), the
  disposable local MySQL rehearsal passes (1 test, 20 assertions), and full
  regression passes (712 tests, 4,646 assertions; one expected opt-in skip).
- No Production connection, migration, schema/data write, deployment, Finance
  business-rule change, Phase 5.5B change, or homepage/assets change occurred.

## Round 3–5 consolidated candidate — local PASS

- Branch `codex/round3-5-consolidated` starts exactly from active Production
  `f3a898de68d8f2b4d3320e6e508365e572f9071a` and integrates Round 3
  `ff00b1d15a490b1dc58814d541c781405377abaa`, Round 4
  `24d6b73dcfaa62b7334473b32b3c2ef4ac4fecbf`, then Round 5
  `0a67c912765f3ca9826269239912ab1bfe1ed8e7` in that order.
- Semantic conflict resolution preserves Round 3 payment locking and Round 4
  immutable payment-level FX snapshots. The Production migration guard keeps
  both independent exact-path runners without exposing a broad migration path.
- Consolidated Finance, Handover, API role isolation, payment concurrency,
  import identity/schema, partial-schema, and deployment-guard regression passes
  (100 tests, 511 assertions). Full regression passes (681 tests, 4,455
  assertions; one intentional opt-in rehearsal skip).
- The disposable local MySQL rehearsal applies the Round 4 migration before the
  Round 5 migration, exercises orphan/duplicate fail-closed behavior and
  concurrent Student Code identity insertion, and passes (1 test, 17 assertions).
- Read-only Production preflight confirms all seven registry mappings. Round 4
  is eligible and unexecuted for all seven tenants. Round 5 is unexecuted and
  correctly blocks on one duplicate non-null transfer-reference group in
  `SCH202615`; the other six tenants have no detected data-integrity issue.
- Release runtime preflight correctly blocks because active `.env` resolves to
  an old release instead of `/www/wwwroot/shared/eschool/.env`, and shared
  `public/storage` is `root:root` and not writable by `www`. These require a
  separate audited data remediation and operations repair before deployment.
- No Production migration, schema/data write, deployment, or financial write
  was performed. Phase 5.5B and homepage/assets remain untouched.

## Round 3 P1A authorization and concurrency — local candidate

- Branch `codex/round3-p1a-authorization-concurrency` starts from current
  Production baseline `f3a898de68d8f2b4d3320e6e508365e572f9071a`.
- Student, Guardian, Teacher, and Staff API tokens now carry explicit family
  abilities. Wildcard and cross-family tokens fail closed; Staff and transport
  expense APIs cannot be reached with Student, Guardian, or Teacher tokens.
- School Admin is denied from tenant Finance in route middleware and service
  authorization, including direct URLs and mobile Finance routes. Super Admin
  and the established Principal, Accountant, Front Desk, Head Finance, Cashier,
  Driver, and Helper boundaries remain explicit and unchanged except for the
  requested School Admin deny.
- Legacy offline payment processing runs in a tenant transaction with locked
  receivable, setup, student, payment aggregate, installment, and optional-item
  rows. Replay and concurrent full-payment delivery create only one payment and
  one canonical ledger row.
- Bank transfer creation locks both current-School Fund Accounts in deterministic
  order and rechecks the source balance after the lock. A two-process 80 + 80
  race against a 100 balance permits exactly one transfer.
- Mobile payment confirmation/listing is authenticated, tenant/owner scoped,
  read-only, state-aware, and returns only a normalized minimal response; signed
  webhooks remain the sole settlement writer.
- Targeted security/concurrency regression passes (44 tests, 242 assertions).
  The complete 653-test PHPUnit inventory passes when run file-sharded to avoid
  the legacy single-process 128 MB test reporter limit. No migration was added
  or executed; Production data, Phase 5.5B, homepage, and assets are untouched.

## Round 4 P1B financial history and currency integrity — local candidate

- Branch `codex/round4-p1b-financial-history` starts from the current
  Production release `f3a898de68d8f2b4d3320e6e508365e572f9071a`. The
  separate Round 3 local candidate passed before this work began but is not
  deployed and is deliberately not part of this Production baseline.
- Fund Account balances and the unified transaction register include only
  successful fee rows and project every money-bearing row into the Fund
  Account's own currency. Mixed currencies are subtotalled independently;
  ambiguous foreign legacy rows fail closed instead of being silently added.
- Fund Account currency becomes immutable after any opening balance,
  adjustment, receipt, expense, fee, transfer, or Group transfer history.
  Expense and Other Income amount/FX fields are immutable after creation, and
  the MMK-only Expense import refuses foreign-currency Fund Accounts.
- Each new offline compulsory or optional payment stores an immutable,
  payment-level FX snapshot and links its child payment rows to that snapshot.
  The legacy aggregate `FeesPaid` FX fields are no longer overwritten by later
  payments.
- Confirmed Student Fee Assignment items now preserve original currency
  amount, FX rate, and MMK equivalent. Offline amount authority, Outstanding
  Fees, Student Ledger, print, and export views consume those confirmed
  snapshots rather than mutable current Fee Setup; all totals remain separated
  by currency.
- The additive tenant migration is available only through
  `finance:migrate-currency-history`, a read-only-by-default, fixed active-School
  code allowlist, exact-path, canary-first runner. Production execution remains
  a separate schema-migration Human Gate.
- The exact migration rehearsed successfully against the local test tenant.
  The focused security/finance regression passes (130 tests, 521 assertions),
  local browser acceptance passes (2 scenarios), and the complete PHPUnit
  suite passes in bounded-memory partitions (655 tests, 4,253 assertions; zero
  failures/errors). The legacy Excel/Zip tests exhaust the PHP 128 MB limit
  after earlier suites retain process memory, but pass in their bounded
  partition without a code or assertion failure.
- No Production migration, deployment, or Production financial/data write was
  performed. Phase 5.5B and homepage/assets remain untouched.

## Round 5 P1C schema/import/deployment integrity — local candidate

- Branch `codex/round5-p1c-schema-import-deployment` starts exactly from the
  current Production/main SHA `f3a898de68d8f2b4d3320e6e508365e572f9071a`.
- Every approved legacy-import School uses the tenant-local text identity
  `school_id + student_code`. The legacy template makes Student Code a Text
  column, preserves leading zeroes, rejects duplicate rows before writes, and
  commits Student/Guardian/identity atomically. Admission/login identifiers use
  UUID entropy and no longer derive from the latest Student ID. The database
  unique key is the final concurrent-import gate.
- The additive Round 5 tenant migration preflights identity, Bank Account, and
  Bank Transfer duplicates, orphans, School mismatches, actor references,
  amounts, account pairs, statuses, and transfer references before its first
  ALTER. It adds exact composite unique/FK and CHECK constraints and verifies
  them from `information_schema`. Existing create migrations now reject partial
  tables rather than recording `hasTable()` as success.
- `schema:round5-integrity` is read-only by default, validates the fixed seven
  School-code/database registry mapping in a complete first pass, and can run
  only the two exact allowlisted files after a separate Production migration
  approval. No broad or discovered migration is reachable.
- Immutable release guards resolve `.env`, `storage`, and `public/storage` to
  exact approved shared paths, verify owner/runtime-user access, writable
  targets, bootstrap cache, and checksum-required assets. A wrong or broken
  symlink blocks release creation/switching.
- Read-only Production-shaped preflight found no identity/account/actor/orphan
  mismatch, but found one duplicate non-null transfer-reference group in
  `SCH202615`; the new schema runner correctly remains fail-closed until a
  separately audited remediation is approved. Runtime inspection also found
  the active `.env` resolves outside the approved shared path and
  `public/storage` is owned `root:root` and not writable by `www`; the hardened
  release guard will block until operations repairs those links/ownership.
- Local focused tests pass (21 tests / 109 assertions). Disposable MariaDB DDL,
  orphan/duplicate preflight, partial-schema detection, and two-process
  concurrent Student Code insertion pass (1 test / 12 assertions). Full
  regression passes (653 tests / 4,277 assertions; one intentional opt-in
  rehearsal skip). No Production migration, schema/data write, deployment,
  Finance business-logic, Phase 5.5B, or homepage/assets change was made.
## Operational UX rebase + School Code finalization — local candidate

- Branch `codex/operational-ux-rebased-final` starts exactly from approved
  Round 3–5 PASS baseline `6e8ce3cb97ab32fb48b866b1e97de156ed8e7220`
  and semantically reapplies Operational UX commit
  `f31de0c0c8750acba715831e3994d7b94c928164`; Production is unchanged by this work.
- Group Finance Import Template V2.2 has School-driven canonical codes,
  School/document-type category lists, mutually exclusive positive Income or
  Expense validation, payment-method selection, text-preserved account codes,
  and a reconciliation-only Statement Balance. The distributed template has no
  UAT sample rows, while shared accounts retain an explicit School per row.
  The formal workbook lookup builder also excludes master data explicitly
  labelled with `UAT` or `TEST`; the retained Production audit/UAT records are
  not changed or deleted.
- Zixuan's actual canonical `schools.code` migrates from deprecated
  `SCH202615` to `MMBOWEN01`. The old value is retained only in immutable
  `school_code_history` audit data and cannot resolve login, API, Finance,
  import, staff, context, report, or search traffic. Runtime resolution is
  exact, uppercase-normalized canonical lookup only.
- New School Codes use the locked zero-padded `MMBOWEN02...` sequence. Super
  Admin may supply a full `MMBOWEN##` value; server-side validation, uppercase
  normalization, current-code uniqueness, audited-history reservation, and a
  sequence row lock are enforced in the same central transaction as School
  creation.
- Forgot-password tokens remain single-use at 60 minutes. Staff invitation and
  first-password tokens use a separate tenant table/broker, expire after 24
  hours, invalidate older tokens, and preserve School/user ownership checks.
- The two additive schemas have a default read-only, fixed-tenant,
  partial-schema-failing exact-path runner. Zixuan ownership and all existing
  `MMBOWEN` formats are checked before the first MySQL DDL, and every downstream
  fixed registry now uses `MMBOWEN01` after the identity cutover.
- The Round 5 transfer-reference constraint is unique across active rows through
  a generated reference column. Cancelled/soft-deleted transfer history remains
  immutable and auditable instead of being rewritten merely to add the index.
- Production-shaped disposable MySQL proves the exact migration changes the
  synthetic Zixuan row to `MMBOWEN01`, records `SCH202615` only in audit
  history, initializes sequence `MMBOWEN=2`, and creates both unique keys plus
  the School FK. The fresh invitation-token migration also passes.
- Changed-surface regression passes (117 tests, 627 assertions). Full regression
  passes (702 tests, 4,584 assertions; one intentional opt-in rehearsal skip).
  Local browser acceptance passes homepage, canonical School Code login, and
  tenant-bound reset/invitation form checks without submitting data.
- No Production migration, schema/data write, deployment, Phase 5.5B, homepage,
  or asset change occurred.

## Round 2 P0B financial integrity — Production

- The approved `c0ba941d2c4b1a8bdbfb53e4d766cbbc9a2f0e37` release and its
  QA-only Paystack 4xx forward-fix descend directly from the prior active
  Production release `06b9c7c2a72736d748b8edfd7c7a5aefcb26a302`.
- Stripe, Razorpay, Paystack, and Flutterwave settlement now serializes on the
  existing server-created `payment_transactions` identity with a database
  transaction and row lock. Replays are no-ops, concurrent deliveries cannot
  duplicate settlement rows, failure events cannot overwrite success, and no
  webhook creates a transaction.
- Legacy offline compulsory, installment, and optional payments now rebuild
  canonical amount, due charge, currency, exchange rate, and selected-item
  totals from locked School-owned Fee Setup/receivable rows. Negative, zero,
  overpayment, cross-School, duplicate, and client-tampered values fail closed.
- Focused financial/security regression passes (65 tests, 252 assertions),
  including a two-process database race. The complete PHPUnit suite passes
  (640 tests, 4,213 assertions; zero failures/errors).
- No schema migration is added or executed and no Production financial data is
  written. Phase 5.5B, homepage/assets, and established Finance workflow remain untouched. Release
  QA uses only invalid-signature requests, read-only pages, and rolled-back
  amount-authority checks; it creates no Payment, Receipt, or Ledger entry.

## Round 1 P0A security hardening — local candidate

- Branch `codex/round1-p0a-security` starts from Production/main baseline
  `a5a715ee17a326698be3cfee428fdcb6998cdb70`; Production remains untouched.
- Teacher API uploads require an explicit non-wildcard token ability, Teacher
  role, School/resource ownership, and positive MIME/extension allowlists.
  A versioned Nginx server-block include prevents `/storage` and `/uploads`
  from falling through to a PHP handler.
- Fee Flutterwave completion requires the provider HMAC signature, provider
  transaction verification, and exact pending reference/amount/currency/School
  matching. Subscription Razorpay, Flutterwave, and Paystack webhooks verify
  their provider signatures and cannot create a transaction from webhook data.
- Web database restore is removed, including its uploaded SQL execution,
  tenant truncation, and restore-time broad migration path.
- Production generic migration commands fail closed. Only exact migration
  files selected by the fixed allowlisted release runners can reach Laravel's
  migrate/rollback commands; the web updater no longer runs broad migration.
- Local security targets pass (88 tests, 284 assertions), and the complete
  PHPUnit suite passes (621 tests, 4,132 assertions; zero failures/errors).
- No Production migration, Production data write, deployment, homepage/assets,
  Phase 5.5B, or established Finance settlement flow is changed by this local
  candidate.

## Phase 5.5B final acceptance correction — local candidate

- The candidate starts at Production SHA `29dfb22b36f7547487e3b5566a73e3f33fd7fb73`.
- Handover now exposes collector-scoped Add/Remove/Submit/Cancel controls and Head Finance-only Hold/Reject/Confirm controls while retaining the canonical Pending Collection confirmation service as the only Payment/Receipt/Ledger writer.
- Draft creation supplies the schema-required zero expected amount; submit replaces it with the server-calculated sum of attached items.
- Removed draft items remain auditable lifecycle rows. A stale Pending item fails the complete confirmation transaction, and browser-form business errors return visible validation feedback rather than HTTP 500.
- Focused Handover, Pending Collection, school-scope, identity, payment, exactly-once, audit, and all-or-nothing regressions pass locally. Production deployment and authenticated browser acceptance remain gated.

## Phase 5.5A — local Pending Collection candidate

- Added an additive Central `central_finance_pending_collections` lifecycle document and a narrow Front Desk submission scope.
- Front Desk submission records only a Pending acknowledgement and audit record; it cannot write Payments, Receipts, Ledger entries, or Fund Account balances.
- Head Finance-only confirmation reuses `CentralFinancePaymentService` with the Pending UUID as its idempotency identity.
- This candidate has not been deployed or migrated in Production.

## Pending local hotfix — Teacher activation status

- The Teacher edit modal now carries the canonical `status` value, requires a
  lifecycle reason only when it changes, and keeps `users.status` and
  `deleted_at` synchronized. The update path is explicitly permission- and
  School-scoped, records the existing lifecycle audit, and preserves the
  Xiaobailong status notifier. This hotfix is local only pending isolated
  browser QA; it has no Finance behavior.

## Phase 4A — Zixuan daily Finance navigation

- Zixuan (`SCH202615`) uses the Central Finance daily read workspace after
  cutover; tenant Fee Setup remains the canonical School/Tenant workspace.
- Legacy School Finance remains available only for historical/read-only data
  with server-side legacy write guards. Current Central and legacy totals are
  not combined.
- HQ / School Funding is a Head Finance-only workflow. School Accountants do
  not receive the menu entry and its direct workspace or mutation endpoints
  are server-authorized as 403.

## Pending local change — selected-School read parity and receipt branding

- A selected Central Finance School now has a strict **read** account scope:
  dashboards, Fund Account directory/statements, reports, Standard Ledger,
  payment exports, and document detail use that School's accounts only. Head
  Finance retains the existing broader authorised account set only for write
  selectors, so authorised HQ collection and funding rules are unchanged.
- Central receipts normalize school-logo paths with the same contract as the
  authenticated header: storage-relative paths resolve through `Storage::url`,
  existing public storage paths and absolute URLs are preserved, and the
  generic vertical logo is used only when no logo is configured.

## Zixuan Central Finance Fresh Start — Production Write UAT

- Production active release: `3be9f22078da4d268d9e9147f1432030c1a75e84`.
- Zixuan (`SCH202615`) is `central`. Its legacy tenant Finance writers are
  server-side blocked; Central Finance is the sole writer for new Zixuan
  Finance documents. This must not be rolled back after the recorded UAT
  transactions.
- The approved Fresh Start Receivable cutoff is `2026-09-01 00:00
  Asia/Rangoon`, stored through the audited Central Finance cutover service.
  Source assignments before that time are intentionally excluded from Central
  Receivable sync and readiness reconciliation; they are not imported.
- The cutoff is interpreted consistently as Yangon business time for the UI,
  Central timestamp, and tenant Fee Assignment source comparisons. It must
  never be inferred from the server clock or `APP_TIMEZONE`.
- Production reconciliation at the cutoff baseline: Student Profiles
  `source=3`, `central=3`, `missing=0`, `stale=0`, `mismatched=0`; Receivables
  in the cutoff scope `source=0`, `central=0`, `missing=0`, `stale=0`,
  `mismatched=0`. The retained `CENTRAL-PROD-UAT-` receivable remains UAT data
  and is outside the formal post-cutoff source scope.
- The approved `CENTRAL-PROD-UAT-` write set is exactly two Payments/Receipts
  totaling 123,456 MMK and one append-only 23,456 MMK refund against the
  designated UAT receivable and Fund Account. Final UAT result: Due 123,456;
  Paid 100,000; Outstanding 23,456; Money In 123,456; Money Out 23,456;
  closing balance and net operating income 100,000; Operating Expense 0.
  No Expense, Other Income, Transfer, Handover, HQ Funding, or tenant
  `FeesPaid` record was created by this UAT.
- The tenant write guard resolves the Central School from the already trusted
  current tenant database connection, never from tenant-local `users.school_id`.
  This prevents a local ID collision from bypassing a Central cutover.

## Zixuan Central Finance Fresh Start — Production Write UAT Phase 2

- Phase 2 used only `CENTRAL-PROD-UAT-` configuration, references, Fund
  Accounts, categories, and the retained UAT student/receivable.  Two synthetic
  zero-opening accounts were added: Zixuan MMK-2 and HQ MMK.  No real Fund
  Account, Opening Balance, or historical tenant Finance record was changed.
- Other Income and Expense lifecycle checks both proved idempotent creation,
  append-only void reversal, preserved original Ledger history, and audit rows.
  The retained approved reimbursement created exactly one Expense; its pending
  request had no balance or Ledger effect.
- Direct transfer, Fund Handover, and HQ↔School Funding proved pending
  neutrality, exactly-once confirmation, same-account/insufficient/unauthorized
  denial, and zero Operating Income/Expense impact for internal transfers.
- Expense Import and Payment Import each completed exactly one UAT batch.  The
  import framework rejected duplicate file/reference, payment overage, and an
  unauthorized Fund Account at preview.  CSV/XLSX Central Ledger, Payment/
  Receipt, and Fund Account Report exports generated from the same scoped read
  model as their pages.
- The UAT receivable's approved Production baseline includes Payment #3 of
  10,000 MMK into the UAT HQ account.  Its current result is Due 123,456 MMK,
  Paid 113,456 MMK, Outstanding 10,000 MMK.  Across the three UAT accounts:
  Money In 147,212 MMK, Money Out 37,256 MMK, closing total 109,956 MMK,
  Operating Income 113,456 MMK, Operating Expense 3,500 MMK, Operating Net
  109,956 MMK.  Tenant legacy Finance counts remained unchanged and the UAT
  student's tenant `FeesPaid` count is zero.

## Zixuan + HQ Central Fund Account Production UAT

- May Myat Mon's Central school-staff principal resolves only Zixuan and can
  read the two explicitly assigned `CENTRAL-PROD-UAT-ZXN-MMK` accounts. The
  Central HQ UAT account and a forged non-Zixuan School request are rejected
  server-side; no School scope or Fund Account scope was broadened.
- Head Finance reads the same canonical Central Fund Account and Ledger rows:
  Zixuan account 1 closes at 92,456 MMK, Zixuan account 2 at 7,000 MMK, and
  the HQ UAT account at 10,500 MMK. The scoped UAT total is 109,956 MMK,
  exactly the sum of those three accounts. This is deliberately **not** a
  complete Group total because other Schools are not onboarded.
- Existing UAT HQ↔Zixuan internal-funding Ledger lines total 1,500 MMK in and
  1,500 MMK out, with zero Operating Income and Expense. Tenant Zixuan
  Finance counts and content hashes were unchanged before/after this read-only
  verification; no legacy Bank Account or tenant Finance record was created.

## Central Finance Gate A — local release candidate

Gate A is a schema-and-student-reference preparation release only. It contains
seven reversible additive central migrations, one reversible tenant Student UUID
migration, trusted-registry Student Financial Profile reconciliation, and a
fixed seven-active-school allowlist. Both operational runners are read-only by
default; the legacy-cutover runner has no execute mode. No Central Finance
workspace/routes, Finance document write paths, historical import, Ledger,
balance, opening-balance, or Group Operating Context write capability is part
of Gate A. Production and staging remain untouched.

## Active development pipeline — V2

`LOCAL → TARGETED TEST → LOCAL PLAYWRIGHT → REVIEW → PRODUCTION GATE`

Local development is the only active implementation/test environment. Use local/test databases and deterministic synthetic data for migrations, PHPUnit, Playwright, debugging, and finance write-path acceptance. Production is read-only until an explicit production gate is approved.

`staging.school.mmbowen.com` is **PAUSED / NOT PART OF ACTIVE PIPELINE**. Do not authenticate to, test, debug, deploy to, delete, or otherwise modify staging without separate authorization.

## eSchool source reconciliation

Transportation expiry reminders now use the trusted central School registry to process each active tenant independently. The scheduled `transport:expiry-reminder` command accepts no tenant/database input, switches only to a registered tenant database, skips tenants without the transportation schema, isolates a tenant failure, supports a zero-write `--dry-run`, and restores the original school configuration plus the central default connection after each tenant and at completion. Local SQLite characterization covers two-tenant isolation, inactive/schema-missing tenants, a failed tenant followed by a valid tenant, repeatable dry runs, and connection restoration. Production and staging remain untouched.

Installer reconciliation retains only the custom source required by the optional installer flow: purchase-code and PHP symlink capability steps, the folders-to-purchase-code transition, and valid installer asset/finish links. The normal application remains unaffected while `INSTALLER_ENABLED` is disabled. Published installer components and unchanged steps remain package-owned; Production's database troubleshooting copy is intentionally excluded. Production and staging remain untouched.

Release asset portability now has a versioned, checksum-verified contract in `release/required-assets.tsv`. It defines the two PDF fonts, shared Font Awesome 4.7 dependency set, and CKEditor Promise fallback required to reproduce the authoritative release without importing unknown binary assets into Git. `sh scripts/release/verify_required_assets.sh` is read-only and offline; it fails release preflight on missing or mismatched assets. Duplicate, temporary, and unreferenced Production assets are excluded. Production and staging remain untouched.

## Completed and production-verified

- P0: Income and Expense require a valid Fund Account.
- P0: Cross-school, inactive, and deleted Fund Account protection.
- Existing Excel paid-fee import production regression passed after P0.

## Current phase

## Phase 4A — Zixuan School Finance navigation and read unification (local implementation)

- The Zixuan School sidebar now switches daily Finance navigation only when
  its *trusted current tenant connection* resolves to the Central registry,
  the School is explicitly approved for the UI rollout, and its Central
  Finance cutover state is `central`. Non-cutover Schools retain the existing
  tenant Finance navigation.
- For this approved Zixuan rollout, the legacy daily Finance and Expenses
  menus are removed; tenant Fee Setup remains visible because it is School
  academic/master data, not a Central financial writer. Central menu and
  server-side Central principal/scope checks remain the only route to current
  Finance operations.
- Retained legacy Finance GET views now carry a conspicuous historical-only
  notice and suppress current create/import/edit/delete controls and row
  actions after cutover; server-side tenant write guards remain authoritative.
  They do not combine tenant values with Central values. No Finance posting,
  balance, scope, cutover, schema, or historical data behavior was changed.

## Phase 4B — Zixuan Student Finance cutover UX (local implementation)

- The School Student Finance summary remains a read bridge to the canonical
  Central Student Finance workspace. It now exposes distinct links for the
  student summary, receivables, payment history, receipts, and (only for an
  explicitly authorised Central operator) collection. No link writes to the
  legacy `fees_paids` path.
- Central receivable, payment, and student-ledger searches accept the stable
  Student Code as well as name and GR/admission number. Student Collection
  distinguishes a student with no receivables from a fully paid student, and
  its canonical payment history surfaces payment method, receiver, Fund
  Account, and receipt.
- This is presentation/read-query work only: Payment, Receipt, Ledger,
  balances, Fund Account scope, cutover checks, exactly-once keys, and legacy
  historical boundaries are unchanged. No migration is required.
- School Accountant and Principal identities now use an explicit **School
  Finance** facade over those same Central services: their School is fixed by
  trusted scope, their navigation and headers are School-branded, and their
  reports and Fund Account balances remain School-scoped. Head Finance retains
  the full Central Finance workspace. Group surfaces and physical shared
  account totals are not exposed through the School facade.

## Zixuan Student Import V2 — local implementation

- Student Import V2 is an explicit Zixuan (`SCH202615`) pilot. It uses a
  tenant-local `student_import_identities` table with a unique
  `school_id + student_code` identity; codes are text and retain leading
  zeroes. Legacy admission numbers and Central Student UUIDs remain intact.
- The V2 workflow is preview-first and cache-backed: preview creates no
  Student, Guardian, fee assignment, Receivable, Payment, Receipt, or Ledger
  data. Confirm processes only New rows, rechecks the unique identity in the
  tenant transaction, then reuses `UserService` plus the canonical compulsory
  `StudentFeeAssignmentService` confirmation/publisher path.
- A missing Central cutover or compulsory Fee Setup is a Preview Conflict;
  optional fees are not created. The legacy CSV bulk import remains unchanged.
- This change is local only and includes an additive tenant migration that has
  been exercised against disposable SQLite. Production is untouched.

## Zixuan Student Import V2.1 — simplified template (local implementation)

- The official Zixuan V2.1 XLSX uses one human-readable Student Name and one
  Guardian Name instead of culturally unsafe first/last-name splitting. It
  keeps only Student Code, placement, core identity/contact fields, optional
  Student Mobile and optional Guardian Email, Notes, and supported configured
  custom fields. Payment and fee-amount columns are absent.
- A tenant additive, forward-only migration makes `users.email` and
  `users.last_name` nullable and adds `students.notes`; existing users are not
  rewritten. Email remains required by existing School Admin, Staff, Teacher,
  Finance Staff, and login validation flows.
- V2.1 reuses a Guardian only when a real Email is supplied. Email-less rows
  emit only a possible-match warning and always create a new Guardian, never
  mutate an existing profile. Preview stays cache-only; Confirm continues to
  use the canonical compulsory assignment and Central Receivable flow with no
  Payment, Receipt, Ledger, or Fund Account effect.

## Student & Finance V2 Phase 3 — roles and permissions (local implementation)

- A tenant School role and a Central Finance principal/scope are now distinct
  requirements. A School Admin receives no Central Finance authority from the
  School role alone. Principal may be explicitly granted a read-only Central
  principal for that School; School Accountant/Cashier may separately receive
  an explicit operating Central scope.
- The Super Admin configuration page grants those trusted tenant Staff
  identities through their stable UUID mapping. It validates the actual tenant
  role server-side, does not replace tenant `Auth::user()`, and preserves the
  established Head Finance group identity path. Multi-role Staff require the
  specific corresponding Central grant; a Principal grant never silently adds
  operating authority.
- Revoking the Central School scope removes the Staff member's Central
  workspace access immediately while retaining their School role and history.
  Runtime routes continue to require Central principal, active Group scope,
  explicit School scope, and existing capability checks; UI visibility is not
  an authorization boundary. No Finance posting, balance, cutover, schema, or
  Production/Staging data behavior changed.

## Student Code School UI — local implementation

- Student Code is now an independent tenant-local School + code identity for
  manual Student creation, edit/backfill, list search, Student Fee Setup, and
  Student Finance. It is text-only and preserves leading zeroes; GR admission
  numbers and stable Central UUIDs remain unchanged.
- Both manual admission and Student Import V2 call the same
  `StudentCodeService`, backed by the existing unique
  `student_import_identities.school_id + student_code` constraint. An assigned
  code is stable rather than silently changed by an edit request.
- An additive Central projection migration adds the display-only `student_code`
  field to Central Finance Student Profiles. The sync source is schema-aware
  during the transition, so tenants not yet migrated continue to project
  safely. No payment, receipt, Ledger, balance, assignment, or receivable rule
  changes are included. Production is untouched.

## Central Finance Feature Gap P0 — local implementation

- Central Finance now exposes Central-only student ledgers, payment/receipt
  history, Fund Account detail reports, paginated/filterable Standard Ledger
  read models, Central Finance Staff scope visibility, and school-scoped
  Income/Expense category management.
- Read models enforce the existing Group + School + Fund Account boundaries;
  an unassigned Fund Account is never disclosed through Ledger, payment, or
  account-report routes. Audit detail and category changes remain Head
  Finance configuration actions.
- No tenant Finance writer, cutover rule, Ledger posting rule, schema, or
  Production/Staging data changed.

## Central Finance pre-opening configuration UX — local implementation

- Finance Groups now presents Central Finance Staff configuration separately:
  a Head Finance may receive all active Group Schools in one explicit grant,
  while a School Accountant remains server-enforced to one School. The scope
  table shows view/operate/approve/confirm state and supports non-destructive
  disable/revoke. Tenant identity mapping is visibly marked Legacy / Transition.
- In Central Student Fee, All Schools is a read-only summary/history state;
  it does not render a disabled collection form. A selected School renders the
  scoped sequence Student → outstanding receivable → Fund Account → amount.
  Students without an outstanding item remain selectable and receive the
  explicit empty-state message. Payment, receipt, Ledger, cutover, and Fund
  Account scope services are unchanged.
- The selected-School payment screen now has optional Class and student
  name/admission-number filters. Selecting a Central Student Financial Profile
  shows its school-synchronized class/section plus Central receivable totals,
  paid amount, outstanding amount, and pending-item count before collection.
  These are read-model/UI additions only; payment posting and authorization
  remain unchanged.
- Finance Groups configuration now has a compact Group-list home, a separate
  create screen, and a per-Group management workspace for basic settings,
  member Schools, Central Finance Staff, and collapsed Advanced / Legacy
  tenant-identity mapping. Existing scope POST targets, validations, and
  non-destructive disable behavior are unchanged.

## Central Finance Fresh Start — per-School cutover guard (local)

- Guardian creation now has a concrete tenant-safe GET create action, and the
  Student Admission Guardian Select2 search control writes only to the single
  submitted `guardian_email` field.
- Central Finance School Accountant configuration now selects an existing
  Staff member from the trusted School registry. The durable mapping stores
  that Staff member's UUID, never a bare tenant user ID; no second login user
  is created. Only an active, matching School Staff identity may receive its
  School's Fund Account scope.
- A Central Super Admin remains a Finance Groups configurator only until
  explicitly granted active Group Finance scope. In `legacy`/`ready`, Student
  Fee retains School/Class/Student/receivable read access while Collect Payment
  remains server-side unavailable.

- Central Finance now has an additive, reversible per-School cutover state:
  `legacy`, `ready`, and `central`. Once its schema is deployed, an absent row
  safely means `legacy`.
- `legacy` and `ready` keep tenant Finance as the only writer and make Central
  workspace documents read-only. `central` makes Central the only new Finance
  writer and server-side rejects tenant payment, expense, Other Income, Fund
  Account, Bank Transfer, Fund Handover, and Finance Staff/account-scope
  mutations; hiding UI alone is not relied upon.
- The transition is `legacy → ready → central`. A return to `legacy` is allowed
  only before any Central financial document or Ledger entry exists for that
  School. It never imports legacy Finance or creates an account/opening
  balance. Zixuan/Timecity focused tests prove independent states and no
  cross-School effect. Production and staging remain untouched.
- Fresh Start configuration now has a Central Super Admin School-scope form
  (configuration only) and a Head Finance-only Central Fund Account setup
  surface. Account creation records a signed opening-balance audit; later
  opening changes are signed adjustments with old/new value and never write a
  Ledger or operating total. `ready → central` fails closed until audited
  account, Head Finance, Group scope, School scope, and Fund Account scope are
  all present. No Production configuration or data has been created.
- Fresh Start Receivable sync has an explicit, audited per-School effective
  datetime. Only tenant Fee Assignments created at or after that approved
  boundary may become Central Receivables. Pre-boundary assignments remain
  legacy history, are excluded from Receivable reconciliation/readiness, and
  are never silently imported or cancelled. A missing boundary blocks
  `legacy → ready`; the boundary freezes once a School is marked ready. This
  is additive/reversible schema only and does not create Finance documents,
  Ledger entries, or balances.
- Production release `d6b9ec2` installed that cutoff schema and UI on
  2026-08-25. Zixuan remains `legacy`; its cutoff is intentionally unset, so
  the readiness gate is blocked until Head Finance records an approved
  effective datetime and reason. The release created no Payment, Receipt,
  Expense, Other Income, Transfer, Handover, Funding, or Ledger row.

Finance P3.2 Unified Finance Transactions — **LOCAL AUTOMATED VERIFIED**. `Finance → Transactions` is a read-only adapter over compulsory payments, optional payments, non-fee `OtherIncome`, Expense, and canonical completed BankTransfer source records; no duplicate transaction/ledger table exists. Pending handovers are omitted, while confirmed handovers appear exactly once through their linked BankTransfer. The register has date/type/account/reference/keyword filters and rejects forged Fund Account filters server-side. A selected Fund Account renders an internal transfer directionally; an all-account view renders it once as neutral `Internal Transfer`, with no Money In/Out or operating-result contribution. Direct transfers require two distinct active, non-deleted, current-school, authorized accounts and execute through an exception-safe transaction; cancellation removes only the canonical transfer balance effect. Receive Money records a tenant-local non-fee source only after payment-method, active current-school Fund Account, Cashier assignment scope, and reference reservation checks. Expense/Import buttons reuse existing Expense and P3.1 Expense Excel workflows. P0 focused tests and broader finance regression pass (143 tests / 546 assertions). The guarded BOWEN_QA browser rerun is pending local MariaDB credentials in this worktree; no non-local fallback is permitted. Production and staging remain untouched.

Finance P3.1 Permission, Money-In, and Expense Import hardening — **LOCAL ACCEPTANCE VERIFIED**. The completed role bootstrap remains infrastructure and was not extended. Runtime authorization now uses named finance permissions with strictly equivalent legacy compatibility only; account scope, tenant isolation, custody rules, forged-account rejection, and School Admin's handover read-only boundary remain server-side. Compulsory/optional payment and paid-fee import paths validate the payment method and an authorized active Fund Account before creating financial rows. The new Expense Excel workflow is preview-first (no `Expense` writes), whole-batch atomic at confirmation, and creates new records only; it never accepts an expense ID or upserts an existing expense. It rejects duplicate references/files/rows and repeated confirmation attempts, including references retained by soft-deleted expenses. Final BOWEN_QA acceptance passed the School Admin grant/revoke UI, Cashier scope invariant, Fund Handover create/confirm/reject/cancel application-modal behavior with no native browser dialogs, paid-fee/expense account protection, and Expense Import preview/confirm/duplicate/invalid/unauthorized paths. The Expense listing's audited-delete button helper was restored after browser acceptance exposed its missing runtime implementation. Broader finance regression passed (143 tests / 516 assertions). Production and staging remain untouched.

Finance P3 Fund Handover + Receiver Confirmation — **LOCAL QA VERIFIED**. Production remains read-only; no production deployment or migration is prepared.

Finance P2-D Finance Staff Management — **LOCAL QA VERIFIED**: School Admin may assign/remove only Finance roles and manages Cashier account assignments; Head Finance may manage active current-school Cashier assignments but cannot change roles; Cashiers are denied staff-management access. Removing Cashier detaches assignments immediately. The Finance Staff page uses an in-application modal (no native prompt/confirm), with one styled Manage action, explicit role actions, and checkbox-based Cashier Fund Account assignment. Targeted authorization/P1-P3 regression and authenticated local Playwright modal acceptance pass. Production remains untouched.

Finance P3 School Admin Handover Oversight — **LOCAL QA VERIFIED**: School Admin may open the Fund Handover register, inspect all current-school handovers and their recorded confirmation/rejection/cancellation audit detail, but remains a non-participant. Participant-only recipient/account discovery is never evaluated for a School Admin session; forged create/confirm/reject/cancel requests are rejected server-side before any finance write. Head Finance/Cashier P3 flows and Cashier Fund Account isolation remain unchanged. Focused P1/P2/P3/P2-D regression (29 tests / 121 assertions) and authenticated local Playwright pass. No schema, migration, role, account, or production data change is included.

Tenant role-context lifecycle fix — **LOCAL QA VERIFIED**: the web group now establishes the selected tenant database immediately after session startup and clears any already resolved guard user before LanguageManager/WizardSettings can evaluate Spatie roles. This prevents an empty central-connection `roles` relation from surviving into the tenant request. Local characterization reproduces central-role absence followed by tenant School Admin/HR role visibility after context establishment, while confirming the central path remains mysql-only. Finance Staff, School Admin Handover oversight, Head Finance/Cashier handover flow, and Cashier account isolation pass after the lifecycle change. No schema or role-data change is required.

Fund Handover role-gate follow-up — **LOCAL QA VERIFIED**: the menu container and handover routes now use the dedicated `School Admin` / `Head Finance` / `Cashier` custody roles rather than unrelated generic Expense permissions. The Expense Management feature entitlement remains required. School Admin stays register-only and forged actions remain 403; Head Finance/Cashier regain participant access without broadening Cashier Fund Account scope. Targeted P2/P3 regression (22 tests / 83 assertions) and authenticated BOWEN_QA Playwright (P2 3/3, P3 1/1) pass. A runtime-only hotfix is prepared; production is untouched.

P2/P3 release prerequisite is locally verified: `finance:p2-p3-migration-safety`
has the fixed eight-tenant and two-file allowlists, verification-only default,
partial-state refusal, isolated-batch verification, canary selection, and
data-aware rollback refusal. It is not deployed or executed on production.

P3 local evidence:

- Tenant migration: `2026_08_12_000001_create_fund_handovers_table.php` adds tenant-local pending/confirmed/rejected/cancelled handover audit records and their underlying `bank_transfer_id` link.
- A pending handover has no balance or ledger effect. Only the designated receiver can confirm it; confirmation is transactional and creates exactly one normal completed `BankTransfer`, reusing the shared transfer balance calculation.
- Head Finance ↔ Cashier is supported; Cashier ↔ Cashier, cross-school, inactive/unassigned accounts, insufficient source balance, unauthorized confirmation, and repeated confirmation are rejected server-side.
- Confirmed handovers are immutable: their completed transfer cannot be cancelled through the immediate-transfer endpoint. Rejection and cancellation retain actor, timestamp, and mandatory reason without a transfer.
- BOWEN_QA Playwright passed the actual Head Finance request → Cashier confirmation flow, pending/no-ledger state, balance and ledger movement, direct-request rejection, exactly-once confirmation, and immutability.
- Targeted Finance regression: 25 tests / 113 assertions passed; local P2/P3 browser regressions passed. PHP 8.5 vendor deprecation notices remain non-functional.

P2-A local evidence:

- Tenant migration: `2026_08_11_000001_create_bank_account_user_table.php` creates the `bank_account_user` many-to-many pivot with unique pair and cascading tenant-local foreign keys.
- Roles: `Head Finance` and `Cashier` reuse Spatie. Fixture Cashiers receive only `expense-list`; Head Finance receives the minimal current finance permissions. Neither inherits the complete School Admin permission set.
- Centralized `FinanceAccountAccessService`: current-school query scope, direct-account authorization, all-account access for School Admin/Head Finance, and elevated account/assignment/opening-balance capability checks.
- BOWEN_QA: `QA_HEAD_FINANCE`, `QA_CASHIER_A`, `QA_CASHIER_B`; Fund Accounts `QA_P2_CASH_A`, `QA_P2_CASH_B`, `QA_P2_BANK`; A/B are assigned only to their respective cash accounts. Head Finance access is role-based, not pivot-based.
- Targeted PHPUnit: 3 tests / 21 assertions pass (the current PHP runtime reports known vendor deprecation notices only).
- Local Playwright: 4/4 pass — Cashier A isolation, Head Finance all-account visibility, direct unassigned-account rejection, and Cashier edit/opening-balance rejection (HTTP 403).

P2-B/P2-C local evidence:

- The centralized scope now protects compulsory/optional payment selectors and writes, Excel paid-fee preview and confirmation, Expense selectors and writes, Bank Transfers, Fund Account reports, account detail/ledger, and the finance report.
- A Cashier sees and may use only assigned active current-school Fund Accounts. Direct unassigned report and transfer requests are rejected before a financial write; transfer cancellation requires access to both accounts.
- General finance-report totals are account-scoped. The school-wide outstanding figure is deliberately hidden from account-scoped Cashiers rather than shown as a misleading partial total.
- Excel confirmation rechecks uploader account authorization after preview; revoking an assignment invalidates the confirmation and rolls back without fees/payment writes.
- BOWEN_QA local Playwright: 7/7 pass, covering Head Finance all-account access, Cashier A/B inverse isolation, payment/expense/transfer/report selectors, direct unauthorized account/report/transfer requests, and Cashier opening-balance restriction.
- Broader local Finance regression: 103 tests / 354 assertions pass. The PHP 8.5 PDO SSL constant deprecation warnings are pre-existing and non-functional.

Finance P1 Financial Audit Safety remains **LOCAL ACCEPTANCE VERIFIED**. Production remains deployed (with its historical non-mutating browser coverage noted below); all destructive-path acceptance evidence was completed only in deterministic BOWEN_QA local synthetic data.

Implemented locally:

- Expense delete: SoftDelete + deleted_by + delete_reason.
- Expense edit: change history with old/new values, reason, changed_by.
- Fee payment delete: SoftDelete + deleted_by + delete_reason; historical reference_no remains reserved.
- Opening balance edit: adjustment history with old/new balance/date and reason.

Current automated evidence:

- 104 tests
- 344 assertions
- 0 failures

## P1 production deployment evidence

Completed on `eschool-prod`:

- Full checksum-verified tenant backups: `/root/backups/finance_p1_20260810_163626`.
- The six targeted P1 migrations completed on all eight tenants, with schema verification and one P1-only batch per tenant.
- Canary: `eschool_saas_1_demo` (batch 5); remaining batches: Zixuan 8, Bahan 7, Timecitys 7, `20_` 7, `21_` 7, Zixuanyang 7, `32_` 2.
- The final application release contains 15 P1 files: 13 initial files plus `FeesPaidImportService.php` and `FeesPaymentService.php`, which reserve references held by soft-deleted payments.
- PHP syntax and Laravel autoload checks passed, caches were cleared, and production was restored online.
- Browser checks passed without a financial write: public/dashboard access, expense list, expense delete-reason dialog and empty-reason rejection, required expense edit reason, paid-fee list, optional-fee list, Bank Account list/report, and expense report.
- No production financial record was created, edited, deleted, refunded, or transferred for testing.

## Production preflight evidence

Exact tenant databases observed:

- `eschool_saas_1_demo`
- `eschool_saas_15_zixuan`
- `eschool_saas_17_bahan`
- `eschool_saas_19_timecitys`
- `eschool_saas_20_`
- `eschool_saas_21_`
- `eschool_saas_31_zixuanyang`
- `eschool_saas_32_`

All eight showed:

- required base finance tables: 5/5
- P1 schema present before migration: none

Important unrelated pending school migrations exist on several tenants.

Therefore:

- DO NOT use broad `php artisan migrate:school` for the P1 cutover.
- P1 production migration must target only the six P1 migration files.

## Local acceptance requirements

The following are deliberately **not** marked PASS in production and are not production blockers:

1. Compulsory and optional payment delete-reason dialogs, including empty-reason rejection and post-delete accounting/audit verification.
2. Opening-balance reason validation and `BankAccountBalanceAdjustment` audit verification.

They require a dedicated local/test tenant with synthetic data and browser permission to perform controlled finance writes. No further production financial testing is authorized for these paths.

### BOWEN local QA environment — ready

The generic local demo was not representative because the local central database had no installed school, only default SaaS settings, and only one enabled feature. Bowen’s visible structure is driven by a combination of current application code, central branding/subscription features, and tenant-local school settings, roles, permissions, academic-year data, and finance bootstrap.

- Local-only tenant: `BOWEN_QA` in fixed database `eschool_local_bowen_qa`.
- Reset/seed: `php artisan local:bowen-qa reset`. The command has no selectable database/tenant argument and refuses non-local APP_ENV, non-local APP_URL, staging, production, and production-style database names.
- Fixture: synthetic QA admin/teacher/guardian/student, `Bowen QA 2026`, class/section, compulsory and optional fee structures, two QA Fund Accounts, fixed payment `BOWEN_QA_P1_PAYMENT_001`, and fixed expense `BOWEN_QA_P1_EXPENSE_001`.
- Representative configuration: Bowen-style school/system name, non-sensitive public branding images copied with checksum verification, active Bowen-equivalent subscription feature names, and School Admin role/permission bootstrap. No production users, students, financial records, uploads other than the two public branding images, sessions, or credentials were copied.
- Repeatability: two reset/seed cycles produced the same stable fixture fingerprint (`f0309de62232949dff2649309f1233ea67bd3a88a14b36ca59702fc566a17fbe`).
- Local browser target: guarded `http://127.0.0.1:8000`, authenticating only to `BOWEN_QA`; the auth state remains gitignored. Representative browser checks pass for dashboard, Fund Accounts, Expenses, compulsory/optional paid-fee pages, and the Excel paid-fee import UI.

Focused production read-only code parity confirms the Finance P0/P1, paid-fee import, and relevant finance/sidebar views used by local QA match the deployed production files. Two unrelated production-only Xiaobailong AI route/sidebar additions are not present in local; they are recorded for separate source-of-truth reconciliation and are outside Finance P1.

Finance P1 local acceptance completed in BOWEN_QA: the real delete-reason dialog rejects empty input then soft-deletes `BOWEN_QA_P1_PAYMENT_001`; audit actor/reason, normal-versus-history visibility, FeesPaid/outstanding, Fund Account balance, ledger exclusion, and manual/Excel reference reservation all pass. The Bank Account edit form now conditionally renders/submits `adjustment_reason`, preserves controller validation, records exactly one opening-balance adjustment, and does not create one for an unrelated edit. A cascade-risk fix retains zeroed FeesPaid aggregates so a soft-deleted payment cannot be removed by a foreign-key cascade.

## Archived staging provisioning — paused

Historical same-host staging work is retained for future reference only. It is not an active requirement or deployment target under Pipeline V2. Production application/data remain protected and were not changed.

- Same host: `eschool-prod` (`43.160.241.126`); separate staging root `staging.school.mmbowen.com` at `/www/wwwroot/staging.school.mmbowen.com`.
- Isolation contract: APP_ENV/APP_KEY/.env/storage/session/cache/queue are independent; central `eschool_staging`; FINANCE_QA tenant `eschool_staging_finance_qa`; staging-only least-privilege credentials; staging must never point to `sql_43_160_241_126` or `eschool_saas_*`.
- Prepared tooling: `StagingFinanceQaGuard`, `staging:finance-qa` (verify/seed/reset), guarded runtime/deploy scripts, a synthetic FINANCE_QA baseline, a staging runbook, and Playwright P1 acceptance scenarios.
- The reset command requires exact staging environment, URL, central database, school, and tenant database checks, a confirmation flag, and creates a targeted local snapshot before resetting only `QA_P1_%` fixtures.
- Payment-delete and opening-balance browser acceptance remain deliberately unverified until the isolated environment and synthetic credentials exist. No production financial mutation was used for testing.
- Same-host preflight (2026-08-11): `eschool-prod` is `VM-0-4-ubuntu`; production root exists; 77 GB is free. Nginx 1.24, PHP 8.3 FPM (`/tmp/php-cgi-83.sock`) / PHP 8.3 CLI (`/usr/bin/php83`), MariaDB 10.11, and Redis are available. The production CLI default remains PHP 8.1; Node/npm are not installed.
- DNS now resolves `staging.school.mmbowen.com` to the active host. The isolated staging root, independent `.env`/APP_KEY/storage/session/cache, `eschool_staging` central DB, and `eschool_staging_finance_qa` tenant DB are provisioned. The application DB account has privileges limited to those two staging DBs; the temporary MariaDB staging-admin credential file was deleted after application credential verification.
- `FINANCE_QA` is installed through the real per-school migration mechanism. Its synthetic tenant includes QA users, student/class/session, compulsory and optional fees, four test Fund Accounts, a transfer, and an expense. `staging:finance-qa verify` passes.
- `https://staging.school.mmbowen.com` is live with its own Let’s Encrypt certificate. HTTP redirects to HTTPS; the staging Nginx vhost contains no production proxy routes. PHP/FPM writable-path and tenant-local `Teacher`-role bootstrap defects were corrected in the staging-only setup.
- Runtime isolation verified: central DB `eschool_staging`, school DB `eschool_staging_finance_qa`, mail `log`, queue `sync`, cache/session `file`. Production project and production databases were not modified.
- Staging-only visual guard deployed: login and authenticated layouts render `STAGING — FINANCE_QA · TEST DATA ONLY` only when `app()->environment('staging')`. The focused view test confirms it renders for staging and is absent for production; staging login rendering was verified over HTTPS. Staging `APP_NAME` is `STAGING_FINANCE_QA` so the login title cannot be mistaken for production.

## Finance UAT terminology

Finance users see **Accountant** as the user-facing name for the internal
Spatie `Cashier` role. Role records, permission defaults, participant/custody
checks, and Fund Account assignment scope continue to use `Cashier`; no
`Accountant` role exists or is provisioned.

Finance Staff Accountant onboarding now uses Laravel's real tenant-local
password broker. Its reset notification derives `school_code` from the
trusted central school registry for the new user's `school_id`; the reset
endpoint requires that code, reconnects only to that registered tenant, and
requires the email/user ownership to match before consuming the token.

## Next task

## Group Finance Operating Context — Checkpoint 3 (local)

- A Central Head Finance keeps the central authenticated identity while a selected, explicitly scoped School may now use only the canonical Expense, Other Income/Receive Money, and compulsory Student Fee write services.
- Every operation re-resolves active Group membership, `operate_finance` School scope, trusted central-registry School, mapped tenant User, original tenant permission, and existing Fund Account scope; no request can name a tenant database.
- Tenant-local `finance_operating_audits` records central actor, Group, School, mapped tenant identity, action, and canonical source ID in the same tenant transaction. It stores no amount and is not a Ledger.
- All Schools stays read-only. Bank Transfer and Fund Handover are described by Checkpoint 4 below; HQ Funding remains outside the Operating workspace.

## Group Finance Operating Context — Checkpoint 4 (local)

- The selected-School workspace now delegates immediate Bank Transfer and Fund Handover creation to the existing `BankTransferService` and `FundHandoverService`. It does not create a Group transfer table, duplicate a ledger, accept a database name, or impersonate the mapped tenant User.
- Both transfer accounts are re-authorized by the existing active/current-School/Fund-Account-scope service and must be distinct. The canonical completed `BankTransfer` remains the only internal movement: its source is Money Out once, destination Money In once, and it has zero operating income, operating expense, and net-result effect.
- A central Head Finance may create a pending handover in the selected School only when its mapped tenant Head Finance identity has the existing permission and custody role. The target School's designated Accountant still confirms it through the normal existing flow; central identity cannot substitute for the receiver. Pending handovers remain ledger/balance-neutral; confirmation creates exactly one linked canonical transfer.
- Central-context creates write tenant-local audit metadata in the same transaction (`central_actor`, Group, School, mapped tenant identity, action, source type/ID, no amount). Normal tenant Accountant confirmation retains the normal custody audit and does not fabricate a central actor.
- Local Zixuan/Timecity browser acceptance covers selected-School direct transfers, Zixuan pending handover creation, source isolation, and central audit rows. Service characterization covers pending neutrality, receiver-only confirmation, exactly-once canonical transfer, repeated-confirm rejection, account scope, and zero operating-result effect. The fixed Group QA reset/verify returns the fixture to a zero-write baseline.

## Group Finance Operating Context — Checkpoint 5 (local)

- HQ ↔ School Funding is now available only inside one trusted Operating School context. The controller derives the School from the opaque central context; it accepts neither a School selector nor a database name from the request.
- Only a central user holding the internal `Head Finance` role plus active explicit Group scopes can enter Group Finance, switch Schools, or fund. School Accountants retain their normal single-School flow and receive no Group switcher; HQ Accountant cross-School operation is intentionally deferred.
- Funding reuses the central canonical `FinanceGroupTransfer` / HQ Account source. Pending records have zero School/HQ balance and Ledger effect; confirmation makes one Internal Transfer and does not change operating income, expense, or net result. The mapped tenant identity is an authorization mapping only; the central browser identity is never impersonated.
- Local Zixuan/Timecity acceptance creates and confirms one HQ funding and one School remittance, then returns to All Schools to reconcile both canonical records in Group Reports. The fixed Group QA fixture separates the central Head Finance login from its mapped tenant Head Finance identities and verifies no-write snapshots across central and tenant financial tables.

## Group Finance Operating Context — Checkpoint 6 (production verified)

- Production active release is `da65af8b68a77656069ff03152a6aaf1f9713e84`.
- The sole additive tenant migration, `2026_08_20_000001_create_finance_operating_audits_table`, completed through a Zixuan canary and then the other six active tenants. Every active tenant has the expected table and migration history with zero audit rows immediately after release; inactive Demo was intentionally excluded.
- A seven-tenant backup was checksum-verified before the canary. Existing Finance table counts were checked per tenant during migration and matched the immediate pre/post-switch snapshot.
- The release used an isolated RC, assets 8/8 verification, atomic symlink switch, `view:clear` only, and graceful reload of the actual global PHP 8.3 FPM master. No Nginx restart, permission bootstrap, Finance data write, or Group configuration write occurred.

## Group Finance central entry (local)

- Central Super Admin remains configuration-only: the Finance Groups page no
  longer exposes operational report/funding links that can fail for a user
  without an explicit Group Finance membership.
- A configured central Group Finance user enters the read-only `group-finance`
  route after normal or 2FA login. The School switcher is limited to active
  explicit `view_reports` scope, and selected-school accounts are read through
  the mapped tenant identity plus the existing Fund Account scope.
- CSV export requires the separate `export_reports` capability. The controller
  is read-only and does not set a global tenant session or impersonate a
  tenant user.
- Central Super Admin configuration exposes `operate_finance` as the explicit
  `校区财务操作` capability at either Group or School scope. It remains in the
  same server-side capability whitelist as the Operating Context; neither
  Super Admin nor Head Finance receives it automatically.
- Regression characterization covers the configured central entry, CSV route,
  forged-school rejection, unscoped denial, and a no-write central snapshot.
- Local-only acceptance uses `php artisan local:finance-group-qa reset` and
  `verify`. It provisions exactly Zixuan QA, Timecity QA, and an unrelated QA
  tenant through a fixed database allowlist; it refuses Production, Staging,
  and every `eschool_saas_*` database. Reset is repeatable and removes only
  the fixed `GROUP_QA` synthetic central fixture. Verify snapshots the fixture
  financial tables before and after its assertions, proving the read models
  and Group reports perform zero financial writes.

## Group Finance Operating Context — Checkpoint 1 (local)

- Scheme B is defined in `docs/finance/GROUP_OPERATING_CONTEXT.md`. The
  authenticated identity remains the central User; a tenant identity is an
  authorization mapping only and is never passed to `Auth::login`.
- `FinanceOperatingContextService` stores only central actor, Group, School,
  and mapped tenant-user IDs. It stores no database name, refuses tenant-login
  sessions, and revalidates every active membership, operating scope, and
  mapped identity before returning a current context.
- `operate_finance` is a separate explicit Group capability. It is not granted
  to Super Admin automatically and has no UI/write adapter in Checkpoint 1.
- Zixuan/Timecity focused characterization proves central identity retention,
  connection restoration, Fund Account scope preservation, safe exit, and
  rejection of unrelated School IDs, tenant session input, forged database
  fields, stale context data, and a different central actor. No production
  work, UI, cross-School route, or Finance write is included.

## Group Finance Operating Context — Checkpoint 2 (local)

- All Schools remains the existing read-only Group Finance / Group Reports
  view. A separate Operating School switcher displays only active explicit
  `operate_finance` scope and creates the opaque Checkpoint 1 context through
  a POST route; no request accepts a tenant database name.
- The selected-school workspace retains the central login and is currently
  read-only. It exposes Bank Accounts, Transactions, and Finance Reports
  through the existing Ledger V1 and Fund Account balance services using only
  the mapped tenant identity and its normal account scope. Tenant write routes
  for fees, Other Income, Expense, transfers, and handovers are not mounted.
- Every page displays the Group/current School context and provides Switch
  School plus Return to All Schools. Zixuan/Timecity local browser acceptance
  covers selection, source isolation, central identity retention, and denial
  of the unrelated School.

Finance P4 Daily Cash Closing, if approved. Do not start Bank Reconciliation, refund/void/reversal, or any production work. Any production release requires a fresh, explicit production deployment/migration gate.

## Integration release validation

- Final local integration validation passed: `php artisan test` reports 291 passed / 870 assertions; the guarded BOWEN_QA Playwright suite reports 18/18 passed. The generic root-route fixture now supplies its server-level host value and the isolated two-stage leave characterization uses Laravel's application test case; neither changes runtime behavior.
- Payroll reconciliation is resolved and locally verified: LWP uses the actual count of non-Sunday dates in the payroll month (not a fixed 30-day or calendar-day divisor). Full approved unpaid leave counts as 1.0 day and approved half leave as 0.5 day under the existing leave semantics. Transportation remains solely on the established Payroll Settings deduction path; the incomplete Production-only `transportationPayments` block is intentionally absent.
- Phase 9 production read-only schema preflight: all eight registry tenants have complete P1/P2/P3 schema; both new P3.1/P3.2 schemas are absent everywhere. Legacy fee-import schema is applied on the seven active tenants and absent on inactive Demo; that divergence remains intentionally out of scope. `finance:migrate-p31-p32` is locally tested only, defaults to zero-write verification, and has an exact allowlist of the Expense Import Batch and Other Income migrations. It is not deployed or executed on Production.

## Backlog

## Central Fund Account custodian selector (local candidate)

- The Create Central Account screen now loads the eligible Central Head Finance
  custodians for the selected Finance Group instead of rendering an empty
  dropdown. Changing the Group refreshes the options in the browser.
- The selection is descriptive Fund Account master data only; it does not
  create account access, alter a School allocation, or change any balance.
  Server-side creation still applies the existing Group Head Finance custody
  authorization before saving.
- Targeted workspace and Fund Account V2 regressions pass locally. No schema,
  migration, Finance record, or Production change is included in this candidate.

- The historical full fresh-install migration inventory has a pre-existing
  cross-connection ordering gap: some tenant migrations read central tables,
  while the 2026-05 multicurrency central migration scans tenant tables. The
  Operational identity migrations themselves pass fresh, and the full suite
  passes on the established fully migrated test schema; repair of the legacy
  bootstrap ordering remains outside this candidate.
- PHP 8.5's `PDO::MYSQL_ATTR_SSL_CA` and PHPUnit XML-schema notices remain
  non-functional compatibility debt.

## Front Desk Pending Collection — Phase 5.5A (local candidate)

- Added the additive Central migration `2026_09_04_000001_create_central_finance_pending_collections`. A Front Desk declaration is an auditable, idempotent Pending Collection and never creates a canonical Payment, Receipt, Ledger entry, or Fund Account balance movement.
- A trusted School `Front Desk` / `Admissions & Collection` role receives only the explicit `can_submit_collections` school scope. School Accountants no longer have the student-payment confirmation path; only Head Finance can select the actual Fund Account and invoke the existing canonical `CentralFinancePaymentService` at confirmation.
- Optional Fee selection remains the existing Fee Setup adapter, now available to Front Desk under the same School/year/class/optional-only validation. Amount and currency remain server-side Fee Setup values.
- Hold/reject require a Head Finance reason. Confirmation preserves collected-by (Front Desk) and confirmed-by (Head Finance) separately, is idempotent, and retains original Pending history. No Production deployment or migration has been performed.

## Zixuan Optional Fee Collection — Phase 5 (Production)

- The School Finance facade now exposes only eligible optional Fee Setup items
  to an explicitly scoped School Accountant. Selection creates a confirmed
  tenant fee-assignment item and its canonical Central receivable; it creates
  no Payment, Receipt, Ledger entry, or Fund Account balance movement.
- Production QA on the marked Zixuan UAT student verified a 500 MMK optional
  receivable, partial 100 MMK and 200 MMK collections, an overpayment rejection,
  and a final 200 MMK settlement. The canonical result is three Payments, three
  receipts, and three Central Ledger money-in entries totaling 500 MMK. These
  are retained QA history; no generic financial deletion was used.
- The trusted School-session tenant bridge rehydrates the authoritative Central
  School record before resolving tenant staff identity. This preserves strict
  School isolation while allowing School Accountant collection actions through
  the existing Central authorization and finance services.

## Zixuan Student Import V2 — Phase 2 (local)

- Student Import V2 is XLSX-only and remains restricted to the explicit canonical School allowlist. The workbook is School-local: it contains no School routing field, and Class Section plus Academic Year are human-readable dropdowns resolved and re-validated against the authenticated tenant.
- The template has a first Import sheet, readable Class Sections / Academic Years / Custom Fields lookup sheets, and a hidden validation sheet. Student Code, Student Mobile, and Guardian Mobile use Excel Text formatting so leading zeroes survive save/reopen.
- Preview caches only row metadata and reports New, Duplicate, Error, or Conflict. It writes no Student, Guardian, identity, assignment, receivable, payment, receipt, ledger, or Fund Account record. Confirm is all-or-nothing for New rows, re-checks placement, readiness and compulsory setup, creates only compulsory assignment items, and relies on the established Central profile/receivable publisher after commit.
- The local BOWEN_QA command correctly refused because this worktree is not a local/test runtime; no environment or database configuration was changed to bypass that guard. Targeted PHPUnit covers real workbook structure/save-reopen, leading-zero formats, School-local mapping, XLSX-only parsing, identity uniqueness, Central profile sync, cutover, fee assignment, receivable, payment and ledger regression.
- Production pilot access resolves the active named tenant connection when a legacy School session contains an empty database key, then still cross-checks the central School registry, authenticated tenant `school_id`, and fixed Zixuan code. It does not introduce cross-tenant lookup or broaden the pilot.

## Shared Fund Account P1 — local additive allocation core

- Central Finance now has an additive `central_finance_fund_account_school_allocations` migration. Existing school-owned accounts backfill exactly one active legacy-owner allocation equal to their physical opening balance; HQ accounts receive no automatic allocation. The legacy `central_finance_fund_accounts.school_id`, all Finance documents, and all Ledger rows remain unchanged.
- `CentralFinanceFundAccountSchoolAvailabilityService` is the canonical account-for-School seam. It permits a School-owned account through an active effective allocation, with a contained legacy-owner fallback only during additive rollout. Expense, Other Income, Payment, both existing import paths, Group Import V2.1, Ledger attribution, and HQ Funding account availability use it while retaining each document/ledger `school_id`.
- Physical balance remains the existing one-account opening plus all Ledger legs exactly once. A School-scoped balance is an allocation opening plus only that School's direct Ledger entries; selected-School directory and statement openings use this view. Direct normal transfer/handover same-school rules remain unchanged; no School Fund Reallocation has been added.
- Head Finance can configure multiple School allocations under transaction/row locking and an audited reason. Active opening allocations cannot exceed the physical opening. A historical funded allocation cannot be changed/removed through this P1 configuration surface; it requires the later formal reallocation workflow. Production and staging remain untouched pending the P1 local/staging gates.

## Roadmap after P1 production verification

1. Head Finance / Cashier / Branch Finance role design
2. Fund Account user ownership and account-level permissions
3. Transfer → Handover + Receiver confirmation
4. Daily Cash Closing
5. Bank Reconciliation
6. Refund / Void / Reversal
7. Reports and Audit

Do not implement finance roles until P1 is production-verified and business rules are confirmed.

## Front Desk onboarding (local candidate)

- Staff Create/Edit now supports the tenant-only `Front Desk / Admissions & Collection` role with multi-role payloads and server-side role ownership validation.
- Central Finance access remains a separate explicit Finance Groups grant (`submit_collections` for one School); the tenant role alone grants no Finance capability.
- The idempotent `school:provision-front-desk-role {school_code}` command provisions only the tenant role and does not modify financial data.
- Central Staff identity linking now provisions or reuses the tenant User/Staff row from an existing Central identity through the audited Super Admin flow. A deterministic School-scoped UUID prevents duplicate people; the existing credential hash is linked (provisioning fails closed when no credential exists), and the separate `submit_collections` grant remains explicit and revocable.

## Snyk P0 security hardening (local candidate)

- Upload paths now accept only bounded, relative folder segments in the shared `UploadService`; original filenames remain traversal-checked and server-generated filenames remain authoritative.
- Payment verification uses fixed HTTPS gateway base URLs, strict provider-specific transaction identifiers, encoded path segments, and disabled redirect following. User input can no longer select a host or arbitrary gateway path.
- The diary description modal now constructs DOM nodes and text content instead of concatenating user-controlled HTML or link attributes.
- The legacy Web installer, default installer credentials, global installer middleware, custom routes, views, and local package source were removed. Environment-setting support still used by System Settings is retained as an explicit standalone dependency.
- Patched Laravel 10-compatible dependency versions remove all active Composer Critical/High advisories. Laravel 10's upstream email-rule advisory is covered by global CR/LF rejection for email-shaped input fields; its duplicate advisory and the Laravel 10 signed-URL advisory are explicitly documented while a future framework-major upgrade remains out of this P0 scope.
- Local disposable-MySQL regression passes 591 tests / 4031 assertions with zero errors or failures. No Production changes were made.

## Snyk Medium security hardening (local candidate)

- The candidate is based directly on Production baseline `98535a9f6399b3ba9f929fd8cd3db7528ddf0d1f`; no Production deployment or data change has been made.
- Legacy Paystack controller responses are decoded and returned with a JSON content type instead of echoing an upstream payload as browser HTML. Transaction references are bounded and encoded, redirects are disabled, and upstream exception details are not exposed.
- Production DOM sinks identified by the security review now render question, plan, and gallery-caption data as text rather than executable HTML.
- Axios is upgraded to the patched 1.20 release line, and the subject-loader option merge now copies only known own properties from a plain object.
- Disposable-MySQL full regression passes 597 tests / 4050 assertions with zero errors or failures. Production-only findings were kept separate from excluded test credentials, test SHA1 fixtures, and translation-file false positives.

## Central Finance Bugfix Batch (local candidate)

- Chart of Accounts now treats the internal category ID as the durable financial relationship: Head Finance may correct the current text Account Code (including leading zeroes) with a required reason, group-unique validation, and before/after audit; Account Type remains immutable and no historical document or Ledger row is rewritten.
- The All Schools Income detail endpoint now resolves the owning School from the canonical income record and then enforces Central/Group scope plus readable-account scope. It no longer depends on a selected School; cross-School access remains denied.
- The shared audited lifecycle confirmation modal disables a repeated confirmation, while Fund Account lifecycle status changes are a server-side no-op when the requested state is already current, preventing duplicate lifecycle audit writes on replay.
- User-facing Central Finance terminology now says `Income`; routes and underlying `other_income` compatibility identifiers remain unchanged.
- Targeted regression covers Account Code change/audit/duplicate rejection, V3 lookup refresh, all-Schools detail authorization, lifecycle replay, operating-document and transfer reversal paths. The complete suite was also run; an initially missing new Chinese translation was fixed. The remaining suite interruption is the pre-existing 128 MB memory failure and unrelated `FeeModelAccessorTest` failures, not bypassed by this candidate. Production is unchanged pending candidate review/release.

## Pre-go-live Central Finance Fresh Start (production candidate)

- The candidate adds a default-dry-run `finance:reset-pre-go-live` command. Its reviewed Central-only allowlist is explicit, never names a tenant table, never truncates, and preserves Schools, users, roles, scopes, Finance Groups, cutover state, subscriptions, subscription bills, payment transactions, and security/identity audit infrastructure.
- Before deletion it records exact row counts, checks the live MySQL foreign-key graph for any non-allowlisted dependent data, hashes protected configuration, requires an authorized Head Finance actor plus an approval reference and reason, and writes an immutable reset manifest. A changed protected hash or an external dependency fails closed and rolls the transaction back.
- The matching `finance:migrate-fresh-start` runner is exact-path and Central-only. It can create only the reset-manifest and business-content-translation tables; it is read-only by default and requires an immutable release for Production execution.
- Dashboard/report currency cards use physical Fund Account aggregation exactly once per account (not per School allocation), showing Opening Balance, Money In, Money Out, and Closing Balance. The QA/Test banner is reduced to a compact scope control.
- Translation V1 stores optional English display values separately from canonical original labels for Central Chart Accounts and Fund Accounts. Chinese/original text remains canonical and English falls back to it when no current translation exists. There is no external translation provider or Burmese locale in this release.

## Zixuan QA Run — staged migration runner successor (local)

- The approved candidate `d06f31d06833ca702d647999168d5ef085ec9218` was pushed and its fresh Production recovery set `eschool-prod-20261006T090250Z-b87bac3a2bc6` passed local checksums plus independent COS HEAD verification for all 12 encrypted artifacts (Central, 8 trusted tenants, shared storage, and recovery configuration).
- Production remains on `b87bac3a2bc6eaf32cada9cdbfa19c565d5e61b2`; no migration or release switch was run. The approved ordering exposed a runner-path conflict: the runner required the candidate to be active even though the approved sequence requires migration before activation.
- The local successor now validates a staged immutable candidate against the exact active `b87bac3...` marker/manifest, the pinned lineage contract, the release marker/manifest and Git HEAD, and ancestry from both the active release and approved `d06f31d...`. Production confirmation remains before DB preflight, and active/candidate identities are checked again immediately before the exact-path migration.
- Targeted migration-guard and QA Run suites pass (24 tests / 80 assertions combined). The isolated full regression passes (1,111 tests / 8,711 assertions, 34 existing skips); `git diff --check` and final diff review pass. The new successor has not been pushed or approved for Production.
