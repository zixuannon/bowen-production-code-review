# Unidentified Deposit P0 — exact migration and write window

## Scope and current stop

Local engineering only. Expected Production is
`135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e`; accepted parent candidate is
`0798e13b3ba94fac16229ccc057d5031ce698ac1`. No push, Production migration,
trigger installation, latch creation, release switch or service action is
authorized by this implementation task.

**Read-only Production runtime readiness now passes** after separately approved
stale-runtime cleanup; see [identity and cleanup evidence](UNIDENTIFIED_DEPOSIT_P0_RUNTIME_BLOCKER.md).
No database writer fence or migration has been executed. Repeat current-process
identity and all deployment gates after separate exact-candidate approval.
Do not infer database isolation from a `sandbox` process name.

## Exact migration contract

- Central only: `database/migrations/2026_10_07_000001_close_unidentified_deposit_p0.php`.
- SHA-256: `8cb9cc668a4ad17ad4c7543564beba3b21f9d6d6ad583ce4acb3f18e1ea4c432`.
- Tenant migrations: **NONE**.
- Runner: `finance:unidentified-deposit-p0-migrate`; default / `--preflight`
  is SELECT-only. Only `--execute` invokes the pinned file.
- Wrong filename/path/contents, tenant connection, runner, rollback command,
  baseline, release proof or partial schema is denied. There is no wildcard
  migration allowlist, broad migration, environment bypass or forced repair.
- The approved migration bytes are unchanged. The operational latch/triggers
  described below are explicit deployment infrastructure DDL, not additional
  application migrations. Future approval must explicitly cover their creation.

States: `not_ready` means missing prerequisites or unresolved data conflict;
`eligible` means the exact pre-P0 schema, old uniqueness and compatible inventory;
`complete` requires exact columns/types/defaults, indexes, FKs, one history entry
and complete identity backfill; `unexpected` includes partial DDL/history/schema.
Complete execution retry additionally requires the original successful durable
preservation receipt. It never reconstructs a missing receipt from post-state.

Historical Payment #37 remains an independently audited `historical_qa`
identity, not a fabricated bank reference. Its NULL reference, money, original
documents, classification and lack of QA Run membership remain unchanged.
Official missing-reference history still fails before DDL.

## Database-native pause

The old active application has no shared Finance write middleware. A gate only
in the new candidate cannot fence old HTTP/CLI/queue processes. The new exact
operational service installs 177 fixed BEFORE INSERT/UPDATE/DELETE triggers on
59 explicitly listed Central tables. The static list covers all pre-existing
Central Finance tables plus the canonical registry and historical-audit actor
authority dependencies. No dynamic table wildcard is used to authorize DDL.

Each trigger takes a current shared locking read of the single row in
`eschool_p0_write_window` and denies writes unless its state is `open`.
Missing latch/row or unknown state fails closed. A stale REPEATABLE READ snapshot
cannot bypass the current locking read. The latch has exact inspected InnoDB
schema; protected tables must be InnoDB. Unexpected triggers, missing tables,
or an unfenced cascading FK parent block closure.

Initial trigger installation takes metadata locks and drains earlier table
transactions. Closure is not declared until every trigger is verified. Later
closing atomically locks the latch row, waits for writers holding shared latch
locks, and commits before any trigger DDL. One database advisory lock serializes
gate operations and the entire runner. Lost connection/partial installation
cannot authorize the migration.

SELECTs remain available. Writes to users/roles/registry/scopes are also paused
because changing them could invalidate historical QA authorization while the
migration inventories it. This does not change permissions or account values.
Login paths which update user records may pause; existing read-only sessions
are not globally cleared. No global application cache or site maintenance flag
is used. Old code may surface a rejected write as its normal database error;
operators must be told not to submit Finance mutations during this short window.

The new identity table is deliberately outside the old-table fence so the exact
migration can backfill it. Identity coverage and its hash are verified before
reopening. The runner hashes all original Finance rows and audit-authority rows,
excluding only the seven approved new columns. It also verifies unrelated
migration history. A durable success receipt is written only after all checks
pass, bound to the exact candidate, Central DB, immutable migration, migration
history/batch, identity table and financial snapshots. Reopen rehashes these.

Opening is **one atomic latch UPDATE**, never sequential trigger removal.
The verified triggers remain dormant after opening. Removal is separate reviewed
maintenance, not an automatic cleanup or permission to drop operational guards.
An open command with a lost acknowledgement has UNKNOWN outcome: inspect latch
state independently. Never claim it remained closed merely because CLI failed.

## Future approved deployment sequence

Only after exact full-SHA approval and resolution of the runtime blocker:

1. Verify candidate, exact remote SHA, ancestry, reviewed diff and clean source.
   Create a fresh encrypted R2E recovery set with Central and the current trusted
   installed/non-deleted tenant registry, shared storage, recovery configuration
   and manifest. No new backup is created by these gate commands.
2. Prepare the immutable candidate using the existing release builder, without
   switching. Shared runtime links and ownership must pass; Laravel boots as
   `www`, never root. Root validates Git/manifest/source byte ownership.
