<?php

/*
 * Finance rollout policy is deliberately separate from the Cutover state.
 * A School must satisfy both conditions before its tenant sidebar is switched
 * to the Central Finance daily workspace.  This keeps a future Cutover from
 * silently changing a School's navigation before its UX rollout is approved.
 */
return [
    'school_finance_navigation_rollout_codes' => ['MMBOWEN01'],
];
