<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TwoStageLeaveService::parseAllowlist() — the fail-closed
 * school-level grayscale allowlist parser.
 *
 * parseAllowlist is a pure function tested in isolation.
 * The isEnabled() three-tier gating is tested via the allowlist
 * behavior: parseAllowlist is Tier 2, and its fail-closed guarantee
 * ensures that no school is enabled unless explicitly listed.
 *
 * Integration tests for isEnabled() require Laravel boot + database
 * and are tested in the project's E2E browser suite.
 */
class TwoStageLeaveServiceAllowlistTest extends TestCase
{
    /**
     * Helper: invoke the private parseAllowlist method via reflection.
     *
     * @param  mixed  $input
     * @return string[]
     */
    private function parse($input): array
    {
        $ref = new \ReflectionClass(\App\Services\StaffLeave\TwoStageLeaveService::class);
        $method = $ref->getMethod('parseAllowlist');
        $method->setAccessible(true);
        return $method->invoke(null, $input);
    }

    // ================================================================
    // Normal cases
    // ================================================================

    /** @test */
    public function single_entry_returns_single_item(): void
    {
        $result = $this->parse('eschool_saas_15_zixuan');
        $this->assertSame(['eschool_saas_15_zixuan'], $result);
    }

    /** @test */
    public function multiple_entries_return_all(): void
    {
        $result = $this->parse('eschool_saas_15_zixuan,eschool_saas_20_other');
        $this->assertSame(['eschool_saas_15_zixuan', 'eschool_saas_20_other'], $result);
    }

    // ================================================================
    // Edge cases: whitespace
    // ================================================================

    /** @test */
    public function leading_and_trailing_whitespace_is_trimmed(): void
    {
        $result = $this->parse('  eschool_saas_15_zixuan , eschool_saas_20_other  ');
        $this->assertSame(['eschool_saas_15_zixuan', 'eschool_saas_20_other'], $result);
    }

    /** @test */
    public function whitespace_around_entries_is_trimmed(): void
    {
        $result = $this->parse('eschool_saas_15_zixuan  ,  eschool_saas_20_other');
        $this->assertSame(['eschool_saas_15_zixuan', 'eschool_saas_20_other'], $result);
    }

    // ================================================================
    // Edge cases: empty entries
    // ================================================================

    /** @test */
    public function empty_string_returns_empty_array(): void
    {
        $result = $this->parse('');
        $this->assertSame([], $result);
    }

    /** @test */
    public function empty_entries_are_ignored(): void
    {
        $result = $this->parse('eschool_saas_15_zixuan,,eschool_saas_20_other,,');
        $this->assertSame(['eschool_saas_15_zixuan', 'eschool_saas_20_other'], $result);
    }

    /** @test */
    public function trailing_comma_is_handled(): void
    {
        $result = $this->parse('eschool_saas_15_zixuan,');
        $this->assertSame(['eschool_saas_15_zixuan'], $result);
    }

    /** @test */
    public function leading_comma_is_handled(): void
    {
        $result = $this->parse(',eschool_saas_15_zixuan');
        $this->assertSame(['eschool_saas_15_zixuan'], $result);
    }

    /** @test */
    public function only_commas_returns_empty_array(): void
    {
        $result = $this->parse(',,,');
        $this->assertSame([], $result);
    }

    // ================================================================
    // Edge cases: duplicates
    // ================================================================

    /** @test */
    public function duplicates_are_deduplicated(): void
    {
        $result = $this->parse('eschool_saas_15_zixuan,eschool_saas_15_zixuan,eschool_saas_15_zixuan');
        $this->assertSame(['eschool_saas_15_zixuan'], $result);
    }

    /** @test */
    public function duplicates_in_mixed_list_are_deduplicated(): void
    {
        $result = $this->parse('a,a,b,b,c,a');
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    // ================================================================
    // Edge cases: invalid input
    // ================================================================

    /** @test */
    public function null_input_returns_empty_array(): void
    {
        $result = $this->parse(null);
        $this->assertSame([], $result);
    }

    /** @test */
    public function boolean_input_returns_empty_array(): void
    {
        $result = $this->parse(false);
        $this->assertSame([], $result);
    }

    /** @test */
    public function integer_input_returns_empty_array(): void
    {
        $result = $this->parse(42);
        $this->assertSame([], $result);
    }

    /** @test */
    public function non_string_array_returns_empty_array(): void
    {
        $result = $this->parse([]);
        $this->assertSame([], $result);
    }

    // ================================================================
    // Safety guarantees (fail-closed)
    // ================================================================

    /** @test */
    public function empty_string_means_no_school_enabled(): void
    {
        // This is the core fail-closed guarantee:
        // empty allowlist → empty array → isEnabled() returns false
        $result = $this->parse('');
        $this->assertEmpty($result);
        $this->assertCount(0, $result);
    }

    /** @test */
    public function invalid_input_means_no_school_enabled(): void
    {
        // Non-string input → empty array → fail-closed
        $result = $this->parse(null);
        $this->assertEmpty($result);
    }

    /** @test */
    public function whitespace_only_is_treated_as_empty(): void
    {
        $result = $this->parse('   ');
        // After trim: '' → split on ',' → [''] → trim '' → '' → skip
        $this->assertEmpty($result);
    }
}
