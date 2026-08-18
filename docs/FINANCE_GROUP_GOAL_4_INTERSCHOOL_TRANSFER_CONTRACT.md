# Finance Group — Goal 4: HQ ↔ School Transfer Contract

**Status:** Architecture and business-rule decision package only.  
**Scope:** Headquarters-to-school and school-to-headquarters movement across
separate School tenants in one Finance Group.  
**Non-goals:** No runtime implementation, schema migration, Group account
bootstrap, cross-tenant write, production access, or change to the existing
single-school P3 Fund Handover flow.

## 1. Decision

Cross-school money movement is an **internal Group transfer**, not income and
not expense. It will reuse the proven custody semantics of:

```text
request -> pending (no movement) -> designated receiver confirms
        -> one canonical completed transfer -> two account-side legs
```

but it cannot reuse the current tenant-local `FundHandover` / `BankTransfer`
database shape directly. Those records have same-tenant foreign keys and a
single tenant transaction boundary.

The future Group module needs one central canonical transfer identity with two
explicit account legs. It must never create two unrelated tenant
`BankTransfer` records and then attempt to treat them as one event.

```text
HQ Main Cash (Group account)         School A Cash (Group account)
             |                                  ^
             |  one GroupTransfer + two legs     |
             +----------------------------------+

Operating income = 0
Operating expense = 0
```

The existing local P3 flow remains correct and remains tenant-local:

```text
local FundHandover confirmed -> exactly one tenant BankTransfer
```

## 2. Valid transfer purposes

Purpose is required for every Group transfer. V1 uses a controlled list,
not unvalidated free text:

| Code | User-facing label | Accounting class |
|---|---|---|
| `HQ_FUNDING` | 总部拨款 / HQ Funding | Internal transfer |
| `SCHOOL_REMITTANCE` | 分校上缴 / School Remittance | Internal transfer |
| `OPERATING_FLOAT` | 日常运营备用金 / Operating Float | Internal transfer |
| `PROCUREMENT_FLOAT` | 采购备用金 / Procurement Float | Internal transfer |
| `ACTIVITY_FLOAT` | 活动备用金 / Activity Float | Internal transfer |
| `EMERGENCY_FLOAT` | 紧急备用金 / Emergency Float | Internal transfer |
| `OTHER_APPROVED` | 其他已批准调拨 / Other Approved Transfer | Internal transfer |

`OTHER_APPROVED` requires a non-empty reason and an approval reference. A
purpose categorizes custody/business intent; it must never make the transfer
operating income or expense.

## 3. Proposed canonical model

All proposed entities are additive on the trusted central Group Finance
control plane introduced in Goal 2.

| Entity | Essential fields | Rule |
|---|---|---|
| `group_fund_transfers` | id/uuid, group_id, reference_no, purpose, requested_by_group_user_id, from_group_account_id, to_group_account_id, amount, currency, requested_at, status, confirmation/approval/cancellation audit fields | One canonical economic event. `reference_no` and immutable UUID are unique within Group. |
| `group_fund_transfer_legs` | transfer_id, group_fund_account_id, direction (`OUT`/`IN`), amount, currency, school_id nullable, posted_at | Exactly two rows after completion: one OUT and one IN, same amount/currency. |
| `group_fund_transfer_events` | transfer_id, event_type, actor_group_user_id, occurred_at, reason, metadata | Append-only custody/audit timeline. Never overwrite a decision. |
| `group_fund_transfer_idempotency_keys` | group_id, operation, key, transfer_id | Protects create and confirmation retries; unique per Group/operation/key. |

Required invariants:

1. Both accounts are active `group_fund_accounts` in the same active Group.
2. Accounts differ and use the same currency in this phase.
3. A completed transfer has exactly two, and only two, legs: one `OUT`, one
   `IN`, amounts equal to the canonical amount.
4. A pending/rejected/cancelled transfer has zero posted legs.
5. `confirmed_at`, confirmation actor, and posted legs are committed together.
6. Reference number and idempotency key cannot be reused for a second
   economic event.
7. Every event records actor/time; rejection/cancellation requires a reason.

## 4. State machine

