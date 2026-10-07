# P0 deployment gate — additional old Production writers

## Resolved under bounded approval — 2026-10-07

The evidence below is historical. The operator subsequently approved only
rediscovery and graceful retirement of verified stale processes, not migration
or deployment. Immediately before the action, exact executable/argv/cwd,
PPID/start ticks/root user, old root-session cgroups and sandbox configuration
were revalidated. PID handles prevented signalling recycled PID numbers.

- Old source: `eschool-rc-e904db9-frontdesk-onboarding-20260907`.
- Artisan 2799084 / child 2799088: **B, stale test service**; root shell parent,
  loopback port 18081; no inspected Nginx/Supervisor/systemd/cron launcher uses it.
- Sandbox FPM 2797859: **B, independent stale test service**; separate master,
  no children, socket `/tmp/frontdesk-sandbox/php-fpm.sock`.
- Production DB configuration matched. Global database process visibility was
  verified; transactions 0, only one sleeping connection, queued jobs 0, failed
  jobs 2. No active test HTTP/FastCGI connection; old logs last active September 7.
- **06:44:05–06:44:09 UTC:** child SIGINT, parent exited, sandbox SIGQUIT.
  No SIGTERM or SIGKILL. Neither application nor server config was modified.
  Normal FPM 2507293, Queue 2058057 and WebSocket 2058061 were not signalled.
- All three old PHP processes exited; listener and socket gone. The exact local
  collector's read-only `current_fpm_pids`, `reject_unreviewed_app_processes`
  and `launcher_contract` checks pass. Other host PHP pools were not touched.
- Before/after **51 Central Finance tables plus jobs/failed_jobs** matched in
  both counts and full-row SHA-256 fingerprints. No SQL mutation was executed.
- /login 200 on three requests. No new Laravel log entries / HTTP 500 in the
  observation window. Nginx, PHP 8.3.27 FPM, MySQL, Redis, Queue, WebSocket and
  both R2E timers remain healthy. Last R2E service results are success/status 0.

Read-only runtime readiness is PASS, not authorization to install the writer
fence or run DDL. The later deployment must repeat all checks against fresh
state and encrypted backup. Root scheduler generic PHP 8.1 remains a separate
unchanged infrastructure backlog item.

Final review also verified www cannot dereference non-dumpable FPM child proc
symlinks. The **local** consumer uses root-attested FPM paths with live PID birth
and child/master validation, while retaining direct CLI checks. The pure live
validator passes under www against all 11 current FPM children without Laravel
boot, database access or a Production file write. Tests reject changed births,
wrong parent, missing/dead processes and inaccessible CLI paths.

## Original read-only investigation

Read-only observation on 2026-10-07; **no process stopped, no configuration,
database, role, Finance record or Production release changed**.

The migration gate must remain fail-closed until these writers are separately
reviewed and safely retired under explicit operational approval:

| Process | Read-only evidence |
| --- | --- |
| PHP CLI PID 2799084 | Working directory `/www/wwwroot/releases/eschool-rc-e904db9-frontdesk-onboarding-20260907`; Artisan reference in command; PHP 8.3 executable. |
| PHP CLI PID 2799088 | Child of 2799084; same old release `/public` working directory; effective `APP_ENV=production`. |
| PHP-FPM master PID 2797859 | Configuration `/tmp/frontdesk-sandbox/php-fpm.conf`; runs pool as `www`; socket `/tmp/frontdesk-sandbox/php-fpm.sock`; `chdir` points to the same old e904db9 release. |

The old release `.env` resolves to
`/www/wwwroot/releases/eschool-rc-168fc25-optional-fee-final-20260904/.env`.
Only non-secret identity fields were compared with the active Production `.env`:

- Old `APP_ENV`: `production`.
- Database host equality: **true**.
- Database name equality: **true**.
- Database connection equality: **true**.
- Old release compiled configuration cache present: **false**.

