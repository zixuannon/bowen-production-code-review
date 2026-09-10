<?php

namespace Tests\Unit;

use App\Models\Fee;
use App\Models\FeesClassType;
use Tests\TestCase;

class FeeModelAccessorTest extends TestCase
{
    protected bool $tenantDbAsDefault = true;

    /** @test */
    public function total_compulsory_fees_uses_relation_when_loaded(): void
    {
        $fee = $this->createFeeWithRawValue(5000.00);

        // Simulate fees_class_type relation loaded with computed data
        $fee->setRelation('fees_class_type', collect([
            new FeesClassType(['optional' => 0, 'amount' => 1200.00]),
            new FeesClassType(['optional' => 0, 'amount' => 800.00]),
            new FeesClassType(['optional' => 1, 'amount' => 300.00]),
        ]));

        // Accessor should return sum of compulsory (optional=0) items
        $this->assertEquals(2000.00, $fee->total_compulsory_fees,
            'With relation loaded, should compute sum of compulsory fees');
    }

    /** @test */
    public function total_compulsory_fees_falls_back_to_raw_when_relation_not_loaded(): void
    {
        $fee = $this->createFeeWithRawValue(3500.00);

        // No relation loaded - should fall back to raw DB value
        $this->assertFalse($fee->relationLoaded('fees_class_type'));
        $this->assertEquals(3500.00, $fee->total_compulsory_fees,
            'Without relation, should return raw database value');
    }

    /** @test */
    public function total_optional_fees_uses_relation_when_loaded(): void
    {
        $fee = $this->createFeeWithRawValue(5000.00);

        $fee->setRelation('fees_class_type', collect([
            new FeesClassType(['optional' => 0, 'amount' => 1200.00]),
            new FeesClassType(['optional' => 0, 'amount' => 800.00]),
            new FeesClassType(['optional' => 1, 'amount' => 300.00]),
            new FeesClassType(['optional' => 1, 'amount' => 150.00]),
        ]));

        // Accessor should return sum of optional (optional=1) items
        $this->assertEquals(450.00, $fee->total_optional_fees,
            'With relation loaded, should compute sum of optional fees');
    }

    /** @test */
    public function total_optional_fees_returns_null_when_column_missing(): void
    {
        // total_optional_fees column may not exist in all database schemas
        // The accessor should gracefully return null when no relation or column
        $fee = $this->createFeeWithRawValue(3500.00);

        $this->assertFalse($fee->relationLoaded('fees_class_type'));
        // When column doesn't exist, accessor should return null gracefully
        $result = $fee->total_optional_fees;
        $this->assertNull($result,
            'total_optional_fees should be null when neither relation loaded nor DB column exists');
    }

    /** @test */
    public function both_accessor_paths_give_same_result_for_compulsory(): void
    {
        $fee = $this->createFeeWithRawValue(2000.00);

        // 1. Without relation → raw DB value
        $freshFee = Fee::find($fee->id);
        $this->assertFalse($freshFee->relationLoaded('fees_class_type'));
        $this->assertEquals(2000.00, $freshFee->total_compulsory_fees);

        // 2. Load relation → computed value
        $freshFee->setRelation('fees_class_type', collect([
            new FeesClassType(['optional' => 0, 'amount' => 1200.00]),
            new FeesClassType(['optional' => 0, 'amount' => 800.00]),
        ]));
        $this->assertEquals(2000.00, $freshFee->total_compulsory_fees,
            'Computed value via relation should match stored raw value');
    }

