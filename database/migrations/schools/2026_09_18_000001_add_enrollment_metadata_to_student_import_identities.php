<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student Import V3 keeps non-financial enrollment metadata beside the stable
 * import identity.  It never changes the immutable generated Student Code.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('school');
        if (!$schema->hasTable('student_import_identities')) {
            throw new LogicException('Student Import identity schema is required before enrollment metadata.');
        }
        $missingSchedule = !$schema->hasColumn('student_import_identities', 'schedule_type');
        $missingStatus = !$schema->hasColumn('student_import_identities', 'enrollment_status');
        if ($missingSchedule || $missingStatus) {
            $schema->table('student_import_identities', function (Blueprint $table) use ($missingSchedule, $missingStatus): void {
                if ($missingSchedule) $table->string('schedule_type', 20)->nullable()->after('import_reference');
                if ($missingStatus) $table->string('enrollment_status', 20)->nullable()->after('schedule_type');
            });
        }
        if (!$schema->hasColumns('student_import_identities', ['schedule_type', 'enrollment_status'])) {
            throw new RuntimeException('Student Import enrollment metadata migration did not complete.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Student Import enrollment metadata is forward-only once imports exist.');
    }
};
