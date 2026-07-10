<?php

/**
 * Minimal bootstrap for TwoStageLeaveService isEnabled integration tests.
 *
 * Creates a minimal Laravel Container so config() helper works without
 * full application boot.
 *
 * Usage:
 *   php vendor/bin/phpunit --bootstrap tests/bootstrap_two_stage_leave.php tests/Unit/TwoStageLeaveServiceIsEnabledTest.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = new \Illuminate\Container\Container();

// Config repository — uses nested arrays for dot-notation support
$configRepo = new \Illuminate\Config\Repository([
    'database' => [
        'connections' => [
            'school' => [
                'database' => 'eschool_saas_15_zixuan',
            ],
        ],
    ],
    'features' => [
        'staff_leave_two_stage_enabled' => false,
        'staff_leave_enabled_school_databases' => '',
    ],
]);
$app->instance('config', $configRepo);
$app->alias('config', \Illuminate\Contracts\Config\Repository::class);

// Schema::connection() internally calls $app['db']->connection()->getSchemaBuilder()
// Tests override 'db' via Mockery when they need Schema results.
$app->instance('db', null);

\Illuminate\Container\Container::setInstance($app);
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
