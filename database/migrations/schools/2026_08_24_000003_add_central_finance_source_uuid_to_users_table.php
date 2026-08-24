<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::connection('school')->hasColumn('users', 'central_finance_source_uuid')) {
            Schema::connection('school')->table('users', function (Blueprint $table): void {
                $table->uuid('central_finance_source_uuid')->nullable()->after('id');
                $table->unique('central_finance_source_uuid', 'users_central_finance_source_uuid_unique');
            });
        }
    }
    public function down(): void
    {
        if (Schema::connection('school')->hasColumn('users', 'central_finance_source_uuid')) {
            Schema::connection('school')->table('users', function (Blueprint $table): void {
                $table->dropUnique('users_central_finance_source_uuid_unique');
                $table->dropColumn('central_finance_source_uuid');
            });
        }
    }
};
