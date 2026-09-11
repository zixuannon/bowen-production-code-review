# Central Finance Gate A

Gate A prepares only the additive Central Finance schema and the tenant-owned
Student Financial Profile sync contract. It is deliberately not a Central
Finance cutover.

## Included

- Seven additive central migrations (student profiles, future Finance schema,
  and legacy identity-map storage), all with `down()` methods.
- The tenant `students.central_finance_source_uuid` migration.
- Trusted-registry Student Profile reconciliation and an explicit future
  backfill/sync command.
- A fixed active-school code allowlist: `MMBOWEN01`, `SCH202616`,
  `SCH202619`, `SCH202620`, `SCH202621`, `SCH202631`, `SCH202632`.
- A read-only legacy-cutover inventory/reconciliation command. It has no
  execute mode and cannot write a migration manifest or Finance document.

## Explicitly excluded

- Central Finance workspace/routes/UI and every Central Finance write path.
- Historical Finance import, Ledger/balance/opening-balance writes, and any
  Group Operating Context write expansion.
- The inactive Demo tenant (`SCH20261`) and arbitrary database selection.

## Commands

`central-finance:student-profile-sync` is read-only by default. A future
profile-sync write requires explicit `--execute`; generating missing tenant
UUIDs additionally requires `--backfill --execute`. School resolution is
always through the trusted central registry and fixed allowlist.

`central-finance:legacy-cutover` is permanently read-only in Gate A. It accepts
only fixed school codes and never accepts a database name.

## Release invariant

Applying Gate A schema alone creates no Central Fund Account, payment, receipt,
expense, other income, transfer, handover, funding request, Ledger entry, or
opening balance. Student UUID backfill and profile sync require a separately
approved, explicit operational run after schema deployment.
