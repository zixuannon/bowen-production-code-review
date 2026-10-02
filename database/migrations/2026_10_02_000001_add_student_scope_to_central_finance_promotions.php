<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Promotion may optionally be approved for one Central student profile.
 * Definitions remain Group master data; applications remain immutable
 * receivable snapshots.  A NULL target keeps the existing School-wide rule.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_promotions', function (Blueprint $table): void {
            $table->unsignedBigInteger('student_profile_id')->nullable()->after('group_id');
            $table->index(['student_profile_id', 'status', 'valid_from'], 'cf_promotion_student_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_promotions', function (Blueprint $table): void {
            $table->dropIndex('cf_promotion_student_status_date_index');
            $table->dropColumn('student_profile_id');
        });
    }
};
