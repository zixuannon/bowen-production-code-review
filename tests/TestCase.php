<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected bool $tenantDbAsDefault = false;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->tenantDbAsDefault) {
            config(['database.default' => 'school']);
        }
    }
}
