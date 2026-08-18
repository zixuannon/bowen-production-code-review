# Finance Group — Goal 5: Income / Expense Category Standard Contract

**Status:** Architecture and taxonomy decision package only.  
**Scope:** Standardizing income and expense classification for Ledger V1,
tenant Finance reports, and future Bowen Group consolidation.  
**Non-goals:** No reclassification of historical transactions, no deletion of
existing categories, no code/schema/data migration, no production/Staging
work, and no change to P0–P3 money movement rules.

## 1. Decision

Adopt one Finance taxonomy with two independent dimensions:

```text
Ledger class                  Finance category
-------------                 -------------------------------
Operating income              Income group -> Income category
Operating expense             Expense group -> Expense category
Internal transfer             Purpose only; no income/expense category
Non-operating / future        Explicit future class, never guessed
```

`transaction_class` answers **what accounting behavior the movement has**.
`finance_category` answers **why the operating income/expense happened**.
They cannot replace one another.

Examples:

- Student tuition is `STUDENT_FEE` + Income / `TUITION`.
- Summer camp collection is `OTHER_INCOME` + Income / `CAMPS_PROGRAMS`.
- Rent payment is `EXPENSE` + Expense / `RENT`.
- HQ funding is `INTERNAL_TRANSFER` + purpose `HQ_FUNDING`, with no income or
  expense category.

## 2. Current-state characterization

| Current component | Current role | Limitation to preserve/fix later |
|---|---|---|
| `finance_categories` / `FinanceCategory` | Has `type`, `category_code`, name, active/default/sort metadata; linked to `fees_class_types` and `expenses` | No category-group hierarchy; no current relation from `OtherIncome`; category governance is not yet Group-standardized. |
| `expense_categories` / `ExpenseCategory` | Legacy school-scoped category used by legacy Expense, transportation, and existing reports | Must remain compatible. It cannot be deleted or silently repurposed. |
| Compulsory / optional fee definitions | May carry `finance_category_id` through `fees_class_types` | One compulsory fee can contain multiple class-type categories; current report safely falls back to `Compulsory Fees` / `Uncategorized` rather than inventing a split. |
| `OtherIncome` | Valid non-fee receipt with Fund Account, payment method, reference, payer, description | Has no `finance_category_id`; Finance Report currently groups all rows as `Other Income`. |
| `Expense` | Valid operating outflow with Fund Account; supports `finance_category_id` and legacy `category_id` | Historical/legacy data can be uncategorized or use the legacy category only. |
| Current Finance report | Uses source records and Finance account scope | It must not convert internal transfers into income/expense merely to fit a category chart. |

## 3. Standard taxonomy model

The future model has exactly two operating category types: `income` and
`expense`. Each category belongs to one category group. Codes are stable,
language-neutral identifiers; Chinese/English/local display names can evolve
without changing historical classification.

```text
INCOME
  Tuition & Academic Fees
    -> TUITION
    -> REGISTRATION_ADMISSION
    -> EXAMINATION
  Programs & Services
    -> CAMPS_PROGRAMS
    -> ACTIVITIES_EVENTS
    -> UNIVERSITY_PLACEMENT
  Sales & Ancillary Services
    -> BOOKS_MATERIALS
    -> UNIFORMS
    -> FACILITY_RENTAL
  Finance & Other Approved Income
    -> BANK_INTEREST
    -> DONATIONS_SPONSORSHIP
    -> OTHER_APPROVED_INCOME

EXPENSE
  People
    -> SALARIES_WAGES
    -> STAFF_BENEFITS
    -> STAFF_TRAINING
  Premises & Utilities
    -> RENT
    -> UTILITIES
    -> REPAIRS_MAINTENANCE
  Teaching & Student Services
    -> TEACHING_MATERIALS
    -> STUDENT_ACTIVITIES
    -> EXAMINATION_COSTS
  Operations
    -> TRANSPORTATION
    -> MARKETING_RECRUITMENT
    -> IT_SOFTWARE
    -> PROFESSIONAL_SERVICES
    -> BANK_CHARGES
  Other Approved Expense
    -> OTHER_APPROVED_EXPENSE
```