```text
                 request
  DRAFT ------------------------> PENDING
                                    |  \ 
                          receiver  |   \ sender cancels (reason)
                           confirms |    v
                                    v  CANCELLED
                                CONFIRMED
                                    ^
                       approval required (policy)
                                    |
                                APPROVED

  PENDING / APPROVED -- receiver rejects (reason) --> REJECTED
```

### Meaning of each state

| State | May alter balance / Ledger? | Required audit |
|---|---|---|
| `DRAFT` (optional UI-only) | No | creator/time |
| `PENDING` | No | requester, purpose, accounts, amount, receiver, request time |
| `APPROVED` (only if policy requires it) | No | approver/time/decision reference |
| `CONFIRMED` | Yes, exactly once | receiver, time, immutable canonical legs/source identity |
| `REJECTED` | No | receiver/authorized approver, time, mandatory reason |
| `CANCELLED` | No | requester/authorized actor, time, mandatory reason |

`CONFIRMED` is immutable as a financial posting. A later correction must be a
new explicit reversing Group transfer with its own purpose/reference and a
link to the original. It must not delete, re-open, or edit the two posted legs.

## 5. Authority and custody

The following is a policy boundary, not a role-name shortcut. All actors also
need the explicit Group/SCHOOL/HQ account scope from Goal 2 and the matching
tenant-local identity where an operational school view is involved.

| Action | Group Head Finance | HQ Accountant | School Accountant | School Admin |
|---|---|---|---|---|
| View assigned Group transfers | allowed by explicit scope | allowed by explicit scope | own assigned School/account only | own School oversight only, if granted |
| Request HQ → School | allowed for scoped HQ + target School | allowed only if explicitly granted | may request funding, not send from HQ | no |
| Request School → HQ | allowed for scoped accounts | allowed only if explicitly granted | allowed only from assigned School account | no |
| Approve (if configured) | allowed only when distinct-policy conditions pass | only if explicitly granted | no by default | no |
| Confirm receipt | never automatically both sender and receiver | only for assigned receiving account | only for assigned receiving account | no |
| Reject / cancel pending | requester/receiver per state plus scope | same | same | no |

Minimum custody rules:

1. Sender, designated receiver, and optional approver must be distinct people
   for transfers that require approval; no one approves their own request.
2. The receiver confirms **physical receipt/custody**, not merely clicks an
   approval button.
3. A School Accountant can never obtain an HQ/shared account by selecting its
   ID in a request; server-side Group-account scope is mandatory.
4. A School Admin retains the existing read-only stance. Visibility never
   creates a participant/action right.
5. No actor receives all Group access solely because they have a tenant-local
   `Head Finance` or `Cashier` role.

## 6. Request validation

Before a request becomes `PENDING`, the service must prove:

```text
trusted active Group membership
AND actor has the correct Group capability
AND source and destination accounts are active, non-deleted, same Group
AND each account has a permitted owner/school link
AND source != destination
AND same currency (V1)
AND amount > 0
AND purpose is allowed; Other has reason + approval reference
AND receiver has active receiving-account custody scope
AND source has sufficient available balance
AND reference/idempotency key is not already consumed
```

The sender balance is rechecked under an appropriate lock at confirmation,
because a pending request does not reserve or move money. Whether a future
policy needs *reservation* of a high-value pending transfer is a separate
business decision; V1 must not silently reserve funds.

## 7. Confirmation and failure safety

Cross-tenant database writes cannot safely pretend to be one ordinary local
database transaction. Implementation must use a durable, recoverable workflow:

1. lock the canonical pending transfer in central storage;
2. revalidate receiver identity, account scope, source status/currency, and
   available balance;
3. create the immutable two-leg canonical posting under a durable idempotency
   key;
4. mark transfer `CONFIRMED` only with the posting identity and confirmation
   audit event; and
5. publish/read Ledger V1 from that one source.

If any step is uncertain, the result must remain safely recoverable and must
not display a completed transfer with only one leg. Operations must provide a
reconciliation queue/report for:

- pending confirmation beyond threshold;
- canonical transfer without two valid legs;
- retry/idempotency collision;
- source-account balance conflict at confirmation;
- revoked scope, deactivated account, or changed currency before confirmation.

There is no automatic financial rollback after a confirmed real-world cash or
bank movement. A correction uses an audited reversal/new transfer after human
approval.

## 8. Ledger, balances, and reports

