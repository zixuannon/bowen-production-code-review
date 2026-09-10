<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected bool $tenantDbAsDefault = false;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->tenantDbAsDefault) {
            config(['database.default' => 'school']);
            DB::setDefaultConnection('school');
            $this->ensureTenantFixtureCompatibility();
            $this->ensureCentralSchoolFixture();
        }
    }

    /**
     * The legacy tenant schema is intentionally assembled from versioned
     * migrations. A few older tests exercise columns/rows that production
     * creates lazily; provide those deterministic fixtures only in the
     * disposable tenant test database.
     */
    protected function ensureTenantFixtureCompatibility(): void
    {
        // Legacy import fixtures omit school_id while exercising the service;
        // default it to the isolated tenant school so MySQL foreign keys stay
        // strict without changing production models or migrations.
        \App\Models\FeesPaid::creating(static function ($payment): void {
            if (! $payment->school_id) {
                $payment->school_id = (int) ($payment->getAttribute('school_id') ?: 1);
            }
        });
        \App\Models\FeesAdvance::creating(static function ($advance): void {
            if (! $advance->parent_id) {
                $advance->parent_id = $advance->student_id;
            }
        });
        \App\Models\OptionalFee::creating(static function ($fee): void {
            if (! $fee->class_id) {
                $fee->class_id = 1;
            }
        });
        $schema = Schema::connection('school');
        if ($schema->hasTable('fees')) {
        }

        if ($schema->hasTable('session_years')) {
            $years = DB::connection('school')->table('session_years');
            foreach ([1 => '2025-2026', 2 => '2026-2027'] as $id => $name) {
                $years->insertOrIgnore([
                        'id' => $id,
                        'name' => $name,
                        'default' => $id === 1 ? 1 : 0,
                        'start_date' => $id === 1 ? '2025-06-01' : '2026-06-01',
                        'end_date' => $id === 1 ? '2026-05-31' : '2027-05-31',
                        'school_id' => 1,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]);
            }
        }

        if ($schema->hasTable('mediums')) {
            $mediums = DB::connection('school')->table('mediums');
            $mediums->insertOrIgnore([
                'id' => 1,
                'name' => 'QA Medium',
                'school_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($schema->hasTable('classes')) {
            $classPayload = [
                'id' => 1,
                'name' => 'QA Class',
                'include_semesters' => 0,
                'medium_id' => 1,
                'school_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $classColumns = array_flip($schema->getColumnListing('classes'));
            DB::connection('school')->table('classes')->insertOrIgnore(array_intersect_key($classPayload, $classColumns));
        }
        if ($schema->hasTable('sections')) {
            $sectionColumns = array_flip($schema->getColumnListing('sections'));
            DB::connection('school')->table('sections')->insertOrIgnore(array_intersect_key([
                'id' => 1, 'name' => 'QA Section', 'school_id' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], $sectionColumns));
        }
        if ($schema->hasTable('class_sections')) {
            $pivotColumns = array_flip($schema->getColumnListing('class_sections'));
            DB::connection('school')->table('class_sections')->insertOrIgnore(array_intersect_key([
                'id' => 1, 'class_id' => 1, 'section_id' => 1, 'medium_id' => 1, 'school_id' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], $pivotColumns));
        }
    }

    protected function ensureCentralSchoolFixture(): void
    {
        $schema = Schema::connection('mysql');
        if (! $schema->hasTable('schools')) {
            return;
        }

        $payload = [
            'id' => 1,
            'name' => 'Handover QA School',
            'address' => 'QA',
            'support_phone' => '000',
            'support_email' => 'central-qa@example.test',
            'tagline' => 'QA',
            'logo' => '',
            'status' => 1,
            'installed' => 1,
            'database_name' => env('DB_SCHOOL_DATABASE', 'school_testing'),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $columns = array_flip($schema->getColumnListing('schools'));
        $table = DB::connection('mysql')->table('schools');
        $filtered = array_intersect_key($payload, $columns);
        if ($table->where('id', 1)->exists()) {
            $table->where('id', 1)->update(array_intersect_key(['database_name' => $filtered['database_name'] ?? null, 'updated_at' => now()], $columns));
        } else {
            $table->insertOrIgnore($filtered);
        }
    }
}
