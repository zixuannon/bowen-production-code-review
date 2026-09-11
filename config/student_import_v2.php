<?php

return [
    /*
     * Student Import V2 is intentionally piloted in Zixuan only.  The code is
     * resolved through the trusted Central School registry, never supplied by
     * the workbook or browser.
     */
    'enabled_school_code' => env('STUDENT_IMPORT_V2_SCHOOL_CODE', 'MMBOWEN01'),
    'preview_ttl_minutes' => 30,
];