3. Invoke `bash scripts/production/run_guarded_unidentified_deposit_p0.sh`
   from that verified release, passing `RELEASE_DIR FULL_SHA preflight RECOVERY_SET`.
   The collector independently verifies encrypted hashes and COS HEAD/size,
   backup age (two hours maximum), registry coverage, exact launchers and process
   inventory. Credentials remain in a private temporary profile, not arguments
   or logs. No upload, deletion or CAM change is made by the collector.
   COSCLI `stat` must retain captured metadata output: **do not use
   `--disable-log` for HEAD**, since it suppresses Content-Length even on success.
   `--log-path` is confined to the same root-only disposable directory; neither
   stdout nor stderr is printed. Require exactly one positive Content-Length
   equal to the encrypted local size; exit code alone is never verification.
4. Repeat with phase `close`. The wrapper holds deployment and existing scheduler
   locks. The runner requires zero queued/reserved database jobs; unsupported
   backends and worker argument overrides are rejected. Never replay failed jobs.
   Resolve an unexpected queue instead of deleting it to satisfy the gate.
5. Repeat with phase `migrate`. Verify `complete` plus the durable preservation
   receipt. The old release can still render reads, but Finance writes remain
   closed throughout the incompatible old-code/new-schema interval.
6. Perform the separately approved atomic activation and graceful runtime
   lifecycle through the established release pipeline. Do not reopen merely
   because the symlink changed. Verify PHP 8.3 FPM and both supervised workers.
7. Repeat with phase `open`. Root checks the remote SHA, immutable contents,
   active marker/manifest, all target FPM children born after switching, exact
   worker commands/backend and release resolution, and a fresh nonce-bound real
   FastCGI probe through the active alias. Additional old application processes
   are denied. Worker cwd may legitimately be `/`; proof uses argv, process birth
   and resolved active path, not a guessed cwd. Root proof lasts 60 seconds and
   its issuer must still be a live root ancestor holding both locks when Laravel
   consumes it. Linux FPM paths are root-attested (non-dumpable children deny
   www readlink); www independently checks live birth and child/master linkage.
   CLI worker paths and commands remain directly checked. Schema, financial preservation receipt and empty queue are
   rechecked before the single atomic OPEN.
8. Read/render Production smoke only. Report failed-job counts without payloads;
   preserve all historical failed jobs. No automated real Finance transaction.

All four wrapper actions require the exact candidate/backup arguments. Non-read
actions require the operator's explicit `YES`; this interactive check does not
replace prior deployment/migration approval. The wrapper never switches a
release, reloads services, changes roles or creates a backup itself.

## Failure handling

| Failure | Required outcome |
| --- | --- |
| Preflight/backup/old writer/queue/baseline mismatch | No gate or application migration; stop. |
| Partially installed triggers | Preserve installed guards; migration denied. Investigate metadata/permissions; no broad cleanup. |
| Closed gate, before DDL | Reads available; writes denied. Restore the proven precondition before retrying. |
| Mid-DDL failure | Partial schema, no success receipt; writes remain closed. No automatic down(), generic migrate or forward repair. |
| Complete DDL, preservation mismatch/missing receipt | Writes remain closed, even if schema says complete. Review against encrypted recovery and before-evidence. |
| Activation/FPM/queue/WebSocket mismatch | Keep gate closed; old-code reads do not imply write compatibility. |
| Open acknowledgement lost | Inspect exact latch and current runtime; OPEN may have committed atomically. Do not assume CLOSED or blindly retry. |

An old-release symlink rollback alone is NOT a P0 schema rollback. Reopening on
old code is prohibited. Use a reviewed forward fix or separately approved
consistent database restore/rollback plan, retaining Finance closure until
schema, code, original data and runtime agree. Do not delete the latch, remove
triggers or edit its state manually to obtain PASS.

## Rehearsal and testing

- `UnidentifiedDepositP0MigrationTest`: runner calls, exact metadata/history,
  partial states and preservation failures.
- `UnidentifiedDepositP0WriteGateTest`: real disposable MySQL, all protected
  DML, read availability, metadata-lock draining, old snapshot bypass rejection,
  atomic reopen, malformed metadata and advisory serialization.
- `UnidentifiedDepositP0ReleaseGateTest`: exact command/path/hash/baseline,
  stale evidence, mismatched runtime and missing recovery evidence.
- `scripts/production/tests/test_p0_release_evidence.py`: operational parsing,
  wrong workers/backends and real Python-to-PHP evidence contract.
- `scripts/qa/unidentified-deposit-p0-runner-rehearsal.php`: fresh-only synthetic
  clone, original pinned migration, accepted historical QA, full runner, durable
  receipt, complete no-op, actual mid-DDL fault and atomic reopen. Only the
  root/Linux release evidence source is simulated in the local DB rehearsal;
  real schema/queue/receipt/latch validation remains active.
- `scripts/qa/p0-runtime-rehearsal.py`: real isolated PHP 8.3 FPM Unix socket,
  old/candidate markers, failed activation, atomic symlink switch, graceful
  reload and fresh worker response. It stops only its owned disposable FPM.

Local DB engine is MySQL 9.6; Production is MariaDB 10.11.10. MariaDB SQL NULL
metadata normalization is explicitly covered, but no Production trigger/DDL has
been run. Synthetic schema and runtime evidence stay local and are not seeds.
