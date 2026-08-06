<?php

namespace Tests\Unit;

use App\Helpers\MoneyDecimal;
use Tests\TestCase;

class MoneyDecimalTest extends TestCase
{
    /** @test */
    public function normalize_trims_and_handles_empty(): void
    {
        $this->assertSame('0.00', MoneyDecimal::normalize(''));
        $this->assertSame('0.00', MoneyDecimal::normalize('   '));
        $this->assertSame('100.50', MoneyDecimal::normalize(' 100.50 '));
        $this->assertSame('50000.00', MoneyDecimal::normalize('50000'));
    }

    /** @test */
    public function to_minor_units_converts_correctly(): void
    {
        $this->assertSame(15000025, MoneyDecimal::toMinorUnits('150000.25'));
        $this->assertSame(5000000, MoneyDecimal::toMinorUnits('50000'));
        $this->assertSame(0, MoneyDecimal::toMinorUnits('0'));
        $this->assertSame(100, MoneyDecimal::toMinorUnits('1'));
        $this->assertSame(1, MoneyDecimal::toMinorUnits('0.01'));
        $this->assertSame(9999, MoneyDecimal::toMinorUnits('99.99'));
    }

    /** @test */
    public function from_minor_units_converts_back(): void
    {
        $this->assertSame('150000.25', MoneyDecimal::fromMinorUnits(15000025));
        $this->assertSame('50000.00', MoneyDecimal::fromMinorUnits(5000000));
        $this->assertSame('0.00', MoneyDecimal::fromMinorUnits(0));
        $this->assertSame('1.00', MoneyDecimal::fromMinorUnits(100));
        $this->assertSame('0.01', MoneyDecimal::fromMinorUnits(1));
        $this->assertSame('99.99', MoneyDecimal::fromMinorUnits(9999));
    }

    /** @test */
    public function round_trip_conversion_is_stable(): void
    {
        $values = ['150000.25', '50000', '0', '99999.99', '0.01', '100.00'];
        foreach ($values as $v) {
            $normalized = MoneyDecimal::normalize($v);
            $this->assertSame(
                $normalized,
                MoneyDecimal::fromMinorUnits(MoneyDecimal::toMinorUnits($normalized)),
                "Round-trip failed for: {$v}"
            );
        }
    }

    /** @test */
    public function compare_returns_correct_ordering(): void
    {
        $this->assertSame(0, MoneyDecimal::compare('100.00', '100.00'));
        $this->assertSame(1, MoneyDecimal::compare('100.01', '100.00'));
        $this->assertSame(-1, MoneyDecimal::compare('99.99', '100.00'));
        $this->assertSame(1, MoneyDecimal::compare('50000', '150.25'));
        $this->assertSame(-1, MoneyDecimal::compare('0', '0.01'));
    }

    /** @test */
    public function add_calculates_correctly(): void
    {
        $this->assertSame('250.75', MoneyDecimal::add('100.50', '150.25'));
        $this->assertSame('50000.00', MoneyDecimal::add('25000', '25000'));
        $this->assertSame('100.01', MoneyDecimal::add('100', '0.01'));
        $this->assertSame('0.00', MoneyDecimal::add('0', '0'));
    }

    /** @test */
    public function subtract_calculates_correctly(): void
    {
        $this->assertSame('49.75', MoneyDecimal::subtract('150.25', '100.50'));
        $this->assertSame('10000.00', MoneyDecimal::subtract('50000', '40000'));
        $this->assertSame('0.00', MoneyDecimal::subtract('100', '100'));
    }

    /** @test */
    public function equality_checks_work(): void
    {
        $this->assertTrue(MoneyDecimal::equals('150000.25', '150000.25'));
        $this->assertFalse(MoneyDecimal::equals('150000.25', '150000.26'));
        $this->assertTrue(MoneyDecimal::greaterThan('150.01', '150.00'));
        $this->assertTrue(MoneyDecimal::greaterThanOrEqual('150.00', '150.00'));
        $this->assertTrue(MoneyDecimal::lessThan('149.99', '150.00'));
        $this->assertTrue(MoneyDecimal::lessThanOrEqual('150.00', '150.00'));
        $this->assertTrue(MoneyDecimal::lessThanOrEqual('149.99', '150.00'));
    }

    /** @test */
    public function to_minor_units_rejects_non_numeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoneyDecimal::toMinorUnits('abc');
    }

    /** @test */
    public function from_float_preserves_two_decimals(): void
    {
        $this->assertSame('100.00', MoneyDecimal::fromFloat(100.0));
        $this->assertSame('100.50', MoneyDecimal::fromFloat(100.50));
        $this->assertSame('100.33', MoneyDecimal::fromFloat(100.333));
    }
}
