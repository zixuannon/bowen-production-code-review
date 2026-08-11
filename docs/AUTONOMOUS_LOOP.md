# Autonomous Development Loop

Use this as the default execution state machine.

```text
READ STATE
   ↓
TRACE RELEVANT CODE
   ↓
PLAN MINIMAL CHANGE
   ↓
TARGETED BASELINE
   ↓
IMPLEMENT
   ↓
TARGETED TESTS
   ↓
FAIL? ── yes ──> DIAGNOSE → FIX → RETEST
   │
   no
   ↓
LOCAL PLAYWRIGHT (UI changes)
   ↓
DIFF / SECURITY / TENANT REVIEW
   ↓
BROADER REGRESSION (only when warranted)
   ↓
FAIL? ── yes ──> FIX → RETEST
   │
   no
   ↓
UPDATE CURRENT_STATE
   ↓
PRODUCTION IMPACT?
   ├─ yes → PREPARE MANIFEST/PREFLIGHT → PRODUCTION HUMAN GATE
   └─ no  → CONTINUE NEXT DEFINED SUB-PHASE
```

All implementation, synthetic data, migrations, PHPUnit, and Playwright work runs locally by default. Staging is paused and is not a required state in this loop.