For a completed Group transfer of X:

| Perspective | Money In | Money Out | Internal transfer | Operating income/expense |
|---|---:|---:|---:|---|
| Source account | 0 | X | 0 | 0 / 0 |
| Destination account | X | 0 | 0 | 0 / 0 |
| Group/all accounts | 0 | 0 | X | 0 / 0 |

Ledger V1 identity is based on the central canonical source, for example:

```text
group:<group-id>:group_fund_transfer:<uuid>
```

It is distinct from a tenant source identity and appears once in a Group
all-accounts register. If a school account is also shown in a tenant-local
ledger, its local presentation must be a carefully designed projection linked
to the same Group key—not an independently editable local BankTransfer.

Opening balance, income, expense, bank reconciliation, and cash closing must
recognize the Group account authority before this feature is implemented. Do
not duplicate an HQ balance into individual school tenants as a shortcut.

## 9. Multi-currency boundary

V1 permits only transfers between accounts with the same currency. This avoids
silently creating a foreign-exchange gain/loss or an invented rate.

Later multi-currency transfer work requires, before confirmation:

- original source amount/currency;
- destination amount/currency;
- approved exchange-rate snapshot, source, time, and approver;
- explicit FX difference classification; and
- two account legs plus a separately classified FX ledger event where
  accounting policy requires it.

No current MMK-only transfer may be relabelled as multi-currency historical
data without a reliable snapshot.

## 10. Business decisions required before implementation

| Decision | Proposed safe default | Owner needed |
|---|---|---|
| Approval threshold | Receiver confirmation only; dual approval disabled until approved matrix exists | Finance Director / Head Finance |
| Who may request HQ funding | Head Finance; School Accountant may submit a request, not release HQ funds | Finance Director |
| Who receives HQ money at a branch | Accountant assigned to the exact destination Fund Account | Branch/HQ Finance |
| School remittance cadence | No automatic schedule; manual dated request | Finance Director |
| Emergency transfer policy | Same controls, mandatory purpose/reason; no bypass | Finance Director |
| High-value attachment | Mandatory once threshold and attachment type are signed off | Finance Director |
| Pending expiry | No automatic cancellation; report/escalate only | Finance Director |
| Reference-number format | Group-generated immutable reference proposed | Finance Operations |

## 11. Required local acceptance before a runtime phase

- two synthetic School tenants plus one unrelated tenant;
- HQ→School request remains pending with both balances unchanged;
- only correctly scoped receiver confirms; then one canonical transfer and
  exactly two balanced legs exist;
- Group all-account Ledger row is one neutral internal transfer;
- account-specific views are directional;
- operating income/expense/net delta is zero;
- duplicate request/confirmation retries produce no extra posting;
- insufficient balance at confirmation produces no legs or partial state;
- inactive/deleted/cross-Group/unassigned account requests fail server-side;
- forged Group/school/account IDs fail server-side;
- receiver, approver, requester separation is enforced where applicable;
- reject/cancel creates no movement and retains reason/actor/time;
- confirmed correction is an explicit reverse/new event, never deletion;
- existing tenant P0–P3 transfer, handover, account scope, and report tests
  remain green.

## 12. Rollout order

1. Obtain the business decisions in section 10 and approved account catalog.
2. Implement Goal 2 Group scope configuration with a guarded, read-only
   preview/bootstrap path.
3. Implement/verify Ledger V1 tenant source adapter and Group read-only
   projection.
4. Build the Group transfer state machine in a synthetic multi-tenant QA
   environment, with reconciliation tooling before enabling any write.
5. Run full balance/ledger/custody failure tests and an end-to-end HQ→School
   browser scenario.
6. Require a separate production migration and deployment Human Gate.

## Goal 4 acceptance result

- [x] Cross-school transfer classified as internal movement only.
- [x] One canonical Group transfer and exactly two legs selected over duplicate
      tenant transfers.
- [x] Pending/confirmed/rejected/cancelled state and no-movement rule defined.
- [x] Authorization, custody, account, idempotency, and recovery invariants
      defined.
- [x] Same-currency V1 boundary and future FX requirements defined.
- [x] Required business sign-offs and local acceptance plan defined.
- [x] No runtime code, schema, data, production, or Staging change made.
