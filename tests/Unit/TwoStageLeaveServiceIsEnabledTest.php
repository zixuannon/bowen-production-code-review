<?php

namespace Tests\Unit;

use App\Services\StaffLeave\TwoStageLeaveService;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive integration tests for TwoStageLeaveService::isEnabled()
 * — the unified three-tier feature gate.
 *
 * Tests the full chain:
 *  1. global flag (config features.staff_leave_two_stage_enabled)
 *  2. school allowlist (config features.staff_leave_enabled_school_databases)
 *  3. schema completeness (10 columns via Schema facade)
 *
 * Uses a custom bootstrap (tests/bootstrap_two_stage_leave.php) that sets
 * up a minimal Laravel Container so config() works.
 *
 * Schema::connection() internally calls $app['db']->connection()->getSchemaBuilder(),
 * so tests mock the 'db' container binding directly.
 *
 * Run with:
 *   php vendor/bin/phpunit --bootstrap tests/bootstrap_two_stage_leave.php tests/Unit/TwoStageLeaveServiceIsEnabledTest.php
 */
class TwoStageLeaveServiceIsEnabledTest extends TestCase
{
    private const SCHOOL_15_DB = 'eschool_saas_15_zixuan';
    private const SCHOOL_16_DB = 'eschool_saas_16_other';

    protected function setUp(): void
    {
        parent::setUp();
        TwoStageLeaveService::resetCache();

        app('config')->set([
            'database.connections.school.database' => self::SCHOOL_15_DB,
            'features.staff_leave_two_stage_enabled' => false,
            'features.staff_leave_enabled_school_databases' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        TwoStageLeaveService::resetCache();
        parent::tearDown();
    }

    // ================================================================
    // Config helpers
    // ================================================================

    private function setGlobalFlag(bool $enabled): void
    {
        app('config')->set('features.staff_leave_two_stage_enabled', $enabled);
    }

    private function setAllowlist(string $databases): void
    {
        app('config')->set('features.staff_leave_enabled_school_databases', $databases);
    }

    private function setSchoolDb(string $dbName): void
    {
        app('config')->set('database.connections.school.database', $dbName);
    }

    /**
     * Mock the 'db' container binding so Schema::connection('school')
     * returns a schema builder where all 10 columns exist.
     */
    private function expectSchemaComplete(): void
    {
        $schemaBuilder = Mockery::mock();
        $schemaBuilder->shouldReceive('hasColumn')->andReturn(true)->byDefault();

        $connection = Mockery::mock();
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schemaBuilder);

        $db = Mockery::mock();
        $db->shouldReceive('connection')->with('school')->andReturn($connection);

        app()->instance('db', $db);
    }

    /**
     * Mock schema incomplete: first column missing.
     */
    private function expectSchemaIncomplete(): void
    {
        $schemaBuilder = Mockery::mock();
        $schemaBuilder->shouldReceive('hasColumn')
            ->with('staffs', 'supervisor_user_id')
            ->andReturn(false);
        $schemaBuilder->shouldReceive('hasColumn')->andReturn(false)->byDefault();

        $connection = Mockery::mock();
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schemaBuilder);

        $db = Mockery::mock();
        $db->shouldReceive('connection')->with('school')->andReturn($connection);

        app()->instance('db', $db);
    }

    /**
     * Mock schema unreachable: $app['db'] throws.
     */
    private function expectSchemaThrows(): void
    {
        $db = Mockery::mock();
        $db->shouldReceive('connection')
            ->with('school')
            ->andThrow(new \RuntimeException('Connection refused'));

        app()->instance('db', $db);
    }

    private function assertEnabled(): void
    {
        $result = TwoStageLeaveService::isEnabled();
        $this->assertTrue($result, 'Expected isEnabled() → true');
    }

    private function assertDisabled(): void
    {
        $result = TwoStageLeaveService::isEnabled();
        $this->assertFalse($result, 'Expected isEnabled() → false');
    }

    // ================================================================
    // Scenario 1: global=false → false
    // ================================================================

    /** @test */
    public function global_flag_false_disables_all(): void
    {
        $this->setGlobalFlag(false);
        $this->setAllowlist(self::SCHOOL_15_DB);
        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->assertDisabled();
    }

    // ================================================================
    // Scenario 2: global=true, allowlist empty → false
    // ================================================================

    /** @test */
    public function empty_allowlist_disables_all(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist('');
        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->assertDisabled();
    }

    /** @test */
    public function whitespace_only_allowlist_is_empty(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist('   ');
        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->assertDisabled();
    }

