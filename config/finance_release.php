<?php

return [
    /*
     * Production's trusted central registry snapshot. The command compares the
     * central `schools` rows with this map before it may execute anything.
     * Tests may replace this value with synthetic tenants.
     */
    'p31_p32_tenants' => [
        'SCH20261' => 'eschool_saas_1_demo',
        'SCH202615' => 'eschool_saas_15_zixuan',
        'SCH202616' => 'eschool_saas_17_bahan',
        'SCH202619' => 'eschool_saas_19_timecitys',
        'SCH202620' => 'eschool_saas_20_',
        'SCH202621' => 'eschool_saas_21_',
        'SCH202631' => 'eschool_saas_31_zixuanyang',
        'SCH202632' => 'eschool_saas_32_',
    ],
];
