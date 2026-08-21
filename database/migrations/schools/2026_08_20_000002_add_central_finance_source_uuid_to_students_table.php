<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The UUID is a tenant-owned source identity. It is nullable during the
     * gradual backfill; Central Finance refuses to ingest a student until a
     * stable identity exists, rather than guessing from mutable name/email.
     */
    public function up(): void
    {
        if (!Schema::connection('school')->hasColumn('students', 'central_finance_source_uuid')) {
            Schema::connection('school')->table('students', function (Blueprint $table): void {
                $table->uuid('central_finance_source_uuid')->nullable()->after('id');
                $table->unique('central_finance_source_uuid', 'students_central_finance_uuid_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('school')->hasColumn('students', 'central_finance_source_uuid')) {
            Schema::connection('school')->table('students', function (Blueprint $table): void {
                $table->dropUnique('students_central_finance_uuid_unique');
                $table->dropColumn('central_finance_source_uuid');
            });
        }
    }
};