The chart starts deliberately small. New categories require controlled
configuration and must not be created ad hoc during payment entry merely to
avoid selecting the right existing category.

## 4. Other Income boundary

`OtherIncome` remains a valid operating income source only when it is a real,
non-student-fee receipt and has a valid Fund Account, payment method,
reference handling, and category.

### Must be classified through Other Income

| Business receipt | Standard category |
|---|---|
| Summer camp, holiday program, non-tuition course | `CAMPS_PROGRAMS` |
| School activity/event charge that is not a normal student fee | `ACTIVITIES_EVENTS` |
| Application/registration payment not represented by student fee module | `REGISTRATION_ADMISSION` |
| HSK/exam service receipt | `EXAMINATION` |
| University/overseas placement or documented service fee | `UNIVERSITY_PLACEMENT` |
| Books, teaching materials, uniforms | `BOOKS_MATERIALS` / `UNIFORMS` |
| Approved facility/room rental | `FACILITY_RENTAL` |
| Bank interest | `BANK_INTEREST` |
| Approved sponsorship/donation recognized as income | `DONATIONS_SPONSORSHIP` |

### Must **not** be Other Income

| Receipt / event | Correct treatment |
|---|---|
| Compulsory/optional student fee payment | Existing student-fee sources; never duplicate in Other Income. |
| HQ funding, school remittance, Bank Transfer, confirmed Fund Handover | Internal Transfer; operating income = 0. |
| Parent refundable deposit / security deposit | Future liability/deposit workflow, not income. |
| Loan proceeds | Future liability/financing workflow, not income. |
| Refund/reversal receipt correction | Link to original source under future refund/reversal contract; do not call it income. |
| Unknown cash receipt | Must remain pending/unclassified exception for Head Finance review; do not use `OTHER_APPROVED_INCOME` without reason/approval. |

`OTHER_APPROVED_INCOME` is a controlled exception: mandatory description,
reason, document/reference, and Head Finance approval rule. It is never a
shortcut for missing category configuration.

## 5. Category selection and audit rules

1. New operating income and expense records require an active category in the
   correct type once this phase is implemented. Fund Account is still a
   separate required field; payment method is not a category.
2. Inactive categories cannot be selected for new records, but must remain
   resolvable for historical ledger/report rows.
3. A category code cannot change meaning after use. Rename/display translation
   is allowed; reusing `RENT` for another purpose is not.
4. Reclassification of a posted financial source is an audited correction:
   actor, old category, new category, reason, time, and approval where
   required. It must not alter amount, Fund Account, source identity, or
   ledger key.
5. Category configuration has its own permission/capability. An Accountant
   entering money must not gain category-management rights.
6. Categories do not bypass tenant or Group scope. A Group standard template
   supplies allowable codes; each School's enabled selection is still
   explicitly configured and authorized.

## 6. Compatibility strategy

Do not merge or delete `ExpenseCategory` and `FinanceCategory` in place.
They have different historical use and routes.

### Phase A — read-only inventory and mapping

Create a versioned mapping contract, not an automatic data rewrite:

| Legacy source | Mapping target | Rule |
|---|---|---|
| `FinanceCategory.category_code` | Standard category code | Direct when a reviewed code/type match exists. |
| `FinanceCategory` without stable code | Standard code or `UNCATEGORIZED_LEGACY` | Head Finance review; do not guess from name alone. |
| `ExpenseCategory` legacy category | Standard expense category code | Explicit per-School mapping, potentially many legacy values → one standard code. |
| Fees without category or mixed compulsory categories | `UNCATEGORIZED_LEGACY` / safe aggregate display | No fabricated split of a single payment. |
| Historical Other Income | `UNCATEGORIZED_LEGACY_INCOME` unless reviewed | Never bulk infer from free-text description. |

### Phase B — future-entry enforcement

New Other Income gets a category field and validates against active,
current-school / permitted Group taxonomy. New Expense uses the selected
standard Finance category while preserving the legacy `category_id` only for
features that still require it.

### Phase C — reporting transition

Ledger V1 shows both:

