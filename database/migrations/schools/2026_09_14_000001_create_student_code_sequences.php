<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const IMPORT_REFERENCE_INDEX = 'student_import_identity_school_reference_unique';

    public function up(): void
    {
        $schema = Schema::connection('school');
        if (!$schema->hasTable('student_import_identities')
            || !$schema->hasColumns('student_import_identities', ['school_id', 'student_code', 'student_id', 'user_id'])) {
            throw new \RuntimeException('Student identity base schema is incomplete; no Student Code schema write was attempted.');
        }

        $sequenceExists = $schema->hasTable('student_code_sequences');
        $referenceExists = $schema->hasColumn('student_import_identities', 'import_reference');
        $referenceIndexExists = $this->hasUniqueIndex(self::IMPORT_REFERENCE_INDEX, ['school_id', 'import_reference']);

        if ($sequenceExists && !$this->sequenceComplete()) {
            throw new \RuntimeException('student_code_sequences exists with a partial schema; refusing migration.');
        }
        if ($referenceExists xor $referenceIndexExists) {
            throw new \RuntimeException('Student import reference schema is partial; refusing migration.');
        }

        if (!$sequenceExists) {
            $schema->create('student_code_sequences', function (Blueprint $table): void {
                $table->unsignedBigInteger('school_id')->primary();
                $table->unsignedBigInteger('next_number')->default(1);
                $table->timestamps();
            });
        }

        if (!$referenceExists) {
            $schema->table('student_import_identities', function (Blueprint $table): void {
                $table->string('import_reference', 100)->nullable()->after('student_code');
                $table->unique(['school_id', 'import_reference'], self::IMPORT_REFERENCE_INDEX);
            });
        }

        // Existing six-digit identities, including soft-deleted Students, are
        // permanent reservations. Seed above their maximum so no code can be
        // reused after deployment.
        $maximumBySchool = [];
        if ($schema->hasTable('schools')) {
            foreach (DB::connection('school')->table('schools')->pluck('id') as $schoolId) $maximumBySchool[(int) $schoolId] = 0;
        }
        foreach (DB::connection('school')->table('students')->distinct()->pluck('school_id') as $schoolId) $maximumBySchool[(int) $schoolId] = 0;
        DB::connection('school')->table('student_import_identities')
            ->orderBy('id')->select(['school_id', 'student_code'])->each(function (object $identity) use (&$maximumBySchool): void {
                $code = (string) $identity->student_code;
                if (!preg_match('/\A\d{6}\z/D', $code)) return;
                $schoolId = (int) $identity->school_id;
                $maximumBySchool[$schoolId] = max($maximumBySchool[$schoolId] ?? 0, (int) $code);
            });

        foreach ($maximumBySchool as $schoolId => $maximum) {
            DB::connection('school')->table('student_code_sequences')->updateOrInsert(
                ['school_id' => $schoolId],
                ['next_number' => $maximum + 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        if (!$this->sequenceComplete()
            || !$schema->hasColumn('student_import_identities', 'import_reference')
            || !$this->hasUniqueIndex(self::IMPORT_REFERENCE_INDEX, ['school_id', 'import_reference'])) {
            throw new \RuntimeException('Student Code sequence migration failed exact schema verification.');
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('school');
        if ($schema->hasColumn('student_import_identities', 'import_reference')) {
            $schema->table('student_import_identities', function (Blueprint $table): void {
                $table->dropUnique(self::IMPORT_REFERENCE_INDEX);
                $table->dropColumn('import_reference');
            });
        }
        $schema->dropIfExists('student_code_sequences');
    }

    private function sequenceComplete(): bool
    {
        $schema = Schema::connection('school');
        return $schema->hasTable('student_code_sequences')
            && $schema->hasColumns('student_code_sequences', ['school_id', 'next_number', 'created_at', 'updated_at'])
            && $this->hasUniqueColumns('student_code_sequences', ['school_id']);
    }

    /** @param list<string> $columns */
    private function hasUniqueIndex(string $name, array $columns): bool
    {
        try {
            foreach (Schema::connection('school')->getIndexes('student_import_identities') as $index) {
                if (($index['name'] ?? null) === $name
                    && (bool) ($index['unique'] ?? false)
                    && array_values($index['columns'] ?? []) === $columns) return true;
            }
        } catch (Throwable) {
        }
        return false;
    }

    /** @param list<string> $columns */
    private function hasUniqueColumns(string $table, array $columns): bool
    {
        try {
            foreach (Schema::connection('school')->getIndexes($table) as $index) {
                if ((bool) ($index['unique'] ?? false)
                    && array_values($index['columns'] ?? []) === $columns) return true;
            }
        } catch (Throwable) {
        }
        return false;
    }
};