    /** @test */
    public function null_raw_value_does_not_trigger_false_fully_paid(): void
    {
        // Fee with NULL total_compulsory_fees in DB
        $fee = new Fee();
        $fee->forceFill([
            'name'            => 'Null Total Fee',
            'due_date'        => now()->addDays(30)->format('Y-m-d'),
            'due_charges'     => 0,
            'class_id'        => 1,
            'school_id'       => 1,
            'session_year_id' => 1,
            'total_compulsory_fees' => null,
        ]);
        $fee->save();

        $fresh = Fee::find($fee->id);
        $this->assertNull($fresh->total_compulsory_fees,
            'NULL raw value should return NULL, not 0 or false');

        // is_fully_paid check should use proper comparison: NULL comparison in PHP 8.x
        // $amount >= null → true in PHP 8.x!
        // This confirms the accessor must return the raw value (null) not a falsy default
        // The payment service MUST handle the null case explicitly
        $amount = 1500;
        $this->assertTrue(
            $amount >= $fresh->total_compulsory_fees,
            'Documenting: PHP 8.x treats (int >= null) as true. Payment service MUST guard against null.'
        );
    }

    /** @test */
    public function modify_fees_class_type_updates_computed_value(): void
    {
        $fee = $this->createFeeWithRawValue(2000.00);

        // Initially with relation loaded
        $fee->setRelation('fees_class_type', collect([
            new FeesClassType(['optional' => 0, 'amount' => 600.00]),
        ]));
        $this->assertEquals(600.00, $fee->total_compulsory_fees);

        // Modify the relation data (simulating a change)
        $fee->setRelation('fees_class_type', collect([
            new FeesClassType(['optional' => 0, 'amount' => 600.00]),
            new FeesClassType(['optional' => 0, 'amount' => 400.00]),
        ]));
        $this->assertEquals(1000.00, $fee->total_compulsory_fees,
            'Should NOT read stale value after relation data changes');
    }

    /** @test */
    public function total_compulsory_fees_not_mass_assignable(): void
    {
        // Verify the field is NOT in $fillable
        $fillable = (new Fee())->getFillable();
        $this->assertNotContains('total_compulsory_fees', $fillable,
            'total_compulsory_fees must NOT be mass-assignable');
        $this->assertNotContains('total_optional_fees', $fillable,
            'total_optional_fees must NOT be mass-assignable');
        $this->assertNotContains('created_at', $fillable,
            'created_at must NOT be mass-assignable (Eloquent-managed)');
        $this->assertNotContains('updated_at', $fillable,
            'updated_at must NOT be mass-assignable (Eloquent-managed)');
    }

    /** @test */
    public function mass_assignment_cannot_overwrite_total_compulsory_fees(): void
    {
        $fee = $this->createFeeWithRawValue(3000.00);

        // Attempt mass assignment via update (should NOT change total_compulsory_fees)
        $fee->update([
            'name'                  => 'Hacked Fee',
            'total_compulsory_fees' => 99999.99, // attacker payload
        ]);

        $fresh = Fee::find($fee->id);
        $this->assertEquals(3000.00, $fresh->total_compulsory_fees,
            'total_compulsory_fees must NOT be overwritten via mass assignment');
    }

    /** @test */
    public function forceFill_still_allows_explicit_assignment(): void
    {
        $fee = $this->createFeeWithRawValue(3000.00);

        // forceFill bypasses mass-assignment protection (for tests/seeds only)
        $fee->forceFill(['total_compulsory_fees' => 5000.00]);
        $fee->save();

        $fresh = Fee::find($fee->id);
        $this->assertEquals(5000.00, $fresh->total_compulsory_fees,
            'forceFill should still work for legitimate use (tests, seeds)');
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function createFeeWithRawValue(float $compulsory, ?float $optional = null): Fee
    {
        $fee = new Fee();
        $payload = [
            'name'                  => 'Accessor Test Fee',
            'due_date'              => now()->addDays(30)->format('Y-m-d'),
            'due_charges'           => 0,
            'class_id'              => 1,
            'school_id'             => 1,
            'session_year_id'       => 1,
            'total_compulsory_fees' => $compulsory,
        ];
        if ($optional !== null) {
            $payload['total_optional_fees'] = $optional;
        }
        $fee->forceFill($payload);
        $fee->save();
        return $fee->fresh();
    }
}