```text
standard_category_code / display name
legacy_category_label (where applicable)
mapping_version
classification_state: classified | uncategorized_legacy | pending_review
```

This allows Group reports to show category completeness rather than presenting
uncertain history as accurate standardized data.

## 7. Reporting rules

1. Income/expense by category includes only operating sources classified in
   Goal 3. Internal transfers are excluded from every category total.
2. Reports filter by category code, group, School, Fund Account, source,
   operator, date, and classification state. Every filter remains constrained
   by existing account/tenant/Group authorization.
3. `Uncategorized` and `pending_review` are first-class report rows, with
   count and amount. They cannot be silently folded into an arbitrary category.
4. Group comparison uses standard codes, not free-text names. This enables
   later comparisons such as Bahan vs Times City `SALARIES_WAGES`.
5. Finance reports must distinguish **source class** (student fee / Other
   Income / Expense) from **category** so a user can trace every total back to
   its canonical source.
6. Export/template row numbers are not IDs and never determine a category or
   overwrite a historical expense.

## 8. Permissions and ownership

| Action | School Admin | Head Finance | Accountant | Group Head Finance |
|---|---|---|---|---|
| View allowed local categories/reports | existing local policy | yes within scope | only accessible-account reports | all explicitly scoped Schools |
| Enter operating category | existing entry permission | yes within scope | yes when entering permitted source | not through arbitrary tenant bypass |
| Manage School category mapping | existing admin policy / explicit capability | yes if granted | no | Group template governance only if granted |
| Create/retire Group standard code | no by default | no by default | no | explicit Group taxonomy capability plus audit |
| Reclassify historical source | explicit audited capability | explicit audited capability | no by default | only explicit Group scope; never bypass tenant audit |

This is a capability matrix, not a reason to broaden current Finance Staff or
Fund Account permissions.

## 9. Implementation boundary and test plan

Before any code change, require approved category ownership and an initial
Bowen category list. Implementation must then prove:

- new Other Income cannot be saved without an active, correct income category;
- a student fee cannot be rerouted to Other Income;
- new Expense requires an active, correct expense category while retaining
  Fund Account/payment-method/tenant/account authorization;
- inactive, deleted, cross-school, and forged category IDs are rejected;
- `OTHER_APPROVED_*` requires reason and approval reference;
- existing legacy ExpenseCategory flows (including transportation) keep their
  behavior and do not lose historical labels;
- existing FinanceCategory fee mapping/report behavior is unchanged or has
  characterized, approved output changes;
- standard category report totals reconcile to Ledger V1 source totals;
- internal transfers remain zero in operating/category totals;
- category changes preserve audit trail and cannot duplicate/mutate money;
- Cashier/Accountant scope remains enforced for entry and reports;
- cross-school Group queries cannot read or configure an unrelated School.

## 10. Business sign-off required

1. Confirm whether activities and summer camp are always operating income or
   whether any are pass-through/refundable programs.
2. Confirm admission/application receipt policy: should it become a normal
   student fee source once student record exists, or remain Other Income?
3. Confirm sponsorship/donation recognition policy and required documents.
4. Confirm the owner permitted to create/retire Group standard categories.
5. Approve the initial category-code dictionary, Chinese/English labels, and
   the mapping of all existing legacy categories.
6. Set the monetary/role threshold for `OTHER_APPROVED_INCOME` and
   `OTHER_APPROVED_EXPENSE` approval.
7. Confirm whether `BANK_INTEREST` and `BANK_CHARGES` are operating or shown
   separately as finance result lines in management reporting.

## Goal 5 acceptance result

- [x] Income/expense classification separated from Ledger class and Fund
      Account/payment method.
- [x] Controlled two-level taxonomy and stable codes proposed.
- [x] Other Income valid/invalid boundary defined.
- [x] Existing FinanceCategory and legacy ExpenseCategory compatibility
      strategy defined without historical rewrite.
- [x] Audit, report, authorization, and Group-scope requirements defined.
- [x] Business sign-off and runtime acceptance plan defined.
- [x] No runtime code, schema, data, production, or Staging change made.
