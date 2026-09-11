<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $complete = static function (): bool {
            $schema = Schema::connection('school');
            if (!$schema->hasTable('student_import_identities')) return false;
            foreach (['id','school_id','student_code','student_id','user_id','created_by','created_at','updated_at'] as $column) {
                if (!$schema->hasColumn('student_import_identities', $column)) return false;
            }
            $indexes = collect($schema->getIndexes('student_import_identities'))->keyBy('name');
            return ($indexes->get('student_import_identity_school_code_unique')['columns'] ?? []) === ['school_id','student_code']
                && (bool) ($indexes->get('student_import_identity_school_code_unique')['unique'] ?? false)
                && ($indexes->get('student_import_identities_student_id_unique')['columns'] ?? []) === ['student_id']
                && (bool) ($indexes->get('student_import_identities_student_id_unique')['unique'] ?? false)
                && ($indexes->get('student_import_identities_user_id_unique')['columns'] ?? []) === ['user_id']
                && (bool) ($indexes->get('student_import_identities_user_id_unique')['unique'] ?? false);
        };
        if (Schema::connection('school')->hasTable('student_import_identities')) {
            if (!$complete()) throw new RuntimeException('student_import_identities exists with a partial schema; refusing to record migration success.');
            return;
        }
        Schema::connection('school')->create('student_import_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            // Text identity: spreadsheet codes such as 00125 are never numeric.
            $table->string('student_code', 100);
            $table->unsignedBigInteger('student_id')->unique();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['school_id', 'student_code'], 'student_import_identity_school_code_unique');
        });
        if (!$complete()) throw new RuntimeException('student_import_identities creation failed exact schema verification.');
    }

    public function down(): void
    {
        Schema::connection('school')->dropIfExists('student_import_identities');
    }
};
