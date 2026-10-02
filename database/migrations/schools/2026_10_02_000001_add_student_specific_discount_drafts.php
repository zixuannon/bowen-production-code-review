<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft-only request details for the Central Promotion engine. Confirmed
 * assignments remain immutable and retain only the selected Promotion id;
 * the immutable Central Promotion Application is the financial audit record.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('school')->table('student_fee_assignment_items', function (Blueprint $table): void {
            $table->string('student_discount_type', 16)->nullable()->after('selected_promotion_id');
            $table->decimal('student_discount_value', 20, 4)->nullable()->after('student_discount_type');
            $table->string('student_discount_reason', 2000)->nullable()->after('student_discount_value');
            $table->date('student_discount_effective_date')->nullable()->after('student_discount_reason');
        });
    }

    public function down(): void
    {
        Schema::connection('school')->table('student_fee_assignment_items', function (Blueprint $table): void {
            $table->dropColumn([
                'student_discount_type',
                'student_discount_value',
                'student_discount_reason',
                'student_discount_effective_date',
            ]);
        });
    }
};
