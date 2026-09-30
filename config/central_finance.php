<?php

// Central Finance daily navigation follows the authoritative per-School
// cutover state. Do not add a second School-code rollout allowlist here: it
// can drift from the write-retirement boundary and expose conflicting flows.
return [
    // A quantity is a bounded business input, never an arbitrary multiplier.
    // The service validates this value as well as the HTTP layer so a crafted
    // request cannot bypass the configured limit.
    'student_fee_max_quantity' => (int) env('CENTRAL_FINANCE_STUDENT_FEE_MAX_QUANTITY', 100),
];
