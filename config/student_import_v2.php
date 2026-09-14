<?php

return [
    /*
     * Student Import V2 is enabled only for the explicitly approved
     * centralization Schools. Every code is resolved through the trusted
     * Central School registry, never supplied by the workbook or browser.
     */
    'enabled_school_codes' => array_values(array_filter(array_map(
        static fn (string $code): string => strtoupper(trim($code)),
        explode(',', (string) env('STUDENT_IMPORT_V2_SCHOOL_CODES', 'MMBOWEN01,MMBOWEN02,MMBOWEN03')),
    ))),
    'preview_ttl_minutes' => 30,
];
