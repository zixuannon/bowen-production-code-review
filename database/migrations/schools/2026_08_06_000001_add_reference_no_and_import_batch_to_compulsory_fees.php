<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'school';

    /**
     * Add reference_no + import_batch_id + unique index to compulsory_fees
     * on the SCHOOL connection.
     *
     * Upward compatible: existing historical data with reference_no=NULL is preserved.
     * Unique constraint on (school_id, reference_no) allows multiple NULLs (MySQL behavior).
     */
    public function up(): void
    {
        Schema::connection('school')->table('compulsory_fees', function (Blueprint $table) {
            if (!Schema::connection('school')->hasColumn('compulsory_fees', 'reference_no')) {
                $table->string('reference_no', 100)->nullable()->after('cheque_no')
                    ->comment('Unique payment reference per school (e.g. INV-001)');
            }
            if (!Schema::connection('school')->hasColumn('compulsory_fees', 'import_batch_id')) {
                $table->bigInteger('import_batch_id')->unsigned()->nullable()->after('reference_no')
                    ->comment('FeeImportBatch that created this record');
            }
        });

        // Add unique index (check both possible names for idempotency)
        Schema::connection('school')->table('compulsory_fees', function (Blueprint $table) {
            try {
                $sm = Schema::connection('school')->getConnection()->getDoctrineSchemaManager();
                $indexes = $sm->listTableIndexes('compulsory_fees');

                if (!isset($indexes['compulsory_fees_reference_no_unique'])
                    && !isset($indexes['comp_ref_unique'])) {
                    $table->unique(['school_id', 'reference_no'], 'compulsory_fees_reference_no_unique');
                }
            } catch (\Throwable) {
                // Best-effort: unique index already exists or schema manager unavailable
            }
        });
    }

    public function down(): void
    {
        // Drop unique index
        Schema::connection('school')->table('compulsory_fees', function (Blueprint $table) {
            try {
                $sm = Schema::connection('school')->getConnection()->getDoctrineSchemaManager();
                $indexes = $sm->listTableIndexes('compulsory_fees');

                if (isset($indexes['compulsory_fees_reference_no_unique'])) {
                    $table->dropUnique('compulsory_fees_reference_no_unique');
                } elseif (isset($indexes['comp_ref_unique'])) {
                    $table->dropUnique('comp_ref_unique');
                }
            } catch (\Throwable) {
                // Index may not exist
            }
        });

        // Drop columns
        if (Schema::connection('school')->hasColumn('compulsory_fees', 'import_batch_id')) {
            Schema::connection('school')->table('compulsory_fees', function (Blueprint $table) {
                $table->dropColumn('import_batch_id');
            });
        }
        if (Schema::connection('school')->hasColumn('compulsory_fees', 'reference_no')) {
            Schema::connection('school')->table('compulsory_fees', function (Blueprint $table) {
                $table->dropColumn('reference_no');
            });
        }
    }
};
