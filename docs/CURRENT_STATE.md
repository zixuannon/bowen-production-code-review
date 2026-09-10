# eSchool Current State

Last updated: 2026-09-10

## Active production target

- Server: `43.160.241.126`
- Project: `/www/wwwroot/43.160.241.126`
- Canonical SSH alias: `eschool-prod`

`183.240.79.48` is a legacy/rollback environment only. It must not be selected, connected to, deployed to, or migrated for Finance P1.

## Current area

Finance V2

## Round 2 P0B financial integrity — local candidate

- Branch `codex/round2-p0b-financial-integrity` starts from the active
  Production release manifest SHA `06b9c7c2a72736d748b8edfd7c7a5aefcb26a302`.
- Stripe, Razorpay, Paystack, and Flutterwave settlement now serializes on the
  existing server-created `payment_transactions` identity with a database
  transaction and row lock. Replays are no-ops, concurrent deliveries cannot
  duplicate settlement rows, failure events cannot overwrite success, and no
  webhook creates a transaction.
- Legacy offline compulsory, installment, and optional payments now rebuild
  canonical amount, due charge, currency, exchange rate, and selected-item
  totals from locked School-owned Fee Setup/receivable rows. Negative, zero,
  overpayment, cross-School, duplicate, and client-tampered values fail closed.
- Focused financial/security regression passes (65 tests, 249 assertions),
  including a two-process database race. The complete PHPUnit suite passes
  (640 tests, 4,210 assertions; zero failures/errors).
- No schema migration is added or executed. Production code/data, Phase 5.5B,
  homepage/assets, and established Finance workflow remain untouched.

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

- No active local test-bootstrap blocker. PHP 8.5's `PDO::MYSQL_ATTR_SSL_CA` and PHPUnit XML-schema notices remain non-functional compatibility debt.

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

- Student Import V2 is XLSX-only and remains a Zixuan-only pilot. The workbook is School-local: it contains no School routing field, and Class Section plus Academic Year are human-readable dropdowns resolved and re-validated against the authenticated tenant.
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