This is not sufficient evidence to treat these processes as disposable or
unrelated. Their process names or a path containing `sandbox` cannot establish
database isolation. PIDs are observations, not future kill targets: resolve and
revalidate identity again before any separately approved lifecycle action.

## Audited normal launchers

The two supervised application processes use PHP 8.3 and the active alias's
absolute `artisan` path. Queue connection is explicitly `database`; the single
Supervisor queue process name is `eschool-queue:eschool-queue_00`. Both currently
have `/` as working directory, so `cwd == release` is not a valid runtime test.
The gate instead requires exact reviewed argv, executable identity, process
birth after the active symlink switch and resolution of the active Artisan path
to the exact candidate. It preserves `cwd` separately for live process checks.

The root Laravel scheduler uses the existing root-owned
`/tmp/bowen_laravel_schedule.lock`. The gate holds that exact lock with
no-symlink opening through proof collection and the guarded Artisan action.
Its generic `/usr/bin/php` currently resolves to PHP 8.1. This is a separate
infrastructure issue; this task does not change the scheduler or PHP binaries.

Other host PHP pools exist. The collector does not stop or modify them. It
checks every PHP process and rejects additional Artisan/eSchool-release writers
and pools pointing to eSchool paths; indirect FPM includes require bounded
review rather than an unsupported assumption that the pool is unrelated.

## Required follow-up

### Additional read-only verification (2026-10-07)

- All three PHP process identities above are still present and date from
  September 7. The built-in server's parent is a root shell, PID 2799082,
  reparented to PID 1; no loop was detected in its command. The FPM master is
  also reparented to PID 1. Both belong to old root login-session cgroups, not
  the current supervised Queue/WebSocket processes.
- The built-in server listens on **127.0.0.1:18081**. The separate FPM master
  listens on **/tmp/frontdesk-sandbox/php-fpm.sock**. At inspection there were
  no observed established TCP connections to the built-in server and no observed
  connected clients on that FPM socket. This is a point-in-time observation,
  not an authorization to stop processes or proof of permanent quiescence.
- File-name-only searches of available Nginx vhost, Supervisor, systemd and
  cron configuration trees found no references to those old release/endpoint
  paths. This supports an ad-hoc launch origin; it does not prove that no other
  launcher or client exists. Shell history was not inspected.
- The normal PHP 8.3 master is independently PID 2507293. Its fixed root-owned
  PID file, exact executable and master configuration title agree. The active
  service is SysV-generated with `MainPID=0`, no systemd PIDFile and
  `Type=forking`; this is not evidence of a failed PHP-FPM daemon.
- The **local** collector now recognizes only this exact SysV topology and
  validates the fixed PID file, actual executable, root master, config title and
  production socket. Arbitrary PID-file fallback, mismatched native MainPID,
  other executables/configs/sockets and inactive services still fail closed.
  Operational contracts: **19 tests PASS**; actual isolated local PHP 8.3 FPM
  activation rehearsal passes again. No application PHP or migration changed.

Minimum next approval: revalidate current process identities and absence of
in-flight work, then stop only the old Artisan serve parent/child and gracefully
quit only the independent sandbox FPM master. Do not stop the normal PHP 8.3
FPM, Queue, WebSocket, database, web server or unrelated pools. Do not edit
launcher configuration unless new evidence and separate approval require it.
No such lifecycle action has been executed.

1. Independently identify how the old built-in server and sandbox FPM are
   launched and whether they remain reachable.
2. Obtain explicit approval for the minimum lifecycle/launcher repair; do not
   kill historical PID numbers blindly or touch unrelated hosting services.
3. Re-run read-only runtime inventory, queue checks and the exact P0 preflight.
4. Only a separately approved deployment may create the fresh backup, close
   writes, run the exact pinned migration and activate the immutable candidate.

The local gate implementation and rehearsal do **not** resolve this Production
runtime blocker or authorize migration/deployment. A clean database preflight
alone is not permission to proceed.