    // ================================================================
    // Scenario 3: true + allowlisted + schema complete → true
    // ================================================================

    /** @test */
    public function all_conditions_met_returns_true(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_15_DB);
        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->expectSchemaComplete();
        $this->assertEnabled();
    }

    // ================================================================
    // Scenario 4: true + school NOT in allowlist → false
    // ================================================================

    /** @test */
    public function school_not_in_allowlist_returns_false(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_15_DB);
        $this->setSchoolDb(self::SCHOOL_16_DB);
        $this->assertDisabled();
    }

    // ================================================================
    // Scenario 5: true + allowlisted + schema incomplete → false
    // ================================================================

    /** @test */
    public function schema_incomplete_returns_false(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_15_DB);
        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->expectSchemaIncomplete();
        $this->assertDisabled();
    }

    // ================================================================
    // Scenario 6: true + different school in allowlist → false
    // ================================================================

    /** @test */
    public function school_mismatched_allowlist_returns_false(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_16_DB);
        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->assertDisabled();
    }

    // ================================================================
    // Scenario 7: default school DB → false
    // ================================================================

    /** @test */
    public function default_school_db_not_in_allowlist_returns_false(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_15_DB);
        $this->setSchoolDb('default');
        $this->assertDisabled();
    }

    // ================================================================
    // Scenario 8: Same-process cache isolation (multi-school)
    // ================================================================

    /** @test */
    public function multi_school_cache_isolation(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_15_DB);

        // School 15 → true
        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->expectSchemaComplete();
        $this->assertEnabled();

        TwoStageLeaveService::resetCache();
        Mockery::close();

        // School 16 not in allowlist → false
        $this->setSchoolDb(self::SCHOOL_16_DB);
        $this->assertDisabled();

        // Back to School 15 → true again
        TwoStageLeaveService::resetCache();
        Mockery::close();

        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->expectSchemaComplete();
        $this->assertEnabled();
    }

    // ================================================================
    // Scenario 9: allowlist change after resetCache
    // ================================================================

    /** @test */
    public function allowlist_change_effect_after_reset_cache(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_15_DB);
        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->expectSchemaComplete();
        $this->assertEnabled();

        $this->setAllowlist(self::SCHOOL_16_DB);
        TwoStageLeaveService::resetCache();
        Mockery::close();

        $this->assertDisabled();
    }

    // ================================================================
    // Scenario 10: global flag change after resetCache
    // ================================================================

    /** @test */
    public function global_flag_change_after_reset_cache(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_15_DB);
        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->expectSchemaComplete();
        $this->assertEnabled();

        $this->setGlobalFlag(false);
        TwoStageLeaveService::resetCache();
        Mockery::close();
        $this->assertDisabled();
    }

    // ================================================================
    // Schema unreachable → false
    // ================================================================

    /** @test */
    public function schema_unreachable_handles_gracefully(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_15_DB);
        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->expectSchemaThrows();
        $this->assertDisabled();
    }

    // ================================================================
    // Multi-school allowlist — only listed schools work
    // ================================================================

    /** @test */
    public function multi_school_allowlist_only_listed_enabled(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_15_DB . ',school_a,school_b');

        $this->setSchoolDb(self::SCHOOL_15_DB);
        $this->expectSchemaComplete();
        $this->assertEnabled();

        TwoStageLeaveService::resetCache();
        Mockery::close();

        $this->setSchoolDb('school_a');
        $this->expectSchemaComplete();
        $this->assertEnabled();

        TwoStageLeaveService::resetCache();
        Mockery::close();

        $this->setSchoolDb(self::SCHOOL_16_DB);
        $this->assertDisabled();
    }

    // ================================================================
    // Strict match — no partial database name
    // ================================================================

    /** @test */
    public function partial_database_name_is_not_allowed(): void
    {
        $this->setGlobalFlag(true);
        $this->setAllowlist(self::SCHOOL_15_DB);

        // Substring
        $this->setSchoolDb('eschool_saas_15');
        $this->assertDisabled();

        TwoStageLeaveService::resetCache();

        // Superset
        $this->setSchoolDb(self::SCHOOL_15_DB . '_extra');
        $this->assertDisabled();
    }

    // ================================================================
    // getHrNotificationRecipients guard
    // ================================================================

    /** @test */
    public function hr_notification_recipients_empty_when_disabled(): void
    {
        $this->setGlobalFlag(false);
        $result = TwoStageLeaveService::getHrNotificationRecipients();
        $this->assertTrue($result->isEmpty());
    }
}
