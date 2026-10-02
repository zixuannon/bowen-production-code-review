<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Links a mutable tenant draft line to its immutable Central approval request. */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('school')->table('student_fee_assignment_items', function (Blueprint $table): void {
            $table->uuid('student_discount_request_uuid')->nullable()->after('student_discount_effective_date');
            $table->string('student_discount_request_status', 16)->nullable()->after('student_discount_request_uuid');
            $table->index('student_discount_request_uuid', 'sfa_item_discount_request_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('school')->table('student_fee_assignment_items', function (Blueprint $table): void {
            $table->dropIndex('sfa_item_discount_request_uuid_idx');
            $table->dropColumn(['student_discount_request_uuid', 'student_discount_request_status']);
        });
    }
};
